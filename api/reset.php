<?php
/**
 * API: Voucher Reset
 * POST { router_id, voucher }
 * Returns JSON: { success, message, type }
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

$API = new RouterosAPI();
$API->timeout = ROUTEROS_TIMEOUT;
$API->port = $router['port'];
$API->attempts = ROUTEROS_ATTEMPTS;

if (!$API->connect($router['ip'], $router['username'], $router['password'])) {
    echo json_encode(['success' => false, 'message' => 'Connection failed. Router may be offline or API is not enabled.', 'type' => 'error']);
    exit;
}

// 1. Find hotspot user
$API->write('/ip/hotspot/user/print', false);
$API->write('?name=' . $voucher);
$userInfo = $API->read();

if (empty($userInfo)) {
    $API->disconnect();
    echo json_encode(['success' => false, 'message' => 'Voucher not found. Please check the code and try again.', 'type' => 'not_found']);
    exit;
}

$user = $userInfo[0];

// 2. Check if expired
if (isset($user['limit-uptime']) && isset($user['uptime']) && $user['limit-uptime'] !== '' && $user['uptime'] !== '' && $user['uptime'] == $user['limit-uptime']) {
    $API->disconnect();
    echo json_encode(['success' => false, 'message' => 'This voucher has expired. The usage limit has been reached.', 'type' => 'expired']);
    exit;
}

// 3. Remove active sessions by username
$API->write('/ip/hotspot/active/print', false);
$API->write('?user=' . $voucher);
$activeList = $API->read();

foreach ($activeList as $aUser) {
    if (isset($aUser['.id'])) {
        $API->write('/ip/hotspot/active/remove', false);
        $API->write('=.id=' . $aUser['.id']);
        $API->read();
    }
}

// 4. Also remove active sessions by MAC address
if (isset($user['mac-address']) && $user['mac-address'] !== '' && $user['mac-address'] !== '00:00:00:00:00:00') {
    $API->write('/ip/hotspot/active/print', false);
    $API->write('?mac-address=' . $user['mac-address']);
    $macList = $API->read();

    foreach ($macList as $aUser) {
        if (isset($aUser['.id'])) {
            $API->write('/ip/hotspot/active/remove', false);
            $API->write('=.id=' . $aUser['.id']);
            $API->read();
        }
    }
}

// 5. Reset MAC address
$API->write('/ip/hotspot/user/set', false);
$API->write('=.id=' . $user['.id'], false);
$API->write('=mac-address=00:00:00:00:00:00');
$API->read();

$API->disconnect();

echo json_encode([
    'success' => true,
    'message' => 'Reset successful! Voucher "' . htmlspecialchars($voucher) . '" has been reset. You can now reconnect.',
    'type'    => 'success',
]);
