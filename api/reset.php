<?php
/**
 * API: Voucher Reset
 * POST { router_id, voucher }
 * Returns JSON: { success, message, type }
 * Types: success, expired, not_found, error
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/RouterosAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed', 'type' => 'error']);
    exit;
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
    echo json_encode(['success' => false, 'message' => 'Please provide both router and voucher code.', 'type' => 'error']);
    exit;
}

$router = getRouter($routerId);
if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found in database.', 'type' => 'error']);
    exit;
}

$api = new RouterosAPI();
$api->setTimeout(ROUTEROS_TIMEOUT);
$api->setPort($router['port']);
$api->setAttempts(ROUTEROS_ATTEMPTS);

if (!$api->connect($router['ip'], $router['username'], $router['password'])) {
    echo json_encode(['success' => false, 'message' => 'Connection failed. Router may be offline or API is not enabled.', 'type' => 'error']);
    exit;
}

try {
    // 1. Find the hotspot user
    $api->write('/ip/hotspot/user/print', false);
    $api->write('?name=' . $voucher);
    $userInfo = $api->read();

    if (empty($userInfo)) {
        $api->disconnect();
        echo json_encode(['success' => false, 'message' => 'Voucher not found. Please check the code and try again.', 'type' => 'not_found']);
        exit;
    }

    $user = $userInfo[0];

    // 2. Check if expired (uptime reached limit-uptime)
    if (
        isset($user['limit-uptime']) && isset($user['uptime']) &&
        $user['limit-uptime'] !== '' && $user['uptime'] !== '' &&
        $user['uptime'] === $user['limit-uptime']
    ) {
        $api->disconnect();
        echo json_encode(['success' => false, 'message' => 'This voucher has expired. The usage limit has been reached.', 'type' => 'expired']);
        exit;
    }

    // 3. Remove active sessions by username
    $api->write('/ip/hotspot/active/print', false);
    $api->write('?user=' . $voucher);
    $activeList = $api->read();

    foreach ($activeList as $session) {
        if (isset($session['.id'])) {
            $api->write('/ip/hotspot/active/remove', false);
            $api->write('=.id=' . $session['.id']);
            $api->read();
        }
    }

    // 4. Also remove active sessions by MAC address
    if (isset($user['mac-address']) && $user['mac-address'] !== '' && $user['mac-address'] !== '00:00:00:00:00:00') {
        $api->write('/ip/hotspot/active/print', false);
        $api->write('?mac-address=' . $user['mac-address']);
        $macList = $api->read();

        foreach ($macList as $session) {
            if (isset($session['.id'])) {
                $api->write('/ip/hotspot/active/remove', false);
                $api->write('=.id=' . $session['.id']);
                $api->read();
            }
        }
    }

    // 5. Reset MAC address to 00:00:00:00:00:00
    if (isset($user['.id'])) {
        $api->write('/ip/hotspot/user/set', false);
        $api->write('=.id=' . $user['.id'], false);
        $api->write('=mac-address=00:00:00:00:00:00');
        $api->read();
    }

    $api->disconnect();

    echo json_encode([
        'success' => true,
        'message' => 'Reset successful! Voucher "' . htmlspecialchars($voucher) . '" has been reset. You can now reconnect.',
        'type'    => 'success',
    ]);

} catch (Exception $e) {
    $api->disconnect();
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage(), 'type' => 'error']);
}
