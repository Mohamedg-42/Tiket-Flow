<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

echo "=== TEST REDIRECTION CALLBACK -> TÉLÉCHARGEMENT TICKETS ===" . PHP_EOL;

// 1. Trouver ou créer une commande pour le test
$stmt = $pdo->prepare("SELECT id FROM orders WHERE statut = 'payee' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$order_id = $stmt->fetchColumn();

if (!$order_id) {
    die("Aucune commande payée trouvée pour le test." . PHP_EOL);
}

$sec_token = get_or_create_resource_token($pdo, 'order', (int)$order_id);
echo "Commande ID: $order_id | Token: $sec_token" . PHP_EOL;

// 2. Tester l'accès à telecharger-ticket.php avec token et payment_success=1
$_GET = [
    'token' => $sec_token,
    'payment_success' => '1'
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/client/telecharger-ticket.php';

ob_start();
chdir(__DIR__ . '/../client');
include __DIR__ . '/../client/telecharger-ticket.php';
$html = ob_get_clean();

echo "Page telecharger-ticket length: " . strlen($html) . PHP_EOL;

$has_success_banner = strpos($html, 'Paiement Validé avec Succès !') !== false;
$has_tickets = strpos($html, 'ticket-card') !== false;
$has_print_btn = strpos($html, 'Télécharger en PDF') !== false;

echo "Success banner present: " . ($has_success_banner ? 'PASS' : 'FAIL') . PHP_EOL;
echo "Ticket cards present: " . ($has_tickets ? 'PASS' : 'FAIL') . PHP_EOL;
echo "PDF download / print button present: " . ($has_print_btn ? 'PASS' : 'FAIL') . PHP_EOL;

if ($has_success_banner && $has_tickets && $has_print_btn) {
    echo "=== TOUS LES TESTS DE REDIRECTION ET AFFICHAGE ONT RÉUSSI ===" . PHP_EOL;
} else {
    echo "=== CERTAINS TESTS ONT ÉCHOUÉ ===" . PHP_EOL;
}
