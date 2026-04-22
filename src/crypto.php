<?php
/**
 * Crypto helpers για συμβατότητα με το υπάρχον σύστημα της ΕΟΠ
 * που κρυπτογραφεί κάποιες στήλες (π.χ. clubs.name, sportsmen.name/lastname)
 * με AES-256-CTR και base64 output.
 *
 * Config keys (σε config.php):
 *   'crypto' => [
 *       'enabled' => true,
 *       'method'  => 'AES-256-CTR',
 *       'key'     => 'helleniquepetanquefederation',
 *       'iv'      => '1234567891011121',
 *   ]
 */

declare(strict_types=1);

/**
 * Αποκρυπτογραφεί ένα base64-encoded ciphertext. Αν το input δεν μοιάζει
 * encrypted (π.χ. είναι ήδη plaintext, κενό, ή null), επιστρέφεται ως έχει.
 */
function hpf_decrypt(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    $cfg = $GLOBALS['CONFIG']['crypto'] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return $value;
    }
    // Heuristic: το base64 του υπάρχοντος συστήματος έχει μόνο [A-Za-z0-9+/=].
    // Αν υπάρχουν άλλοι χαρακτήρες (π.χ. Ελληνικά), είναι ήδη plaintext.
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
        return $value;
    }
    $plain = @openssl_decrypt(
        $value,
        $cfg['method'] ?? 'AES-256-CTR',
        $cfg['key']    ?? '',
        0,
        $cfg['iv']     ?? ''
    );
    if ($plain === false) {
        return $value;
    }
    // Αν το decrypted είναι valid UTF-8, το επιστρέφουμε· αλλιώς το input
    // δεν ήταν encrypted (ή το key/iv είναι λάθος).
    if (function_exists('mb_check_encoding') && !mb_check_encoding($plain, 'UTF-8')) {
        return $value;
    }
    return $plain;
}

/**
 * Κρυπτογραφεί plaintext → base64 ciphertext συμβατό με το υπάρχον σύστημα.
 */
function hpf_encrypt(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    $cfg = $GLOBALS['CONFIG']['crypto'] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return $value;
    }
    $enc = @openssl_encrypt(
        $value,
        $cfg['method'] ?? 'AES-256-CTR',
        $cfg['key']    ?? '',
        0,
        $cfg['iv']     ?? ''
    );
    return $enc === false ? $value : $enc;
}

/**
 * Αποκρυπτογραφεί συγκεκριμένες στήλες ενός row (associative array).
 */
function hpf_decrypt_row(array $row, array $cols): array
{
    foreach ($cols as $c) {
        if (array_key_exists($c, $row)) {
            $row[$c] = hpf_decrypt($row[$c] === null ? null : (string)$row[$c]);
        }
    }
    return $row;
}

/**
 * Αποκρυπτογραφεί συγκεκριμένες στήλες σε λίστα rows.
 */
function hpf_decrypt_rows(array $rows, array $cols): array
{
    foreach ($rows as &$r) {
        $r = hpf_decrypt_row($r, $cols);
    }
    unset($r);
    return $rows;
}

/**
 * Encrypted columns ανά "λογικό" πίνακα (όπως τον βλέπει το app μας).
 * Ενημερώνονται από το config αν θέλουμε να τα παραμετροποιήσουμε.
 */
function hpf_encrypted_cols(string $logical): array
{
    $defaults = [
        'clubs'   => ['name', 'city'],               // clubs.name, city encrypted (edra/president δεν εκτίθενται στο VIEW)
        'players' => ['firstname', 'lastname'],      // sportsmen.name→firstname, sportsmen.lastname
    ];
    $fromCfg = $GLOBALS['CONFIG']['crypto']['columns'][$logical] ?? null;
    return is_array($fromCfg) ? $fromCfg : ($defaults[$logical] ?? []);
}
