<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$readonly = is_readonly_external();
$clubcodeFilter = $_GET['club'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT p.*, c.name AS club_name FROM `{$T['players']}` p LEFT JOIN `{$T['clubs']}` c ON c.clubcode=p.clubcode WHERE 1=1";
$params = [];
if ($clubcodeFilter !== '') { $sql .= " AND p.clubcode=?"; $params[] = $clubcodeFilter; }
if ($q !== '') { $sql .= " AND (p.firstname LIKE ? OR p.lastname LIKE ? OR p.playercode LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
$sql .= " ORDER BY p.lastname, p.firstname LIMIT 500";

$players = db_all($pdo, $sql, $params);
$clubs = db_all($pdo, "SELECT clubcode, name FROM `{$T['clubs']}` ORDER BY name");

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
