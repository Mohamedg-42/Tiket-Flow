<?php
// ==============================================================================
// FICHIER DE CONNEXION POSTGRESQL (config/database.php)
// Plateforme Tike WA — Connecté à PostgreSQL
// Credentials chargés depuis .env via config/env.php (aucune donnée sensible ici)
// ==============================================================================

// 1. Chargement des variables d'environnement (.env) — doit être le premier require
require_once __DIR__ . '/env.php';

// 2. Lecture des paramètres de connexion depuis l'environnement
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5433';
$db   = getenv('DB_NAME') ?: 'ticket_platform';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: '';

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
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
                      OR (date_evenement = CURRENT_DATE AND (heure IS NULL OR heure <= CURRENT_TIME))
                  )
            ");
        } catch (\Exception $e) {
            // Silence silent background lock error to prevent breaking user flow
            error_log("Avertissement mise à jour auto events: " . $e->getMessage());
        }
    }

} catch (\PDOException $e) {
    error_log("Erreur PDO Tike WA: " . $e->getMessage());
    $is_debug = (getenv('APP_DEBUG') === 'true' || getenv('APP_ENV') === 'development');
    if ($is_debug) {
        die("❌ Erreur technique de connexion à la base : " . htmlspecialchars($e->getMessage()));
    } else {
        die("❌ Service momentanément indisponible. Veuillez rafraîchir la page ou réessayer dans quelques instants.");
    }
}

if (!function_exists('resolve_media_url')) {
    /**
     * Résout l'URL publique d'un média (affiche, image, photo) avec fallback automatique.
     * Détecte les URLs distantes, vérifie l'existence et l'intégrité des fichiers locaux (> 500 octets).
     * Optimisé avec mémoïsation en mémoire vive pour supprimer les accès disques répétitifs.
     */
    function resolve_media_url($image_val, $default_url, $subfolders = ['events', 'cotisations', 'candidats']) {
        static $memo = [];
        $val = trim((string) $image_val);
        if ($val === '' || $val === 'default.jpg' || $val === 'null') {
            return $default_url;
        }
        if (strpos($val, 'http://') === 0 || strpos($val, 'https://') === 0) {
            return $val;
        }
        $cacheKey = $val . '|' . (is_array($subfolders) ? implode(',', $subfolders) : (string)$subfolders);
        if (isset($memo[$cacheKey])) {
            return $memo[$cacheKey];
        }
        $rootUploads = realpath(__DIR__ . '/../uploads');
        if ($rootUploads) {
            foreach ((array)$subfolders as $folder) {
                $filePath = $rootUploads . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $val;
                if (file_exists($filePath) && @filesize($filePath) > 500) {
                    return $memo[$cacheKey] = '../uploads/' . $folder . '/' . $val;
                }
            }
        }
        return $memo[$cacheKey] = $default_url;
    }
}

if (!function_exists('friendly_db_error')) {
    /**
     * Transforme une exception technique de base de données (PDOException / Exception)
     * en un message clair, professionnel et orienté action pour l'utilisateur,
     * tout en consignant les détails techniques réels dans les logs serveur.
     *
     * @param Throwable $e L'exception interceptée
     * @param string $actionContext Le contexte de l'action (ex: 'inscription', 'demande_evenement')
     * @param string|null $customDefault Message de repli personnalisé
     * @return string Message convivial et actionnable destiné à l'utilisateur
     */
    function friendly_db_error(Throwable $e, string $actionContext = 'operation', ?string $customDefault = null): string {
        // 1. Journalisation sécurisée des détails techniques pour le débogage serveur
        error_log(sprintf(
            "[%s] [DB_ERROR:%s] %s in %s:%d",
            date('Y-m-d H:i:s'),
            strtoupper($actionContext),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        // 2. Détection du code SQLSTATE ou type d'erreur
        $code = (string) $e->getCode();
        $msg = $e->getMessage();

        // 23505 = Unique violation (PostgreSQL), 1062 = Duplicate entry (MySQL)
        if ($code === '23505' || $code === '1062' || stripos($msg, 'duplicate') !== false || stripos($msg, 'unique') !== false) {
            if (stripos($msg, 'email') !== false) {
                return "Cette adresse email est déjà enregistrée. Veuillez vous connecter ou utiliser une autre adresse.";
            }
            if (stripos($msg, 'telephone') !== false || stripos($msg, 'tel') !== false) {
                return "Ce numéro de téléphone est déjà associé à un compte ou une inscription. Veuillez vérifier votre saisie.";
            }
            if (stripos($msg, 'code_guichet') !== false) {
                return "Ce code de guichet est déjà attribué. Veuillez choisir un autre code.";
            }
            if (stripos($msg, 'code') !== false || stripos($msg, 'reference') !== false) {
                return "Cette référence ou ce code existe déjà. Veuillez utiliser un identifiant différent.";
            }
            return "Un enregistrement avec ces informations existe déjà. Veuillez vérifier vos données.";
        }

        // 23503 = Foreign key violation
        if ($code === '23503' || stripos($msg, 'foreign key') !== false) {
            return "L'élément associé (événement, catégorie, utilisateur) n'existe plus ou n'est plus accessible. Veuillez actualiser la page.";
        }

        // 23502 = Not null violation
        if ($code === '23502' || stripos($msg, 'not-null') !== false) {
            return "Certaines informations obligatoires sont manquantes. Veuillez compléter tous les champs requis.";
        }

        // 22001 = String data right truncation
        if ($code === '22001' || stripos($msg, 'too long') !== false) {
            return "Le texte saisi dans l'un des champs dépasse la taille maximale autorisée.";
        }

        // Connexion au serveur de données
        if (stripos($msg, 'connection') !== false || stripos($msg, 'server closed') !== false) {
            return "Le serveur est momentanément indisponible. Veuillez patienter quelques instants et réessayer.";
        }

        if ($customDefault !== null) {
            return $customDefault;
        }

        return "Une difficulté technique est survenue lors de l'opération. Veuillez vérifier vos informations et réessayer, ou contacter notre assistance si le problème persiste.";
    }
}


