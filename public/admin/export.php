<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require_admin();

// Φορτώνουμε τον XLSX writer εφόσον υπάρχει (zero deps, οπτικοποιείται μόνο στα xlsx routes)
if (is_file(APP_ROOT . '/src/xlsx.php')) {
    require_once APP_ROOT . '/src/xlsx.php';
}

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$gamecode = $_GET['g'] ?? null;
$type     = $_GET['type'] ?? null;

// Χωρίς ρητό type (δηλ. navigation από το menu) ή με ?pick ή χωρίς gamecode
// → εμφανίζουμε τη λίστα επιλογής πρωταθλήματος + μορφής εξαγωγής.
if (!$gamecode || !$type || isset($_GET['pick'])) {
    require PUBLIC_ROOT . '/assets/layout.php';
    $games = db_all($pdo, "SELECT gameid, gamecode, name, status FROM `{$T['games']}` ORDER BY gameid DESC");
    render_header('Εξαγωγή', 'admin', 'export');
    echo '<div class="main__header"><div><h2 class="main__title">Εξαγωγή Δηλώσεων</h2><p class="main__sub">Επιλέξτε πρωτάθλημα και μορφή εξαγωγής</p></div></div>';
    echo '<div class="card"><table class="table"><thead><tr><th>Κωδ.</th><th>Όνομα</th><th>Κατάσταση</th><th class="actions">Εξαγωγές</th></tr></thead><tbody>';
    foreach ($games as $gg) {
        echo '<tr><td><code>' . h($gg['gamecode']) . '</code></td>';
        echo '<td>' . h($gg['name']) . '</td>';
        echo '<td>' . ($gg['status']==='Y' ? '<span class="badge badge--on">Ενεργό</span>' : '<span class="badge">—</span>') . '</td>';
        echo '<td class="actions">';
        $base = 'admin/export.php?g=' . urlencode($gg['gamecode']);
        echo '<a class="btn btn--sm" href="' . h(url($base . '&type=teams')) . '">CSV Ομάδες</a> ';
        echo '<a class="btn btn--sm btn--ghost" href="' . h(url($base . '&type=players')) . '">CSV Παίκτες</a> ';
        echo '<a class="btn btn--sm" href="' . h(url($base . '&type=men_xlsx')) . '">Συγκ. Άνδρες (.xlsx)</a> ';
        echo '<a class="btn btn--sm" href="' . h(url($base . '&type=women_xlsx')) . '">Συγκ. Γυναίκες (.xlsx)</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    render_footer();
    exit;
}

$game = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$gamecode]);
if (!$game) { http_response_code(404); exit('Not found'); }

// ============================================================
// XLSX συγκεντρωτικά (Άνδρες / Γυναίκες) με Greeklish ονόματα
// ============================================================
if ($type === 'men_xlsx' || $type === 'women_xlsx') {
    if (!class_exists('HpfXlsx')) {
        http_response_code(500);
        exit('Λείπει το src/xlsx.php — ανεβάστε το αρχείο.');
    }
    if (!function_exists('greeklish')) {
        http_response_code(500);
        exit('Λείπει το src/greeklish.php — ανεβάστε το αρχείο.');
    }

    $gender = $type === 'men_xlsx' ? 'M' : 'F';
    $catLabel = $gender === 'M' ? 'Άνδρες' : 'Γυναίκες';

    // Ανίχνευση στήλης role (υπάρχει μετά το ALTER TABLE)
    $hasRoleCol = false;
    try {
        $colChk = db_one($pdo, "SHOW COLUMNS FROM `{$T['games2']}` LIKE 'role'");
        $hasRoleCol = !empty($colChk);
    } catch (Throwable $e) { $hasRoleCol = false; }

    $roleSel = $hasRoleCol ? "g2.`role` AS role" : "'starter' AS role";

    // Ανάκτηση όλων των εγγραφών games2 για το πρωτάθλημα + κατηγορία φύλου
    // Join με players για lastname/firstname/gender και με teams για teamname
    // (μέσω clubcode+gamecode+playercode1 που ανήκει σε playercodes ή substitutes).
    $rows = db_all($pdo, "
        SELECT
            g2.playercode1 AS playercode,
            g2.clubcode    AS clubcode,
            g2.teamcode    AS teamcode,
            $roleSel,
            p.firstname,
            p.lastname,
            p.gender,
            c.name         AS club_name
        FROM `{$T['games2']}` g2
        JOIN `{$T['players']}` p ON p.playercode = g2.playercode1
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode = g2.clubcode
        WHERE g2.gamecode = ?
          AND g2.checkstatus = 'Y'
          AND p.gender = ?
        ORDER BY c.name, g2.teamcode, g2.`role` ASC, p.lastname, p.firstname
    ", [$gamecode, $gender]);

    if (function_exists('hpf_decrypt_rows')) {
        $rows = hpf_decrypt_rows($rows, array_merge(hpf_encrypted_cols('players'), ['club_name']));
    }

    // Για να βρούμε το teamname ανά teamcode, φορτώνουμε όλες τις ομάδες του πρωταθλήματος
    $teams = db_all($pdo, "SELECT * FROM `{$T['teams']}` WHERE gamecode=? AND status='Y'", [$gamecode]);
    // Build index: (clubcode, playercode) -> teamname
    $teamByPlayer = [];
    foreach ($teams as $tm) {
        $starters = array_filter(explode('-', (string)$tm['playercodes']), 'strlen');
        foreach ($starters as $pc) {
            $teamByPlayer[$tm['clubcode'] . '|' . $pc] = $tm['teamname'];
        }
        if (!empty($tm['substitutes'])) {
            foreach (explode('-', (string)$tm['substitutes']) as $pc) {
                if ($pc !== '') {
                    $teamByPlayer[$tm['clubcode'] . '|' . $pc] = $tm['teamname'];
                }
            }
        }
    }

    $roleLabel = function(string $r): string {
        return $r === 'substitute' ? 'Αναπληρωματικός' : 'Βασικός';
    };

    // Στήλες: A/A, Σύλλογος, Επώνυμο (LAT), Όνομα (LAT), Ομάδα, Ρόλος, Κωδ. Αθλητή
    $header = ['A/A', 'Σύλλογος', 'Επώνυμο (LAT)', 'Όνομα (LAT)', 'Ομάδα', 'Ρόλος', 'Κωδ. Αθλητή'];
    $data = [$header];
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $key = $r['clubcode'] . '|' . $r['playercode'];
        $teamName = $teamByPlayer[$key] ?? ($r['teamcode'] ?? '');
        $data[] = [
            $i,
            (string)($r['club_name'] ?? $r['clubcode']),
            greeklish((string)($r['lastname'] ?? '')),
            greeklish((string)($r['firstname'] ?? '')),
            (string)$teamName,
            $roleLabel((string)($r['role'] ?? 'starter')),
            (string)$r['playercode'],
        ];
    }

    $xlsx = new HpfXlsx();
    $xlsx->sheet($catLabel, $data, true);
    $fname = 'petreg_' . ($gender === 'M' ? 'men' : 'women') . '_'
           . preg_replace('/\W+/', '', $game['gamecode']) . '_' . date('Ymd_His') . '.xlsx';
    $xlsx->send($fname);
    exit;
}

// ============================================================
// CSV exports (παλιά λειτουργικότητα)
// ============================================================
$filename = 'petreg_' . $type . '_' . preg_replace('/\W+/', '', $game['gamecode']) . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM για Excel
$out = fopen('php://output', 'w');

if ($type === 'teams') {
    fputcsv($out, ['Πρωτάθλημα','Σύλλογος','Κωδ.Συλλόγου','Ομάδα','Κατηγορία','Πλήθος','Παίκτες (κωδικοί)','Παίκτες (ονόματα)','Αναπληρωματικός']);
    $rows = db_all($pdo, "
        SELECT t.*, c.name AS club_name
        FROM `{$T['teams']}` t
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode=t.clubcode
        WHERE t.gamecode=? AND t.status='Y'
        ORDER BY t.clubcode, t.category, t.teamname
    ", [$gamecode]);
    if (function_exists('hpf_decrypt_rows')) { $rows = hpf_decrypt_rows($rows, ['club_name']); }
    foreach ($rows as $t) {
        $codes = explode('-', (string)$t['playercodes']);
        $subCode = (string)($t['substitutes'] ?? '');
        $allCodes = $codes;
        if ($subCode !== '') { $allCodes[] = $subCode; }
        $in = implode(',', array_fill(0, count($allCodes), '?'));
        $plist = db_all($pdo, "SELECT playercode, firstname, lastname FROM `{$T['players']}` WHERE playercode IN ($in)", $allCodes);
        if (function_exists('hpf_decrypt_rows')) { $plist = hpf_decrypt_rows($plist, hpf_encrypted_cols('players')); }
        $pmap = [];
        foreach ($plist as $pl) { $pmap[$pl['playercode']] = $pl; }
        $names = [];
        foreach ($codes as $pc) {
            if (isset($pmap[$pc])) { $names[] = $pmap[$pc]['lastname'] . ' ' . $pmap[$pc]['firstname']; }
        }
        $subName = '';
        if ($subCode !== '' && isset($pmap[$subCode])) {
            $subName = $pmap[$subCode]['lastname'] . ' ' . $pmap[$subCode]['firstname'] . ' (' . $subCode . ')';
        }
        fputcsv($out, [
            $game['name'],
            $t['club_name'] ?? $t['clubcode'],
            $t['clubcode'],
            $t['teamname'],
            $t['category']==='M'?'Άνδρες':($t['category']==='F'?'Γυναίκες':'Μεικτό'),
            count($codes),
            implode('-', $codes),
            implode(' | ', $names),
            $subName,
        ]);
    }
} else {
    // Ανίχνευση role column
    $hasRoleCol = false;
    try {
        $colChk = db_one($pdo, "SHOW COLUMNS FROM `{$T['games2']}` LIKE 'role'");
        $hasRoleCol = !empty($colChk);
    } catch (Throwable $e) { $hasRoleCol = false; }
    $roleSel = $hasRoleCol ? "g2.`role` AS role" : "'starter' AS role";

    fputcsv($out, ['Πρωτάθλημα','Σύλλογος','Κωδ.Συλλόγου','Αθλητής','Επώνυμο','Όνομα','Φύλο','Κωδ.Παίκτη','Team Code','Ρόλος']);
    $rows = db_all($pdo, "
        SELECT g2.*, $roleSel, p.firstname, p.lastname, p.gender, c.name AS club_name
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
            ($r['role'] ?? 'starter') === 'substitute' ? 'Αναπληρωματικός' : 'Βασικός',
        ]);
    }
}

fclose($out);
