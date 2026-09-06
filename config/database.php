<?php
// ==============================================================================
// FICHIER DE CONNEXION POSTGRESQL (config/database.php)
// Plateforme Eventia — Connecté à PostgreSQL
// ==============================================================================

$host    = '127.0.0.1';
$port    = '5432';
$db      = 'ticket_platform';
$user    = 'postgres';
$pass    = '123';

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Mise à jour automatique du statut des événements terminés
    $pdo->exec("
        UPDATE events 
        SET statut = 'termine' 
        WHERE statut = 'actif' 
          AND (
              date_evenement < CURRENT_DATE 
              OR (date_evenement = CURRENT_DATE AND heure <= CURRENT_TIME)
          )
    ");

} catch (\PDOException $e) {
    die("❌ Erreur de connexion à PostgreSQL : " . $e->getMessage());
}
