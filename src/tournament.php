<?php
declare(strict_types=1);

/**
 * Data layer για τις διοργανώσεις. Χρησιμοποιεί το global PDO/tables και τις
 * μηχανές src/swiss.php (1η φάση) + src/knockout.php (2η φάση). Οι ομάδες
 * διαβάζονται από τον πίνακα `teams` (config `tables.teams`) — γράφουμε μόνο
 * στους app_tournament* πίνακες.
 */

require_once __DIR__ . '/swiss.php';
require_once __DIR__ . '/knockout.php';

/** Ονόματα πινάκων διοργάνωσης (με προαιρετικό override από config `tables`). */
function tour_tables(): array
{
    $T = $GLOBALS['T'] ?? [];
    return [
        'tournaments' => $T['tournaments']         ?? 'app_tournaments',
        'teams'       => $T['tournament_teams']    ?? 'app_tournament_teams',
        'rounds'      => $T['tournament_rounds']   ?? 'app_tournament_rounds',
        'matches'     => $T['tournament_matches']  ?? 'app_tournament_matches',
    ];
}

/** Ετικέτα κατηγορίας/φύλου ταμπλό. */
function tour_category_label(string $cat): string
{
    switch ($cat) {
        case 'M':   return 'Άνδρες';
        case 'F':   return 'Γυναίκες';
        case 'MIX': return 'Μικτό';
        default:    return '';
    }
}

function tour_get(PDO $pdo, int $id): ?array
{
    $tt = tour_tables();
    return db_one($pdo, "SELECT * FROM `{$tt['tournaments']}` WHERE id=?", [$id]);
}

/** Λίστα διοργανώσεων + πλήθος ομάδων/γύρων. */
function tour_all(PDO $pdo): array
{
    $tt = tour_tables();
    return db_all($pdo, "
        SELECT t.*,
               (SELECT COUNT(*) FROM `{$tt['teams']}`  x WHERE x.tournament_id=t.id) AS team_count,
               (SELECT COUNT(*) FROM `{$tt['rounds']}` r WHERE r.tournament_id=t.id) AS round_count
        FROM `{$tt['tournaments']}` t
        ORDER BY t.id DESC
    ");
}

function tour_create(PDO $pdo, string $name, string $gamecode, string $category = 'ALL'): int
{
    $tt = tour_tables();
    // Προεπιλογές προδιαγραφής: 5 γύροι Ελβετικού, 1 βαθμός/νίκη, χωρίς ισοπαλία,
    // Κύπελλο Φιλίας ενεργό, TOP-16 για Άνδρες/MIX και TOP-8 για Γυναίκες.
    $koSize = $category === 'F' ? 8 : 16;
    $st = $pdo->prepare("INSERT INTO `{$tt['tournaments']}`
        (name, gamecode, category, rounds_planned, win_points, draw_points, loss_points, ko_size, friendship_cup)
        VALUES (?,?,?, 5, 1, 0, 0, ?, 1)");
    $st->execute([$name, $gamecode, $category, $koSize]);
    return (int)$pdo->lastInsertId();
}

/**
 * Δημιουργεί διοργάνωση(εις) για ένα championship ανάλογα με το είδος:
 *   - Mixed    → ΕΝΑ ενιαίο ταμπλό (κατηγορία MIX).
 *   - Doubles/Triplets → ΔΥΟ ξεχωριστά ταμπλό (Άνδρες / Γυναίκες).
 *   - άλλο     → ΕΝΑ ταμπλό (ALL).
 * Επιστρέφει τα ids που δημιουργήθηκαν.
 *
 * @return array<int,int>
 */
function tour_create_for_game(PDO $pdo, string $name, string $gamecode, string $gametype): array
{
    $name = trim($name);
    if ($gametype === 'Mixed') {
        return [tour_create($pdo, $name, $gamecode, 'MIX')];
    }
    if ($gametype === 'Doubles' || $gametype === 'Triplets') {
        return [
            tour_create($pdo, $name . ' — Άνδρες',   $gamecode, 'M'),
            tour_create($pdo, $name . ' — Γυναίκες', $gamecode, 'F'),
        ];
    }
    return [tour_create($pdo, $name, $gamecode, 'ALL')];
}

/** Ενημέρωση ρυθμίσεων διοργάνωσης (φάσεις, γήπεδα, βαθμολογία). */
function tour_update_settings(PDO $pdo, int $id, array $s): void
{
    $tt = tour_tables();
    $pdo->prepare("UPDATE `{$tt['tournaments']}` SET
            name=?, rounds_planned=?, courts=?, court_from=?, court_to=?, ko_size=?, friendship_cup=?,
            win_points=?, draw_points=?, loss_points=?, bye_score_for=?, bye_score_against=?
        WHERE id=?")
        ->execute([
            (string)$s['name'],
            $s['rounds_planned'] !== null ? (int)$s['rounds_planned'] : null,
            (int)$s['courts'],
            (int)($s['court_from'] ?? 0),
            (int)($s['court_to'] ?? 0),
            (int)$s['ko_size'],
            (int)$s['friendship_cup'],
            (int)$s['win_points'],
            (int)$s['draw_points'],
            (int)$s['loss_points'],
            (int)$s['bye_score_for'],
            (int)$s['bye_score_against'],
            $id,
        ]);
}

/**
 * Ταξινομημένη λίστα διαθέσιμων αριθμών γηπέδου για το ταμπλό.
 * Προτεραιότητα: εύρος [court_from..court_to] → αλλιώς 1..courts → αλλιώς κενή
 * (κενή = σειριακή αρίθμηση = board_no).
 *
 * @return array<int,int>
 */
function tour_court_list(array $tour): array
{
    $from = (int)($tour['court_from'] ?? 0);
    $to   = (int)($tour['court_to'] ?? 0);
    if ($from > 0 && $to >= $from) {
        return range($from, $to);
    }
    $courts = (int)($tour['courts'] ?? 0);
    if ($courts > 0) {
        return range(1, $courts);
    }
    return [];
}

function tour_delete(PDO $pdo, int $id): void
{
    $tt = tour_tables();
    $pdo->prepare("DELETE FROM `{$tt['matches']}` WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['rounds']}`  WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['teams']}`   WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['tournaments']}` WHERE id=?")->execute([$id]);
}

function tour_teams(PDO $pdo, int $id): array
{
    $tt = tour_tables();
    return db_all($pdo, "SELECT * FROM `{$tt['teams']}` WHERE tournament_id=? ORDER BY seed IS NULL, seed, id", [$id]);
}

/**
 * Import ομάδων από το championship (πίνακας teams) που δεν υπάρχουν ήδη.
 * Φιλτράρει με βάση την κατηγορία της διοργάνωσης (M/F/MIX· ALL = όλες).
 *
 * Η ταυτότητα κάθε ομάδας είναι το ΜΟΝΑΔΙΚΟ `teamid` του championship — ΟΧΙ το
 * `teamname` (που δεν είναι μοναδικό ανά σύλλογο, π.χ. δύο σύλλογοι με «CLUBmix1»).
 * Έτσι η επανάληψη import είναι idempotent χωρίς να «καταρρέουν» ομάδες.
 *
 * Επιστρέφει πλήθος νέων ομάδων.
 */
function tour_import_teams(PDO $pdo, int $id, string $gamecode): int
{
    $tt = tour_tables();
    $T  = $GLOBALS['T'];
    $tour = tour_get($pdo, $id);
    $cat  = (string)($tour['category'] ?? 'ALL');

    $where = "t.gamecode=? AND t.status='Y'";
    $args  = [$gamecode];
    if (in_array($cat, ['M', 'F', 'MIX'], true)) {
        $where .= " AND t.category=?";
        $args[] = $cat;
    }

    $rows = db_all($pdo, "
        SELECT t.teamid, t.teamname, t.playercodes, t.clubcode, t.category, c.name AS club_name
        FROM `{$T['teams']}` t
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode = t.clubcode
        WHERE {$where}
        ORDER BY t.clubcode, t.category, t.teamname
    ", $args);
    if (function_exists('hpf_decrypt_rows')) {
        $rows = hpf_decrypt_rows($rows, ['club_name']);
    }

    // Ονόματα αθλητών ανά playercode (μία μαζική ανάγνωση για όλες τις ομάδες).
    $playerNames = tour_player_name_map($pdo, $rows);

    $existing = [];  // src_teamid => true
    foreach (tour_teams($pdo, $id) as $r) {
        if ($r['src_teamid'] !== null) {
            $existing[(int)$r['src_teamid']] = true;
        }
    }

    $seed = 0;
    foreach (db_all($pdo, "SELECT MAX(seed) AS m FROM `{$tt['teams']}` WHERE tournament_id=?", [$id]) as $r) {
        $seed = (int)($r['m'] ?? 0);
    }

    // Idempotent με βάση το src_teamid: ξανα-import ανανεώνει μόνο την ετικέτα.
    $ins = $pdo->prepare("INSERT INTO `{$tt['teams']}` (tournament_id, src_teamid, teamname, clubcode, label, seed)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE teamname=VALUES(teamname), clubcode=VALUES(clubcode), label=VALUES(label)");
    $added = 0;
    foreach ($rows as $row) {
        $srcId    = (int)$row['teamid'];
        $tn       = (string)$row['teamname'];
        $clubReal = trim((string)($row['club_name'] ?? ''));
        $names    = tour_team_athlete_label($row['playercodes'] ?? '', $playerNames);
        $label    = ($names !== '' ? $names : $tn) . ($clubReal !== '' ? ' — ' . $clubReal : '');
        $ins->execute([$id, $srcId, $tn, $row['clubcode'] ?? null, $label, isset($existing[$srcId]) ? null : ++$seed]);
        if (!isset($existing[$srcId])) {
            $existing[$srcId] = true;
            $added++;
        }
    }
    return $added;
}

/**
 * Μαζική ανάγνωση ονομάτων αθλητών για όλα τα playercodes ενός συνόλου ομάδων.
 *
 * @param array<int,array<string,mixed>> $teamRows γραμμές με στήλη `playercodes`
 * @return array<string,string> playercode => «ΕΠΩΝΥΜΟ ΟΝΟΜΑ»
 */
function tour_player_name_map(PDO $pdo, array $teamRows): array
{
    $T = $GLOBALS['T'];
    $allCodes = [];
    foreach ($teamRows as $t) {
        foreach (explode('-', (string)($t['playercodes'] ?? '')) as $c) {
            $c = trim($c);
            if ($c !== '') {
                $allCodes[$c] = true;
            }
        }
    }
    if (!$allCodes) {
        return [];
    }
    $codes = array_keys($allCodes);
    $in    = implode(',', array_fill(0, count($codes), '?'));
    $players = db_all($pdo, "SELECT playercode, firstname, lastname FROM `{$T['players']}` WHERE playercode IN ($in)", $codes);
    if (function_exists('hpf_decrypt_rows')) {
        $cols = function_exists('hpf_encrypted_cols') ? hpf_encrypted_cols('players') : ['firstname', 'lastname'];
        $players = hpf_decrypt_rows($players, $cols);
    }
    $names = [];
    foreach ($players as $p) {
        $names[(string)$p['playercode']] = trim(trim((string)$p['lastname']) . ' ' . trim((string)$p['firstname']));
    }
    return $names;
}

/**
 * Ετικέτα ομάδας από dash-separated playercodes + χάρτη ονομάτων.
 * π.χ. «000002-000021» => «Παπαδόπουλος Γιώργος / Αντωνίου Νίκος». Fallback: κενό.
 *
 * @param array<string,string> $playerNames
 */
function tour_team_athlete_label(string $playercodes, array $playerNames): string
{
    $parts = [];
    foreach (explode('-', $playercodes) as $c) {
        $c = trim($c);
        if ($c !== '' && isset($playerNames[$c]) && $playerNames[$c] !== '') {
            $parts[] = $playerNames[$c];
        }
    }
    return $parts ? implode(' / ', $parts) : '';
}

function tour_delete_team(PDO $pdo, int $id, int $teamId): void
{
    $tt = tour_tables();
    $pdo->prepare("DELETE FROM `{$tt['teams']}` WHERE tournament_id=? AND id=?")->execute([$id, $teamId]);
}

function tour_set_team_withdrawn(PDO $pdo, int $id, int $teamId, bool $withdrawn): void
{
    $tt = tour_tables();
    $pdo->prepare("UPDATE `{$tt['teams']}` SET withdrawn=? WHERE tournament_id=? AND id=?")
        ->execute([$withdrawn ? 1 : 0, $id, $teamId]);
}

function tour_rounds(PDO $pdo, int $id): array
{
    $tt = tour_tables();
    return db_all($pdo, "SELECT * FROM `{$tt['rounds']}` WHERE tournament_id=? ORDER BY round_no", [$id]);
}

function tour_matches(PDO $pdo, int $id, ?int $roundNo = null): array
{
    $tt = tour_tables();
    if ($roundNo === null) {
        return db_all($pdo, "SELECT * FROM `{$tt['matches']}` WHERE tournament_id=? ORDER BY round_no, board_no", [$id]);
    }
    return db_all($pdo, "SELECT * FROM `{$tt['matches']}` WHERE tournament_id=? AND round_no=? ORDER BY board_no", [$id, $roundNo]);
}

/** Νικητής/ηττημένος ενός αγώνα (ή null αν δεν έχει κριθεί). */
function tour_match_winner(array $m): ?int
{
    if ((int)($m['is_bye'] ?? 0) === 1 || $m['away_team_id'] === null) {
        return $m['home_team_id'] !== null ? (int)$m['home_team_id'] : null;
    }
    if (($m['status'] ?? 'pending') !== 'played') {
        return null;
    }
    $hs = (int)$m['home_score'];
    $as = (int)$m['away_score'];
    return $hs >= $as ? (int)$m['home_team_id'] : (int)$m['away_team_id'];
}

function tour_match_loser(array $m): ?int
{
    if ((int)($m['is_bye'] ?? 0) === 1 || $m['away_team_id'] === null) {
        return null;
    }
    $w = tour_match_winner($m);
    if ($w === null) {
        return null;
    }
    $h = (int)$m['home_team_id'];
    $a = (int)$m['away_team_id'];
    return $w === $h ? $a : $h;
}

/** Κατάταξη μέσω της μηχανής Swiss (μόνο αγώνες 1ης φάσης). */
function tour_standings(PDO $pdo, int $id): array
{
    $tour  = tour_get($pdo, $id);
    $teams = array_map(static function (array $r): array {
        return [
            'id'        => (int)$r['id'],
            'label'     => (string)$r['label'],
            'clubcode'  => $r['clubcode'] ?? null,
            'seed'      => $r['seed'] !== null ? (int)$r['seed'] : null,
            'withdrawn' => (int)$r['withdrawn'] === 1,
        ];
    }, tour_teams($pdo, $id));

    $swissMatches = array_values(array_filter(
        tour_matches($pdo, $id),
        static fn (array $m): bool => ($m['phase'] ?? 'swiss') === 'swiss'
    ));

    $cfg = [
        'win_points'  => (int)($tour['win_points']  ?? 2),
        'draw_points' => (int)($tour['draw_points'] ?? 1),
        'loss_points' => (int)($tour['loss_points'] ?? 0),
    ];
    return swiss_standings($teams, $swissMatches, $cfg);
}

/** Ids ομάδων με σειρά τελικής κατάταξης 1ης φάσης (ενεργές μόνο). */
function tour_ranked_ids(PDO $pdo, int $id): array
{
    $ids = [];
    foreach (tour_standings($pdo, $id) as $s) {
        if (empty($s['withdrawn'])) {
            $ids[] = (int)$s['id'];
        }
    }
    return $ids;
}

/** Ανώτατος αριθμός γύρου (όλες οι φάσεις). */
function tour_last_round_no(PDO $pdo, int $id): int
{
    $last = 0;
    foreach (tour_rounds($pdo, $id) as $r) {
        $last = max($last, (int)$r['round_no']);
    }
    return $last;
}

/** Γύροι μιας φάσης (swiss|ko|friendship). */
function tour_rounds_phase(PDO $pdo, int $id, string $phase): array
{
    return array_values(array_filter(
        tour_rounds($pdo, $id),
        static fn (array $r): bool => ($r['phase'] ?? 'swiss') === $phase
    ));
}

/** Πλήθος ενεργών ομάδων. */
function tour_active_count(PDO $pdo, int $id): int
{
    $n = 0;
    foreach (tour_teams($pdo, $id) as $t) {
        if ((int)$t['withdrawn'] === 0) { $n++; }
    }
    return $n;
}

/**
 * Δημιουργεί τον επόμενο γύρο 1ης φάσης (Swiss). Αναθέτει γήπεδα.
 * @throws RuntimeException
 */
function tour_generate_round(PDO $pdo, int $id): int
{
    $tt   = tour_tables();
    $tour = tour_get($pdo, $id);
    if (!$tour) {
        throw new RuntimeException('Η διοργάνωση δεν βρέθηκε.');
    }
    if (tour_rounds_phase($pdo, $id, 'ko') !== [] || tour_rounds_phase($pdo, $id, 'friendship') !== []) {
        throw new RuntimeException('Έχει ήδη ξεκινήσει η φάση knockout — δεν μπορούν να προστεθούν γύροι Ελβετικού.');
    }

    $swissRounds = tour_rounds_phase($pdo, $id, 'swiss');
    $lastRound = 0;
    foreach ($swissRounds as $r) {
        $lastRound = max($lastRound, (int)$r['round_no']);
    }
    foreach ($swissRounds as $r) {
        if ((int)$r['round_no'] === $lastRound && $r['status'] !== 'completed') {
            throw new RuntimeException('Ολοκληρώστε πρώτα τα αποτελέσματα του τρέχοντος γύρου.');
        }
    }

    $planned = $tour['rounds_planned'] !== null ? (int)$tour['rounds_planned'] : 0;
    if ($planned > 0 && $lastRound >= $planned) {
        throw new RuntimeException("Ολοκληρώθηκαν και οι $planned προγραμματισμένοι γύροι Ελβετικού. Προχωρήστε στη φάση knockout.");
    }

    if (tour_active_count($pdo, $id) < 2) {
        throw new RuntimeException('Χρειάζονται τουλάχιστον 2 ενεργές ομάδες.');
    }

    $standings = tour_standings($pdo, $id);

    $byeHistory = [];
    $pastPairs  = [];
    foreach (tour_matches($pdo, $id) as $m) {
        if (($m['phase'] ?? 'swiss') !== 'swiss') {
            continue;
        }
        if ((int)$m['is_bye'] === 1) {
            $byeHistory[(int)$m['home_team_id']] = ($byeHistory[(int)$m['home_team_id']] ?? 0) + 1;
        } elseif ($m['away_team_id'] !== null) {
            $pastPairs[swiss_pair_key((int)$m['home_team_id'], (int)$m['away_team_id'])] = true;
        }
    }

    // Στον 1ο γύρο, αποφυγή (όσο γίνεται) αγώνων μεταξύ ομάδων του ίδιου συλλόγου.
    $avoidPairs = [];
    if ($lastRound === 0) {
        $active = array_values(array_filter($standings, static fn($s) => empty($s['withdrawn'])));
        for ($i = 0; $i < count($active); $i++) {
            for ($j = $i + 1; $j < count($active); $j++) {
                $ca = $active[$i]['clubcode'] ?? null;
                $cb = $active[$j]['clubcode'] ?? null;
                if ($ca !== null && $ca !== '' && $ca === $cb) {
                    $avoidPairs[swiss_pair_key((int)$active[$i]['id'], (int)$active[$j]['id'])] = true;
                }
            }
        }
    }

    $result    = swiss_pair_next($standings, $byeHistory, $pastPairs, $avoidPairs);
    $roundNo   = tour_last_round_no($pdo, $id) + 1;
    $courtList = tour_court_list($tour);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO `{$tt['rounds']}` (tournament_id, round_no, phase, stage, status) VALUES (?,?, 'swiss', NULL, 'paired')")
            ->execute([$id, $roundNo]);

        $insM = $pdo->prepare("INSERT INTO `{$tt['matches']}`
            (tournament_id, round_no, board_no, court_no, phase, stage, home_team_id, away_team_id, home_score, away_score, status, is_bye)
            VALUES (?,?,?,?, 'swiss', NULL, ?,?,?,?,?,?)");

        $board = 0;
        foreach ($result['pairs'] as [$home, $away]) {
            $board++;
            $court = $courtList !== [] ? $courtList[($board - 1) % count($courtList)] : $board;
            $insM->execute([$id, $roundNo, $board, $court, $home, $away, null, null, 'pending', 0]);
        }
        if ($result['bye'] !== null) {
            $bf = (int)($tour['bye_score_for'] ?? 13);
            $ba = (int)($tour['bye_score_against'] ?? 7);
            $insM->execute([$id, $roundNo, ++$board, null, $result['bye'], null, $bf, $ba, 'played', 1]);
        }

        if ($tour['status'] === 'setup') {
            $pdo->prepare("UPDATE `{$tt['tournaments']}` SET status='running' WHERE id=?")->execute([$id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $roundNo;
}

/**
 * Ξεκινά ή προχωρά τη φάση knockout ('ko' = κυρίως ταμπλό, 'friendship' =
 * Κύπελλο Φιλίας θέσεων 17–32). Επιστρέφει τον αριθμό του νέου γύρου.
 * @throws RuntimeException
 */
function tour_generate_ko(PDO $pdo, int $id, string $phase): int
{
    $tt   = tour_tables();
    $tour = tour_get($pdo, $id);
    if (!$tour) {
        throw new RuntimeException('Η διοργάνωση δεν βρέθηκε.');
    }
    if (!in_array($phase, ['ko', 'friendship'], true)) {
        throw new RuntimeException('Άγνωστη φάση.');
    }

    $phaseRounds = tour_rounds_phase($pdo, $id, $phase);
    $courts = (int)($tour['courts'] ?? 0);

    if ($phaseRounds === []) {
        // 1η φάση knockout: απαιτείται ολοκληρωμένη 1η φάση (Swiss).
        $swiss = tour_rounds_phase($pdo, $id, 'swiss');
        if ($swiss === []) {
            throw new RuntimeException('Ολοκληρώστε πρώτα τους γύρους της 1ης φάσης.');
        }
        foreach ($swiss as $r) {
            if ($r['status'] !== 'completed') {
                throw new RuntimeException('Ολοκληρώστε όλους τους γύρους της 1ης φάσης πριν την knockout.');
            }
        }

        $ranked = tour_ranked_ids($pdo, $id);
        if ($phase === 'ko') {
            $size = (int)($tour['ko_size'] ?? 0);
            if ($size < 2) {
                throw new RuntimeException('Ορίστε TOP-8 ή TOP-16 στις ρυθμίσεις.');
            }
            $pool = array_slice($ranked, 0, $size);
        } else {
            if ((int)($tour['friendship_cup'] ?? 0) !== 1) {
                throw new RuntimeException('Το Κύπελλο Φιλίας δεν είναι ενεργό στις ρυθμίσεις.');
            }
            // Επόμενες ko_size θέσεις: TOP-16 → 17–32, TOP-8 → 9–16.
            $koSize = (int)($tour['ko_size'] ?? 16);
            if ($koSize < 2) {
                $koSize = 16;
            }
            $pool = array_slice($ranked, $koSize, $koSize);
        }
        if (count($pool) < 2) {
            throw new RuntimeException('Δεν υπάρχουν αρκετές ομάδες για αυτή τη φάση.');
        }

        $pairs = ko_first_pairs($pool);
        $stage = ko_stage_label(ko_bracket_size(count($pool)));
        return _tour_write_ko_round($pdo, $id, $phase, $stage, $pairs, $courts, $tour);
    }

    // Επόμενο στάδιο: ο τελευταίος γύρος της φάσης πρέπει να έχει ολοκληρωθεί.
    $lastRoundNo = 0;
    foreach ($phaseRounds as $r) {
        $lastRoundNo = max($lastRoundNo, (int)$r['round_no']);
    }
    foreach ($phaseRounds as $r) {
        if ((int)$r['round_no'] === $lastRoundNo && $r['status'] !== 'completed') {
            throw new RuntimeException('Ολοκληρώστε το τρέχον στάδιο πριν το επόμενο.');
        }
    }
    $lastMatches = tour_matches($pdo, $id, $lastRoundNo);
    foreach ($lastMatches as $m) {
        if (($m['stage'] ?? '') === 'Τελικός') {
            throw new RuntimeException('Το ταμπλό έχει ολοκληρωθεί (έγινε ο τελικός).');
        }
    }

    $winners = [];
    foreach ($lastMatches as $m) {
        $w = tour_match_winner($m);
        if ($w !== null) {
            $winners[] = $w;
        }
    }

    if (count($winners) === 2) {
        // Ημιτελικοί → Τελικός + Μικρός Τελικός.
        $losers = [];
        foreach ($lastMatches as $m) {
            $l = tour_match_loser($m);
            if ($l !== null) {
                $losers[] = $l;
            }
        }
        $pairs = [
            ['stage' => 'Τελικός',       'home' => $winners[0], 'away' => $winners[1] ?? null],
        ];
        if (count($losers) === 2) {
            $pairs[] = ['stage' => 'Μικρός Τελικός', 'home' => $losers[0], 'away' => $losers[1]];
        }
        return _tour_write_ko_round($pdo, $id, $phase, 'Τελικοί', $pairs, $courts, $tour, true);
    }

    $next  = ko_next_pairs($winners);
    $stage = ko_stage_label(count($winners));
    return _tour_write_ko_round($pdo, $id, $phase, $stage, $next, $courts, $tour);
}

/**
 * Γράφει έναν γύρο knockout. Τα $pairs είναι είτε [home,away] είτε
 * ['stage'=>..,'home'=>..,'away'=>..] (για τον γύρο των τελικών).
 * @param array<int,mixed> $pairs
 */
function _tour_write_ko_round(PDO $pdo, int $id, string $phase, string $roundStage, array $pairs, int $courts, array $tour, bool $perMatchStage = false): int
{
    $tt = tour_tables();
    $roundNo = tour_last_round_no($pdo, $id) + 1;
    $courtList = tour_court_list($tour);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO `{$tt['rounds']}` (tournament_id, round_no, phase, stage, status) VALUES (?,?,?,?, 'paired')")
            ->execute([$id, $roundNo, $phase, $roundStage]);

        $insM = $pdo->prepare("INSERT INTO `{$tt['matches']}`
            (tournament_id, round_no, board_no, court_no, phase, stage, home_team_id, away_team_id, home_score, away_score, status, is_bye)
            VALUES (?,?,?,?,?,?, ?,?,?,?,?,?)");

        $board = 0;
        $realIdx = 0;
        foreach ($pairs as $p) {
            if ($perMatchStage) {
                $stage = (string)$p['stage'];
                $home  = $p['home'];
                $away  = $p['away'];
            } else {
                $stage = $roundStage;
                [$home, $away] = $p;
            }
            $board++;
            if ($away === null) {
                // Bye: πρόκριση άνευ αγώνα.
                $insM->execute([$id, $roundNo, $board, null, $phase, $stage, $home, null, null, null, 'played', 1]);
            } else {
                $court = $courtList !== [] ? $courtList[$realIdx % count($courtList)] : $board;
                $realIdx++;
                $insM->execute([$id, $roundNo, $board, $court, $phase, $stage, $home, $away, null, null, 'pending', 0]);
            }
        }

        // Αν όλοι οι αγώνες του γύρου είναι byes → ολοκληρωμένος.
        $rows = db_all($pdo, "SELECT status FROM `{$tt['matches']}` WHERE tournament_id=? AND round_no=?", [$id, $roundNo]);
        $allPlayed = $rows !== [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'played') { $allPlayed = false; break; }
        }
        if ($allPlayed) {
            $pdo->prepare("UPDATE `{$tt['rounds']}` SET status='completed' WHERE tournament_id=? AND round_no=?")
                ->execute([$id, $roundNo]);
        }

        if (($tour['status'] ?? '') === 'setup') {
            $pdo->prepare("UPDATE `{$tt['tournaments']}` SET status='running' WHERE id=?")->execute([$id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $roundNo;
}

/**
 * Αποθηκεύει αποτελέσματα ενός γύρου.
 * @param array<int,array{home:?int,away:?int}> $results  map match_id => σκορ
 */
function tour_save_results(PDO $pdo, int $id, int $roundNo, array $results): void
{
    $tt = tour_tables();
    $upd = $pdo->prepare("UPDATE `{$tt['matches']}`
        SET home_score=?, away_score=?, status=?
        WHERE id=? AND tournament_id=? AND round_no=? AND is_bye=0");

    foreach (tour_matches($pdo, $id, $roundNo) as $m) {
        if ((int)$m['is_bye'] === 1) {
            continue;
        }
        $mid = (int)$m['id'];
        $r = $results[$mid] ?? null;
        $home = ($r['home'] ?? '') === '' ? null : (int)$r['home'];
        $away = ($r['away'] ?? '') === '' ? null : (int)$r['away'];
        $status = ($home !== null && $away !== null) ? 'played' : 'pending';
        $upd->execute([$home, $away, $status, $mid, $id, $roundNo]);
    }

    $rows = tour_matches($pdo, $id, $roundNo);
    $allPlayed = $rows !== [];
    foreach ($rows as $m) {
        if ($m['status'] !== 'played') { $allPlayed = false; break; }
    }
    $pdo->prepare("UPDATE `{$tt['rounds']}` SET status=? WHERE tournament_id=? AND round_no=?")
        ->execute([$allPlayed ? 'completed' : 'paired', $id, $roundNo]);
}

/** Διαγράφει τον τελευταίο γύρο (matches + round). */
function tour_delete_last_round(PDO $pdo, int $id): void
{
    $tt = tour_tables();
    $last = tour_last_round_no($pdo, $id);
    if ($last === 0) {
        return;
    }
    $pdo->prepare("DELETE FROM `{$tt['matches']}` WHERE tournament_id=? AND round_no=?")->execute([$id, $last]);
    $pdo->prepare("DELETE FROM `{$tt['rounds']}`  WHERE tournament_id=? AND round_no=?")->execute([$id, $last]);
}

/** Χάρτης team_id => label (για εμφάνιση σε ζευγάρια). */
function tour_team_labels(PDO $pdo, int $id): array
{
    $map = [];
    foreach (tour_teams($pdo, $id) as $t) {
        $map[(int)$t['id']] = (string)$t['label'];
    }
    return $map;
}
