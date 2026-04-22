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

function login_admin(PDO $pdo, array $T, string $username, string $password): bool
{
    $u = db_one($pdo, "SELECT * FROM `{$T['admins']}` WHERE username=? AND active=1", [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'role'     => 'admin',
        'id'       => (int)$u['id'],
        'username' => $u['username'],
        'fullname' => $u['fullname'] ?? 'Admin',
    ];
    $pdo->prepare("UPDATE `{$T['admins']}` SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
    return true;
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
    if (!function_exists('hpf_decrypt')) {
        return false;
    }

    // Το `clubcode` είναι plaintext. Για να μη γυρνάμε ολόκληρο τον πίνακα
    // σε κάθε login, αν το input μοιάζει με clubcode κάνουμε πρώτο φιλτράρισμα.
    // Αλλιώς σκανάρουμε όλους τους ενεργούς club users και κάνουμε match στο
    // decrypted username.
    $candidates = db_all(
        $pdo,
        "SELECT * FROM `{$T['users']}` WHERE clubcode IS NOT NULL AND clubcode <> ''"
    );

    $inputNorm = _norm_username($username);

    foreach ($candidates as $row) {
        $type   = (string)hpf_decrypt((string)($row['type']   ?? ''));
        $lvl    = (string)hpf_decrypt((string)($row['lvl']    ?? ''));
        $status = (string)hpf_decrypt((string)($row['status'] ?? ''));

        // Μόνο club administrators (type='user' + lvl='lvl1') με status='Y'
        if ($type !== 'user' || $lvl !== 'lvl1' || $status !== 'Y') {
            continue;
        }

        $uname = (string)hpf_decrypt((string)($row['username'] ?? ''));
        if (_norm_username($uname) !== $inputNorm) {
            continue;
        }

        if (!_verify_user_password($password, (string)($row['password'] ?? ''))) {
            continue;
        }

        // Match found
        $club = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubcode=?", [$row['clubcode']]);
        if (!$club) {
            return false;
        }

        _club_session_start($pdo, $T, [
            'id'          => (int)($row['userid'] ?? 0),
            'source'      => 'users',
            'username'    => $uname,
            'clubcode'    => (string)$row['clubcode'],
            'clubname'    => $club['name'] ?? '',
            'must_change' => false,
        ]);
        return true;
    }

    return false;
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
