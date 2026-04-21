<?php
declare(strict_types=1);

/**
 * Υπολογίζει απαιτούμενο μέγεθος ομάδας ανά gametype.
 */
function team_size_for(string $gametype): int
{
    return match ($gametype) {
        'Doubles', 'Mixed' => 2,
        'Triplets'         => 3,
        default            => 0,
    };
}

/**
 * Δυνατές κατηγορίες (gender combinations) ανά gametype.
 * Επιστρέφει ['M','F'] για Doubles/Triples, ['MIX'] για Mixed.
 */
function categories_for(string $gametype): array
{
    return match ($gametype) {
        'Doubles', 'Triplets' => ['M', 'F'],
        'Mixed'               => ['MIX'],
        default               => [],
    };
}

/**
 * Επικύρωση σύνθεσης ομάδας.
 * $players: λίστα πλήρων rows από πίνακα players (ίδιος σύλλογος υποχρεωτικά).
 * $gametype: τύπος πρωταθλήματος.
 * $category: 'M' | 'F' | 'MIX'
 * @return string|null error message ή null αν οκ
 */
function validate_team_composition(array $players, string $gametype, string $category): ?string
{
    $expected = team_size_for($gametype);
    if ($expected === 0) {
        return 'Άγνωστος τύπος πρωταθλήματος.';
    }
    if (count($players) !== $expected) {
        return "Η ομάδα πρέπει να έχει ακριβώς $expected παίκτες.";
    }
    // Unique
    $codes = array_column($players, 'playercode');
    if (count(array_unique($codes)) !== count($codes)) {
        return 'Δεν μπορείτε να επιλέξετε τον ίδιο παίκτη δύο φορές.';
    }
    // Ίδιος σύλλογος
    $clubs = array_unique(array_column($players, 'clubcode'));
    if (count($clubs) !== 1) {
        return 'Όλοι οι παίκτες πρέπει να ανήκουν στον ίδιο σύλλογο.';
    }
    // Κατηγορία φύλου
    $genders = array_column($players, 'gender');
    sort($genders);
    if ($category === 'M') {
        if (in_array('F', $genders, true)) {
            return 'Η κατηγορία Ανδρών δέχεται μόνο άνδρες.';
        }
    } elseif ($category === 'F') {
        if (in_array('M', $genders, true)) {
            return 'Η κατηγορία Γυναικών δέχεται μόνο γυναίκες.';
        }
    } elseif ($category === 'MIX') {
        if ($gametype !== 'Mixed') {
            return 'Μεικτή κατηγορία επιτρέπεται μόνο σε Μεικτό πρωτάθλημα.';
        }
        if ($genders !== ['F', 'M']) {
            return 'Η μεικτή ντουμπλέτα πρέπει να έχει 1 άνδρα και 1 γυναίκα.';
        }
    }
    return null;
}

/**
 * Δημιουργεί το teamcode prefix για games2 εγγραφές ανάλογα με κατηγορία.
 *   M   -> 'ath'      (ath1, ath2, ...)
 *   F   -> 'athXw'    (ath1w, ath2w, ...)  [το w στο τέλος]
 *   MIX -> 'mix'      (mix1, mix2, ...)
 */
function teamcode_for(string $category, int $teamNumber): string
{
    return match ($category) {
        'M'   => 'ath' . $teamNumber,
        'F'   => 'ath' . $teamNumber . 'w',
        'MIX' => 'mix' . $teamNumber,
        default => 'ath' . $teamNumber,
    };
}

/**
 * Teamname για τον πίνακα teams (π.χ. GAL1, GAL1w, GALmix1)
 */
function teamname_for(string $clubShort, string $category, int $teamNumber): string
{
    $clubShort = preg_replace('/[^A-Z0-9]/', '', strtoupper($clubShort ?: 'CLUB')) ?: 'CLUB';
    return match ($category) {
        'M'   => $clubShort . $teamNumber,
        'F'   => $clubShort . $teamNumber . 'w',
        'MIX' => $clubShort . 'mix' . $teamNumber,
        default => $clubShort . $teamNumber,
    };
}

/**
 * Ελέγχει αν πέρασε η προθεσμία δήλωσης.
 */
function is_registration_open(array $game): bool
{
    if (($game['status'] ?? 'N') !== 'Y') {
        return false;
    }
    $deadline = $game['registration_deadline'] ?? null;
    if (!$deadline) {
        return true;
    }
    return strtotime($deadline) > time();
}
