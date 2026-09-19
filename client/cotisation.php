<?php
// ==============================================================================
// PAGE DÉDIÉE DE COTISATION & TRAITEMENT (client/cotisation.php)
// Présentation complète d'une campagne solidaire et contribution directe SANS MODALE
// Standard Typographique Suisse Müller-Brockmann & Performance
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==============================================================================
// 1. TRAITEMENT DU FORMULAIRE DE CONTRIBUTION (POST)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campagne_id = filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT) ?: null;
    $post_token  = trim($_POST['token'] ?? '');

    // Les promoteurs et administrateurs ne peuvent pas contribuer (réservé aux clients)
    if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
        $_SESSION['cotisation_message'] = "Les contributions sont réservées aux clients. Votre compte " . ($_SESSION['user_role'] ?? '') . " ne peut pas cotiser.";
        $_SESSION['cotisation_type'] = 'error';
        $redirect = $campagne_id ? ('cotisation?id=' . $campagne_id . ($post_token ? '&token=' . urlencode($post_token) : '')) : 'accueil?onglet=cotisations';
        header('Location:  ' . $redirect);
        exit();
    }

    $is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $user_id = $is_logged_in ? (int) $_SESSION['user_id'] : null;

    $nom = trim($_POST['nom'] ?? ($_SESSION['user_nom'] ?? ''));
    $email = trim($_POST['email'] ?? ($_SESSION['user_email'] ?? ''));
    $telephone = trim($_POST['telephone'] ?? '');
    $montant = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);

    if ($nom === '' || !$montant || $montant < 500) {
        $_SESSION['cotisation_message'] = "Veuillez renseigner votre nom et un montant valide (minimum 500 FCFA).";
        $_SESSION['cotisation_type'] = 'error';
        $redirect = $campagne_id ? ('cotisation?id=' . $campagne_id . ($post_token ? '&token=' . urlencode($post_token) : '')) : 'accueil?onglet=cotisations';
        header('Location:  ' . $redirect);
        exit();
    }

    $campagne_titre = null;
    $camp_info = null;
    if ($campagne_id !== null) {
        try {
            $stmt_camp = $pdo->prepare("SELECT id, titre, visibilite, access_token, event_id FROM cotisation_campagnes WHERE id = ? AND statut = 'active'");
            $stmt_camp->execute([$campagne_id]);
            $camp_info = $stmt_camp->fetch(PDO::FETCH_ASSOC);

            if (!$camp_info) {
                $_SESSION['cotisation_message'] = "Cette campagne de cotisation n'est plus active ou est clôturée.";
                $_SESSION['cotisation_type'] = 'error';
                header('Location: accueil?onglet=cotisations');
                exit();
            }
            $campagne_titre = $camp_info['titre'];

            // 1.1 VÉRIFICATION DE SÉCURITÉ POUR LES COTISATIONS PRIVÉES
            if (($camp_info['visibilite'] ?? 'public') === 'prive') {
                $expected_token = (string) ($camp_info['access_token'] ?? '');
                if (empty($post_token) || !hash_equals($expected_token, $post_token)) {
                    $_SESSION['cotisation_message'] = "Action non autorisée : Jeton d'accès privé manquant ou invalide.";
                    $_SESSION['cotisation_type'] = 'error';
                    header('Location: accueil?onglet=cotisations');
                    exit();
                }

                // Contrôle de la Whitelist des personnes enregistrées au préalable
                require_once __DIR__ . '/../includes/whitelist.php';
                $tel_clean = normalizePhone($telephone);
                if (empty($tel_clean)) {
                    $_SESSION['cotisation_message'] = "Un numéro de téléphone valide est obligatoire pour participer à cette collecte privée.";
                    $_SESSION['cotisation_type'] = 'error';
                    header('Location: cotisation?id=' . $campagne_id . '&token=' . urlencode($post_token));
                    exit();
                }

                $is_whitelisted = false;
                try {
                    $stmt_cwl = $pdo->prepare("SELECT id FROM cotisation_whitelist WHERE campagne_id = ? AND telephone = ?");
                    $stmt_cwl->execute([$campagne_id, $tel_clean]);
                    if ($stmt_cwl->fetch()) {
                        $is_whitelisted = true;
                    } elseif (!empty($camp_info['event_id'])) {
                        $stmt_ewl = $pdo->prepare("SELECT id FROM event_guest_whitelist WHERE event_id = ? AND telephone = ?");
                        $stmt_ewl->execute([(int)$camp_info['event_id'], $tel_clean]);
                        if ($stmt_ewl->fetch()) {
                            $is_whitelisted = true;
                        }
                    }
                } catch (PDOException $e) {
                    $is_whitelisted = false;
                }

                if (!$is_whitelisted) {
                    $_SESSION['cotisation_message'] = "Action refusée : Le numéro " . htmlspecialchars($telephone) . " ne figure pas sur la liste des participants enregistrés au préalable pour cette collecte privée.";
                    $_SESSION['cotisation_type'] = 'error';
                    header('Location: cotisation?id=' . $campagne_id . '&token=' . urlencode($post_token));
                    exit();
                }
            }
        } catch (PDOException $e) {
            $campagne_id = null;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO cotisations (campagne_id, user_id, nom, email, telephone, montant, statut) 
            VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
        ");
        $stmt->execute([
            $campagne_id,
            $user_id,
            $nom,
            $email ?: null,
            $telephone ?: null,
            $montant
        ]);

        $inserted_cot_id = (int) $pdo->lastInsertId();
        $cot_token = get_or_create_resource_token($pdo, 'cotisation_payment', $inserted_cot_id);
        header('Location: paiement-cotisation?token=' . urlencode($cot_token) . ($post_token ? '&campagne_token=' . urlencode($post_token) : ''));
        exit();

    } catch (PDOException $e) {
        $_SESSION['cotisation_message'] = "Une erreur est survenue lors de l'enregistrement de votre don. Veuillez réessayer.";
        $_SESSION['cotisation_type'] = 'error';
        $redirect = $campagne_id ? ('cotisation?id=' . $campagne_id . ($post_token ? '&token=' . urlencode($post_token) : '')) : 'accueil?onglet=cotisations';
        header('Location:  ' . $redirect);
        exit();
    }
}

// ==============================================================================
// 2. CHARGEMENT DE LA CAMPAGNE POUR L'AFFICHAGE PLEINE PAGE (GET)
// ==============================================================================
$campagne_token = trim((string) ($_GET['token'] ?? ''));
$campagne_id = 0;

if (!empty($campagne_token)) {
    // 1. Résolution via la table des tokens sécurisés
    $resolved_c_id = resolve_resource_token($pdo, $campagne_token, 'cotisation');
    if ($resolved_c_id) {
        $campagne_id = $resolved_c_id;
    } else {
        // 2. Fallback pour access_token natif de campagne privée
        $stmt_tok = $pdo->prepare("SELECT id FROM cotisation_campagnes WHERE access_token = ? LIMIT 1");
        $stmt_tok->execute([$campagne_token]);
        $campagne_id = (int) $stmt_tok->fetchColumn();
    }
}

if (!$campagne_id) {
    $campagne_id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int) $_GET['id'] : ((isset($_GET['campagne_id']) && is_numeric($_GET['campagne_id'])) ? (int) $_GET['campagne_id'] : 0);
}

// Fallback si aucun ID n'est passé : charger la première campagne active PUBLIQUE
if (!$campagne_id) {
    try {
        $first_id = (int) $pdo->query("
            SELECT id FROM cotisation_campagnes 
            WHERE statut = 'active' AND (visibilite IS NULL OR visibilite = 'public') 
            ORDER BY created_at DESC LIMIT 1
        ")->fetchColumn();
        if ($first_id > 0) {
            $campagne_id = $first_id;
        } else {
            header('Location: accueil?onglet=cotisations');
            exit();
        }
    } catch (PDOException $e) {
        header('Location: accueil?onglet=cotisations');
        exit();
    }
}

$stmt = $pdo->prepare("
    SELECT c.*,
           COALESCE(p.nom_commercial, u.nom) AS promoteur_nom, 
           COALESCE(p.telephone_contact, u.telephone) AS promoteur_tel,
           COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                      WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
           COALESCE((SELECT COUNT(*) FROM cotisations ct
                      WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
    FROM cotisation_campagnes c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN promoters p ON c.user_id = p.user_id
    WHERE c.id = ? AND c.statut IN ('active', 'terminee')
");
$stmt->execute([$campagne_id]);
$campagne = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campagne) {
    header('Location: accueil?onglet=cotisations');
    exit();
}

// 2.1 Événement / Collecte privée : accès strictement limité au lien direct avec jeton
$is_private_campagne = ($campagne['visibilite'] ?? 'public') === 'prive';
if ($is_private_campagne) {
    $expected_token = (string) ($campagne['access_token'] ?? '');
    if ($expected_token === '' || !hash_equals($expected_token, $campagne_token)) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Accès restreint — Collecte Privée | TikeWA</title>
            <meta name="robots" content="noindex, nofollow">
            <style>
                body { font-family: system-ui, -apple-system, sans-serif; background: #0F172A; color: #E2E8F0; min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
                .box { max-width: 440px; width: 100%; text-align: center; background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 2.5rem 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
                .icon { font-size: 2.75rem; color: #FF4A0D; margin-bottom: 1.25rem; display: block; }
                h1 { font-size: 1.35rem; margin: 0 0 0.75rem; font-weight: 800; color: #FFFFFF; }
                p { color: #94A3B8; font-size: 0.92rem; line-height: 1.6; margin: 0 0 1.5rem; }
                a { display: inline-flex; align-items: center; gap: 8px; background: #FF4A0D; color: #FFFFFF; font-weight: 700; font-size: 0.88rem; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 999px; transition: background 0.2s; }
                a:hover { background: #E03E08; }
            </style>
        </head>
        <body>
            <div class="box">
                <span class="icon">🔒</span>
                <h1>Collecte Privée Restreinte</h1>
                <p>Cette campagne de cotisation est strictement confidentielle. Vous devez obligatoirement utiliser le lien d'invitation officiel fourni par l'organisateur pour la consulter et y contribuer.</p>
                <a href="accueil">← Retour à l'accueil</a>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_id = $is_logged_in ? (int) $_SESSION['user_id'] : null;
$user_role = $_SESSION['user_role'] ?? 'client';
$peut_agir = empty($user_id) || ($user_role === 'client');

$collecte = (float) ($campagne['montant_collecte'] ?? 0);
$objectif = (float) ($campagne['montant_objectif'] ?? $campagne['montant_cible'] ?? 0);
$pct_collecte = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100, 1)) : 0;
$objectif_atteint = ($objectif > 0 && $collecte >= $objectif);
$est_terminee = ($campagne['statut'] === 'terminee');
$peut_contribuer = !$est_terminee && $peut_agir;

$default_cotisation_img = 'https://images.unsplash.com/photo-1532629345422-7515f3d16bb7?auto=format&fit=crop&w=1200&q=80';
$campagne_img = resolve_media_url($campagne['image'] ?? '', $default_cotisation_img, ['cotisations', 'events']);

$user_telephone = '';
if ($is_logged_in && !empty($user_id)) {
    try {
        $stmt_u = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_u->execute([$user_id]);
        $user_telephone = $stmt_u->fetchColumn() ?: '';
    } catch (PDOException $e) {
    }
}

// 3. Récupération des dons et donateurs récents pour cette campagne
$contributeurs_recents = [];
try {
    $stmt_c = $pdo->prepare("
        SELECT nom, montant, created_at 
        FROM cotisations 
        WHERE campagne_id = ? AND statut IN ('payee', 'en_attente')
        ORDER BY created_at DESC 
        LIMIT 6
    ");
    $stmt_c->execute([$campagne_id]);
    $contributeurs_recents = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $contributeurs_recents = [];
}



$cotisation_msg = $_SESSION['cotisation_message'] ?? '';
$cotisation_type = $_SESSION['cotisation_type'] ?? 'success';
if (!empty($cotisation_msg)) {
    unset($_SESSION['cotisation_message'], $_SESSION['cotisation_type']);
}

$page_title = htmlspecialchars($campagne['titre']) . " - Contribution Solidaire";
$body_class = "client-page cotisation-page";
include 'header.php';
?>

<div class="cotisation-layout-container"
    style="max-width: 1200px; margin: 1.5rem auto 4rem; padding: 0 clamp(1.25rem, 4vw, 2.5rem);">
    <!-- Lien de retour épuré avec marge aérée -->
    <div style="margin-bottom: 2.25rem;">
        <a href="accueil?onglet=cotisations"
            style="display: inline-flex; align-items: center; gap: 0.6rem; color: var(--muted); text-decoration: none; font-weight: 700; font-size: 0.92rem; transition: all 0.2s;"
            onmouseover="this.style.color='var(--primary)'; this.style.transform='translateX(-3px)';"
            onmouseout="this.style.color='var(--muted)'; this.style.transform='translateX(0)';">
            <i class="fa-solid fa-arrow-left"></i> Retour aux cotisations
        </a>
    </div>

    <?php if (!empty($cotisation_msg)): ?>
        <div class="alert <?php echo ($cotisation_type === 'success') ? 'alert-success' : 'alert-error'; ?>"
            style="margin-bottom: 2.5rem; padding: 1rem 1.25rem; border-radius: 10px;">
            <i class="fa-solid fa-circle-<?php echo ($cotisation_type === 'success') ? 'check' : 'exclamation'; ?>"></i>
            <?php echo htmlspecialchars($cotisation_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Grille Principale 2 Colonnes avec marges aérées -->
    <div class="cotisation-grid"
        style="display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: clamp(2rem, 4vw, 3.5rem); align-items: start;">

        <!-- ==========================================
             COLONNE GAUCHE : DÉTAILS DE LA CAMPAGNE
             ========================================== -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">

            <div
                style="background: #ffffff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);">
                <!-- Affiche Pleine Largeur avec hauteur compacte et proportionnée -->
                <div style="position: relative; overflow: hidden; background: #0f172a; height: 230px;">
                    <img src="<?php echo htmlspecialchars($campagne_img, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($campagne['titre']); ?>"
                        style="width: 100%; height: 100%; object-fit: cover; display: block;"
                        onerror="this.onerror=null; this.src='<?php echo $default_cotisation_img; ?>';">
                    <span class="event-category-badge"
                        style="font-size: 0.75rem; padding: 5px 10px; position: absolute; top: 12px; left: 12px;">
                        <i class="fa-solid fa-hand-holding-heart" style="margin-right: 4px;"></i>
                        <?php echo $est_terminee ? 'Campagne Clôturée' : 'Campagne Active'; ?>
                    </span>
                    <span class="event-date-chip"
                        style="font-size: 0.75rem; padding: 5px 10px; position: absolute; top: 12px; right: 12px;">
                        <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                        <?php echo $campagne['date_limite'] ? 'Jusqu\'au ' . date('d/m/Y', strtotime($campagne['date_limite'])) : 'Sans date limite'; ?>
                    </span>
                </div>

                <!-- Contenu avec marge interne équilibrée -->
                <div style="padding: clamp(1.25rem, 2.5vw, 1.75rem);">
                    <span class="page-kicker"
                        style="margin-bottom: 0.4rem; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-hand-holding-heart"></i> Campagne de Contribution
                    </span>
                    <h1
                        style="margin: 0 0 0.75rem; color: var(--navy); font-size: clamp(1.35rem, 2.2vw, 1.75rem); line-height: 1.25; font-weight: 800; letter-spacing: -0.01em;">
                        <?php echo htmlspecialchars($campagne['titre']); ?>
                    </h1>

                    <?php if (!empty($campagne['promoteur_nom'])): ?>
                        <div
                            style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.84rem; color: var(--muted); margin-bottom: 1.25rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--line-light);">
                            <i class="fa-solid fa-circle-user" style="color: var(--primary);"></i>
                            <span>Initié par <strong
                                    style="color: var(--navy);"><?php echo htmlspecialchars($campagne['promoteur_nom']); ?></strong></span>
                        </div>
                    <?php endif; ?>

                    <!-- Grille Métriques Reliees aux Données Réelles -->
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div
                            style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem 0.9rem;">
                            <small
                                style="color: var(--muted); font-size: 0.7rem; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 2px;">Collecté</small>
                            <strong class="swiss-numeral"
                                style="color: var(--navy); font-size: 1.2rem; font-weight: 800;">
                                <?php echo number_format($collecte, 0, ',', ' '); ?> <span
                                    style="font-size: 0.7rem; color: var(--muted); font-family: 'Space Mono', monospace;">FCFA</span>
                            </strong>
                        </div>
                        <div
                            style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem 0.9rem;">
                            <small
                                style="color: var(--muted); font-size: 0.7rem; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 2px;">Objectif</small>
                            <strong class="swiss-numeral"
                                style="color: var(--navy); font-size: 1.2rem; font-weight: 800;">
                                <?php echo number_format($objectif, 0, ',', ' '); ?> <span
                                    style="font-size: 0.7rem; color: var(--muted); font-family: 'Space Mono', monospace;">FCFA</span>
                            </strong>
                        </div>
                        <div
                            style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem 0.9rem;">
                            <small
                                style="color: var(--muted); font-size: 0.7rem; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 2px;">Progression</small>
                            <strong
                                style="color: <?php echo $objectif_atteint ? '#059669' : '#FF4A0D'; ?>; font-size: 1.2rem; font-weight: 800; font-family: 'Space Mono', monospace;">
                                <?php echo $pct_collecte; ?>%
                            </strong>
                        </div>
                        <div
                            style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem 0.9rem;">
                            <small
                                style="color: var(--muted); font-size: 0.7rem; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 2px;">Contributeurs</small>
                            <strong style="color: var(--navy); font-size: 1.2rem; font-weight: 800;">
                                <?php echo (int) $campagne['nb_contributeurs']; ?>
                            </strong>
                        </div>
                    </div>

                    <!-- Barre de progression -->
                    <div style="margin-bottom: 1.25rem;">
                        <div
                            style="display: flex; justify-content: space-between; font-size: 0.78rem; font-weight: 700; margin-bottom: 6px;">
                            <span style="color: var(--navy);"><i class="fa-solid fa-chart-line"
                                    style="color: var(--primary);"></i> Avancement de la collecte</span>
                            <span
                                style="color: <?php echo $objectif_atteint ? '#059669' : '#FF4A0D'; ?>; font-weight: 800;"><?php echo $pct_collecte; ?>%</span>
                        </div>
                        <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                            <div
                                style="height: 100%; width: <?php echo $pct_collecte; ?>%; background: <?php echo $objectif_atteint ? '#059669' : 'linear-gradient(90deg, #FF4A0D, #FF7A3D)'; ?>; border-radius: 999px; transition: width 0.5s ease;">
                            </div>
                        </div>
                        <?php if ($objectif_atteint): ?>
                            <small
                                style="color: #059669; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; margin-top: 6px;">
                                <i class="fa-solid fa-circle-check"></i> Objectif atteint ! Merci aux contributeurs.
                            </small>
                        <?php endif; ?>
                    </div>

                    <!-- Motivation & Description complète -->
                    <?php if (!empty($campagne['description'])): ?>
                        <div style="border-top: 1px solid var(--line-light); padding-top: 1.25rem;">
                            <h3
                                style="color: var(--navy); font-size: 1.05rem; font-weight: 800; margin: 0 0 0.5rem; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-align-left" style="color: var(--primary);"></i> À propos de ce projet
                            </h3>
                            <p
                                style="color: var(--ink); font-size: 0.9rem; line-height: 1.65; margin: 0; white-space: pre-line;">
                                <?php echo htmlspecialchars($campagne['description']); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ==========================================
             COLONNE DROITE : FICHE DE CONTRIBUTION DIRECTE & COMPACTE
             ========================================== -->
        <div style="position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1.25rem;">
            <div
                style="background: #ffffff; border: 1px solid var(--line); border-radius: 14px; padding: 1.25rem 1.5rem; box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.06);">

                <div
                    style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--line-light);">
                    <div
                        style="width: 36px; height: 36px; background: #FFF2ED; border-radius: 8px; display: grid; place-items: center; color: var(--primary); font-size: 1.05rem; flex-shrink: 0;">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <h2 style="margin: 0; font-size: 1.15rem; color: var(--navy); font-weight: 800;">Faire une
                            Contribution</h2>
                        <small style="color: var(--muted); font-size: 0.75rem;">Soutien direct · Aucun compte
                            requis</small>
                    </div>
                </div>

                <?php if (!$peut_agir): ?>
                    <!-- Promoteurs / Administrateurs -->
                    <div
                        style="background: #FFF2ED; border: 1px solid #FFD8CC; border-radius: 8px; padding: 1rem; text-align: center;">
                        <i class="fa-solid fa-lock"
                            style="font-size: 1.5rem; color: #FF4A0D; margin-bottom: 0.4rem; display: block;"></i>
                        <h4 style="margin: 0 0 0.2rem; color: var(--navy); font-size: 0.9rem;">Espace réservé aux clients
                        </h4>
                        <p style="margin: 0; color: var(--muted); font-size: 0.8rem;">Votre profil ne permet pas de cotiser.
                        </p>
                    </div>
                <?php elseif ($est_terminee): ?>
                    <!-- Campagne close -->
                    <div
                        style="background: #F5F5F5; border: 1px solid #E5E5E5; border-radius: 8px; padding: 1rem; text-align: center;">
                        <i class="fa-solid fa-flag-checkered"
                            style="font-size: 1.5rem; color: var(--muted); margin-bottom: 0.4rem; display: block;"></i>
                        <h4 style="margin: 0 0 0.2rem; color: var(--navy); font-size: 0.9rem;">Campagne Clôturée</h4>
                        <p style="margin: 0; color: var(--muted); font-size: 0.8rem;">Cette campagne a pris fin. Merci !</p>
                    </div>
                <?php else: ?>
                    <!-- FORMULAIRE ACTIF DIRECTEMENT DANS LA PAGE -->
                    <form method="POST" action="cotisation<?php echo $is_private_campagne ? ('?token=' . urlencode($campagne_token)) : ''; ?>" id="directCotisationForm">
                        <input type="hidden" name="campagne_id" value="<?php echo (int) $campagne['id']; ?>">
                        <?php if ($is_private_campagne): ?>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($campagne_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <div style="background: rgba(255, 74, 13, 0.08); border: 1px solid rgba(255, 74, 13, 0.25); border-radius: 8px; padding: 0.65rem 0.85rem; margin-bottom: 0.85rem; font-size: 0.78rem; color: #FF4A0D; display: flex; align-items: center; gap: 8px; font-weight: 700;">
                                <i class="fa-solid fa-user-shield" style="font-size: 1rem;"></i>
                                <span>Collecte Privée — Réservée aux personnes enregistrées au préalable par l'organisateur.</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($is_logged_in): ?>
                            <!-- Client Connecté -->
                            <input type="hidden" name="nom"
                                value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="email"
                                value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (!$is_private_campagne || !empty($user_telephone)): ?>
                                <input type="hidden" name="telephone"
                                    value="<?php echo htmlspecialchars($user_telephone, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>

                            <div
                                style="background: #F8FAFC; border: 1px solid var(--line); border-left: 3px solid var(--primary); border-radius: 8px; padding: 0.65rem 0.85rem; margin-bottom: 1rem; font-size: 0.82rem; color: var(--ink);">
                                <i class="fa-solid fa-circle-user" style="color: var(--primary); margin-right: 4px;"></i>
                                Don au nom de <strong><?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?></strong>
                                <span style="color: var(--muted); display: block; font-size: 0.74rem; margin-top: 2px;">
                                    <?php echo htmlspecialchars($user_telephone !== '' ? $user_telephone : ($_SESSION['user_email'] ?? '')); ?>
                                </span>
                            </div>

                            <?php if ($is_private_campagne && empty($user_telephone)): ?>
                                <div style="margin-bottom: 0.85rem;">
                                    <label for="direct_cot_tel_logged" style="font-size: 0.78rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">
                                        Téléphone enregistré auprès de l'organisateur *
                                    </label>
                                    <input type="tel" id="direct_cot_tel_logged" name="telephone" required placeholder="07 00 00 00 00"
                                        style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.85rem; font-family: inherit;">
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Visiteur : Nom & Téléphone en 2 Colonnes pour compacter la hauteur -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; margin-bottom: 0.75rem;">
                                <div>
                                    <label for="direct_cot_nom"
                                        style="font-size: 0.78rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">
                                        Nom & Prénom *
                                    </label>
                                    <input type="text" id="direct_cot_nom" name="nom" required placeholder="Ex: Awa Koné"
                                        style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.85rem; font-family: inherit;">
                                </div>
                                <div>
                                    <label for="direct_cot_tel"
                                        style="font-size: 0.78rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">
                                        Téléphone *
                                    </label>
                                    <input type="tel" id="direct_cot_tel" name="telephone" required placeholder="07 00 00 00 00"
                                        style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.85rem; font-family: inherit;">
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label for="direct_cot_email"
                                    style="font-size: 0.78rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">
                                    Email (optionnel)
                                </label>
                                <input type="email" id="direct_cot_email" name="email" placeholder="votre.email@domaine.com"
                                    style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.85rem; font-family: inherit;">
                            </div>
                        <?php endif; ?>

                        <!-- Choix du montant avec raccourcis -->
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label for="direct_cot_montant"
                                style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 6px;">
                                <i class="fa-solid fa-coins" style="color: var(--primary);"></i> Montant (FCFA) *
                            </label>

                            <!-- 4 Boutons de montants rapides sur une seule ligne -->
                            <div
                                style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px;">
                                <button type="button" class="btn-amount-preset" onclick="setQuickAmount(1000, this)"
                                    style="background: #F8FAFC; border: 1px solid var(--line); padding: 0.45rem 0.2rem; border-radius: 6px; font-weight: 700; font-size: 0.76rem; cursor: pointer; transition: all 0.2s; font-family: 'Space Mono', monospace;">
                                    1 000 F
                                </button>
                                <button type="button" class="btn-amount-preset" onclick="setQuickAmount(2500, this)"
                                    style="background: #F8FAFC; border: 1px solid var(--line); padding: 0.45rem 0.2rem; border-radius: 6px; font-weight: 700; font-size: 0.76rem; cursor: pointer; transition: all 0.2s; font-family: 'Space Mono', monospace;">
                                    2 500 F
                                </button>
                                <button type="button" class="btn-amount-preset" onclick="setQuickAmount(5000, this)"
                                    style="background: #FFF2ED; border: 1px solid #FFD8CC; color: #FF4A0D; padding: 0.45rem 0.2rem; border-radius: 6px; font-weight: 800; font-size: 0.76rem; cursor: pointer; transition: all 0.2s; font-family: 'Space Mono', monospace;">
                                    5 000 F
                                </button>
                                <button type="button" class="btn-amount-preset" onclick="setQuickAmount(10000, this)"
                                    style="background: #F8FAFC; border: 1px solid var(--line); padding: 0.45rem 0.2rem; border-radius: 6px; font-weight: 700; font-size: 0.76rem; cursor: pointer; transition: all 0.2s; font-family: 'Space Mono', monospace;">
                                    10 000 F
                                </button>
                            </div>

                            <input type="number" id="direct_cot_montant" name="montant" required min="500" step="500"
                                value="5000" placeholder="Autre montant (min 500)"
                                style="width: 100%; padding: 0.65rem 0.85rem; border: 1.5px solid var(--line); border-radius: 8px; font-size: 1.05rem; font-weight: 800; font-family: 'Space Mono', monospace; color: var(--navy); transition: border-color 0.2s;"
                                onfocus="this.style.borderColor='var(--primary)'"
                                onblur="this.style.borderColor='var(--line)'">
                        </div>

                        <!-- Bouton d'action principal -->
                        <button type="submit" class="btn-submit"
                            style="width: 100%; margin: 0.5rem 0 0; padding: 0.75rem 1.25rem; font-size: 0.92rem; font-weight: 800; border-radius: 8px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(255, 74, 13, 0.2);">
                            <i class="fa-solid fa-paper-plane"></i> Procéder au paiement
                        </button>

                        <div
                            style="margin-top: 0.75rem; text-align: center; color: var(--muted); font-size: 0.72rem; display: flex; align-items: center; justify-content: center; gap: 5px;">
                            <i class="fa-solid fa-shield-halved" style="color: #059669;"></i>
                            <span>Paiement sécurisé via Mobile Money</span>
                        </div>
                    </form>
                <?php endif; ?>

            </div>

            <!-- Donateurs / Dernières contributions EN DESSOUS DU MOYEN DE PAIEMENT (compact) -->
            <?php if (!empty($contributeurs_recents)): ?>
                <div
                    style="background: #ffffff; border: 1px solid var(--line); border-radius: 14px; padding: 1rem 1.25rem; box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.05);">
                    <h3
                        style="color: var(--navy); font-size: 0.88rem; font-weight: 800; margin: 0 0 0.75rem; display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.5rem; border-bottom: 1px solid var(--line-light);">
                        <span><i class="fa-solid fa-heart" style="color: #FF4A0D; margin-right: 6px;"></i> Dernières
                            contributions</span>
                        <span
                            style="font-size: 0.72rem; font-family: 'Space Mono', monospace; color: var(--muted); font-weight: 700;"><?php echo count($contributeurs_recents); ?>
                            récentes</span>
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <?php
                        $max_donateurs = array_slice($contributeurs_recents, 0, 3);
                        foreach ($max_donateurs as $ct):
                            ?>
                            <div
                                style="display: flex; align-items: center; justify-content: space-between; padding: 0.35rem 0; border-bottom: 1px solid var(--line-light);">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div
                                        style="width: 28px; height: 28px; border-radius: 50%; background: #FFF2ED; color: #FF4A0D; font-weight: 800; font-size: 0.75rem; display: grid; place-items: center; flex-shrink: 0;">
                                        <?php echo mb_strtoupper(mb_substr(trim($ct['nom']), 0, 1)); ?>
                                    </div>
                                    <div>
                                        <strong
                                            style="color: var(--navy); font-size: 0.82rem; display: block; line-height: 1.2;"><?php echo htmlspecialchars($ct['nom']); ?></strong>
                                        <small
                                            style="color: var(--muted); font-size: 0.7rem;"><?php echo date('d/m à H:i', strtotime($ct['created_at'])); ?></small>
                                    </div>
                                </div>
                                <span class="swiss-numeral" style="color: #059669; font-weight: 800; font-size: 0.84rem;">
                                    +<?php echo number_format((float) $ct['montant'], 0, ',', ' '); ?> F
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>

</div>

<style>
    @media (max-width: 900px) {
        .cotisation-grid {
            grid-template-columns: 1fr !important;
            gap: 2rem !important;
        }
    }
</style>

<script>
    function setQuickAmount(val, btn) {
        var input = document.getElementById('direct_cot_montant');
        if (input) {
            input.value = val;
            input.focus();
        }
        // Mise en valeur du bouton cliqué
        document.querySelectorAll('.btn-amount-preset').forEach(function (b) {
            b.style.background = '#F8FAFC';
            b.style.borderColor = 'var(--line)';
            b.style.color = 'inherit';
            b.style.fontWeight = '700';
        });
        if (btn) {
            btn.style.background = '#FFF2ED';
            btn.style.borderColor = '#FFD8CC';
            btn.style.color = '#FF4A0D';
            btn.style.fontWeight = '800';
        }
    }
</script>

<?php include 'footer.php'; ?>