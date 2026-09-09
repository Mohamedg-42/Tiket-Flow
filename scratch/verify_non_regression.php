<?php
// Tests de non-régression pour client/accueil.php
chdir(__DIR__ . '/../client');
require_once '../config/database.php';

function runTest($title, $getParams, $sessionData = []) {
    global $pdo;
    $_GET = $getParams;
    $_SESSION = $sessionData;

    ob_start();
    try {
        include 'accueil.php';
        $output = ob_get_clean();
        $ok = true;
        $errors = [];

        // Vérifications de base
        if (empty($output)) {
            $ok = false;
            $errors[] = "Sortie HTML vide";
        }
        if (strpos($output, 'Fatal error') !== false || strpos($output, 'Parse error') !== false) {
            $ok = false;
            $errors[] = "Présence d'erreur PHP dans la page";
        }
        
        // Vérification de la présence des éléments clés
        if (strpos($output, 'client-header') === false) {
            $ok = false;
            $errors[] = "Header absent";
        }
        if (strpos($output, 'main-tabs') === false) {
            $ok = false;
            $errors[] = "Onglets principaux absents";
        }

        if ($ok) {
            echo "  [PASS] $title (" . strlen($output) . " octets)\n";
        } else {
            echo "  [FAIL] $title : " . implode(', ', $errors) . "\n";
        }
        return $ok;
    } catch (Throwable $e) {
        ob_end_clean();
        echo "  [EXCEPTION] $title : " . $e->getMessage() . "\n";
        return false;
    }
}

echo "🔍 Lancement des tests de non-régression...\n\n";

// 1. Visiteur non connecté
echo "1. Tests Visiteur non connecté :\n";
runTest("Accueil sans filtre", []);
runTest("Recherche par mot-clé (q)", ['q' => 'Concert']);
runTest("Filtre par lieu (lieu)", ['lieu' => 'Abidjan']);
runTest("Filtre par catégorie", ['categorie' => 'Festival']);
runTest("Onglet Cotisations", ['onglet' => 'cotisations']);
runTest("Onglet Voter", ['onglet' => 'voter']);

// 2. Client connecté
echo "\n2. Tests Client connecté :\n";
$clientSession = [
    'user_id' => 1,
    'user_nom' => 'Client Test',
    'user_email' => 'client@test.com',
    'user_role' => 'client'
];
runTest("Accueil client connecté", [], $clientSession);
runTest("Cotisations client connecté", ['onglet' => 'cotisations'], $clientSession);
runTest("Voter client connecté", ['onglet' => 'voter'], $clientSession);

// 3. Promoteur connecté (lecture seule)
echo "\n3. Tests Promoteur connecté (consultation) :\n";
$promoSession = [
    'user_id' => 2,
    'user_nom' => 'Promoteur Test',
    'user_email' => 'promoteur@test.com',
    'user_role' => 'promoteur'
];
runTest("Accueil promoteur", [], $promoSession);

// 4. Admin connecté
echo "\n4. Tests Administrateur connecté :\n";
$adminSession = [
    'user_id' => 3,
    'user_nom' => 'Admin Test',
    'user_email' => 'admin@test.com',
    'user_role' => 'admin'
];
runTest("Accueil admin", [], $adminSession);

echo "\n✅ Fin des tests de non-régression.\n";
