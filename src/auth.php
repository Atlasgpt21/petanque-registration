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

function login_club(PDO $pdo, array $T, string $username, string $password): bool
{
    $u = db_one($pdo, "SELECT * FROM `{$T['club_users']}` WHERE username=? AND active=1", [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    $club = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubcode=?", [$u['clubcode']]);
    if (!$club) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'role'         => 'club',
        'id'           => (int)$u['id'],
        'username'     => $u['username'],
        'clubcode'     => $u['clubcode'],
        'clubname'     => $club['name'],
        'must_change'  => (int)$u['must_change'] === 1,
    ];
    $pdo->prepare("UPDATE `{$T['club_users']}` SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
    return true;
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
