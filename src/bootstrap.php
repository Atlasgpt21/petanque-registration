<?php
/**
 * Κοινή αρχικοποίηση: config, session, DB, helpers.
 * Καλείται από κάθε public entrypoint.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// PUBLIC_ROOT = ο φάκελος με τα public αρχεία (assets/, admin/, club/, index.php).
// Στο κανονικό repo είναι `APP_ROOT/public`. Σε flat deployment (π.χ. Hostinger
// subdomain όπου τα public αρχεία κάθονται απευθείας στο doc root) είναι ίδιος
// με το APP_ROOT ή έναν αδελφό φάκελο. Ψάχνουμε το πιθανό κάθε φορά.
if (!defined('PUBLIC_ROOT')) {
    $_candidates = [
        APP_ROOT . '/public',                  // κανονικό repo layout
        APP_ROOT,                              // flat: src/ και assets/ στον ίδιο root
        dirname(APP_ROOT) . '/registration',   // Hostinger: src/ σε public_html/, public σε public_html/registration/
    ];
    $_publicRoot = APP_ROOT . '/public';
    foreach ($_candidates as $_c) {
        if (is_file($_c . '/assets/layout.php')) { $_publicRoot = $_c; break; }
    }
    define('PUBLIC_ROOT', $_publicRoot);
    unset($_candidates, $_publicRoot, $_c);
}

// Φόρτωση config — ψάχνουμε σε APP_ROOT, μετά στον γονικό φάκελο (για
// Hostinger layout όπου το config.php βρίσκεται σε public_html/ ενώ το src/
// είναι σε public_html/src/).
$configPath = null;
foreach ([APP_ROOT . '/config.php', dirname(APP_ROOT) . '/config.php', APP_ROOT . '/config.php.example'] as $_p) {
    if (is_file($_p)) { $configPath = $_p; break; }
}
if ($configPath === null) {
    throw new RuntimeException('config.php not found near APP_ROOT=' . APP_ROOT);
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
