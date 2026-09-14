<?php
require_once __DIR__ . '/../config/bictorys.php';

echo "=== TEST BICTORYS API CONNECTION ===\n";
echo "ENV: " . BICTORYS_ENV . "\n";
echo "API URL: " . BICTORYS_API_URL . "\n";
echo "API KEY: " . substr(BICTORYS_API_KEY, 0, 25) . "...\n\n";

$testParams = [
    'amount'             => 500,
    'paymentReference'   => 'TEST-' . time(),
    'successRedirectUrl' => 'https://tikewa.com/client/callback.php?test=1',
    'errorRedirectUrl'   => 'https://tikewa.com/client/paiement.php?test=1',
    'country'            => 'CI',
    'customer'           => [
        'name'  => 'Test Client',
        'phone' => '+2250701234567',
        'email' => 'test@tikewa.com'
    ]
];

echo "Envoi de la requête create_charge...\n";
$result = bictorys_create_charge($testParams);
print_r($result);
