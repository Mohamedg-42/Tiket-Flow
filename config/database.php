<?php
// ==============================================================================
// FICHIER DE CONNEXION À LA BASE DE DONNÉES (PDO)
// Mise à jour automatique des statuts d'événements expirés (statut -> 'termine')
// ==============================================================================

// 1. Paramètres de connexion
$host    = 'localhost';        // Adresse du serveur de base de données (WAMP)
$db      = 'ticket_platform';  // Nom de la base de données
$user    = 'root';             // Utilisateur MySQL
$pass    = '';                 // Mot de passe MySQL
$charset = 'utf8mb4';          // Encodage

// 2. Construction du DSN
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// 3. Options de configuration de PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// 4. Connexion PDO
try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 5. MISE À JOUR AUTOMATIQUE DU STATUT DES ÉVÉNEMENTS TERMINÉS
    // Dès que la date et l'heure de l'événement sont passées, son statut bascule à 'termine'
    $pdo->exec("
        UPDATE `events` 
        SET `statut` = 'termine' 
        WHERE `statut` = 'actif' 
          AND (
              `date_evenement` < CURDATE() 
              OR (`date_evenement` = CURDATE() AND `heure` <= CURTIME())
          )
    ");

} catch (\PDOException $e) {
    die("❌ Erreur de connexion à la base de données : " . $e->getMessage());
}