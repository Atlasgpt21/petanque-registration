<?php
declare(strict_types=1);

/**
 * Μηχανή knockout (single elimination) για τη 2η φάση των διοργανώσεων.
 *
 * Καθαρές (pure) συναρτήσεις — καμία εξάρτηση από DB. Ο data layer
 * (src/tournament.php) τους δίνει λίστες ομάδων (με σειρά κατάταξης) και
 * αποθηκεύει τα ζευγάρια στους πίνακες app_tournament_*.
 *
 * Ζευγάρωμα 1ου γύρου (όπως ζητήθηκε): 1 με τον τελευταίο, 2 με τον
 * προτελευταίο κ.ο.κ. (1–16, 2–15, …). Οι νικητές προχωρούν κατά ζεύγη
 * (νικητής αγώνα 1 vs νικητής αγώνα 2 κ.λπ.). Στους ημιτελικούς προκύπτει
 * και Μικρός Τελικός (3η θέση) από τους ηττημένους.
 */

/** Ετικέτα σταδίου με βάση το μέγεθος του ταμπλό (πλήθος ομάδων στον γύρο). */
function ko_stage_label(int $bracketSize): string
{
    switch ($bracketSize) {
        case 2:  return 'Τελικός';
        case 4:  return 'Ημιτελικά';
        case 8:  return 'Προημιτελικά';
        case 16: return 'Φάση των 16';
        case 32: return 'Φάση των 32';
        case 64: return 'Φάση των 64';
        default: return 'Φάση των ' . $bracketSize;
    }
}

/** Επόμενη δύναμη του 2 ≥ n (τουλάχιστον 2). */
function ko_bracket_size(int $n): int
{
    $p = 1;
    while ($p < $n) {
        $p <<= 1;
    }
    return max(2, $p);
}

/**
 * Ζευγάρια 1ου γύρου από λίστα ids με σειρά κατάταξης (καλύτερος πρώτος).
 * Γεμίζει με byes (null) αν το πλήθος δεν είναι δύναμη του 2.
 *
 * @param array<int,int> $rankedIds
 * @return array<int,array{0:int,1:?int}>  [home, away|null]
 */
function ko_first_pairs(array $rankedIds): array
{
    $rankedIds = array_values($rankedIds);
    $n = count($rankedIds);
    if ($n < 2) {
        return [];
    }
    $p = ko_bracket_size($n);
    $pairs = [];
    for ($i = 0; $i < $p / 2; $i++) {
        $home = $rankedIds[$i];                 // πάντα έγκυρος (i < p/2 < n)
        $awayIdx = $p - 1 - $i;
        $away = $rankedIds[$awayIdx] ?? null;   // null = bye για τον home
        $pairs[] = [$home, $away];
    }
    return $pairs;
}

/**
 * Ζευγάρια επόμενου γύρου από τους νικητές (με τη σειρά των αγώνων).
 *
 * @param array<int,int> $winnerIds
 * @return array<int,array{0:int,1:?int}>
 */
function ko_next_pairs(array $winnerIds): array
{
    $winnerIds = array_values($winnerIds);
    $pairs = [];
    for ($i = 0; $i < count($winnerIds); $i += 2) {
        $pairs[] = [$winnerIds[$i], $winnerIds[$i + 1] ?? null];
    }
    return $pairs;
}
