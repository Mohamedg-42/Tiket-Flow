<?php
require_once 'c:/wamp64/www/ticket-platform/config/database.php';

echo "=== TEST DE CONNEXION POSTGRESQL ===\n";
$stmt = $pdo->query("SELECT id, nom, email, role, statut FROM users ORDER BY id ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Nombre d'utilisateurs connectés sur PostgreSQL : " . count($users) . "\n";
foreach ($users as $u) {
    echo "  [ID {$u['id']}] {$u['nom']} ({$u['email']}) -> Rôle : {$u['role']}\n";
}

$stmt_e = $pdo->query("SELECT id, nom, lieu, statut FROM events");
$events = $stmt_e->fetchAll(PDO::FETCH_ASSOC);
echo "Nombre d'événements : " . count($events) . "\n";
foreach ($events as $e) {
    echo "  [Event #{$e['id']}] {$e['nom']} ({$e['lieu']})\n";
}

$stmt_s = $pdo->query("SELECT id, nom, capacite, type_salle FROM salles");
$salles = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
echo "Nombre de salles de spectacle : " . count($salles) . "\n";
foreach ($salles as $s) {
    echo "  [Salle #{$s['id']}] {$s['nom']} (Capacité : {$s['capacite']} places)\n";
}
