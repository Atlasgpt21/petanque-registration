<?php
declare(strict_types=1);

/**
 * Μηχανή Ελβετικού Συστήματος (Swiss ladder) για διοργανώσεις πετάνκ.
 *
 * Καθαρές (pure) συναρτήσεις — καμία εξάρτηση από DB. Ο data layer
 * (src/tournament.php) τους δίνει arrays και αποθηκεύει τα αποτελέσματα.
 *
 * Βαθμολογικά κριτήρια ισοβαθμίας (με τη σειρά που ζητήθηκε):
 *   1. Βαθμοί (Νίκες − Ήττες, με βάρη win/draw/loss)
 *   2. Buchholz          = άθροισμα βαθμών των αντιπάλων
 *   3. Fine Buchholz     = άθροισμα των Buchholz των αντιπάλων
 *   4. Διαφορά πόντων    = πόντοι υπέρ − πόντοι κατά
 *   5. Πόντοι υπέρ       (τελευταίο, σπάει σχεδόν πάντα την ισοβαθμία)
 *   6. Seed              (σταθερή σειρά)
 */

/**
 * Υπολογίζει πλήρη στατιστικά + κατάταξη.
 *
 * @param array<int,array{id:int,label:string,seed:int|null,withdrawn:bool}> $teams
 * @param array<int,array{round_no:int,home_team_id:int,away_team_id:?int,home_score:?int,away_score:?int,is_bye:int,status:string}> $matches
 * @param array{win_points:int,draw_points:int,loss_points:int} $cfg
 * @return array<int,array<string,mixed>> Ταξινομημένο (καλύτερος πρώτος), με πεδίο `rank`.
 */
function swiss_standings(array $teams, array $matches, array $cfg): array
{
    $win  = (int)($cfg['win_points']  ?? 2);
    $draw = (int)($cfg['draw_points'] ?? 1);
    $loss = (int)($cfg['loss_points'] ?? 0);

    $stats = [];
    foreach ($teams as $t) {
        $stats[(int)$t['id']] = [
            'id'        => (int)$t['id'],
            'label'     => (string)$t['label'],
            'clubcode'  => $t['clubcode'] ?? null,
            'seed'      => $t['seed'] ?? null,
            'withdrawn' => !empty($t['withdrawn']),
            'played'    => 0,
            'wins'      => 0,
            'draws'     => 0,
            'losses'    => 0,
            'byes'      => 0,
            'pf'        => 0,   // points for
            'pa'        => 0,   // points against
            'points'    => 0,   // βαθμοί κατάταξης
            'opponents' => [],  // ids πραγματικών αντιπάλων (όχι byes)
            'buchholz'  => 0,
            'fine_buchholz' => 0,
            'diff'      => 0,
        ];
    }

    foreach ($matches as $m) {
        if (($m['status'] ?? 'pending') !== 'played') {
            continue;
        }
        $home = (int)$m['home_team_id'];
        if (!isset($stats[$home])) {
            continue;
        }
        $hs = (int)($m['home_score'] ?? 0);
        $as = (int)($m['away_score'] ?? 0);

        if (!empty($m['is_bye'])) {
            // Ρεπό: μετράει ως νίκη με το προκαθορισμένο σκορ.
            $stats[$home]['played'] += 1;
            $stats[$home]['byes']   += 1;
            $stats[$home]['wins']   += 1;
            $stats[$home]['points'] += $win;
            $stats[$home]['pf']     += $hs;
            $stats[$home]['pa']     += $as;
            continue;
        }

        $away = (int)$m['away_team_id'];
        if (!isset($stats[$away])) {
            continue;
        }

        $stats[$home]['played'] += 1;
        $stats[$away]['played'] += 1;
        $stats[$home]['pf'] += $hs; $stats[$home]['pa'] += $as;
        $stats[$away]['pf'] += $as; $stats[$away]['pa'] += $hs;
        $stats[$home]['opponents'][] = $away;
        $stats[$away]['opponents'][] = $home;

        if ($hs > $as) {
            $stats[$home]['wins']   += 1; $stats[$home]['points'] += $win;
            $stats[$away]['losses'] += 1; $stats[$away]['points'] += $loss;
        } elseif ($hs < $as) {
            $stats[$away]['wins']   += 1; $stats[$away]['points'] += $win;
            $stats[$home]['losses'] += 1; $stats[$home]['points'] += $loss;
        } else {
            $stats[$home]['draws'] += 1; $stats[$home]['points'] += $draw;
            $stats[$away]['draws'] += 1; $stats[$away]['points'] += $draw;
        }
    }

    // diff
    foreach ($stats as $id => &$s) {
        $s['diff'] = $s['pf'] - $s['pa'];
    }
    unset($s);

    // Buchholz = άθροισμα βαθμών των αντιπάλων.
    foreach ($stats as $id => &$s) {
        $b = 0;
        foreach ($s['opponents'] as $oid) {
            $b += $stats[$oid]['points'] ?? 0;
        }
        $s['buchholz'] = $b;
    }
    unset($s);

    // Fine Buchholz = άθροισμα των Buchholz των αντιπάλων.
    foreach ($stats as $id => &$s) {
        $fb = 0;
        foreach ($s['opponents'] as $oid) {
            $fb += $stats[$oid]['buchholz'] ?? 0;
        }
        $s['fine_buchholz'] = $fb;
    }
    unset($s);

    $list = array_values($stats);
    usort($list, 'swiss_compare_standing');

    $rank = 0;
    foreach ($list as &$row) {
        $row['rank'] = ++$rank;
        unset($row['opponents']); // δεν χρειάζεται στην έξοδο
    }
    unset($row);

    return $list;
}

/**
 * Comparator κατάταξης (βλ. σειρά κριτηρίων στο docblock του αρχείου).
 */
function swiss_compare_standing(array $a, array $b): int
{
    return
        ($b['points']        <=> $a['points'])        ?:
        ($b['wins']          <=> $a['wins'])          ?:
        ($a['losses']        <=> $b['losses'])        ?:
        ($b['buchholz']      <=> $a['buchholz'])      ?:
        ($b['fine_buchholz'] <=> $a['fine_buchholz']) ?:
        ($b['diff']          <=> $a['diff'])          ?:
        ($b['pf']            <=> $a['pf'])            ?:
        (($a['seed'] ?? PHP_INT_MAX) <=> ($b['seed'] ?? PHP_INT_MAX));
}

/**
 * Κλειδί ζευγαριού ανεξάρτητο σειράς.
 */
function swiss_pair_key(int $a, int $b): string
{
    return $a < $b ? "$a-$b" : "$b-$a";
}

/**
 * Παράγει τα ζευγάρια του επόμενου γύρου με βάση την τρέχουσα κατάταξη,
 * αποφεύγοντας επαναλήψεις αγώνων (rematches) όσο είναι εφικτό.
 *
 * @param array<int,array<string,mixed>> $standings  Ταξινομημένη κατάταξη (καλύτερος πρώτος)· περιλαμβάνει `id`,`seed`,`byes`,`withdrawn`.
 * @param array<int,int> $byeHistory  map team_id => πλήθος ρεπό που έχει ήδη πάρει.
 * @param array<string,bool> $pastPairs  set από swiss_pair_key() για ήδη παιγμένα ζευγάρια.
 * @param array<string,bool> $avoidPairs  ΕΠΙΠΛΕΟΝ soft περιορισμός (π.χ. ίδιος σύλλογος στον 1ο γύρο)· αγνοείται αν δεν βρίσκεται λύση.
 * @return array{pairs:array<int,array{0:int,1:int}>,bye:?int}
 */
function swiss_pair_next(array $standings, array $byeHistory, array $pastPairs, array $avoidPairs = []): array
{
    // Ενεργές ομάδες με τη σειρά κατάταξης.
    $order = [];
    foreach ($standings as $s) {
        if (empty($s['withdrawn'])) {
            $order[] = (int)$s['id'];
        }
    }

    $bye = null;
    if (count($order) % 2 === 1) {
        // Ρεπό στη χαμηλότερα κατατασσόμενη ομάδα που δεν έχει ξαναπάρει ρεπό.
        for ($i = count($order) - 1; $i >= 0; $i--) {
            $tid = $order[$i];
            if (($byeHistory[$tid] ?? 0) === 0) {
                $bye = $tid;
                array_splice($order, $i, 1);
                break;
            }
        }
        if ($bye === null) {
            // Όλοι έχουν πάρει ρεπό — δώσ' το στον τελευταίο.
            $bye = array_pop($order);
        }
    }

    // 1η προσπάθεια: αποφυγή rematches ΚΑΙ των soft περιορισμών (π.χ. ίδιος σύλλογος).
    $pairs = null;
    if ($avoidPairs !== []) {
        $pairs = _swiss_backtrack_pairs($order, $pastPairs + $avoidPairs);
    }
    // 2η προσπάθεια: αποφυγή μόνο rematches.
    if ($pairs === null) {
        $pairs = _swiss_backtrack_pairs($order, $pastPairs);
    }
    if ($pairs === null) {
        // Δεν βρέθηκε λύση χωρίς rematch — επιτρέπουμε rematches (γειτονικά).
        $pairs = [];
        for ($i = 0; $i + 1 < count($order); $i += 2) {
            $pairs[] = [$order[$i], $order[$i + 1]];
        }
    }

    return ['pairs' => $pairs, 'bye' => $bye];
}

/**
 * Backtracking: ζευγαρώνει την πρώτη αζευγάρωτη ομάδα με την πλησιέστερη
 * επόμενη που δεν έχει ξαναπαίξει μαζί της. Επιστρέφει null αν αδύνατον.
 *
 * @param array<int,int> $order
 * @param array<string,bool> $pastPairs
 * @return array<int,array{0:int,1:int}>|null
 */
function _swiss_backtrack_pairs(array $order, array $pastPairs): ?array
{
    if (count($order) === 0) {
        return [];
    }
    $first = $order[0];
    for ($i = 1; $i < count($order); $i++) {
        $cand = $order[$i];
        if (!empty($pastPairs[swiss_pair_key($first, $cand)])) {
            continue; // ήδη έχουν παίξει
        }
        $rest = $order;
        unset($rest[$i], $rest[0]);
        $rest = array_values($rest);
        $sub = _swiss_backtrack_pairs($rest, $pastPairs);
        if ($sub !== null) {
            array_unshift($sub, [$first, $cand]);
            return $sub;
        }
    }
    return null;
}
