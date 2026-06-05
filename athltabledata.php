<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
require 'db.php';
require_once __DIR__ . '/login/auth_guard.php';
enforceSameOrigin();
requireAuth();

$key = "helleniquepetanquefederation";
$iv  = "1234567891011121";
$options = 0;
$ciphering = "AES-256-CTR";

if (canSeeAll()) {
    $sql = "SELECT * FROM sportsmen WHERE activate!='PD' ORDER BY clubcode ASC";
    $result = $conn->query($sql);
} else {
    $cc = userClubcode();
    $stmt = $conn->prepare("SELECT * FROM sportsmen WHERE activate!='PD' AND clubcode=? ORDER BY clubcode ASC");
    $stmt->bind_param("s", $cc);
    $stmt->execute();
    $result = $stmt->get_result();
}

if (!$result) {
    echo json_encode(['data' => [], 'error' => $conn->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$statusMap = [
    'Y' => 'ΕΝΕΡΓΟΣ',
    'N' => 'ΑΝΕΝΕΡΓΟΣ',
    'D' => 'ΕΛΕΥΘΕΡΟΣ'
];
$statusMap2 = [
    'Y' => 'ΝΑΙ',
    'N' => 'ΟΧΙ'
];

    while ($row = $result->fetch_assoc()) {
    $name = openssl_decrypt($row['name'], $ciphering, $key, $options, $iv) ?: '';
    $lastname = openssl_decrypt($row['lastname'], $ciphering, $key, $options, $iv) ?: '';
    $amka = openssl_decrypt($row['amka'], $ciphering, $key, $options, $iv) ?: '';
    $clubcode1 = $row['clubcode'];
    $birthday1 = openssl_decrypt($row['birthday'], $ciphering, $key, $options, $iv) ?: '';

    // Calculate age and assign category
    $category = '';
    if (!empty($birthday1)) {
        $birthDate = DateTime::createFromFormat('d/m/Y', $birthday1)
                  ?: DateTime::createFromFormat('Y-m-d', $birthday1)
                  ?: DateTime::createFromFormat('d-m-Y', $birthday1);
        if ($birthDate) {
            $today = new DateTime();
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
        }
    }

    if($clubcode1==000000){
    $clubcode='';    
    } else {
    $stmt1 = $conn->prepare("SELECT * FROM clubs WHERE mitroo=?");
    $stmt1->bind_param("s", $clubcode1);
    $stmt1->execute();
    $result1 = $stmt1->get_result();
    while ($row1 = $result1->fetch_assoc()) {
    $clubcode = openssl_decrypt($row1['name'], $ciphering, $key, $options, $iv) ?: '';
    }
    }
    
    $status = $statusMap[trim($row['activate'])] ?? '';
    $ban = $statusMap2[trim($row['ban'])] ?? '';
    
    $data[] = [
        'mitroo'   => $row['mitroo'] ?? '',
        'name'     => $name,
        'lastname' => $lastname,
        'activate' => $status,
        'ban' =>      $ban,
        'amka' =>     $amka,
        'category' =>     $category,
        'clubcode' => $clubcode,
        'aaid'     => $row['aaid'] ?? ''
    ];
}

echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
exit;