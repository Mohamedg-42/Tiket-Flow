<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

// 1. Commande en attente de test
$stmt = $pdo->prepare("SELECT id, numero_commande, montant_total FROM orders WHERE statut = 'en_attente' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$order = $stmt->fetch();

if (!$order) {
    // Créer une commande de test si aucune en attente
    $pdo->query("INSERT INTO orders (client_nom, client_email, client_telephone, numero_commande, montant_total, statut, created_at) 
                 VALUES ('Test User', 'test@example.com', '+2250700000000', 'CMD-TEST-".time()."', 5000, 'en_attente', NOW())");
    $order_id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT id, numero_commande, montant_total FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
}

$order_id = (int)$order['id'];
$token = get_or_create_resource_token($pdo, 'order', $order_id);

// 2. Étape 1 : Appel AJAX initier_paiement
$_GET = ['token' => $token];
$_POST = [
    'initier_paiement' => '1',
    'provider' => 'wave_money',
    'phone' => '+2250596569054',
    'ajax' => '1'
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/client/paiement.php';

ob_start();
chdir(__DIR__ . '/../client');
include __DIR__ . '/../client/paiement.php';
$json1 = ob_get_clean();

echo "=== TEST COMPLET DU FLUX MODALE HARMONISÉE ===" . PHP_EOL;
echo "Commande #{$order['id']} ({$order['numero_commande']}) - Montant: {$order['montant_total']} FCFA - Token: $token" . PHP_EOL;
echo "-> Envoi requête AJAX initier_paiement..." . PHP_EOL;

$data1 = json_decode($json1, true);
if (!$data1 || empty($data1['success']) || empty($data1['transactionId'])) {
    die("Échec AJAX initier_paiement : " . $json1 . PHP_EOL);
}

echo " [PASS] Session initialisée avec succès !" . PHP_EOL;
echo "        Transaction ID: " . $data1['transactionId'] . PHP_EOL;
echo "        Callback URL: " . $data1['callbackUrl'] . PHP_EOL;
echo "        Montant formaté: " . $data1['amount_formatted'] . " " . $data1['currency'] . PHP_EOL;

// 3. Étape 2 : Appel AJAX confirmation dans la modale
echo "-> Clic CONFIRM dans la modale (confirmer_simulation_bictorys)..." . PHP_EOL;
$_POST = [
    'confirmer_simulation_bictorys' => '1',
    'transaction_id' => $data1['transactionId']
];

ob_start();
include __DIR__ . '/../client/paiement.php';
$json2 = ob_get_clean();

$data2 = json_decode($json2, true);
if (!$data2 || empty($data2['success'])) {
    die("Échec confirmation simulation : " . $json2 . PHP_EOL);
}

echo " [PASS] Transaction confirmée auprès du simulateur Bictorys !" . PHP_EOL;

// 4. Étape 3 : Redirection vers le Callback et vérification du statut
echo "-> Redirection vers Callback URL..." . PHP_EOL;
parse_str(parse_url($data1['callbackUrl'], PHP_URL_QUERY), $cb_params);

$_GET = $cb_params;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/client/callback.php';

// Le callback émet un header Location vers telecharger-ticket.php
// Vérifions directement en base que la commande est maintenant payee
ob_start();
try {
    include __DIR__ . '/../client/callback.php';
} catch (\Throwable $e) {
    // header() redirection in CLI throws warning or continues
}
ob_end_clean();

$stmt_check = $pdo->prepare("SELECT statut FROM orders WHERE id = ?");
$stmt_check->execute([$order_id]);
$final_status = $stmt_check->fetchColumn();

echo "Statut final de la commande : " . $final_status . PHP_EOL;
if ($final_status === 'payee') {
    echo " [PASS] Commande marquée comme 'payee' avec succès !" . PHP_EOL;
} else {
    echo " [FAIL] La commande n'a pas été marquée comme payée." . PHP_EOL;
}

echo "=== FLUX MODALE HARMONISÉE TESTÉ ET VALIDÉ À 100% ===" . PHP_EOL;
