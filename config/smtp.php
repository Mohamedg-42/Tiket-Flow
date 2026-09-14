<?php
// ==============================================================================
// CONFIGURATION SMTP (config/smtp.php)
// Toutes les valeurs sensibles sont lues depuis .env via config/env.php
// Ne jamais mettre de credentials en dur ici.
//
// ÉTAPES DE CONFIGURATION GMAIL :
//   1. Connectez-vous à votre compte Google
//   2. Activez la validation en 2 étapes : https://myaccount.google.com/security
//   3. Générez un "Mot de passe d'application" :
//      https://myaccount.google.com/apppasswords
//      → Sélectionnez "Autre (nom personnalisé)" → "Tike WA" → Générer
//   4. Renseignez SMTP_USER et SMTP_PASS dans votre fichier .env
// ==============================================================================

// S'assurer que env.php est chargé (idempotent grâce au function_exists)
if (!function_exists('tikeli_load_env')) {
    require_once __DIR__ . '/env.php';
}

// Paramètres SMTP — lus exclusivement depuis les variables d'environnement
if (!defined('SMTP_USER')) {
    $smtp_user = getenv('SMTP_USER');
    if (empty($smtp_user)) {
        error_log("⚠️  Tike WA SMTP: SMTP_USER non défini dans .env");
    }
    define('SMTP_USER', $smtp_user ?: '');
}

if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
}

if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
}

if (!defined('SMTP_FROM')) {
    define('SMTP_FROM', getenv('SMTP_FROM') ?: getenv('SMTP_USER') ?: '');
}

if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Tike WA');
}