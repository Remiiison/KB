<?php
/*
 * ── KAPITBISIG API ·  config.php ──
 * Loads .env from the same directory and defines constants.
 * Copy .env.example → .env on Hostinger and fill in real values.
 */

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (!array_key_exists($k, $_ENV)) {
                $_ENV[$k]  = $v;
                putenv("$k=$v");
            }
        }
    }
}

define('DB_HOST',          $_ENV['DB_HOST']        ?? '127.0.0.1');
define('DB_PORT',          $_ENV['DB_PORT']        ?? '3306');
define('DB_USER',          $_ENV['DB_USER']        ?? 'root');
define('DB_PASS',          $_ENV['DB_PASSWORD']    ?? '');
define('DB_NAME',          $_ENV['DB_NAME']        ?? 'kapitbisig_db');
define('FRONTEND_ORIGINS', $_ENV['FRONTEND_ORIGINS'] ?? 'https://kapitbisig.online');
define('FRONTEND_URL',     $_ENV['FRONTEND_URL']   ?? 'https://kapitbisig.online');
define('SMTP_HOST',        $_ENV['SMTP_HOST']      ?? '');
define('SMTP_PORT',        (int)($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_USER',        $_ENV['SMTP_USER']      ?? '');
define('SMTP_PASS',        $_ENV['SMTP_PASS']      ?? '');
define('SMTP_FROM',        $_ENV['SMTP_FROM']      ?? 'noreply@kapitbisig.online');
define('ADMIN_EMAIL',      $_ENV['ADMIN_EMAIL']    ?? '');
