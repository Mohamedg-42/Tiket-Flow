<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/secure_token.php';

$ordId = 87;
$token = get_or_create_resource_token($pdo, 'order', $ordId);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/client/paiement.php';
$_GET = ['token' => $token];
$_POST = [
    'initier_paiement' => '1',
    'provider' => 'wave_money',
    'phone' => '0701020304'
];

$test_token = trim((string) ($_GET['token'] ?? ''));
$order_id = null;

if (!empty($test_token)) {
    $order_id = resolve_resource_token($pdo, $test_token, 'order');
} elseif (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $order_id = (int) $_GET['order_id'];
}

echo "Resolved order_id: $order_id\n";

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

echo "Order found: " . ($order ? 'YES' : 'NO') . " | Statut: " . ($order['statut'] ?? '') . "\n";

$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_secret_test';
$pay_token = hash_hmac('sha256', $order_id . '|' . $order['montant_total'] . '|' . $order['created_at'], $pay_secret);

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$callbackUrl = $baseUrl . '/callback.php?order_id=' . $order_id . '&methode=bictorys&pay_token=' . $pay_token;
$errorUrl = $baseUrl . '/paiement.php?token=' . urlencode($test_token) . '&error=1';

$chargeParams = [
    'amount' => (int) round($order['montant_total']),
    'paymentReference' => 'ORDER_' . $order_id,
    'successRedirectUrl' => $callbackUrl,
    'errorRedirectUrl' => $errorUrl,
    'country' => 'CI',
    'customer' => [
        'name' => $order['client_nom'] ?: 'Client',
        'phone' => '0701020304',
        'email' => $order['client_email'] ?: ''
    ],
    'payment_type' => 'wave_money'
];

$charge = bictorys_create_charge($chargeParams);
echo "Charge success: " . ($charge['success'] ? 'YES' : 'NO') . "\n";
echo "Redirect URL: " . ($charge['redirectUrl'] ?? '') . "\n";
echo "Error (if any): " . ($charge['error'] ?? 'none') . "\n";
