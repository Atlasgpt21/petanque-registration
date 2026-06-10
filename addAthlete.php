<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include 'db.php';
require_once __DIR__ . '/login/auth_guard.php';
enforceSameOrigin();
requireAuth();
requireManagerOrAdmin();

header('Content-Type: application/json; charset=utf-8');
ob_clean();

/* =========================
CONFIG
========================= */
$key = "helleniquepetanquefederation";
$iv  = "1234567891011121";
$cipher = "AES-256-CTR";

date_default_timezone_set('Europe/Athens');
$actdatetime = date('Y-m-d H:i:s');

/* =========================
POST DATA (FULL)
========================= */
$firstname   = $_POST['firstname'] ?? '';
$lastname    = $_POST['lastname'] ?? '';
$father      = $_POST['father'] ?? '';
$mother      = $_POST['mother'] ?? '';
$address     = $_POST['address'] ?? '';
$city        = $_POST['city'] ?? '';
$birthday    = $_POST['birthday'] ?? '';
$birthplace  = $_POST['birthplace'] ?? '';
$nationality = $_POST['nationality'] ?? '';
$gender      = $_POST['gender'] ?? '';
$job         = $_POST['job'] ?? '';
$phone       = $_POST['phone'] ?? '';
$email       = $_POST['email3'] ?? '';
$amka        = $_POST['amka'] ?? '';
$idcard      = $_POST['id'] ?? '';
$clubcode    = $_POST['clubmitroo1'] ?? '';

/* SCOPE: lvl1 → πάντα δικό του clubcode (override input) */
if (!canSeeAll()) {
    $clubcode = userClubcode();
}

/* =========================
VALIDATION
========================= */
if(!$firstname || !$lastname){
    echo json_encode(["status"=>"error","msg"=>"Συμπληρώστε Όνομα και Επώνυμο"]);
    exit;
}

// Birthday format validation: must be dd/mm/yyyy (4-digit year)
if (!empty($birthday)) {
    // Reject 2-digit year (e.g. 23/02/03)
    if (preg_match('#^\d{1,2}/\d{1,2}/\d{2}$#', $birthday)) {
        echo json_encode(["status"=>"error","msg"=>"Η ημερομηνία γέννησης πρέπει να έχει 4-ψήφιο έτος (π.χ. 23/02/2003)"]);
        exit;
    }
    // Must match dd/mm/yyyy
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $birthday, $m)) {
        echo json_encode(["status"=>"error","msg"=>"Μη έγκυρη μορφή ημερομηνίας. Χρησιμοποιήστε ηη/μμ/εεεε (π.χ. 23/02/2003)"]);
        exit;
    }
    $day = (int)$m[1]; $month = (int)$m[2]; $year = (int)$m[3];
    // Check valid date
    if (!checkdate($month, $day, $year)) {
        echo json_encode(["status"=>"error","msg"=>"Η ημερομηνία γέννησης δεν είναι έγκυρη"]);
        exit;
    }
    // Check not in the future
    $bdObj = DateTime::createFromFormat('d/m/Y', $birthday);
    if ($bdObj && $bdObj > new DateTime()) {
        echo json_encode(["status"=>"error","msg"=>"Η ημερομηνία γέννησης δεν μπορεί να είναι μελλοντική"]);
        exit;
    }
    // Check reasonable year range (1900 - current year)
    if ($year < 1900 || $year > (int)date('Y')) {
        echo json_encode(["status"=>"error","msg"=>"Μη αποδεκτό έτος γέννησης. Πρέπει να είναι μεταξύ 1900 και " . date('Y')]);
        exit;
    }
}

/* =========================
ENCRYPT FUNCTION
========================= */
function enc($d,$cipher,$key,$iv){
    return openssl_encrypt($d,$cipher,$key,0,$iv);
}

/* =========================
INSERT FULL
========================= */
$stmt = $conn->prepare("
INSERT INTO sportsmen 
(name, lastname, father, mother, address, city, placebirth, birthday, nationality, gender, work, phone, email, amka, idcard, registrationdate, clubcode)

VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$name_e        = enc($firstname,$cipher,$key,$iv);
$lastname_e     = enc($lastname,$cipher,$key,$iv);
$father_e       = enc($father,$cipher,$key,$iv);
$mother_e       = enc($mother,$cipher,$key,$iv);
$address_e      = enc($address,$cipher,$key,$iv);
$city_e         = enc($city,$cipher,$key,$iv);
$birthplace_e   = enc($birthplace,$cipher,$key,$iv);
$birthday_e     = enc($birthday,$cipher,$key,$iv);
$nationality_e  = enc($nationality,$cipher,$key,$iv);
$job_e          = enc($job,$cipher,$key,$iv);
$phone_e        = enc($phone,$cipher,$key,$iv);
$email_e        = enc($email,$cipher,$key,$iv);
$amka_e         = enc($amka,$cipher,$key,$iv);
$idcard_e       = enc($idcard,$cipher,$key,$iv);

$stmt->bind_param(
"sssssssssssssssss",
$name_e,
$lastname_e,
$father_e,
$mother_e,
$address_e,
$city_e,
$birthplace_e,
$birthday_e,
$nationality_e,
$gender,
$job_e,
$phone_e,
$email_e,
$amka_e,
$idcard_e,
$actdatetime,
$clubcode
);

$stmt->execute();

/* =========================
AAID
========================= */
$aaid = mysqli_insert_id($conn);

/* =========================
RETURN JSON (CRITICAL)
========================= */
echo json_encode([
    "status" => "ok",
    "aaid" => $aaid
]);
// Ανακατεύθυνση στο δεύτερο PHP αρχείο για τη δημιουργία του PDF
exit;
?>