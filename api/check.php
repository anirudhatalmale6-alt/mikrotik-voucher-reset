<?php
/**
 * API: Voucher Check (read only - nothing on the router is changed)
 * POST { router_id, voucher }
 *
 * Answers the two questions the operator asks at the counter:
 *   - when was this voucher activated (first used)?
 *   - when does it expire / how much is left?
 *
 * The router is the only source of truth, and different voucher systems
 * store those dates in different places, so we look in all of them:
 *   1. User Manager (RouterOS 7 and the old RouterOS 6 one) - exact dates
 *   2. the hotspot user's comment - where voucher generators write the date
 *   3. the hotspot user's uptime / limit-uptime counters
 *   4. the live session, if the voucher is online right now
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/RouterosAPI.php';

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

/**
 * parseResponse() stores a !trap's "=message=" under key -1, because the
 * trap sentence never increments its record counter. Real records start at 0.
 */
function rosRows(array $res): array {
    $rows = [];
    foreach ($res as $k => $row) {
        if ($k >= 0) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function rosTrap(array $res): ?string {
    return isset($res[-1]['message']) ? (string) $res[-1]['message'] : null;
}

/**
 * Run a print command with optional query words (?name=..., ?user=...).
 */
function rosQuery(RouterosAPI $api, string $path, array $filters = []): array {
    $api->write($path, empty($filters));
    $left = count($filters);
    foreach ($filters as $f) {
        $left--;
        $api->write($f, $left === 0);
    }
    return $api->read();
}

/**
 * "1w2d3h4m5s" or "01:23:45" -> seconds. Anything else (unset, "none") -> null.
 */
function rosSeconds($value): ?int {
    if ($value === null) return null;
    $v = strtolower(trim((string) $value));
    if ($v === '' || $v === 'none' || $v === 'unlimited') return null;

    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $v, $m)) {
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }
    if (!preg_match('/^(\d+w)?(\d+d)?(\d+h)?(\d+m)?(\d+s)?$/', $v) || $v === '') {
        return null;
    }
    $mul = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    $total = 0;
    if (preg_match_all('/(\d+)([wdhms])/', $v, $all, PREG_SET_ORDER)) {
        foreach ($all as $p) {
            $total += ((int) $p[1]) * $mul[$p[2]];
        }
        return $total;
    }
    return null;
}

function fmtDuration(int $sec): string {
    if ($sec <= 0) return '0m';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    $parts = [];
    if ($d) $parts[] = $d . 'd';
    if ($h) $parts[] = $h . 'h';
    if ($m) $parts[] = $m . 'm';
    if (!$parts && $s) $parts[] = $s . 's';
    return implode(' ', array_slice($parts, 0, 2));
}

function fmtBytes($bytes): string {
    $b = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return ($b >= 10 || $i === 0 ? round($b) : round($b, 1)) . ' ' . $units[$i];
}

/**
 * RouterOS prints dates as "sep/06/2026" (v6) or "2026-09-06" (v7).
 * Returned as a UTC DateTimeImmutable so the server's own timezone can never
 * shift the router's clock - we only ever print it back as text.
 */
function rosDateTime(string $date, string $time = '00:00:00'): ?DateTimeImmutable {
    $date = trim($date);
    $time = trim($time);
    if ($time === '' || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
        $time = '00:00:00';
    }
    if (substr_count($time, ':') === 1) {
        $time .= ':00';
    }

    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
               'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    $y = $mo = $d = null;
    if (preg_match('#^([a-z]{3})/(\d{1,2})/(\d{4})$#i', $date, $m)) {
        $key = strtolower($m[1]);
        if (!isset($months[$key])) return null;
        $mo = $months[$key];
        $d  = (int) $m[2];
        $y  = (int) $m[3];
    } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $date, $m)) {
        $y  = (int) $m[1];
        $mo = (int) $m[2];
        $d  = (int) $m[3];
    } elseif (preg_match('#^(\d{1,2})-([a-z]{3})-(\d{4})$#i', $date, $m)) {
        $key = strtolower($m[2]);
        if (!isset($months[$key])) return null;
        $d  = (int) $m[1];
        $mo = $months[$key];
        $y  = (int) $m[3];
    } else {
        return null;
    }

    if (!checkdate($mo, $d, $y)) return null;

    try {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %s', $y, $mo, $d, $time),
            new DateTimeZone('UTC')
        );
    } catch (Exception $e) {
        return null;
    }
}

function fmtDate(?DateTimeImmutable $dt, bool $withTime = true): string {
    if (!$dt) return '';
    return $dt->format($withTime ? 'd M Y, H:i' : 'd M Y');
}

/**
 * Find every date (with optional time) inside a free-text comment.
 * Voucher generators write the activation and/or the expiry date there.
 */
function scanDates(string $text): array {
    $found = [];
    $patterns = [
        '#(\d{4}-\d{1,2}-\d{1,2})[ T]?(\d{1,2}:\d{2}(?::\d{2})?)?#i',
        '#([a-z]{3}/\d{1,2}/\d{4})[ ]?(\d{1,2}:\d{2}(?::\d{2})?)?#i',
        '#(\d{1,2}-[a-z]{3}-\d{4})[ ]?(\d{1,2}:\d{2}(?::\d{2})?)?#i',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $dt = rosDateTime($m[1], $m[2] ?? '');
                if ($dt) {
                    $found[] = ['dt' => $dt, 'raw' => trim($m[0]), 'hasTime' => !empty($m[2])];
                }
            }
        }
    }
    usort($found, function ($a, $b) {
        return $a['dt'] <=> $b['dt'];
    });
    return $found;
}

function fail(string $message, string $type = 'error'): void {
    echo json_encode(['success' => false, 'message' => $message, 'type' => $type]);
    exit;
}

/* ------------------------------------------------------------------ */
/* Input                                                               */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed');
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$routerId = isset($input['router_id']) ? (int) $input['router_id'] : 0;
$voucher  = isset($input['voucher']) ? trim($input['voucher']) : '';

if ($routerId <= 0 || $voucher === '') {
    fail('Please provide both router and voucher code.');
}

$router = getRouter($routerId);
if (!$router) {
    fail('Router not found in database.');
}

/* ------------------------------------------------------------------ */
/* Connect                                                             */
/* ------------------------------------------------------------------ */

$API = new RouterosAPI();
$API->timeout  = ROUTEROS_TIMEOUT;
$API->port     = $router['port'];
$API->attempts = ROUTEROS_ATTEMPTS;

if (!$API->connect($router['ip'], $router['username'], $router['password'])) {
    fail('Connection failed. Router may be offline or API is not enabled.');
}

/* Router clock - every date below is shown in the ROUTER's time, not the server's */
$now      = null;
$nowLabel = '';
$clock = rosRows(rosQuery($API, '/system/clock/print'));
if (!empty($clock[0])) {
    $now = rosDateTime($clock[0]['date'] ?? '', $clock[0]['time'] ?? '');
    if ($now) {
        $nowLabel = fmtDate($now) . (isset($clock[0]['time-zone-name']) ? ' (' . $clock[0]['time-zone-name'] . ')' : '');
    }
}

/* 1. The local hotspot user */
$hsRes = rosQuery($API, '/ip/hotspot/user/print', ['?name=' . $voucher]);
$hs    = rosRows($hsRes);
$user  = $hs[0] ?? null;

/* 2. User Manager - RouterOS 7 first, then the RouterOS 6 one */
$umUser    = null;
$umProfile = null;
$umFirst   = null;   // first session start
$umLast    = null;   // last session end

$umRows = rosRows(rosQuery($API, '/user-manager/user/print', ['?name=' . $voucher]));
if (!empty($umRows[0])) {
    $umUser = $umRows[0];
    $upRows = rosRows(rosQuery($API, '/user-manager/user-profile/print', ['?user=' . $voucher]));
    if (!empty($upRows[0])) {
        $umProfile = $upRows[0];
    }
    $sesRows = rosRows(rosQuery($API, '/user-manager/session/print', ['?user=' . $voucher]));
    foreach ($sesRows as $s) {
        $started = isset($s['started']) ? rosDateTime(...array_pad(explode(' ', trim($s['started'])), 2, '')) : null;
        if ($started && (!$umFirst || $started < $umFirst)) {
            $umFirst = $started;
        }
        $ended = isset($s['ended']) ? rosDateTime(...array_pad(explode(' ', trim($s['ended'])), 2, '')) : null;
        if ($ended && (!$umLast || $ended > $umLast)) {
            $umLast = $ended;
        }
    }
} else {
    $umRows = rosRows(rosQuery($API, '/tool/user-manager/user/print', ['?username=' . $voucher]));
    if (!empty($umRows[0])) {
        $umUser = $umRows[0];
    }
}

if (!$user && !$umUser) {
    $API->disconnect();
    echo json_encode([
        'success' => false,
        'type'    => 'not_found',
        'message' => 'Voucher "' . $voucher . '" was not found on ' . $router['name'] . '. Check the code, or try another router.',
    ]);
    exit;
}

/* 3. Is it online right now? */
$activeRows = [];
if ($user || $umUser) {
    $activeRows = rosRows(rosQuery($API, '/ip/hotspot/active/print', ['?user=' . $voucher]));
}
$active = $activeRows[0] ?? null;

/* 4. The user's profile (for its session timeout) */
$hsProfile = null;
if ($user && !empty($user['profile'])) {
    $pRows = rosRows(rosQuery($API, '/ip/hotspot/user/profile/print', ['?name=' . $user['profile']]));
    if (!empty($pRows[0])) {
        $hsProfile = $pRows[0];
    }
}

$API->disconnect();

/* ------------------------------------------------------------------ */
/* Work out the two dates                                              */
/* ------------------------------------------------------------------ */

$comment     = trim((string) ($user['comment'] ?? ($umUser['comment'] ?? '')));
$commentDates = $comment !== '' ? scanDates($comment) : [];

$usedSec  = rosSeconds($user['uptime'] ?? null);
$limitSec = rosSeconds($user['limit-uptime'] ?? null);
$bytesIn  = isset($user['bytes-in'])  ? (float) $user['bytes-in']  : null;
$bytesOut = isset($user['bytes-out']) ? (float) $user['bytes-out'] : null;
$limitBytes = isset($user['limit-bytes-total']) ? (float) $user['limit-bytes-total'] : null;
if ($limitBytes === null && isset($user['limit-bytes-in'])) {
    $limitBytes = (float) $user['limit-bytes-in'];
}
$usedBytes  = ($bytesIn ?? 0) + ($bytesOut ?? 0);
$disabled   = isset($user['disabled']) && $user['disabled'] === 'true';
$mac        = $user['mac-address'] ?? '';
$everUsed   = ($usedSec !== null && $usedSec > 0)
              || ($mac !== '' && $mac !== '00:00:00:00:00:00')
              || $umFirst !== null
              || $active !== null;

/* --- activation date --- */
$activatedText = '';
$activatedNote = '';

if ($umFirst) {
    $activatedText = fmtDate($umFirst);
    $activatedNote = 'First login, from the router\'s User Manager history.';
} elseif (count($commentDates) >= 2) {
    $activatedText = fmtDate($commentDates[0]['dt'], $commentDates[0]['hasTime']);
    $activatedNote = 'Written on the voucher when it was first used.';
} elseif (count($commentDates) === 1 && $now && $commentDates[0]['dt'] <= $now) {
    $activatedText = fmtDate($commentDates[0]['dt'], $commentDates[0]['hasTime']);
    $activatedNote = 'Written on the voucher when it was first used.';
} elseif ($active && $now) {
    $sessionSec = rosSeconds($active['uptime'] ?? null);
    if ($sessionSec !== null) {
        $activatedText = fmtDate($now->sub(new DateInterval('PT' . $sessionSec . 'S')));
        $activatedNote = 'Start of the session running right now (not necessarily the first one).';
    } else {
        $activatedText = 'In use right now';
    }
} elseif ($everUsed) {
    $activatedText = 'Already used';
    $activatedNote = 'The router keeps the used time for this voucher but not the date of the first login.';
} else {
    $activatedText = 'Not used yet';
    $activatedNote = 'Nobody has logged in with this voucher. The clock starts on the first login.';
}

/* --- expiry date --- */
$expiresText = '';
$expiresNote = '';
$expired     = false;

$umEnd = null;
foreach (['end-time', 'till-time', 'end-date'] as $k) {
    if ($umProfile && !empty($umProfile[$k])) {
        $umEnd = rosDateTime(...array_pad(explode(' ', trim($umProfile[$k])), 2, ''));
        if ($umEnd) break;
    }
    if ($umUser && !empty($umUser[$k])) {
        $umEnd = rosDateTime(...array_pad(explode(' ', trim($umUser[$k])), 2, ''));
        if ($umEnd) break;
    }
}

$futureComment = null;
foreach ($commentDates as $cd) {
    if (!$now || $cd['dt'] > $now) {
        $futureComment = $cd;
    }
}
if ($futureComment === null && count($commentDates) >= 2) {
    $futureComment = $commentDates[count($commentDates) - 1];
}

if ($umEnd) {
    $expiresText = fmtDate($umEnd);
    $expiresNote = 'From the router\'s User Manager.';
    $expired = ($now && $umEnd < $now);
} elseif ($futureComment) {
    $expiresText = fmtDate($futureComment['dt'], $futureComment['hasTime']);
    $expiresNote = 'The expiry date stored on the voucher itself.';
    $expired = ($now && $futureComment['dt'] < $now);
} elseif ($limitSec !== null) {
    $left = $limitSec - (int) ($usedSec ?? 0);
    if ($left <= 0) {
        $expiresText = 'Expired';
        $expiresNote = 'The whole time limit of ' . fmtDuration($limitSec) . ' has been used up.';
        $expired = true;
    } elseif (!$everUsed) {
        $expiresText = 'Starts on first login';
        $expiresNote = 'Good for ' . fmtDuration($limitSec) . ' of use once someone logs in.';
    } else {
        $expiresText = fmtDuration($left) . ' of use left';
        $expiresNote = 'This voucher is limited by time online (' . fmtDuration($limitSec)
                     . ' in total), not by a calendar date - the clock only runs while somebody is connected.';
    }
} elseif ($limitBytes !== null && $limitBytes > 0) {
    $leftBytes = $limitBytes - $usedBytes;
    if ($leftBytes <= 0) {
        $expiresText = 'Data finished';
        $expiresNote = 'All ' . fmtBytes($limitBytes) . ' have been used.';
        $expired = true;
    } else {
        $expiresText = fmtBytes($leftBytes) . ' of data left';
        $expiresNote = 'This voucher is limited by data (' . fmtBytes($limitBytes) . ' in total), not by a date.';
    }
} else {
    $expiresText = 'No expiry set';
    $expiresNote = 'This voucher has no time limit, no data limit and no expiry date on the router.';
}

/* --- overall status --- */
if ($disabled) {
    $status = 'disabled';
    $statusLabel = 'Disabled';
} elseif ($expired) {
    $status = 'expired';
    $statusLabel = 'Expired';
} elseif ($active) {
    $status = 'online';
    $statusLabel = 'Online now';
} elseif ($everUsed) {
    $status = 'used';
    $statusLabel = 'In use (offline)';
} else {
    $status = 'unused';
    $statusLabel = 'Not used yet';
}

/* ------------------------------------------------------------------ */
/* Details                                                             */
/* ------------------------------------------------------------------ */

$details = [];
$add = function (string $label, $value) use (&$details) {
    if ($value !== null && $value !== '') {
        $details[] = ['label' => $label, 'value' => (string) $value];
    }
};

$add('Router', $router['name']);
if ($user && !empty($user['profile'])) {
    $add('Package', $user['profile']);
} elseif ($umProfile && !empty($umProfile['profile'])) {
    $add('Package', $umProfile['profile']);
}
if ($limitSec !== null) {
    $add('Time limit', fmtDuration($limitSec));
}
if ($usedSec !== null) {
    $add('Time used', $usedSec > 0 ? fmtDuration($usedSec) : 'none');
}
if ($limitSec !== null && $usedSec !== null && $limitSec - $usedSec > 0) {
    $add('Time left', fmtDuration($limitSec - $usedSec));
}
if ($limitBytes !== null && $limitBytes > 0) {
    $add('Data limit', fmtBytes($limitBytes));
}
if ($bytesIn !== null || $bytesOut !== null) {
    $add('Data used', fmtBytes($usedBytes));
}
if ($hsProfile && !empty($hsProfile['session-timeout'])) {
    $sessTo = rosSeconds($hsProfile['session-timeout']);
    if ($sessTo) {
        $add('Session timeout', fmtDuration($sessTo));
    }
}
if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
    $add('Locked to device', $mac);
}
if ($active) {
    $sessionSec = rosSeconds($active['uptime'] ?? null);
    $add('Online for', $sessionSec !== null ? fmtDuration($sessionSec) : ($active['uptime'] ?? ''));
    $add('IP address', $active['address'] ?? '');
    $left = rosSeconds($active['session-time-left'] ?? null);
    if ($left !== null) {
        $add('This session ends in', fmtDuration($left));
    }
}
if ($umUser && !$user) {
    $add('Managed by', 'User Manager');
}
$add('Router time now', $nowLabel);

echo json_encode([
    'success'     => true,
    'type'        => $status,
    'voucher'     => $voucher,
    'router'      => $router['name'],
    'statusLabel' => $statusLabel,
    'activated'   => ['text' => $activatedText, 'note' => $activatedNote],
    'expires'     => ['text' => $expiresText,   'note' => $expiresNote],
    'details'     => $details,
    'comment'     => $comment,
]);
