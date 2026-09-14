<?php
require_once __DIR__ . '/../config/bictorys.php';

echo "=== TEST DIRECT WAVE VS ORANGE ===\n";

$resWave = bictorys_create_charge([
    'amount' => 500,
    'paymentReference' => 'TEST-WAVE-' . time(),
    'successRedirectUrl' => 'https://tikewa.com',
    'payment_type' => 'wave_money',
    'customer' => ['name' => 'Jean', 'phone' => '0701020304', 'email' => 'jean@test.com']
]);
echo "WAVE:\n";
print_r($resWave);

$resOM = bictorys_create_charge([
    'amount' => 500,
    'paymentReference' => 'TEST-OM-' . time(),
    'successRedirectUrl' => 'https://tikewa.com',
    'payment_type' => 'orange_money',
    'customer' => ['name' => 'Jean', 'phone' => '0701020304', 'email' => 'jean@test.com']
]);
echo "ORANGE:\n";
print_r($resOM);
