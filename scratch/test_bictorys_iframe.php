<?php
require_once __DIR__ . '/../config/bictorys.php';

$p = [
    'amount' => 5000,
    'paymentReference' => 'TEST_' . time(),
    'successRedirectUrl' => 'http://localhost/client/callback.php',
    'errorRedirectUrl' => 'http://localhost/client/paiement.php',
    'country' => 'CI',
    'customer' => ['name' => 'Test', 'phone' => '+2250700000000', 'email' => 'test@example.com'],
    'payment_type' => 'wave_money'
];

$res = bictorys_create_charge($p);
echo "Result success: " . ($res['success'] ? 'true' : 'false') . PHP_EOL;
echo "Redirect URL: " . ($res['redirectUrl'] ?? 'None') . PHP_EOL;

if (!empty($res['redirectUrl'])) {
    $ch = curl_init($res['redirectUrl']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    if ($response === false) {
        echo "Curl error: " . curl_error($ch) . PHP_EOL;
    }
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    echo "HTTP CODE: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL;
    
    echo "=== HEADERS ===" . PHP_EOL;
    echo $headers . PHP_EOL;
    
    echo "=== FULL BODY ===" . PHP_EOL;
    echo $body . PHP_EOL;
}
