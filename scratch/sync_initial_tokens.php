<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

echo "=== INITIALISATION DES TOKENS POUR LES RESSOURCES EXISTANTES ===\n";

// 1. Promoteurs
$promoters = $pdo->query("SELECT user_id FROM promoters")->fetchAll(PDO::FETCH_COLUMN);
$countP = 0;
foreach ($promoters as $uid) {
    get_or_create_resource_token($pdo, 'promoter', (int) $uid);
    $countP++;
}
echo "✓ Promoteurs synchronisés : $countP\n";

// 2. Événements
$events = $pdo->query("SELECT id FROM events")->fetchAll(PDO::FETCH_COLUMN);
$countE = 0;
foreach ($events as $eid) {
    get_or_create_resource_token($pdo, 'event', (int) $eid);
    $countE++;
}
echo "✓ Événements synchronisés : $countE\n";

// 3. Billets
$tickets = $pdo->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
$countT = 0;
foreach ($tickets as $tid) {
    get_or_create_resource_token($pdo, 'ticket', (int) $tid);
    $countT++;
}
echo "✓ Billets synchronisés : $countT\n";

// 4. Commandes
$orders = $pdo->query("SELECT id FROM orders")->fetchAll(PDO::FETCH_COLUMN);
$countO = 0;
foreach ($orders as $oid) {
    get_or_create_resource_token($pdo, 'order', (int) $oid);
    $countO++;
}
echo "✓ Commandes synchronisées : $countO\n";

// 5. Campagnes cotisations
try {
    $cotis = $pdo->query("SELECT id FROM cotisation_campagnes")->fetchAll(PDO::FETCH_COLUMN);
    $countC = 0;
    foreach ($cotis as $cid) {
        get_or_create_resource_token($pdo, 'cotisation', (int) $cid);
        $countC++;
    }
    echo "✓ Campagnes cotisations synchronisées : $countC\n";
} catch (Exception $e) {}

echo "Synchronisation terminée avec succès.\n";
