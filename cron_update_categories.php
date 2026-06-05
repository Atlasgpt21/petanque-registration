<?php
/**
 * Cron job: Ενημέρωση κατηγορίας αθλητών βάσει ηλικίας
 *
 * Διατρέχει όλους τους αθλητές στον πίνακα `sportsmen`,
 * αποκρυπτογραφεί την ημερομηνία γέννησης, υπολογίζει την ηλικία
 * και ενημερώνει τη στήλη `category`.
 *
 * Κατηγορίες:
 *   Benjamin : ≤8
 *   Minime   : 9-11
 *   Cadet    : 12-14
 *   Junior   : 15-17
 *   Senior   : 18-55
 *   Veteran  : ≥56
 *
 * Χρήση:
 *   php cron_update_categories.php
 *
 * Crontab (π.χ. κάθε μέρα στις 03:00):
 *   0 3 * * * /usr/bin/php /path/to/cron_update_categories.php >> /path/to/cron.log 2>&1
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require __DIR__ . '/db.php';

$key = "helleniquepetanquefederation";
$iv  = "1234567891011121";
$options = 0;
$ciphering = "AES-256-CTR";

$today = new DateTime();
$updated = 0;
$skipped = 0;
$errors  = 0;

$sql = "SELECT aaid, birthday FROM sportsmen WHERE activate != 'PD'";
$result = $conn->query($sql);

if (!$result) {
    echo date('Y-m-d H:i:s') . " [ERROR] Query failed: " . $conn->error . "\n";
    exit(1);
}

$stmtUpdate = $conn->prepare("UPDATE sportsmen SET category = ? WHERE aaid = ?");
if (!$stmtUpdate) {
    echo date('Y-m-d H:i:s') . " [ERROR] Prepare failed: " . $conn->error . "\n";
    exit(1);
}

while ($row = $result->fetch_assoc()) {
    $birthday1 = openssl_decrypt($row['birthday'], $ciphering, $key, $options, $iv) ?: '';

    if (empty($birthday1)) {
        $skipped++;
        continue;
    }

    $birthDate = DateTime::createFromFormat('d/m/Y', $birthday1)
              ?: DateTime::createFromFormat('Y-m-d', $birthday1)
              ?: DateTime::createFromFormat('d-m-Y', $birthday1);

    if (!$birthDate) {
        $skipped++;
        continue;
    }

    $age = (int)$today->diff($birthDate)->y;

    if ($age <= 8) {
        $category = 'Benjamin';
    } elseif ($age <= 11) {
        $category = 'Minime';
    } elseif ($age <= 14) {
        $category = 'Cadet';
    } elseif ($age <= 17) {
        $category = 'Junior';
    } elseif ($age <= 55) {
        $category = 'Senior';
    } else {
        $category = 'Veteran';
    }

    $stmtUpdate->bind_param("si", $category, $row['aaid']);
    if ($stmtUpdate->execute()) {
        $updated++;
    } else {
        $errors++;
        echo date('Y-m-d H:i:s') . " [ERROR] Update failed for aaid=" . $row['aaid'] . ": " . $stmtUpdate->error . "\n";
    }
}

$stmtUpdate->close();
$conn->close();

echo date('Y-m-d H:i:s') . " [OK] Category update complete. Updated: $updated | Skipped: $skipped | Errors: $errors\n";
exit($errors > 0 ? 1 : 0);
