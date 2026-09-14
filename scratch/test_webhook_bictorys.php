<?php
require_once __DIR__ . '/../config/database.php';

echo "=== TEST WEBHOOK BICTORYS EXECUTION ===\n";

// Créons une fausse commande de test en attente
$numero = 'CMD-TEST-BIC-' . time();
$stmt = $pdo->prepare("
    INSERT INTO orders (user_id, client_nom, client_email, client_telephone, numero_commande, montant_total, statut, created_at, updated_at) 
    VALUES (NULL, 'Test Bictorys Webhook', 'webhook@test.com', '0701020304', ?, 2500, 'en_attente', NOW(), NOW())
    RETURNING id
");
$stmt->execute([$numero]);
$order_id = (int) $stmt->fetchColumn();
echo "Commande de test créée avec ID #$order_id ($numero)\n";

// Simuler l'appel webhook vers client/webhook-bictorys.php
$webhookPayload = json_encode([
    'event'            => 'charge.success',
    'type'             => 'charge.success',
    'transactionId'    => 'BICTXN-' . uniqid(),
    'paymentReference' => 'ORDER_' . $order_id,
    'status'           => 'success',
    'amount'           => 2500,
    'payment_type'     => 'wave'
]);

$ch = curl_init('http://localhost/ticket-platform/client/webhook-bictorys.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $webhookPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Secret-Key: test'
    ],
    CURLOPT_TIMEOUT        => 10
]);

$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $resp\n";

// Vérifions en BDD si la commande est bien passée en 'paye'
$stmt2 = $pdo->prepare("SELECT statut, mode_paiement FROM orders WHERE id = ?");
$stmt2->execute([$order_id]);
$order_after = $stmt2->fetch();
echo "Statut commande après webhook: " . $order_after['statut'] . " (mode: " . $order_after['mode_paiement'] . ")\n";

// Vérifions si le paiement a été inséré
$stmt3 = $pdo->prepare("SELECT id, reference, methode, montant, statut FROM payments WHERE order_id = ?");
$stmt3->execute([$order_id]);
$pay_row = $stmt3->fetch();
if ($pay_row) {
    echo "Paiement inséré: ID #{$pay_row['id']} | Ref: {$pay_row['reference']} | Methode: {$pay_row['methode']} | Statut: {$pay_row['statut']}\n";
} else {
    echo "Paiement non trouvé en BDD!\n";
}

// Nettoyage de la commande de test
$pdo->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$order_id]);
$pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$order_id]);
echo "Nettoyage de la commande de test effectué.\n";
