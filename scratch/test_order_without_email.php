<?php
require_once __DIR__ . '/../config/database.php';

// Test creating an order without email directly with the logic of commander.php
$event_id = 1; // Concert Géant Abidjan Live 2026
$client_nom = "Client Sans Email Test";
$client_email = ""; // EMPTY EMAIL
$client_telephone = "0701020304";
$tickets_input = [1 => 1]; // 1 ticket standard

// Verify validation:
if (empty($client_nom)) {
    die("Validation failed on nom\n");
}

$numero_commande = 'CMD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

try {
    $pdo->beginTransaction();
    $sql_order = "INSERT INTO orders (user_id, client_nom, client_email, client_telephone, numero_commande, montant_total, statut) 
                  VALUES (?, ?, ?, ?, ?, ?, 'en_attente')";
    $stmt_order = $pdo->prepare($sql_order);
    $stmt_order->execute([
        null,
        $client_nom,
        !empty($client_email) ? $client_email : null,
        !empty($client_telephone) ? $client_telephone : null,
        $numero_commande,
        5000
    ]);
    $order_id = (int) $pdo->lastInsertId();

    $stmt_fetch = $pdo->prepare("SELECT id, client_nom, client_email, client_telephone, numero_commande, statut FROM orders WHERE id = ?");
    $stmt_fetch->execute([$order_id]);
    $created_order = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

    $pdo->rollBack(); // Don't pollute DB permanently
    echo "SUCCESS! Order created without email:\n";
    print_r($created_order);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
