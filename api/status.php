<?php
/**
 * API: Router Status Check
 * GET ?id=<router_id>
 * Returns JSON: {"status": "online"} or {"status": "offline"}
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'offline', 'error' => 'Invalid router ID']);
    exit;
}

$router = getRouter($id);
if (!$router) {
    echo json_encode(['status' => 'offline', 'error' => 'Router not found']);
    exit;
}

// Check if the API port is reachable (no authentication needed)
$socket = @fsockopen($router['ip'], $router['port'], $errno, $errstr, ROUTEROS_TIMEOUT);

if ($socket) {
    fclose($socket);
    echo json_encode(['status' => 'online']);
} else {
    echo json_encode(['status' => 'offline']);
}
