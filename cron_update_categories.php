<?php
/**
 * Cron job: Ενημέρωση κατηγορίας αθλητών βάσει ηλικίας + Email αναφοράς
 *
 * Διατρέχει όλους τους αθλητές στον πίνακα `sportsmen`,
 * αποκρυπτογραφεί την ημερομηνία γέννησης, υπολογίζει την ηλικία,
 * ενημερώνει τη στήλη `category` και στέλνει αναλυτική αναφορά με email.
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

$mailFrom = "admin@hpf.gr";
$mailTo   = "tmpo21@gmail.com";

$today = new DateTime();
$updated = 0;
$skipped = 0;
$errors  = 0;

$updatedList = [];
$skippedList = [];
$errorList   = [];

$sql = "SELECT aaid, mitroo, name, lastname, birthday FROM sportsmen WHERE activate != 'PD'";
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
    $firstname = openssl_decrypt($row['name'], $ciphering, $key, $options, $iv) ?: '';
    $lastname  = openssl_decrypt($row['lastname'], $ciphering, $key, $options, $iv) ?: '';
    $birthday1 = openssl_decrypt($row['birthday'], $ciphering, $key, $options, $iv) ?: '';
    $mitroo    = $row['mitroo'] ?? '';
    $fullname  = trim("$lastname $firstname");
    if ($fullname === '') {
        $fullname = "aaid={$row['aaid']}";
    }

    if (empty($birthday1)) {
        $skipped++;
        $skippedList[] = "$fullname (Μητρώο: $mitroo) — Κενή ημερομηνία γέννησης";
        continue;
    }

    $birthDate = DateTime::createFromFormat('d/m/Y', $birthday1);
    // Handle 2-digit years (e.g. 23/02/03 parsed as year 0003)
    if ($birthDate && (int)$birthDate->format('Y') < 100) {
        $birthDate = DateTime::createFromFormat('d/m/y', $birthday1);
        if ($birthDate && $birthDate > $today) {
            $birthDate->modify('-100 years');
        }
    }
    if (!$birthDate) {
        $birthDate = DateTime::createFromFormat('Y-m-d', $birthday1)
                  ?: DateTime::createFromFormat('d-m-Y', $birthday1);
    }

    if (!$birthDate) {
        $skipped++;
        $skippedList[] = "$fullname (Μητρώο: $mitroo) — Μη αναγνωρίσιμη ημ/νία: $birthday1";
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
        $updatedList[] = "$fullname (Μητρώο: $mitroo) — Ηλικία: $age → $category";
    } else {
        $errors++;
        $errorList[] = "$fullname (Μητρώο: $mitroo) — Σφάλμα: " . $stmtUpdate->error;
    }
}

$stmtUpdate->close();
$conn->close();

// --- Δημιουργία αναφοράς email ---
$dateStr = $today->format('d/m/Y H:i');
$subject = "=?UTF-8?B?" . base64_encode("Αναφορά ενημέρωσης κατηγοριών αθλητών — $dateStr") . "?=";

$body  = "════════════════════════════════════════\n";
$body .= "  ΑΝΑΦΟΡΑ ΕΝΗΜΕΡΩΣΗΣ ΚΑΤΗΓΟΡΙΩΝ ΑΘΛΗΤΩΝ\n";
$body .= "  Ημερομηνία: $dateStr\n";
$body .= "════════════════════════════════════════\n\n";

$body .= "── ΣΥΝΟΛΙΚΑ ──\n";
$body .= "  Ενημερώθηκαν : $updated\n";
$body .= "  Παραλείφθηκαν: $skipped\n";
$body .= "  Σφάλματα     : $errors\n\n";

if (!empty($updatedList)) {
    $body .= "── ΕΝΗΜΕΡΩΜΕΝΟΙ ΑΘΛΗΤΕΣ ($updated) ──\n";
    foreach ($updatedList as $i => $line) {
        $body .= "  " . ($i + 1) . ". $line\n";
    }
    $body .= "\n";
}

if (!empty($skippedList)) {
    $body .= "── ΠΑΡΑΛΕΙΦΘΕΝΤΕΣ ΑΘΛΗΤΕΣ ($skipped) ──\n";
    foreach ($skippedList as $i => $line) {
        $body .= "  " . ($i + 1) . ". $line\n";
    }
    $body .= "\n";
}

if (!empty($errorList)) {
    $body .= "── ΣΦΑΛΜΑΤΑ ($errors) ──\n";
    foreach ($errorList as $i => $line) {
        $body .= "  " . ($i + 1) . ". $line\n";
    }
    $body .= "\n";
}

$body .= "════════════════════════════════════════\n";
$body .= "Αυτοματοποιημένη αναφορά — HPF Cron System\n";

$headers  = "From: $mailFrom\r\n";
$headers .= "Reply-To: $mailFrom\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: HPF-Cron/1.0\r\n";

if (mail($mailTo, $subject, $body, $headers)) {
    echo date('Y-m-d H:i:s') . " [OK] Email sent to $mailTo\n";
} else {
    echo date('Y-m-d H:i:s') . " [WARNING] Email failed to send to $mailTo\n";
}

echo date('Y-m-d H:i:s') . " [OK] Category update complete. Updated: $updated | Skipped: $skipped | Errors: $errors\n";
exit($errors > 0 ? 1 : 0);
