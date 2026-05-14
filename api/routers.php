<?php
/**
 * API: Get public router list (no passwords)
 * Returns JSON array of routers with id, name only
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';

$routers = getAllRouters();

$public = array_map(function ($r) {
    return [
        'id'   => $r['id'],
        'name' => $r['name'],
    ];
}, $routers);

echo json_encode($public);
