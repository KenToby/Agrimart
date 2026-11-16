<?php
// Database connection settings for XAMPP defaults.
// If you set a MySQL root password in XAMPP, put it in DB_PASS.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'agrimart_admin';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// Generates ids like f-1001, p-1002, SF-1003 (same style as the JS version)
function next_uid(string $prefix): string {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $pdo->exec("UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = 'uid'");
    $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($own) $pdo->commit();
    return $prefix . '-' . $n;
}
