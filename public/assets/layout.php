<?php
// Shared layout helpers (header + sidebar + footer) για club & admin.
// Χρήση:
//   require PUBLIC_ROOT . '/assets/layout.php';
//   render_header('Τίτλος', 'club', 'dashboard');
//     ...html...
//   render_footer();

function render_header(string $title, string $role, string $active = ''): void
{
    $u = current_user();
    $brandTitle = $role === 'admin' ? 'Διαχείριση' : ($u['clubname'] ?? 'Σύλλογος');
    $brandSub   = $role === 'admin' ? 'Admin panel' : ('Κωδ. ' . ($u['clubcode'] ?? '—'));

    $clubLinks = [
        ['dashboard',  'Αρχική',               'club/dashboard.php', '🏠'],
        ['athletes',   'Αθλητές',              'club/athletes.php',  '🏃'],
        ['teams',      'Δηλώσεις Ομάδων',      'club/teams.php',     '📋'],
        ['password',   'Αλλαγή κωδικού',       'club/password.php',  '🔑'],
    ];
    $adminLinks = [
        ['dashboard',     'Αρχική',             'admin/dashboard.php',     '🏠'],
        ['championships', 'Πρωταθλήματα',        'admin/championships.php', '🏆'],
        ['tournaments',   'Διοργανώσεις',        'admin/tournaments.php',   '🎯'],
        ['clubs',         'Σύλλογοι',           'admin/clubs.php',         '🏛️'],
        ['club_users',    'Λογαριασμοί',        'admin/club_users.php',    '👤'],
        ['players',       'Αθλητές',            'admin/players.php',       '🏃'],
        ['all_teams',     'Όλες οι δηλώσεις',   'admin/all_teams.php',     '📋'],
        ['export',        'Εξαγωγή',            'admin/export.php',        '📤'],
    ];
    $links = $role === 'admin' ? $adminLinks : $clubLinks;
    $userName = (string)($u['username'] ?? '');
    $avatarCh = function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($userName, 0, 1));

    $cssHref = url('assets/style.css');
    $bsHref  = url('assets/vendor/bootstrap/bootstrap.min.css');
    $jsHref  = url('assets/app.js');
    $logoutHref = url('logout.php');

    echo '<!doctype html>';
    echo '<html lang="el"><head><meta charset="utf-8"><title>' . h($title) . '</title>';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">';
    echo '<link rel="stylesheet" href="' . h($bsHref) . '">';
    echo '<link rel="stylesheet" href="' . h($cssHref) . '"></head><body>';
    echo '<div class="app">';
    echo '<aside class="sidebar offcanvas-md offcanvas-start" tabindex="-1" id="appSidebar">';
    echo '<div class="sidebar__brand">';
    echo '<div class="sidebar__logo">🟠</div>';
    echo '<div><h1 class="sidebar__title">' . h($brandTitle) . '</h1>';
    echo '<p class="sidebar__subtitle">' . h($brandSub) . '</p></div>';
    echo '</div>';
    echo '<nav class="sidebar__nav">';
    foreach ($links as [$slug, $label, $path, $ico]) {
        $cls = 'sidebar__link' . ($slug === $active ? ' sidebar__link--active' : '');
        echo '<a class="' . $cls . '" href="' . h(url($path)) . '"><span class="ico">' . $ico . '</span>' . h($label) . '</a>';
    }
    echo '</nav>';
    echo '<div class="sidebar__footer">';
    echo 'Συνδεδεμένος ως<br><span class="sidebar__user">' . h($userName) . '</span><br>';
    echo '<a class="sidebar__logout" href="' . h($logoutHref) . '">Αποσύνδεση →</a>';
    echo '</div>';
    echo '</aside>';
    echo '<div class="main-wrap" style="min-width:0;display:flex;flex-direction:column;">';
    echo '<header class="topbar">';
    echo '<button class="topbar-toggle btn btn--ghost btn--sm d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar">☰</button>';
    echo '<h2 class="topbar__title">' . h($title) . '</h2>';
    echo '<div class="topbar__spacer"></div>';
    echo '<div class="topbar__user"><span class="d-none d-sm-inline">' . h($userName) . '</span><span class="topbar__avatar">' . h($avatarCh !== '' ? $avatarCh : '?') . '</span></div>';
    echo '</header>';
    echo '<main class="main">';
    echo render_flash();
}

function render_footer(): void
{
    $jsHref  = url('assets/app.js');
    $bsJs    = url('assets/vendor/bootstrap/bootstrap.bundle.min.js');
    echo '</main></div></div>';
    echo '<div class="cmodal-backdrop" id="cmodalBackdrop">'
       . '<div class="cmodal" role="dialog" aria-modal="true">'
       . '<h3 class="cmodal__title">Επιβεβαίωση</h3>'
       . '<p class="cmodal__text" id="cmodalText"></p>'
       . '<div class="cmodal__actions">'
       . '<button type="button" class="btn btn--ghost btn--sm" id="cmodalCancel">Άκυρο</button>'
       . '<button type="button" class="btn btn--danger btn--sm" id="cmodalOk">Ναι, συνέχεια</button>'
       . '</div></div></div>';
    echo '<script src="' . h($bsJs) . '"></script>';
    echo '<script src="' . h($jsHref) . '"></script>';
    echo '</body></html>';
}
