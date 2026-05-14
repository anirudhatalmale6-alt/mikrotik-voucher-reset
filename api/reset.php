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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
        'type'    => 'error',
    ]);
    exit;
}

// Parse input (accept both form data and JSON)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$routerId = isset($input['router_id']) ? (int) $input['router_id'] : 0;
$voucher  = isset($input['voucher']) ? trim($input['voucher']) : '';

// Validate
if ($routerId <= 0 || $voucher === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide both router and voucher code.',
        'type'    => 'error',
    ]);
    exit;
}

// Look up router
$router = getRouter($routerId);
if (!$router) {
    echo json_encode([
        'success' => false,
        'message' => 'Router not found in database.',
        'type'    => 'error',
    ]);
    exit;
}

// Connect via RouterOS API
$api = new RouterosAPI();
$api->setTimeout(ROUTEROS_TIMEOUT);
$api->setPort($router['port']);
$api->setAttempts(ROUTEROS_ATTEMPTS);

if (!$api->connect($router['ip'], $router['username'], $router['password'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Connection failed. Router may be offline or API is not enabled.',
        'type'    => 'error',
    ]);
    exit;
}

try {
    // Query hotspot user
    $userInfo = $api->command('/ip/hotspot/user/print', [
        '?name=' . $voucher,
    ]);

    if (empty($userInfo)) {
        $api->disconnect();
        echo json_encode([
            'success' => false,
            'message' => 'Voucher not found. Please check the code and try again.',
            'type'    => 'not_found',
        ]);
        exit;
    }

    $user = $userInfo[0];

    // Check if expired: uptime has reached limit-uptime
    if (
        isset($user['limit-uptime']) && isset($user['uptime']) &&
        $user['limit-uptime'] !== '' && $user['uptime'] !== '' &&
        $user['uptime'] === $user['limit-uptime']
    ) {
        $api->disconnect();
        echo json_encode([
            'success' => false,
            'message' => 'This voucher has expired. The usage limit has been reached.',
            'type'    => 'expired',
        ]);
        exit;
    }

    // Remove all active sessions for this user
    $activeSessions = $api->command('/ip/hotspot/active/print', [
        '?user=' . $voucher,
    ]);

    foreach ($activeSessions as $session) {
        if (isset($session['.id'])) {
            $api->command('/ip/hotspot/active/remove', [
                '=.id=' . $session['.id'],
            ]);
        }
    }

    // Reset MAC address to 00:00:00:00:00:00
    if (isset($user['.id'])) {
        $api->command('/ip/hotspot/user/set', [
            '=.id=' . $user['.id'],
            '=mac-address=00:00:00:00:00:00',
        ]);
    }

    $api->disconnect();

    echo json_encode([
        'success' => true,
        'message' => 'Reset successful! Voucher "' . htmlspecialchars($voucher) . '" has been reset. You can now reconnect.',
        'type'    => 'success',
    ]);

} catch (Exception $e) {
    $api->disconnect();
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage(),
        'type'    => 'error',
    ]);
}
