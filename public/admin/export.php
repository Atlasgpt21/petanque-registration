<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$gamecode = $_GET['g'] ?? null;
if (!$gamecode) {
    $g = db_one($pdo, "SELECT gamecode FROM `{$T['games']}` WHERE status='Y' ORDER BY gameid DESC LIMIT 1");
    $gamecode = $g['gamecode'] ?? null;
}

// Αν δεν έχει gamecode, εμφανίζουμε λίστα επιλογής (μέσα σε layout)
if (!$gamecode || isset($_GET['pick'])) {
    require PUBLIC_ROOT . '/assets/layout.php';
    $games = db_all($pdo, "SELECT gameid, gamecode, name, status FROM `{$T['games']}` ORDER BY gameid DESC");
    // games.name δεν είναι encrypted, οπότε δεν χρειάζεται decrypt.
    render_header('Εξαγωγή CSV', 'admin', 'export');
    echo '<div class="main__header"><div><h2 class="main__title">Εξαγωγή CSV</h2><p class="main__sub">Επιλέξτε πρωτάθλημα για εξαγωγή δηλώσεων</p></div></div>';
    echo '<div class="card"><table class="table"><thead><tr><th>Κωδ.</th><th>Όνομα</th><th>Κατάσταση</th><th class="actions"></th></tr></thead><tbody>';
    foreach ($games as $gg) {
        echo '<tr><td><code>' . h($gg['gamecode']) . '</code></td>';
        echo '<td>' . h($gg['name']) . '</td>';
        echo '<td>' . ($gg['status']==='Y' ? '<span class="badge badge--on">Ενεργό</span>' : '<span class="badge">—</span>') . '</td>';
        echo '<td class="actions">';
        echo '<a class="btn btn--sm" href="' . h(url('admin/export.php?g=' . urlencode($gg['gamecode']) . '&type=teams')) . '">CSV Ομάδες</a> ';
        echo '<a class="btn btn--sm btn--ghost" href="' . h(url('admin/export.php?g=' . urlencode($gg['gamecode']) . '&type=players')) . '">CSV Παίκτες</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    render_footer();
    exit;
}

$type = $_GET['type'] ?? 'teams';
$game = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$gamecode]);
if (!$game) { http_response_code(404); exit('Not found'); }

$filename = 'petreg_' . $type . '_' . preg_replace('/\W+/', '', $game['gamecode']) . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM για Excel
$out = fopen('php://output', 'w');

if ($type === 'teams') {
    fputcsv($out, ['Πρωτάθλημα','Σύλλογος','Κωδ.Συλλόγου','Ομάδα','Κατηγορία','Πλήθος','Παίκτες (κωδικοί)','Παίκτες (ονόματα)']);
    $rows = db_all($pdo, "
        SELECT t.*, c.name AS club_name
        FROM `{$T['teams']}` t
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode=t.clubcode
        WHERE t.gamecode=? AND t.status='Y'
        ORDER BY t.clubcode, t.category, t.teamname
    ", [$gamecode]);
    if (function_exists('hpf_decrypt_rows')) { $rows = hpf_decrypt_rows($rows, ['club_name']); }
    foreach ($rows as $t) {
        $codes = explode('-', $t['playercodes']);
        $in = implode(',', array_fill(0, count($codes), '?'));
        $plist = db_all($pdo, "SELECT playercode, firstname, lastname FROM `{$T['players']}` WHERE playercode IN ($in)", $codes);
        if (function_exists('hpf_decrypt_rows')) { $plist = hpf_decrypt_rows($plist, hpf_encrypted_cols('players')); }
        usort($plist, function($a,$b) use ($codes) { return array_search($a['playercode'], $codes) <=> array_search($b['playercode'], $codes); });
        $names = implode(' | ', array_map(function($p) { return $p['lastname'] . ' ' . $p['firstname']; }, $plist));
        fputcsv($out, [
            $game['name'],
            $t['club_name'] ?? $t['clubcode'],
            $t['clubcode'],
            $t['teamname'],
            $t['category']==='M'?'Άνδρες':($t['category']==='F'?'Γυναίκες':'Μεικτό'),
            count($codes),
            implode('-', $codes),
            $names,
        ]);
    }
} else {
    fputcsv($out, ['Πρωτάθλημα','Σύλλογος','Κωδ.Συλλόγου','Αθλητής','Επώνυμο','Όνομα','Φύλο','Κωδ.Παίκτη','Team Code']);
    $rows = db_all($pdo, "
        SELECT g2.*, p.firstname, p.lastname, p.gender, c.name AS club_name
        FROM `{$T['games2']}` g2
        JOIN `{$T['players']}` p ON p.playercode=g2.playercode1
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode=g2.clubcode
        WHERE g2.gamecode=? AND g2.checkstatus='Y'
        ORDER BY g2.clubcode, g2.teamcode
    ", [$gamecode]);
    if (function_exists('hpf_decrypt_rows')) { $rows = hpf_decrypt_rows($rows, array_merge(hpf_encrypted_cols('players'), ['club_name'])); }
    foreach ($rows as $r) {
        fputcsv($out, [
            $game['name'],
            $r['club_name'] ?? $r['clubcode'],
            $r['clubcode'],
            $r['lastname'] . ' ' . $r['firstname'],
            $r['lastname'],
            $r['firstname'],
            $r['gender']==='M'?'Α':'Γ',
            $r['playercode1'],
            $r['teamcode'],
        ]);
    }
}
fclose($out);
