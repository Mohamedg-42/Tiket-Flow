<?php
require_once __DIR__ . '/../config/bictorys.php';

$res = bictorys_create_charge([
    'amount' => 500,
    'paymentReference' => 'TEST_URL_' . time(),
    'successRedirectUrl' => 'http://localhost/ticket-platform/client/callback.php?test=1',
    'errorRedirectUrl' => 'http://localhost/ticket-platform/client/paiement.php?test=1',
    'payment_type' => 'wave_money',
    'customer' => ['name' => 'Test', 'phone' => '0701020304']
]);

echo "Success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
echo "Error: " . ($res['error'] ?? 'none') . "\n";
echo "RedirectUrl: " . ($res['redirectUrl'] ?? '') . "\n";
