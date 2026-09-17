<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/secure_token.php';

echo "========================================================\n";
echo "       TEST SUITE : VALIDATION BOUTONS DE PAIEMENT      \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test($label, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $label\n";
        if ($details) echo "        -> $details\n";
        $passed++;
    } else {
        echo " [FAIL] $label\n";
        if ($details) echo "        -> $details\n";
        $failed++;
    }
}

// -----------------------------------------------------------
// TEST 1: Paiement Commande Billet (client/paiement.php)
// -----------------------------------------------------------
echo "1. Test Paiement Commande Billet (client/paiement.php) :\n";
$stmt = $pdo->query("SELECT id FROM events WHERE statut = 'actif' LIMIT 1");
$eventId = (int)$stmt->fetchColumn();

// Créer une commande de test
$pdo->prepare("
    INSERT INTO orders (client_nom, client_email, client_telephone, numero_commande, montant_total, statut)
    VALUES ('Client Suite', 'suite@test.com', '0701020304', 'CMD-SUITE-" . time() . "', 5000, 'en_attente')
")->execute();
$testOrderId = (int)$pdo->lastInsertId();
$orderToken = get_or_create_resource_token($pdo, 'order', $testOrderId);

assert_test("Commande créée et jeton sécurisé généré", !empty($orderToken) && $testOrderId > 0, "Token: $orderToken (#$testOrderId)");

// Résolution du token
$resolvedId = resolve_resource_token($pdo, $orderToken, 'order');
assert_test("Résolution du jeton de commande", $resolvedId === $testOrderId, "Résolu: #$resolvedId");

// Test soumission POST Bictorys
$chargeOrder = bictorys_create_charge([
    'amount' => 5000,
    'paymentReference' => 'ORDER_' . $testOrderId,
    'successRedirectUrl' => 'http://localhost/client/callback.php?order_id=' . $testOrderId,
    'errorRedirectUrl' => 'http://localhost/client/paiement.php?token=' . urlencode($orderToken) . '&error=1',
    'country' => 'CI',
    'customer' => ['name' => 'Client Suite', 'phone' => '0701020304', 'email' => 'suite@test.com'],
    'payment_type' => 'wave_money'
]);
assert_test("Initialisation charge Bictorys (Wave)", $chargeOrder['success'] === true && !empty($chargeOrder['redirectUrl']), "URL: " . ($chargeOrder['redirectUrl'] ?? ''));


// -----------------------------------------------------------
// TEST 2: Vote Payant (client/vote-event.php + paiement-vote.php)
// -----------------------------------------------------------
echo "\n2. Test Vote Payant (vote-event.php & paiement-vote.php) :\n";
$stmt_cand = $pdo->query("SELECT id FROM event_candidats WHERE event_id = $eventId LIMIT 1");
$candId = (int)$stmt_cand->fetchColumn();

$refVote = 'VOTE-' . strtoupper(substr(uniqid(), -6));
$stmt_v = $pdo->prepare("
    INSERT INTO vote_paiements (event_id, candidat_id, candidats_ids, user_id, visitor_id, telephone, montant, methode, reference, statut)
    VALUES (?, ?, ?, NULL, 'visitor_test', '0701020304', 1000, NULL, ?, 'en_attente')
");
$stmt_v->execute([$eventId, $candId ?: null, $candId ? json_encode([$candId]) : null, $refVote]);
$votePayId = (int)$pdo->lastInsertId();
assert_test("Insertion vote_paiements (execute + lastInsertId)", $votePayId > 0, "Vote Payment ID: #$votePayId");

$voteToken = get_or_create_resource_token($pdo, 'vote_payment', $votePayId);
assert_test("Génération jeton sécurisé vote_payment", !empty($voteToken), "Token: $voteToken");

$chargeVote = bictorys_create_charge([
    'amount' => 1000,
    'paymentReference' => 'VOTE_' . $votePayId,
    'successRedirectUrl' => 'http://localhost/client/callback-vote.php?vote_paiement_id=' . $votePayId,
    'errorRedirectUrl' => 'http://localhost/client/paiement-vote.php?token=' . urlencode($voteToken) . '&error=1',
    'country' => 'CI',
    'customer' => ['name' => 'Électeur Test', 'phone' => '0701020304', 'email' => 'electeur@test.com'],
    'payment_type' => 'orange_money'
]);
assert_test("Initialisation charge Bictorys Vote (Orange Money)", $chargeVote['success'] === true && !empty($chargeVote['redirectUrl']), "URL: " . ($chargeVote['redirectUrl'] ?? ''));


// -----------------------------------------------------------
// TEST 3: Cotisation / Don (client/paiement-cotisation.php)
// -----------------------------------------------------------
echo "\n3. Test Cotisation / Don (paiement-cotisation.php) :\n";
$pdo->prepare("
    INSERT INTO cotisations (nom, email, telephone, montant, statut)
    VALUES ('Donateur Suite', 'don@test.com', '0701020304', 3000, 'en_attente')
")->execute();
$cotId = (int)$pdo->lastInsertId();
$cotToken = get_or_create_resource_token($pdo, 'cotisation_payment', $cotId);

assert_test("Insertion cotisation et jeton sécurisé", $cotId > 0 && !empty($cotToken), "Token: $cotToken (#$cotId)");

$chargeCot = bictorys_create_charge([
    'amount' => 3000,
    'paymentReference' => 'COT_' . $cotId,
    'successRedirectUrl' => 'http://localhost/client/callback-cotisation.php?cotisation_id=' . $cotId,
    'errorRedirectUrl' => 'http://localhost/client/paiement-cotisation.php?token=' . urlencode($cotToken) . '&error=1',
    'country' => 'CI',
    'customer' => ['name' => 'Donateur Suite', 'phone' => '0701020304', 'email' => 'don@test.com'],
    'payment_type' => 'mtn_money'
]);
assert_test("Initialisation charge Bictorys Cotisation (MTN MoMo)", $chargeCot['success'] === true && !empty($chargeCot['redirectUrl']), "URL: " . ($chargeCot['redirectUrl'] ?? ''));


// -----------------------------------------------------------
// TEST 4: Contrôle syntaxe & lint des fichiers modifiés
// -----------------------------------------------------------
echo "\n4. Contrôle syntaxique PHP des fichiers modifiés :\n";
$files = [
    'paiement.php',
    'vote-event.php',
    'paiement-vote.php',
    'paiement-cotisation.php',
    'commander.php',
    'accueil.php',
    'mes-commandes.php',
    '../connexion.php'
];
foreach ($files as $f) {
    $full = __DIR__ . '/../client/' . $f;
    if ($f === '../connexion.php') $full = __DIR__ . '/../connexion.php';
    $output = [];
    $ret = 0;
    exec("php -l \"$full\" 2>&1", $output, $ret);
    assert_test("Syntaxe : " . basename($full), $ret === 0, implode(' ', $output));
}

echo "\n========================================================\n";
echo " RÉSULTATS : $passed PASSÉS / $failed ÉCHOUÉS\n";
echo "========================================================\n";
