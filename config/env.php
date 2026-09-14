<?php
// ==============================================================================
// CHARGEUR DE VARIABLES D'ENVIRONNEMENT (config/env.php)
// Loader natif .env — Sans dépendance externe (pas de vlucas/phpdotenv requis)
// Chargé automatiquement par config/database.php avant toute connexion
// ==============================================================================

if (!function_exists('tikeli_load_env')) {
    /**
     * Charge le fichier .env depuis la racine du projet et injecte
     * les variables dans getenv() / $_ENV / $_SERVER.
     * Les variables déjà définies dans l'environnement système prennent la priorité.
     *
     * @param string $path Chemin absolu vers le fichier .env
     * @return void
     */
    function tikeli_load_env(string $path): void {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);

            // Ignorer les commentaires et les lignes vides
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Séparer clé = valeur (première occurrence du =)
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Nettoyer les guillemets optionnels autour de la valeur
            if (strlen($value) >= 2) {
                $firstChar = $value[0];
                $lastChar  = $value[strlen($value) - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Ignorer les clés invalides (protection XSS env)
            if (empty($key) || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            // Les variables d'environnement système ont la priorité (Docker, PaaS, CI/CD)
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Chargement automatique dès l'inclusion de ce fichier
tikeli_load_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

// Définir APP_SECRET_KEY comme constante PHP (utilisée dans les callbacks de paiement)
if (!defined('APP_SECRET_KEY')) {
    $app_secret = getenv('APP_SECRET_KEY');
    if (empty($app_secret) || $app_secret === 'tikeli_pay_sec_change_me_in_production_use_a_long_random_key') {
        // Avertissement en développement uniquement
        if (getenv('APP_ENV') === 'development' || getenv('APP_DEBUG') === 'true') {
            error_log("⚠️  Tike WA: APP_SECRET_KEY utilise la valeur par défaut. Définissez une clé forte dans .env en production !");
        }
    }
    define('APP_SECRET_KEY', !empty($app_secret) ? $app_secret : 'tikeli_pay_sec_9948271');
}
