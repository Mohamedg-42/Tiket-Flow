<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/secure_token.php';

$stmt = $pdo->query("SELECT id, campagne_id, montant FROM cotisations WHERE statut = 'en_attente' ORDER BY id DESC LIMIT 1");
$cot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cot) {
    $pdo->query("INSERT INTO cotisations (nom, email, telephone, montant, statut) VALUES ('Donateur Test', 'donateur@test.com', '0701020304', 5000, 'en_attente')");
    $cotId = (int)$pdo->lastInsertId();
} else {
    $cotId = (int)$cot['id'];
}

$token = get_or_create_resource_token($pdo, 'cotisation_payment', $cotId);
echo "Cotisation ID: $cotId | Token: $token\n";

$resolved = resolve_resource_token($pdo, $token, 'cotisation_payment');
echo "Resolved: $resolved\n";

$callbackUrl = 'http://localhost/client/callback-cotisation.php?cotisation_id=' . $cotId . '&methode=bictorys';
$errorUrl = 'http://localhost/client/paiement-cotisation.php?token=' . urlencode($token) . '&error=1';

$chargeParams = [
    'amount' => 5000,
    'paymentReference' => 'COT_' . $cotId,
    'successRedirectUrl' => $callbackUrl,
    'errorRedirectUrl' => $errorUrl,
    'country' => 'CI',
    'customer' => ['name' => 'Donateur', 'phone' => '0701020304', 'email' => 'don@test.com'],
    'payment_type' => 'wave_money'
];

$charge = bictorys_create_charge($chargeParams);
echo "Charge success: " . ($charge['success'] ? 'YES' : 'NO') . "\n";
echo "Redirect URL: " . ($charge['redirectUrl'] ?? '') . "\n";
