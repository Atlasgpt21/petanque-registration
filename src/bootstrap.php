<?php
/**
 * Κοινή αρχικοποίηση: config, session, DB, helpers.
 * Καλείται από κάθε public entrypoint.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Φόρτωση config
$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    $configPath = APP_ROOT . '/config.php.example';
}
$CONFIG = require $configPath;

date_default_timezone_set($CONFIG['app']['timezone'] ?? 'Europe/Athens');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_name($CONFIG['app']['session_name'] ?? 'PETREG');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/validators.php';

// Global
$GLOBALS['CONFIG'] = $CONFIG;
$GLOBALS['PDO']    = db_connect($CONFIG['db']);
$GLOBALS['T']      = $CONFIG['tables'];
