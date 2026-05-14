<?php
/**
 * VoucherReset - Database (SQLite PDO)
 * Auto-creates database and tables on first use.
 */

require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $isNew = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Enable WAL mode for better concurrent access
    $pdo->exec('PRAGMA journal_mode=WAL');

    if ($isNew) {
        initDatabase($pdo);
    }

    return $pdo;
}

function initDatabase(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS routers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            ip TEXT NOT NULL,
            port INTEGER NOT NULL DEFAULT 8728,
            username TEXT NOT NULL,
            password TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

/**
 * Get all routers
 */
function getAllRouters(): array {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM routers ORDER BY name ASC');
    return $stmt->fetchAll();
}

/**
 * Get a single router by ID
 */
function getRouter(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM routers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Add a new router
 */
function addRouter(string $name, string $ip, int $port, string $username, string $password): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO routers (name, ip, port, username, password) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $ip, $port, $username, $password]);
    return (int) $db->lastInsertId();
}

/**
 * Update an existing router
 */
function updateRouter(int $id, string $name, string $ip, int $port, string $username, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('UPDATE routers SET name = ?, ip = ?, port = ?, username = ?, password = ? WHERE id = ?');
    return $stmt->execute([$name, $ip, $port, $username, $password, $id]);
}

/**
 * Delete a router
 */
function deleteRouter(int $id): bool {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM routers WHERE id = ?');
    return $stmt->execute([$id]);
}
