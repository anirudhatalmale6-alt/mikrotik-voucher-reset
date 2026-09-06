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
 *   3. the expiry date minus the validity of the package (that is exactly how
 *      the generator worked the expiry out on the first login)
 *   4. the login cookie the router keeps for the device
 *   5. the router log
 *   6. the live session, if the voucher is online right now
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

/** Parse a "date time" string coming back from the router in one field. */
function rosStamp(?string $value): ?DateTimeImmutable {
    if ($value === null) return null;
    $parts = preg_split('/\s+/', trim($value));
    if (!$parts || $parts[0] === '') return null;
    return rosDateTime($parts[0], $parts[1] ?? '');
}

function fmtDate(?DateTimeImmutable $dt, bool $withTime = true): string {
    if (!$dt) return '';
    return $dt->format($withTime ? 'd M Y, H:i' : 'd M Y');
}

function dtSub(DateTimeImmutable $dt, int $seconds): DateTimeImmutable {
    return $dt->sub(new DateInterval('PT' . max(0, $seconds) . 'S'));
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

/**
 * How long a voucher is valid for, written into the package name ("30d-5M",
 * "7 Days", "1Month") or into the comment the generator leaves ("vc-30d").
 *
 * Only day/week/hour/month words are accepted on purpose: a bare "m" or "M"
 * in a package name is the speed limit ("30d-5M"), never a month.
 */
function scanValidity(string $text): ?array {
    $t = strtolower($text);
    /* dates first - a date must never be read as a duration */
    $t = preg_replace('#\d{4}-\d{1,2}-\d{1,2}|[a-z]{3}/\d{1,2}/\d{4}|\d{1,2}-[a-z]{3}-\d{4}#', ' ', $t);
    $t = preg_replace('/\d{1,2}:\d{2}(:\d{2})?/', ' ', $t);

    $tests = [
        ['/(\d+)\s*(?:months?|mo)\b/', 2592000, 'month'],
        ['/(\d+)\s*(?:weeks?|w)\b/',    604800,  'week'],
        ['/(\d+)\s*(?:days?|d)\b/',     86400,   'day'],
        ['/(\d+)\s*(?:hours?|hrs?|h)\b/', 3600,  'hour'],
    ];
    foreach ($tests as $t3) {
        list($re, $mul, $unit) = $t3;
        if (preg_match($re, $t, $m)) {
            $n = (int) $m[1];
            if ($n <= 0 || $n > 999) continue;
            return ['sec' => $n * $mul, 'raw' => $n . ' ' . $unit . ($n > 1 ? 's' : '')];
        }
    }
    return null;
}

/**
 * The router log prints its timestamps as "14:56:03" (today), "sep/05 14:56:03"
 * (this year) or with the full date. Resolve them against the router's clock.
 */
function logStamp(string $value, ?DateTimeImmutable $now): ?DateTimeImmutable {
    $value = trim($value);
    if ($value === '') return null;

    if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)$/', $value, $m)) {
        if (!$now) return null;
        $dt = rosDateTime($now->format('Y-m-d'), $m[1]);
        if ($dt && $dt > $now) {
            $dt = $dt->sub(new DateInterval('P1D'));
        }
        return $dt;
    }
    if (preg_match('#^([a-z]{3}/\d{1,2})\s+(\d{1,2}:\d{2}(?::\d{2})?)$#i', $value, $m)) {
        if (!$now) return null;
        $dt = rosDateTime($m[1] . '/' . $now->format('Y'), $m[2]);
        if ($dt && $dt > $now) {
            $dt = rosDateTime($m[1] . '/' . ((int) $now->format('Y') - 1), $m[2]);
        }
        return $dt;
    }
    return rosStamp($value);
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
        $started = rosStamp($s['started'] ?? null);
        if ($started && (!$umFirst || $started < $umFirst)) {
            $umFirst = $started;
        }
        $ended = rosStamp($s['ended'] ?? null);
        if ($ended && (!$umLast || $ended > $umLast)) {
            $umLast = $ended;
        }
    }
} else {
    $umRows = rosRows(rosQuery($API, '/tool/user-manager/user/print', ['?username=' . $voucher]));
    if (!empty($umRows[0])) {
        $umUser = $umRows[0];
        /* the old User Manager keeps its history in the same place */
        $sesRows = rosRows(rosQuery($API, '/tool/user-manager/session/print', ['?user=' . $voucher]));
        foreach ($sesRows as $s) {
            $started = rosStamp($s['from-time'] ?? null);
            if ($started && (!$umFirst || $started < $umFirst)) {
                $umFirst = $started;
            }
            $ended = rosStamp($s['till-time'] ?? null);
            if ($ended && (!$umLast || $ended > $umLast)) {
                $umLast = $ended;
            }
        }
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

/* 4. The user's package (its session timeout, and any validity written in it) */
$hsProfile = null;
if ($user && !empty($user['profile'])) {
    $pRows = rosRows(rosQuery($API, '/ip/hotspot/user/profile/print', ['?name=' . $user['profile']]));
    if (!empty($pRows[0])) {
        $hsProfile = $pRows[0];
    }
}

/* ------------------------------------------------------------------ */
/* Everything the router already told us                               */
/* ------------------------------------------------------------------ */

$comment      = trim((string) ($user['comment'] ?? ($umUser['comment'] ?? '')));
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
$sessionSec = $active ? rosSeconds($active['uptime'] ?? null) : null;
$sessionStart = ($active && $now && $sessionSec !== null) ? dtSub($now, $sessionSec) : null;
$everUsed   = ($usedSec !== null && $usedSec > 0)
              || ($mac !== '' && $mac !== '00:00:00:00:00:00')
              || $umFirst !== null
              || $active !== null;

/* --- the expiry date, worked out first: the activation can be derived from it --- */
$umEnd = null;
foreach (['end-time', 'till-time', 'end-date'] as $k) {
    if ($umProfile && !empty($umProfile[$k])) {
        $umEnd = rosStamp($umProfile[$k]);
        if ($umEnd) break;
    }
    if ($umUser && !empty($umUser[$k])) {
        $umEnd = rosStamp($umUser[$k]);
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

$calendarExpiry = $umEnd ?: ($futureComment ? $futureComment['dt'] : null);

/* --- how long this voucher is valid for --- */
$validity = null;
if ($comment !== '') {
    $validity = scanValidity($comment);
}
if (!$validity && $user && !empty($user['profile'])) {
    $validity = scanValidity($user['profile']);
}
if (!$validity && $umProfile && !empty($umProfile['profile'])) {
    $validity = scanValidity($umProfile['profile']);
}
if (!$validity && $hsProfile) {
    /* voucher generators keep the validity as a quoted "30d" inside the
       on-login script that writes the expiry onto the voucher */
    foreach (['on-login', 'on-logout'] as $k) {
        if (empty($hsProfile[$k])) continue;
        if (preg_match('/"(\d+[dwh])"/i', $hsProfile[$k], $m)) {
            $validity = scanValidity($m[1]);
            if ($validity) break;
        }
    }
}

/* ------------------------------------------------------------------ */
/* The activation date, best source first                              */
/* ------------------------------------------------------------------ */

$activatedLabel = 'Activated on';
$activatedText  = '';
$activatedNote  = '';
$activatedFrom  = null;   // DateTimeImmutable once we have a real answer

if ($umFirst) {
    $activatedFrom = $umFirst;
    $activatedNote = 'First login, from the router\'s User Manager history.';
} elseif (count($commentDates) >= 2) {
    $activatedFrom = $commentDates[0]['dt'];
    $activatedNote = 'Written on the voucher when it was first used.';
} elseif (count($commentDates) === 1 && $now && $commentDates[0]['dt'] <= $now) {
    $activatedFrom = $commentDates[0]['dt'];
    $activatedNote = 'Written on the voucher when it was first used.';
} elseif ($calendarExpiry && $validity && $now && $everUsed) {
    /* The generator stamps the expiry onto the voucher on the first login,
       as "first login + validity" - so the first login is the expiry minus
       that same validity. */
    $cand  = dtSub($calendarExpiry, $validity['sec']);
    $floor = dtSub($now, 800 * 86400);
    if ($cand <= $now && $cand > $floor) {
        $activatedFrom = $cand;
        $activatedNote = 'First login: the expiry date (' . fmtDate($calendarExpiry)
                       . ') less the ' . $validity['raw'] . ' this package is valid for.';
    }
}

/* Still nothing? Ask the router for the traces a login leaves behind. */
$cookieLogin = null;
$logLogin    = null;

if (!$activatedFrom && $everUsed && $now) {
    /* (a) the login cookie: it is created at login and counts down from the
           hotspot server profile's cookie-lifetime */
    $cookies = rosRows(rosQuery($API, '/ip/hotspot/cookie/print', ['?user=' . $voucher]));
    if ($cookies) {
        $cookieLife    = null;
        $srvProfile    = null;
        if ($active && !empty($active['server'])) {
            $srv = rosRows(rosQuery($API, '/ip/hotspot/print', ['?name=' . $active['server']]));
            if (!empty($srv[0]['profile'])) {
                $srvProfile = $srv[0]['profile'];
            }
        }
        $lifetimes = [];
        foreach (rosRows(rosQuery($API, '/ip/hotspot/profile/print')) as $p) {
            $s = rosSeconds($p['cookie-lifetime'] ?? null);
            if ($s === null) continue;
            if ($srvProfile !== null && ($p['name'] ?? '') === $srvProfile) {
                $cookieLife = $s;
                break;
            }
            $lifetimes[$s] = true;
        }
        if ($cookieLife === null && count($lifetimes) === 1) {
            $cookieLife = (int) array_keys($lifetimes)[0];
        }
        if ($cookieLife !== null) {
            foreach ($cookies as $c) {
                $rem = rosSeconds($c['expires-in'] ?? null);
                if ($rem === null || $rem > $cookieLife) continue;
                $age = $cookieLife - $rem;
                if ($age < 120) continue;           // created seconds ago - tells us nothing
                $cand = dtSub($now, $age);
                if (!$cookieLogin || $cand < $cookieLogin) {
                    $cookieLogin = $cand;
                }
            }
        }
    }

    /* (b) the router log, if the login is still in it */
    if (!$cookieLogin) {
        $needle = strtolower($voucher);
        foreach (rosRows(rosQuery($API, '/log/print')) as $row) {
            $msg = strtolower($row['message'] ?? '');
            if ($msg === '' || strpos($msg, $needle) === false) continue;
            if (strpos($msg, 'logged in') === false) continue;
            $stamp = logStamp($row['time'] ?? '', $now);
            if ($stamp && (!$logLogin || $stamp < $logLogin)) {
                $logLogin = $stamp;
            }
        }
    }
}

$API->disconnect();

if (!$activatedFrom && $cookieLogin) {
    $activatedFrom = $cookieLogin;
    $activatedNote = 'First login on this device, from the login cookie the router still holds.';
} elseif (!$activatedFrom && $logLogin) {
    $activatedFrom = $logLogin;
    $activatedNote = 'Earliest login for this voucher still in the router log.';
}

if ($activatedFrom) {
    $activatedText = fmtDate($activatedFrom);
} elseif ($sessionStart) {
    /* Last resort. This is NOT the activation date, so it is not labelled as one. */
    $activatedLabel = 'Online since';
    $activatedText  = fmtDate($sessionStart);
    $activatedNote  = 'This is when the session running right now started. This voucher has no'
                    . ' expiry date and no User Manager record on the router, so the first login'
                    . ' was never saved anywhere.';
} elseif ($active) {
    $activatedLabel = 'Online since';
    $activatedText  = 'In use right now';
} elseif ($everUsed) {
    $activatedText = 'Already used';
    $activatedNote = 'The router keeps the used time for this voucher but not the date of the first login.';
} else {
    $activatedText = 'Not used yet';
    $activatedNote = 'Nobody has logged in with this voucher. The clock starts on the first login.';
}

/* ------------------------------------------------------------------ */
/* The expiry date                                                     */
/* ------------------------------------------------------------------ */

$expiresLabel = 'Expires on';
$expiresText  = '';
$expiresNote  = '';
$expired      = false;

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
        $expiresLabel = 'Time left';
        $expiresText = fmtDuration($left) . ' of use left';
        $expiresNote = 'This voucher is limited by time online (' . fmtDuration($limitSec)
                     . ' in total), not by a calendar date - the clock only runs while somebody is connected.';
    }
} elseif ($limitBytes !== null && $limitBytes > 0) {
    $leftBytes = $limitBytes - $usedBytes;
    if ($leftBytes <= 0) {
        $expiresLabel = 'Data left';
        $expiresText = 'Data finished';
        $expiresNote = 'All ' . fmtBytes($limitBytes) . ' have been used.';
        $expired = true;
    } else {
        $expiresLabel = 'Data left';
        $expiresText = fmtBytes($leftBytes) . ' of data left';
        $expiresNote = 'This voucher is limited by data (' . fmtBytes($limitBytes) . ' in total), not by a date.';
    }
} elseif ($activatedFrom && $validity) {
    $end = $activatedFrom->add(new DateInterval('PT' . $validity['sec'] . 'S'));
    $expiresText = fmtDate($end);
    $expiresNote = 'First login plus the ' . $validity['raw'] . ' this package is valid for.';
    $expired = ($now && $end < $now);
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
if ($validity) {
    $add('Valid for', $validity['raw']);
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
if ($umLast) {
    $add('Last seen', fmtDate($umLast));
} elseif ($umUser && !empty($umUser['last-seen'])) {
    $add('Last seen', $umUser['last-seen']);
}
if ($active) {
    if ($sessionStart && $activatedLabel !== 'Online since') {
        $add('This session started', fmtDate($sessionStart));
    }
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
    'activated'   => ['label' => $activatedLabel, 'text' => $activatedText, 'note' => $activatedNote],
    'expires'     => ['label' => $expiresLabel,   'text' => $expiresText,   'note' => $expiresNote],
    'details'     => $details,
    'comment'     => $comment,
]);
