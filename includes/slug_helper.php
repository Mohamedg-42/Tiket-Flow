<?php
// ==============================================================================
// GESTIONNAIRE DE SLUGS & URLS AMICALES (includes/slug_helper.php)
// Plateforme Tike WA — Clean URLs sans identifiants numériques visibles
// ==============================================================================

if (!function_exists('slugify')) {
    /**
     * Convertit une chaîne de caractères en slug URL-safe.
     * 
     * @param string $text
     * @return string
     */
    function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'evenement';
        }

        // Conversion translittérée des accents si l'extension intl est disponible
        if (function_exists('transliterator_transliterate')) {
            $trans = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
            if ($trans !== false) {
                $text = $trans;
            }
        } elseif (function_exists('iconv')) {
            $iconvText = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($iconvText !== false) {
                $text = $iconvText;
            }
        }

        $text = strtolower($text);
        // Remplace les apostrophes par des tirets
        $text = preg_replace('/[\'’]/', '-', $text);
        // Remplace tout caractère non alphanumérique par un tiret
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        // Supprime les tirets multiples et aux extrémités
        $text = trim((string) $text, '-');

        return !empty($text) ? substr($text, 0, 180) : 'evenement';
    }
}

if (!function_exists('generate_unique_event_slug')) {
    /**
     * Génère un slug unique garanti pour un événement dans la base de données.
     * 
     * @param PDO $pdo
     * @param string $nom
     * @param int|null $excludeEventId Identifiant à ignorer lors des mises à jour
     * @return string
     */
    function generate_unique_event_slug(PDO $pdo, string $nom, ?int $excludeEventId = null): string
    {
        $baseSlug = slugify($nom);
        $candidateSlug = $baseSlug;
        $counter = 2;

        while (true) {
            $sql = "SELECT id FROM events WHERE slug = ?";
            $params = [$candidateSlug];
            if ($excludeEventId !== null && $excludeEventId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeEventId;
            }
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existingId = $stmt->fetchColumn();

            if (!$existingId) {
                return $candidateSlug;
            }

            $candidateSlug = $baseSlug . '-' . $counter;
            $counter++;
        }
    }
}

if (!function_exists('get_event_slug_url')) {
    /**
     * Renvoie l'URL conviviale propre sans extension et sans ID pour un événement.
     * 
     * @param array $event Tableau contenant les clés 'slug' ou 'id' ou 'token'
     * @param string $prefix Préfixe relatif (par défaut 'client/evenement/' ou 'evenement/')
     * @return string
     */
    function get_event_slug_url(array $event, string $prefix = 'evenement/'): string
    {
        if (!empty($event['slug'])) {
            return $prefix . urlencode($event['slug']);
        }
        if (!empty($event['access_token'])) {
            return $prefix . urlencode($event['access_token']);
        }
        if (!empty($event['token'])) {
            return $prefix . urlencode($event['token']);
        }
        return $prefix . ($event['id'] ?? '');
    }
}
