<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("
    SELECT id, nom, visibilite, access_token, type_vote, prix_vote, statut 
    FROM events 
    ORDER BY id DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "TOTAL EVENTS : " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Nom: {$r['nom']} | Visibilité: '{$r['visibilite']}' | Token: '{$r['access_token']}' | TypeVote: {$r['type_vote']} | Statut: {$r['statut']}\n";
}

echo "\n--- EVENT REQUESTS ---\n";
try {
    $stmt_req = $pdo->query("SELECT id, nom, visibilite, type_vote, statut FROM event_requests ORDER BY id DESC");
    foreach ($stmt_req->fetchAll(PDO::FETCH_ASSOC) as $rq) {
        echo "REQ ID: {$rq['id']} | Nom: {$rq['nom']} | Visibilité: '{$rq['visibilite']}' | TypeVote: {$rq['type_vote']} | Statut: {$rq['statut']}\n";
    }
} catch (Exception $e) {
    echo "Erreur event_requests: " . $e->getMessage() . "\n";
}

