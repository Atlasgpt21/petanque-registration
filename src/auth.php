<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_club(): bool
{
    return ($_SESSION['user']['role'] ?? null) === 'club';
}

function is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? null) === 'admin';
}

function require_club(): void
{
    if (!is_club()) {
        redirect(url('index.php'));
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect(url('index.php'));
    }
}

/**
 * Σύνδεση συλλόγου. Δοκιμάζει πρώτα τον νέο `app_club_users` (test/manual
 * λογαριασμούς), μετά fallback στον υπάρχοντα `users` πίνακα (read-only
 * integration με την άλλη εφαρμογή).
 */
function login_club(PDO $pdo, array $T, string $username, string $password): bool
{
    // 1) app_club_users (local): plaintext username, bcrypt password_hash
    $u = db_one($pdo, "SELECT * FROM `{$T['club_users']}` WHERE username=? AND active=1", [$username]);
    if ($u && password_verify($password, $u['password_hash'])) {
        $club = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubcode=?", [$u['clubcode']]);
        if (!$club) { return false; }
        _club_session_start($pdo, $T, [
            'id'          => (int)$u['id'],
            'source'      => 'app',
            'username'    => $u['username'],
            'clubcode'    => $u['clubcode'],
            'clubname'    => $club['name'] ?? '',
            'must_change' => (int)$u['must_change'] === 1,
        ]);
        $pdo->prepare("UPDATE `{$T['club_users']}` SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
        return true;
    }

    // 2) users_v (legacy): encrypted username/type/lvl/status + AES-CTR|bcrypt password
    if (!empty($T['users']) && _login_club_via_users($pdo, $T, $username, $password)) {
        return true;
    }

    return false;
}

/**
 * Σύνδεση διαχειριστή. Δοκιμάζει πρώτα τον νέο `app_admins` (test/manual
 * λογαριασμό), μετά fallback στον υπάρχοντα `users` πίνακα με type='Admin'.
 */
function login_admin(PDO $pdo, array $T, string $username, string $password): bool
{
    // 1) app_admins (local)
    $u = db_one($pdo, "SELECT * FROM `{$T['admins']}` WHERE username=? AND active=1", [$username]);
    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'role'     => 'admin',
            'id'       => (int)$u['id'],
            'source'   => 'app',
            'username' => $u['username'],
            'fullname' => $u['fullname'] ?? 'Admin',
        ];
        $pdo->prepare("UPDATE `{$T['admins']}` SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
        return true;
    }

    // 2) users_v (legacy): type='Admin' + status='Y'
    if (!empty($T['users']) && _login_admin_via_users($pdo, $T, $username, $password)) {
        return true;
    }

    return false;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Κοινό session initialization για club login (από app_club_users ή users_v).
 *
 * @param array{
 *   id:int, source:string, username:string, clubcode:string,
 *   clubname:string, must_change:bool
 * } $info
 */
function _club_session_start(PDO $pdo, array $T, array $info): void
{
    session_regenerate_id(true);
    $clubname = $info['clubname'] ?? '';
    if (function_exists('hpf_decrypt')) {
        $clubname = (string)hpf_decrypt($clubname);
    }
    $_SESSION['user'] = [
        'role'        => 'club',
        'id'          => $info['id'],
        'source'      => $info['source'],
        'username'    => $info['username'],
        'clubcode'    => $info['clubcode'],
        'clubname'    => $clubname,
        'must_change' => (bool)$info['must_change'],
    ];
}

/**
 * Fallback login μέσω του legacy `users` πίνακα (users_v VIEW).
 * Encrypted στήλες αποκρυπτογραφούνται PHP-side. Το `password` μπορεί να
 * είναι είτε bcrypt (αν ο χρήστης έχει ξανασυνδεθεί στην άλλη εφαρμογή)
 * είτε AES-256-CTR (αν δεν έχει). Και τα δύο υποστηρίζονται.
 *
 * Καμία εγγραφή δεν γίνεται στον `users` — είναι αυστηρά read-only.
 */
function _login_club_via_users(PDO $pdo, array $T, string $username, string $password): bool
{
    $row = _find_legacy_user($pdo, $T, $username, $password, function (string $type, string $lvl): bool {
        // club administrator: type='user' + lvl='lvl1'
        return strcasecmp($type, 'user') === 0 && strcasecmp($lvl, 'lvl1') === 0;
    });
    if ($row === null) {
        return false;
    }
    if (empty($row['clubcode'])) {
        return false;
    }
    $club = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubcode=?", [$row['clubcode']]);
    if (!$club) {
        return false;
    }
    _club_session_start($pdo, $T, [
        'id'          => (int)($row['userid'] ?? 0),
        'source'      => 'users',
        'username'    => (string)($row['_username_dec'] ?? $username),
        'clubcode'    => (string)$row['clubcode'],
        'clubname'    => $club['name'] ?? '',
        'must_change' => false,
    ]);
    return true;
}

/**
 * Admin login μέσω `users_v`: type='Admin' + status='Y'.
 */
function _login_admin_via_users(PDO $pdo, array $T, string $username, string $password): bool
{
    $row = _find_legacy_user($pdo, $T, $username, $password, function (string $type, string $lvl): bool {
        return strcasecmp($type, 'admin') === 0;
    });
    if ($row === null) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'role'     => 'admin',
        'id'       => (int)($row['userid'] ?? 0),
        'source'   => 'users',
        'username' => (string)($row['_username_dec'] ?? $username),
        'fullname' => (string)hpf_decrypt((string)($row['name'] ?? '')) ?: 'Admin',
    ];
    return true;
}

/**
 * Σκανάρει τον legacy `users` πίνακα, αποκρυπτογραφεί τα encrypted πεδία
 * και επιστρέφει τη γραμμή που ταιριάζει με το input (username + password)
 * και περνάει το $typeFilter. Επιστρέφει null αν δεν βρεθεί match.
 * Η γραμμή που επιστρέφεται έχει ένα extra key `_username_dec` με το
 * αποκρυπτογραφημένο username.
 */
function _find_legacy_user(
    PDO $pdo,
    array $T,
    string $username,
    string $password,
    callable $typeFilter
): ?array {
    if (!function_exists('hpf_decrypt')) {
        return null;
    }
    $candidates = db_all($pdo, "SELECT * FROM `{$T['users']}`");
    $inputNorm  = _norm_username($username);

    foreach ($candidates as $row) {
        $type   = (string)hpf_decrypt((string)($row['type']   ?? ''));
        $lvl    = (string)hpf_decrypt((string)($row['lvl']    ?? ''));
        $status = (string)hpf_decrypt((string)($row['status'] ?? ''));

        if (strcasecmp($status, 'Y') !== 0) {
            continue;
        }
        if (!$typeFilter($type, $lvl)) {
            continue;
        }

        $uname = (string)hpf_decrypt((string)($row['username'] ?? ''));
        if (_norm_username($uname) !== $inputNorm) {
            continue;
        }
        if (!_verify_user_password($password, (string)($row['password'] ?? ''))) {
            continue;
        }

        $row['_username_dec'] = $uname;
        return $row;
    }
    return null;
}

/**
 * Κανονικοποίηση username για σύγκριση: trim + lowercase (UTF-8 safe).
 */
function _norm_username(string $s): string
{
    $s = trim($s);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

/**
 * Ελέγχει password έναντι αποθηκευμένης τιμής από τον `users` πίνακα.
 * Δύο περιπτώσεις:
 *  - Αρχίζει με "$2y$"/"$2a$"/"$2b$"  → bcrypt (password_verify)
 *  - Αλλιώς base64 AES-256-CTR        → decrypt και raw compare
 */
function _verify_user_password(string $input, string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    // bcrypt
    if (preg_match('/^\$2[aby]\$/', $stored)) {
        return password_verify($input, $stored);
    }
    // AES-256-CTR (base64)
    if (!function_exists('hpf_decrypt')) {
        return false;
    }
    $plain = (string)hpf_decrypt($stored);
    if ($plain === '' || $plain === $stored) {
        // hpf_decrypt επιστρέφει το ίδιο string αν decrypt failed ή δεν ήταν encrypted
        return false;
    }
    return hash_equals($plain, $input);
}
