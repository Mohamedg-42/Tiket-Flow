<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$order_id = 87;
$sec_token = get_or_create_resource_token($pdo, 'order', $order_id);

echo "=== TEST ENDPOINT INITIER_PAIEMENT AJAX ===" . PHP_EOL;

$_GET = ['token' => $sec_token];
$_POST = [
    'initier_paiement' => '1',
    'provider' => 'wave_money',
    'phone' => '+2250596569054',
    'ajax' => '1'
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/client/paiement.php';

ob_start();
chdir(__DIR__ . '/../client');
include __DIR__ . '/../client/paiement.php';
$json = ob_get_clean();

echo "Response raw: " . $json . PHP_EOL;
$data = json_decode($json, true);

if (is_array($data) && !empty($data['success']) && !empty($data['transactionId'])) {
    echo "INITIER AJAX: PASS" . PHP_EOL;
    $txId = $data['transactionId'];
    
    echo "=== TEST ENDPOINT CONFIRMER SIMULATION AJAX ===" . PHP_EOL;
    $_POST = [
        'confirmer_simulation_bictorys' => '1',
        'transaction_id' => $txId
    ];
    ob_start();
    include __DIR__ . '/../client/paiement.php';
    $json2 = ob_get_clean();
    echo "Confirm response: " . $json2 . PHP_EOL;
    $data2 = json_decode($json2, true);
    if (is_array($data2) && !empty($data2['success'])) {
        echo "CONFIRMER AJAX: PASS" . PHP_EOL;
    } else {
        echo "CONFIRMER AJAX: FAIL" . PHP_EOL;
    }
} else {
    echo "INITIER AJAX: FAIL" . PHP_EOL;
}
