<?php
// ==============================================================================
// FICHIER DE CONNEXION À LA BASE DE DONNÉES POSTGRESQL (PDO)
// ==============================================================================

$pg_host     = getenv('PGHOST') ?: '127.0.0.1';
$pg_port     = getenv('PGPORT') ?: '5433';
$pg_database = getenv('PGDATABASE') ?: 'ticket_platform';
$pg_user     = getenv('PGUSER') ?: 'postgres';
$pg_password = getenv('PGPASSWORD') ?: '123';

$dsn = "pgsql:host={$pg_host};port={$pg_port};dbname={$pg_database};";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo_pg = new PDO($dsn, $pg_user, $pg_password, $options);
} catch (\PDOException $e) {
    // Si la connexion échoue, notification claire
    error_log("❌ Erreur de connexion PostgreSQL : " . $e->getMessage());
}
