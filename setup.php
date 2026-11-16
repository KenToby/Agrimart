<?php
// Run ONCE in the browser after importing schema.sql:
//   http://localhost/agrimart/setup.php
// It creates the admin account (admin / admin123) with a hashed password,
// and seeds nothing else. Delete or rename this file afterwards.
require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
try {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $st = db()->prepare(
        'INSERT INTO admins (username, password_hash) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
    );
    $st->execute(['admin', $hash]);
    echo "OK - admin account ready (username: admin, password: admin123).\n";
    echo "Change the password, then delete setup.php.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Setup failed: " . $e->getMessage() . "\n";
    echo "Did you import sql/schema.sql in phpMyAdmin first?\n";
}
