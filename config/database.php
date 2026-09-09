<?php
// ==============================================================================
// FICHIER DE CONNEXION POSTGRESQL (config/database.php)
// Plateforme Tikéli — Connecté à PostgreSQL
// ==============================================================================

$host = '127.0.0.1';
$port = '5433';
$db = 'ticket_platform';
$user = 'postgres';
$pass = '123';

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Mise à jour automatique du statut des événements terminés (optimisation: limité à 1 fois par minute)
    $eventsLockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tikeli_events_status_check.timestamp';
    if (!file_exists($eventsLockFile) || (time() - @filemtime($eventsLockFile)) > 60) {
        @touch($eventsLockFile);
        try {
            $pdo->exec("
                UPDATE events 
                SET statut = 'termine' 
                WHERE statut = 'actif' 
                  AND (
                      date_evenement < CURRENT_DATE 
                      OR (date_evenement = CURRENT_DATE AND heure <= CURRENT_TIME)
                  )
            ");
        } catch (\Exception $e) {
            // Silence silent background lock error to prevent breaking user flow
            error_log("Avertissement mise à jour auto events: " . $e->getMessage());
        }
    }

} catch (\PDOException $e) {
    die("❌ Erreur de connexion à PostgreSQL : " . $e->getMessage());
}

if (!function_exists('resolve_media_url')) {
    /**
     * Résout l'URL publique d'un média (affiche, image, photo) avec fallback automatique.
     * Détecte les URLs distantes, vérifie l'existence et l'intégrité des fichiers locaux (> 500 octets).
     */
    function resolve_media_url($image_val, $default_url, $subfolders = ['events', 'cotisations', 'candidats']) {
        $val = trim((string) $image_val);
        if ($val === '' || $val === 'default.jpg' || $val === 'null') {
            return $default_url;
        }
        if (strpos($val, 'http://') === 0 || strpos($val, 'https://') === 0) {
            return $val;
        }
        $rootUploads = realpath(__DIR__ . '/../uploads');
        if ($rootUploads) {
            foreach ((array)$subfolders as $folder) {
                $filePath = $rootUploads . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $val;
                if (file_exists($filePath) && @filesize($filePath) > 500) {
                    return '../uploads/' . $folder . '/' . $val;
                }
            }
        }
        return $default_url;
    }
}
