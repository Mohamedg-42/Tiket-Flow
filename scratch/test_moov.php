<?php
require_once __DIR__ . '/../config/bictorys.php';

$candidates = ['moov', 'flooz', 'moov_money_ci', 'moov_ci', 'all'];
foreach ($candidates as $t) {
    $res = bictorys_create_charge([
        'amount' => 500,
        'paymentReference' => 'TEST-' . strtoupper($t) . '-' . time(),
        'successRedirectUrl' => 'https://tikewa.com',
        'payment_type' => $t,
        'country' => 'CI',
        'customer' => ['name' => 'Jean', 'phone' => '0701020304', 'email' => 'jean@test.com']
    ]);
    echo "Candidate [$t] => Success: " . ($res['success'] ? '1' : '0') . " | Msg: " . ($res['error'] ?? 'OK') . "\n";
}
