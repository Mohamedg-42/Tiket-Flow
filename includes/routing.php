<?php
// ==============================================================================
// GESTION DU ROUTAGE ET DES URLS CONVIVIALES (SLUGS)
// Plateforme Tike WA
// ==============================================================================

if (!function_exists('slugify')) {
    /**
     * Convertit une chaîne de caractères en slug URL propre et sécurisé.
     * Exemple: "Festival des Grillades 2026 !" => "festival-des-grillades-2026"
     */
    function slugify(string $text, string $divider = '-'): string {
        // Remplacer les caractères accentués ou spécifiques
        $transliterator = 'Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove';
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate($transliterator, $text);
        } else {
            // Fallback iconv / preg
            $text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        }

        // Remplacement des caractères non alphanumériques par le séparateur
        $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // Supprimer les caractères non autorisés
        $text = preg_replace('~[^-\w]+~', '', $text);

        // Nettoyer les tirets en début et fin
        $text = trim($text, $divider);

        // Réduire les séparateurs multiples consécutifs
        $text = preg_replace('~-+~', $divider, $text);

        // Mettre en minuscules
        $text = strtolower($text);

        if (empty($text)) {
            return 'item-' . time();
        }

        return substr($text, 0, 100);
    }
}

if (!function_exists('get_app_base_path')) {
    /**
     * Détermine le chemin de base relatif de l'application (ex: '/ticket-platform' ou '')
     */
    function get_app_base_path(): string {
        static $base_path = null;
        if ($base_path !== null) {
            return $base_path;
        }

        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // Détection automatique du sous-dossier projet
        $folders = ['/ticket-platform', '/ticket-platform-main'];
        foreach ($folders as $folder) {
            if (strpos($script_name, $folder . '/') === 0 || $script_name === $folder) {
                $base_path = $folder;
                return $base_path;
            }
        }

        // Si le script est dans /client/, /admin/, /promoteur/, etc.
        $dir = dirname($script_name);
        $dir = str_replace('\\', '/', $dir);
        if ($dir === '/' || $dir === '.') {
            $base_path = '';
        } else {
            // Nettoie les sous-dossiers applicatifs
            $clean_dir = preg_replace('#/(client|admin|promoteur|gare|pos|ajax|scratch|services)$#', '', $dir);
            $base_path = ($clean_dir === '/' || $clean_dir === '.') ? '' : rtrim($clean_dir, '/');
        }

        return $base_path;
    }
}

if (!function_exists('get_app_base_url')) {
    /**
     * Retourne l'URL racine absolue du site
     */
    function get_app_base_url(): string {
        $env_url = getenv('APP_URL');
        if (!empty($env_url) && !in_array($env_url, ['https://tikewa.com', 'http://localhost'], true)) {
            return rtrim($env_url, '/');
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_path = get_app_base_path();

        return $scheme . '://' . $host . $base_path;
    }
}

if (!function_exists('generate_unique_slug')) {
    /**
     * Génère un slug unique pour une table donnée (events ou cotisations_campagnes).
     */
    function generate_unique_slug(PDO $pdo, string $table, string $title, ?int $exclude_id = null): string {
        $base_slug = slugify($title);
        $slug = $base_slug;
        $counter = 1;

        // Assurer que la table est autorisée
        $allowed_tables = ['events', 'cotisation_campagnes'];
        if (!in_array($table, $allowed_tables, true)) {
            return $base_slug;
        }

        while (true) {
            $sql = "SELECT id FROM {$table} WHERE slug = ?";
            $params = [$slug];
            if ($exclude_id !== null) {
                $sql .= " AND id != ?";
                $params[] = $exclude_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $counter++;
            $slug = $base_slug . '-' . $counter;
        }
    }
}

if (!function_exists('event_url')) {
    /**
     * Génère l'URL conviviale d'un événement billetterie.
     */
    function event_url(array $event, bool $absolute = false): string {
        $prefix = $absolute ? get_app_base_url() : get_app_base_path();
        $slug = !empty($event['slug']) ? $event['slug'] : (!empty($event['id']) ? ((int)$event['id'] . '-' . slugify($event['titre'] ?? 'evenement')) : '');
        
        $url = rtrim($prefix, '/') . '/evenement/' . $slug;

        // Si l'événement est privé, propager le jeton
        if (($event['visibilite'] ?? '') === 'prive' && !empty($event['access_token'])) {
            $url .= '?token=' . urlencode($event['access_token']);
        }

        return $url;
    }
}

if (!function_exists('vote_url')) {
    /**
     * Génère l'URL conviviale d'une campagne de vote.
     */
    function vote_url(array $event, bool $absolute = false): string {
        $prefix = $absolute ? get_app_base_url() : get_app_base_path();
        $slug = !empty($event['slug']) ? $event['slug'] : (!empty($event['id']) ? ((int)$event['id'] . '-' . slugify($event['titre'] ?? 'vote')) : '');
        
        $url = rtrim($prefix, '/') . '/vote/' . $slug;

        // Si le vote est privé, propager le jeton
        if (($event['visibilite'] ?? '') === 'prive' && !empty($event['access_token'])) {
            $url .= '?token=' . urlencode($event['access_token']);
        }

        return $url;
    }
}

if (!function_exists('cotisation_url')) {
    /**
     * Génère l'URL conviviale d'une campagne de cotisation.
     */
    function cotisation_url(array $campagne, bool $absolute = false): string {
        $prefix = $absolute ? get_app_base_url() : get_app_base_path();
        $slug = !empty($campagne['slug']) ? $campagne['slug'] : (!empty($campagne['id']) ? ((int)$campagne['id'] . '-' . slugify($campagne['titre'] ?? 'cotisation')) : '');
        
        $url = rtrim($prefix, '/') . '/cotisation/' . $slug;

        // Si la cotisation est privée, propager le jeton
        if (($campagne['visibilite'] ?? '') === 'prive' && !empty($campagne['access_token'])) {
            $url .= '?token=' . urlencode($campagne['access_token']);
        }

        return $url;
    }
}
