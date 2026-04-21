<?php
declare(strict_types=1);

/** HTML escape */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect helper */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Flash messages */
function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_pop(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function render_flash(): string
{
    $out = '';
    foreach (flash_pop() as $f) {
        $cls = match ($f['type']) {
            'success' => 'flash flash--success',
            'error'   => 'flash flash--error',
            'warning' => 'flash flash--warning',
            default   => 'flash flash--info',
        };
        $out .= '<div class="' . $cls . '">' . h($f['msg']) . '</div>';
    }
    return $out;
}

/** CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)$t)) {
        http_response_code(419);
        exit('CSRF token invalid');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** JSON response helper */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** URL helper που σέβεται subfolder mounting (π.χ. /petanque-registration/public/) */
function url(string $path): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = dirname($script);
    // Στο club/admin subfolders, γυρνάμε πάνω στο public/
    if (str_contains($script, '/club/') || str_contains($script, '/admin/')) {
        $base = dirname($base);
    }
    $base = rtrim($base, '/\\');
    if ($base === '' || $base === '\\') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

/** Gender label helper */
function gender_label(string $g): string
{
    return $g === 'M' ? 'Άνδρας' : ($g === 'F' ? 'Γυναίκα' : $g);
}

/** Gametype label */
function gametype_label(string $gt): string
{
    return match ($gt) {
        'Doubles'  => 'Ντουμπλέτες',
        'Triplets' => 'Τριπλέτες',
        'Mixed'    => 'Ντουμπλέτες Μεικτό',
        'Intercup' => 'Διασυλλογικό',
        default    => $gt,
    };
}
