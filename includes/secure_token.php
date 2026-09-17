<?php
// ==============================================================================
// MODULE DE TOKENS SÉCURISÉS & PROTECTION D'IDENTIFIANTS (includes/secure_token.php)
// Plateforme Tike WA — Conforme aux exigences de sécurité cryptographique
// Empêche l'exposition et l'énumération des identifiants sensibles dans les URLs
// ==============================================================================

if (!defined('SECURE_TOKEN_LOADED')) {
    define('SECURE_TOKEN_LOADED', true);

    /**
     * Génère un token aléatoire cryptographiquement sécurisé (CSPRNG).
     * Utilise un alphabet alphanumérique Base62 (sans ambiguïté, URL-safe sans encodage).
     * 24 caractères = ~142 bits d'entropie réelle (imprévisible, impossible à deviner).
     * 
     * @param int $length Longueur du token (défaut : 24 caractères)
     * @return string Token aléatoire pur
     */
    function generate_secure_random_token(int $length = 24): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $alphabetLength = strlen($alphabet);
        $token = '';

        // Tirage aléatoire indépendant sans biais
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $index = ord($bytes[$i]) % $alphabetLength;
            $token .= $alphabet[$index];
        }

        return $token;
    }

    /**
     * Valide le format strict d'un token pour éviter toute tentative d'injection.
     * 
     * @param mixed $token
     * @return bool
     */
    function is_valid_token_format($token): bool
    {
        if (!is_string($token)) {
            return false;
        }
        $token = trim($token);
        return (bool) preg_match('/^[A-Za-z0-9]{12,64}$/', $token);
    }

    /**
     * Récupère un token actif existant pour une ressource ou en génère un nouveau de manière permanente.
     * 
     * @param PDO $pdo
     * @param string $resourceType Type de ressource (ex: 'promoter', 'ticket', 'order', 'event', etc.)
     * @param int $resourceId Identifiant réel en base de données
     * @param string|null $expiresAt Date d'expiration optionnelle (format Y-m-d H:i:s)
     * @param array|null $metadata Métadonnées contextuelles optionnelles
     * @return string Token sécurisé
     */
    function get_or_create_resource_token(PDO $pdo, string $resourceType, int $resourceId, ?string $expiresAt = null, ?array $metadata = null): string
    {
        if ($resourceId <= 0) {
            throw new InvalidArgumentException("L'identifiant de ressource doit être un entier positif.");
        }

        // 1. Vérifier si un token permanent ou encore valide existe déjà pour cette ressource
        $sqlCheck = "
            SELECT token 
            FROM secure_resource_tokens 
            WHERE resource_type = ? 
              AND resource_id = ? 
              AND is_active = TRUE 
              AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
            ORDER BY id DESC 
            LIMIT 1
        ";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([$resourceType, $resourceId]);
        $existing = $stmtCheck->fetchColumn();

        if ($existing && is_string($existing)) {
            return $existing;
        }

        // 2. Générer et enregistrer un nouveau token avec retry en cas de collision théorique
        $maxAttempts = 5;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $token = generate_secure_random_token(24);
            try {
                $sqlInsert = "
                    INSERT INTO secure_resource_tokens (token, resource_type, resource_id, is_active, expires_at, metadata, created_at)
                    VALUES (?, ?, ?, TRUE, ?, ?, CURRENT_TIMESTAMP)
                ";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $metaJson = $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
                $stmtInsert->execute([$token, $resourceType, $resourceId, $expiresAt, $metaJson]);
                return $token;
            } catch (PDOException $e) {
                // Code d'erreur PostgreSQL pour violation d'unicité : 23505
                if ($e->getCode() === '23505' && $attempt < $maxAttempts - 1) {
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException("Échec de génération du token sécurisé unique.");
    }

    /**
     * Crée un token temporaire à durée de vie limitée pour une ressource.
     * 
     * @param PDO $pdo
     * @param string $resourceType
     * @param int $resourceId
     * @param int $durationSeconds Durée de validité en secondes
     * @param array|null $metadata
     * @return string
     */
    function create_temporary_resource_token(PDO $pdo, string $resourceType, int $resourceId, int $durationSeconds, ?array $metadata = null): string
    {
        if ($durationSeconds <= 0) {
            throw new InvalidArgumentException("La durée de validité doit être supérieure à 0.");
        }

        $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);
        $token = generate_secure_random_token(24);

        $sql = "
            INSERT INTO secure_resource_tokens (token, resource_type, resource_id, is_active, expires_at, metadata, created_at)
            VALUES (?, ?, ?, TRUE, ?, ?, CURRENT_TIMESTAMP)
        ";
        $stmt = $pdo->prepare($sql);
        $metaJson = $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        $stmt->execute([$token, $resourceType, $resourceId, $expiresAt, $metaJson]);

        return $token;
    }

    /**
     * Résout un token sécurisé en identifiant réel en base de données.
     * Effectue la vérification d'existence, de format, d'activité et d'expiration.
     * 
     * @param PDO $pdo
     * @param mixed $token Token soumis dans la requête
     * @param string $resourceType Type de ressource attendu
     * @return int|null Identifiant réel de la ressource si valide, null sinon
     */
    function resolve_resource_token(PDO $pdo, $token, string $resourceType): ?int
    {
        if (!is_valid_token_format($token)) {
            return null;
        }

        $tokenStr = trim((string) $token);

        $sql = "
            SELECT resource_id, is_active, expires_at
            FROM secure_resource_tokens 
            WHERE token = ? 
              AND resource_type = ? 
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tokenStr, $resourceType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Vérification de l'activation
        if (empty($row['is_active'])) {
            return null;
        }

        // Vérification de l'expiration
        if (!empty($row['expires_at'])) {
            $expiresTimestamp = strtotime($row['expires_at']);
            if ($expiresTimestamp !== false && $expiresTimestamp <= time()) {
                return null;
            }
        }

        return (int) $row['resource_id'];
    }

    /**
     * Désactive (révoque) un token pour interdire tout accès futur.
     * 
     * @param PDO $pdo
     * @param string $token
     * @return bool
     */
    function revoke_resource_token(PDO $pdo, string $token): bool
    {
        if (!is_valid_token_format($token)) {
            return false;
        }

        $sql = "
            UPDATE secure_resource_tokens 
            SET is_active = FALSE, revoked_at = CURRENT_TIMESTAMP 
            WHERE token = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([trim($token)]);
        return ($stmt->rowCount() > 0);
    }

    /**
     * Construit une URL sécurisée avec le token de la ressource.
     * 
     * @param string $basePath Chemin du script (ex: 'promoteur.php' ou 'client/telecharger-ticket.php')
     * @param string $token
     * @param array $extraParams Paramètres d'URL additionnels (ex: ['candidat_id' => 3])
     * @return string
     */
    function build_secure_url(string $basePath, string $token, array $extraParams = []): string
    {
        $params = array_merge(['token' => $token], $extraParams);
        return $basePath . '?' . http_build_query($params);
    }

    /**
     * Affiche une page d'erreur standard 404 sécurisée et termine l'exécution.
     * Conçue selon la grille et l'esthétique sobre de la plateforme, sans révéler d'informations système.
     * 
     * @param string $title
     * @param string $message
     * @param int $httpCode
     * @param string $backUrl
     */
    function render_token_security_error(
        string $title = "Ressource introuvable",
        string $message = "Le lien auquel vous tentez d'accéder est inexistant, a expiré ou a été désactivé.",
        int $httpCode = 404,
        string $backUrl = "accueil.php"
    ): void {
        http_response_code($httpCode);
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($title); ?> — Tike WA</title>
            <meta name="robots" content="noindex, nofollow">
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    background-color: #0f172a;
                    color: #f8fafc;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 1.5rem;
                }
                .error-card {
                    background: #1e293b;
                    border: 1px solid #334155;
                    border-radius: 12px;
                    max-width: 480px;
                    width: 100%;
                    padding: 2.5rem 2rem;
                    text-align: center;
                    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
                }
                .icon-badge {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 56px;
                    height: 56px;
                    border-radius: 50%;
                    background: rgba(239, 68, 68, 0.15);
                    color: #ef4444;
                    font-size: 24px;
                    margin-bottom: 1.25rem;
                    border: 1px solid rgba(239, 68, 68, 0.3);
                }
                h1 {
                    font-size: 1.35rem;
                    font-weight: 700;
                    margin-bottom: 0.75rem;
                    letter-spacing: -0.01em;
                }
                p {
                    font-size: 0.95rem;
                    line-height: 1.55;
                    color: #94a3b8;
                    margin-bottom: 1.75rem;
                }
                .btn-home {
                    display: inline-block;
                    background: #FF4A0D;
                    color: #ffffff;
                    text-decoration: none;
                    font-size: 0.9rem;
                    font-weight: 600;
                    padding: 0.75rem 1.5rem;
                    border-radius: 8px;
                    transition: background-color 0.15s ease;
                }
                .btn-home:hover {
                    background: #e03e05;
                }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="icon-badge">✕</div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-home">Retour à l'accueil</a>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}
