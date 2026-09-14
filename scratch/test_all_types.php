<?php
require_once __DIR__ . '/../config/bictorys.php';

$types = ['wave_money', 'orange_money', 'mtn_money', 'moov_money', 'card'];
foreach ($types as $t) {
    $res = bictorys_create_charge([
        'amount' => 500,
        'paymentReference' => 'TEST-' . strtoupper($t) . '-' . time(),
        'successRedirectUrl' => 'https://tikewa.com/client/callback.php',
        'errorRedirectUrl' => 'https://tikewa.com/client/paiement.php',
        'payment_type' => $t,
        'country' => 'CI',
        'customer' => ['name' => 'Jean Dupont', 'phone' => '0701020304', 'email' => 'jean@test.com']
    ]);
    echo "Type [$t] => Success: " . ($res['success'] ? '1' : '0') . " | Error: " . ($res['error'] ?? 'none') . " | Link: " . ($res['redirectUrl'] ?? '') . "\n";
}
