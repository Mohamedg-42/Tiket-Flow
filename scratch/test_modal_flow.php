<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/secure_token.php';

// Test of the AJAX initiation logic
$order_id = 87;
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

$pay_secret = APP_SECRET_KEY;
$pay_token = hash_hmac('sha256', $order_id . '|' . $order['montant_total'] . '|' . $order['created_at'], $pay_secret);

$callbackUrl = 'http://localhost/client/callback.php?order_id=' . $order_id . '&methode=bictorys&pay_token=' . $pay_token;
$errorUrl = 'http://localhost/client/paiement.php?error=1';

$chargeParams = [
    'amount' => (int) round($order['montant_total']),
    'paymentReference' => 'ORDER_' . $order_id,
    'successRedirectUrl' => $callbackUrl,
    'errorRedirectUrl' => $errorUrl,
    'country' => 'CI',
    'customer' => [
        'name' => $order['client_nom'] ?: 'Client',
        'phone' => '+2250596569054',
        'email' => $order['client_email'] ?: 'client@example.com'
    ],
    'payment_type' => 'wave_money'
];

$charge = bictorys_create_charge($chargeParams);

echo "Charge success: " . ($charge['success'] ? 'true' : 'false') . PHP_EOL;
echo "Transaction ID: " . ($charge['transactionId'] ?? 'None') . PHP_EOL;
echo "Redirect URL: " . ($charge['redirectUrl'] ?? 'None') . PHP_EOL;

if ($charge['success'] && !empty($charge['transactionId'])) {
    $txId = $charge['transactionId'];
    $confirmUrl = 'https://api.test.bictorys.com/simulator/v1/confirm?transaction_id=' . urlencode($txId);
    $ch = curl_init($confirmUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "Confirm HTTP code: " . $code . PHP_EOL;
    echo "Confirm response length: " . strlen($resp) . PHP_EOL;
    echo "Contains 'successfully proceed': " . (strpos($resp, 'successfully proceed') !== false ? 'YES' : 'NO') . PHP_EOL;
}
