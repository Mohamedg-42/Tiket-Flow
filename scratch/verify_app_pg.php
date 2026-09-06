<?php
require_once __DIR__ . '/../config/database.php';

try {
    global $pdo;
    $nb_places = $pdo->query('SELECT COUNT(*) FROM places')->fetchColumn();
    $nb_tickets = $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    $nb_users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $nb_events = $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $nb_salles = $pdo->query('SELECT COUNT(*) FROM salles')->fetchColumn();
    
    echo "=== TEST DE CONNEXION DE L'APPLICATION ===\n";
    echo "Pilote actif : " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
    echo "Total Salles : " . $nb_salles . "\n";
    echo "Total Places : " . $nb_places . "\n";
    echo "Total Billets: " . $nb_tickets . "\n";
    echo "Total Events : " . $nb_events . "\n";
    echo "Total Users  : " . $nb_users . "\n";
    echo "\nTOUT FONCTIONNE PARFAITEMENT DANS POSTGRESQL !\n";
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}
