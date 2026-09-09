<?php
// ==============================================================================
// GESTION DE L'AUTHENTIFICATION, DES RÔLES, PERMISSIONS & ACTIVITÉ (includes/auth.php)
// Sécurisation granulaire côté serveur pour Tikéli
// ==============================================================================

// En-têtes HTTP de sécurité globaux (SEC-008)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
}

// 1. Démarrage sécurisé de la session avec protection des cookies (SEC-006)
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Fonctions de Protection Anti-CSRF (SEC-005)
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(bool $auto_exit = true): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    $valid = !empty($token) && !empty($session_token) && hash_equals($session_token, $token);
    if (!$valid && $auto_exit) {
        http_response_code(403);
        die("<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>403 - Requête Invalide</title></head><body style='font-family:system-ui,sans-serif;text-align:center;padding:3rem;background:#f8fafc;'><h1>Erreur de Validation CSRF</h1><p>Votre session a expiré ou la requête est invalide. Veuillez recharger la page et réessayer.</p><p><a href='javascript:history.back()'>Retour</a></p></body></html>");
    }
    return $valid;
}

/**
 * Fonction interne pour récupérer l'instance PDO courante
 * @return PDO
 */
function getAuthPDO() {
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    require_once __DIR__ . '/../config/database.php';
    return $pdo;
}

/**
 * Vérifie si un utilisateur est connecté et s'il possède le rôle requis.
 *
 * @param string|array $roles_autorises Un rôle (ex: 'admin') ou un tableau de rôles (ex: ['admin', 'agent'])
 * @param string $redirect_url URL de redirection en cas d'accès refusé
 */
function checkRole($roles_autorises, $redirect_url = '../connexion.php') {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header("Location: " . $redirect_url);
        exit();
    }

    // Vérification de la suspension en cours de session
    $statusCheck = checkAccountStatus($_SESSION['user_id']);
    if (!$statusCheck['allowed']) {
        session_unset();
        session_destroy();
        header("Location: " . $redirect_url . "?error=compte_suspendu&msg=" . urlencode($statusCheck['message']));
        exit();
    }

    if (!is_array($roles_autorises)) {
        $roles_autorises = [$roles_autorises];
    }

    $user_role = $_SESSION['user_role'] ?? '';

    if (!in_array($user_role, $roles_autorises, true)) {
        header("Location: " . $redirect_url . "?error=acces_interdit");
        exit();
    }
}

/**
 * Vérifie simplement si l'utilisateur est connecté (quel que soit son rôle).
 * @param string $redirect_url
 */
function requireLogin($redirect_url = '../connexion.php') {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header("Location: " . $redirect_url);
        exit();
    }

    $statusCheck = checkAccountStatus($_SESSION['user_id']);
    if (!$statusCheck['allowed']) {
        session_unset();
        session_destroy();
        header("Location: " . $redirect_url . "?error=compte_suspendu&msg=" . urlencode($statusCheck['message']));
        exit();
    }
}

/**
 * Savoir si un visiteur est connecté.
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Vérifie l'état d'un compte (actif / suspendu temporairement ou définitivement).
 * Gère la réactivation automatique si l'échéance de suspension est passée.
 *
 * @param int|array $user_or_id ID de l'utilisateur ou ligne utilisateur
 * @return array ['allowed' => bool, 'message' => string, 'statut' => string]
 */
function checkAccountStatus($user_or_id) {
    $db = getAuthPDO();

    if (is_array($user_or_id)) {
        $u = $user_or_id;
        // Si role ou est_verifie ne sont pas présents dans le tableau fourni, les récupérer si id est présent
        if (!isset($u['role']) && isset($u['id'])) {
            $st_sup = $db->prepare("SELECT role, est_verifie FROM users WHERE id = ?");
            $st_sup->execute([(int)$u['id']]);
            $extra = $st_sup->fetch();
            if ($extra) {
                $u['role'] = $extra['role'];
                $u['est_verifie'] = $extra['est_verifie'];
            }
        }
    } else {
        $stmt = $db->prepare("SELECT id, nom, prenom, email, role, est_verifie, statut, suspended_from, suspended_until, suspension_reason FROM users WHERE id = ?");
        $stmt->execute([(int)$user_or_id]);
        $u = $stmt->fetch();
    }

    if (!$u) {
        return ['allowed' => false, 'message' => "Compte introuvable.", 'statut' => 'inconnu'];
    }

    $statut = $u['statut'] ?? 'actif';
    $role   = $u['role'] ?? '';
    $est_verifie = (int)($u['est_verifie'] ?? 1);

    // 0. Compte promoteur en attente de validation administrative
    if ($statut === 'en_attente' || ($role === 'promoteur' && $est_verifie === 0)) {
        return [
            'allowed' => false,
            'message' => "Votre dossier de promoteur est actuellement en cours d'examen par l'administration de Tikéli. Vous recevrez une notification par e-mail dès validation de votre compte, après quoi vous pourrez vous connecter.",
            'statut'  => 'en_attente'
        ];
    }

    // 1. Compte inactif
    if ($statut === 'inactif') {
        return [
            'allowed' => false,
            'message' => "Ce compte est actuellement inactif. Veuillez contacter le support.",
            'statut'  => 'inactif'
        ];
    }

    // 2. Compte suspendu définitivement
    if ($statut === 'suspendu_def') {
        $motif = !empty($u['suspension_reason']) ? (" Motif : " . $u['suspension_reason']) : "";
        return [
            'allowed' => false,
            'message' => "Votre compte a été suspendu définitivement par l'administrateur." . $motif,
            'statut'  => 'suspendu_def'
        ];
    }

    // 3. Compte suspendu temporairement
    if ($statut === 'suspendu_temp') {
        $today = date('Y-m-d');
        $date_fin = $u['suspended_until'] ?? null;

        // Si la date limite est définie et que le jour actuel a dépassé la date de fin : réactivation automatique !
        if ($date_fin && $today > $date_fin) {
            $stmt_reactivate = $db->prepare("
                UPDATE users 
                SET statut = 'actif', suspended_from = NULL, suspended_until = NULL, suspension_reason = NULL 
                WHERE id = ?
            ");
            $stmt_reactivate->execute([(int)$u['id']]);

            logActivity('user.auto_reactivated', 'user', (int)$u['id'], "Réactivation automatique à l'expiration de la suspension temporaire.");

            return [
                'allowed' => true,
                'message' => "Compte réactivé automatiquement.",
                'statut'  => 'actif'
            ];
        }

        // Sinon suspension encore active
        $fin_formattee = $date_fin ? date('d/m/Y', strtotime($date_fin)) : "indéterminée";
        $motif = !empty($u['suspension_reason']) ? ("\nMotif : " . $u['suspension_reason']) : "";

        return [
            'allowed' => false,
            'message' => "Votre compte est suspendu temporairement jusqu'au $fin_formattee.$motif",
            'statut'  => 'suspendu_temp'
        ];
    }

    return ['allowed' => true, 'message' => "Compte actif.", 'statut' => 'actif'];
}

/**
 * Récupère la liste de tous les codes de permissions d'un utilisateur.
 * Les administrateurs avec role 'admin' disposent de toutes les permissions.
 *
 * @param int|null $user_id ID utilisateur (par défaut l'utilisateur connecté)
 * @return array Liste des codes de permission autorisés
 */
function getUserPermissions($user_id = null) {
    if ($user_id === null) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    if (!$user_id) {
        return [];
    }

    // Si déjà en session pour l'utilisateur courant, retourner le cache de session
    if ($user_id === ($_SESSION['user_id'] ?? null) && isset($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions'])) {
        return $_SESSION['user_permissions'];
    }

    $db = getAuthPDO();
    $stmt_u = $db->prepare("SELECT role, profile_id FROM users WHERE id = ?");
    $stmt_u->execute([(int)$user_id]);
    $u = $stmt_u->fetch();

    if (!$u) {
        return [];
    }

    // Si super-administrateur (role = 'admin') : toutes les permissions existantes
    if ($u['role'] === 'admin') {
        $all_perms = $db->query("SELECT code FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        if ($user_id === ($_SESSION['user_id'] ?? null)) {
            $_SESSION['user_permissions'] = $all_perms;
        }
        return $all_perms;
    }

    // Sinon récupérer les permissions associées à son profil
    $profile_id = (int)($u['profile_id'] ?? 0);
    if (!$profile_id) {
        return [];
    }

    $stmt_p = $db->prepare("
        SELECT p.code 
        FROM permissions p
        JOIN profile_permissions pp ON pp.permission_id = p.id
        WHERE pp.profile_id = ?
    ");
    $stmt_p->execute([$profile_id]);
    $perms = $stmt_p->fetchAll(PDO::FETCH_COLUMN);

    if ($user_id === ($_SESSION['user_id'] ?? null)) {
        $_SESSION['user_permissions'] = $perms;
    }

    return $perms;
}

/**
 * Vérifie si l'utilisateur possède une permission précise.
 *
 * @param string $permission_code Code unique (ex: 'users.edit', 'events.create')
 * @param int|null $user_id ID utilisateur optionnel (courant par défaut)
 * @return bool
 */
function hasPermission($permission_code, $user_id = null) {
    // Si super-admin connecté
    if ($user_id === null && ($_SESSION['user_role'] ?? '') === 'admin') {
        return true;
    }

    $perms = getUserPermissions($user_id);
    return in_array($permission_code, $perms, true);
}

/**
 * Bloque l'exécution côté serveur si la permission n'est pas accordée.
 *
 * @param string $permission_code
 * @param string|null $redirect_url URL de redirection optionnelle
 */
function requirePermission($permission_code, $redirect_url = null) {
    requireLogin('../connexion.php');

    if (!hasPermission($permission_code)) {
        if ($redirect_url) {
            header("Location: " . $redirect_url . (str_contains($redirect_url, '?') ? '&' : '?') . "error=permission_refusee&perm=" . urlencode($permission_code));
            exit();
        }

        // Affichage d'un écran 403 propre et professionnel Tikéli
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 - Accès Non Autorisé | Tikéli</title>
            <link rel="stylesheet" href="../Css/style.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
            <style>
                body {
                    display: grid;
                    place-items: center;
                    min-height: 100vh;
                    background: #f8fafc;
                    margin: 0;
                    padding: 1.5rem;
                }
                .forbidden-card {
                    background: #ffffff;
                    border: 1px solid var(--line);
                    border-radius: var(--radius-xl);
                    padding: 2.5rem 2rem;
                    max-width: 480px;
                    width: 100%;
                    text-align: center;
                    box-shadow: var(--shadow-xl);
                }
            </style>
        </head>
        <body>
            <div class="forbidden-card">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: #fef2f2; color: #ef4444; display: grid; place-items: center; font-size: 1.75rem; margin: 0 auto 1.25rem;">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h1 style="font-size: 1.5rem; color: var(--navy); margin: 0 0 0.5rem;">Accès Restreint (403)</h1>
                <p style="color: var(--muted); font-size: 0.92rem; margin: 0 0 1.5rem; line-height: 1.5;">
                    Votre profil actuel ne dispose pas de la permission requise (<code><?php echo htmlspecialchars($permission_code); ?></code>) pour effectuer cette opération.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <a href="javascript:history.back()" class="btn-submit" style="background: #f1f5f9; color: var(--navy); text-decoration: none; width: auto; padding: 0.6rem 1.25rem;">
                        <i class="fa-solid fa-arrow-left"></i> Retour
                    </a>
                    <a href="dashboard.php" class="btn-submit" style="text-decoration: none; width: auto; padding: 0.6rem 1.25rem;">
                        <i class="fa-solid fa-house"></i> Tableau de bord
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

/**
 * Enregistre une action dans le journal d'activité (Audit Trail)
 *
 * @param string $action Nom de l'action (ex: 'user.edit', 'task.create')
 * @param string|null $target_type Type d'élément impacté (ex: 'user', 'task', 'event')
 * @param int|null $target_id ID de l'élément concerné
 * @param string|null $details Description lisible du changement
 * @param int|null $user_id ID de l'auteur (courant par défaut)
 * @return bool
 */
function logActivity($action, $target_type = null, $target_id = null, $details = null, $user_id = null) {
    try {
        $db = getAuthPDO();

        if ($user_id === null) {
            $user_id = $_SESSION['user_id'] ?? null;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);

        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, target_type, target_id, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $user_id ? (int)$user_id : null,
            $action,
            $target_type,
            $target_id ? (int)$target_id : null,
            $details,
            $ip,
            $ua
        ]);
    } catch (Exception $e) {
        error_log("Erreur lors de l'enregistrement du log d'activité : " . $e->getMessage());
        return false;
    }
}