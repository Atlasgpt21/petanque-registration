<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$readonly = is_readonly_external();
$clubcodeFilter = $_GET['club'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));

// Σημ: αν το firstname/lastname είναι encrypted στη βάση (π.χ. Hostinger ΕΟΠ),
// το LIKE δεν θα πιάσει ονόματα — μένει το match πάνω στον playercode. Το
// sort γίνεται PHP-side μετά την αποκρυπτογράφηση.
$sql = "SELECT p.*, c.name AS club_name FROM `{$T['players']}` p LEFT JOIN `{$T['clubs']}` c ON c.clubcode=p.clubcode WHERE 1=1";
$params = [];
if ($clubcodeFilter !== '') { $sql .= " AND p.clubcode=?"; $params[] = $clubcodeFilter; }
if ($q !== '') { $sql .= " AND (p.firstname LIKE ? OR p.lastname LIKE ? OR p.playercode LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
$sql .= " ORDER BY p.playercode LIMIT 500";

$players = db_all($pdo, $sql, $params);
$clubs = db_all($pdo, "SELECT clubcode, name FROM `{$T['clubs']}` ORDER BY clubcode");
if (function_exists('hpf_decrypt_rows')) {
    $players = hpf_decrypt_rows($players, array_merge(hpf_encrypted_cols('players'), ['club_name']));
    $clubs   = hpf_decrypt_rows($clubs, ['name']);
    usort($players, fn($a, $b) => strcasecmp((string)($a['lastname'] ?? ''), (string)($b['lastname'] ?? '')));
    usort($clubs, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    // Text-search fallback: αν ο χρήστης έδωσε query αλλά λόγω encryption δεν
    // ταίριαξε τίποτα, ξαναφιλτράρουμε PHP-side πάνω στα αποκρυπτογραφημένα.
    if ($q !== '' && empty($players)) {
        $all = db_all($pdo, "SELECT p.*, c.name AS club_name FROM `{$T['players']}` p LEFT JOIN `{$T['clubs']}` c ON c.clubcode=p.clubcode" . ($clubcodeFilter !== '' ? " WHERE p.clubcode=?" : '') . " LIMIT 5000", $clubcodeFilter !== '' ? [$clubcodeFilter] : []);
        $all = hpf_decrypt_rows($all, array_merge(hpf_encrypted_cols('players'), ['club_name']));
        $needle = mb_strtolower($q);
        $players = array_values(array_filter($all, fn($p) =>
            str_contains(mb_strtolower((string)($p['firstname'] ?? '')), $needle) ||
            str_contains(mb_strtolower((string)($p['lastname']  ?? '')), $needle) ||
            str_contains(mb_strtolower((string)($p['playercode'] ?? '')), $needle)
        ));
        usort($players, fn($a, $b) => strcasecmp((string)($a['lastname'] ?? ''), (string)($b['lastname'] ?? '')));
        $players = array_slice($players, 0, 500);
    }
}

render_header('Αθλητές', 'admin', 'players');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Αθλητές</h2>
        <p class="main__sub">
            Σύνολο (φιλτραρισμένο): <?= count($players) ?>
            <?php if ($readonly): ?>· <em>προβολή από κεντρικό σύστημα</em><?php endif; ?>
        </p>
    </div>
</div>
<div class="card">
    <form method="get" class="row">
        <div class="field"><label class="field__label">Σύλλογος</label>
            <select class="select" name="club">
                <option value="">— Όλοι —</option>
                <?php foreach ($clubs as $c): ?>
                    <option value="<?= h($c['clubcode']) ?>" <?= $clubcodeFilter===$c['clubcode']?'selected':'' ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label class="field__label">Αναζήτηση</label>
            <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Όνομα, επώνυμο ή κωδικός">
        </div>
        <div class="field" style="display:flex;align-items:flex-end;">
            <button class="btn" type="submit">Αναζήτηση</button>
        </div>
    </form>
</div>
<div class="card">
    <table class="table">
        <thead><tr><th>Κωδ.</th><th>Επώνυμο</th><th>Όνομα</th><th>Φύλο</th><th>Σύλλογος</th><th>Δελτίο</th></tr></thead>
        <tbody>
        <?php foreach ($players as $p): ?>
            <tr>
                <td><code><?= h($p['playercode']) ?></code></td>
                <td><strong><?= h($p['lastname']) ?></strong></td>
                <td><?= h($p['firstname']) ?></td>
                <td><span class="badge badge--<?= h($p['gender']) ?>"><?= h(gender_label($p['gender'])) ?></span></td>
                <td><?= h($p['club_name'] ?? $p['clubcode']) ?></td>
                <td><?= h($p['licenseno'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
