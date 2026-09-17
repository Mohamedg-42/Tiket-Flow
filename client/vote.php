<?php
// ==============================================================================
// PAGE DÉDIÉE DE VOTE & CONCOURS (client/vote.php)
// Présente la description complète de l'événement et la liste des candidats à voter
// Standard Typographique Suisse Müller-Brockmann & Performance
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$event_token = trim((string) ($_GET['token'] ?? ''));
$event_id = 0;

if (!empty($event_token)) {
    // 1. Résolution via la table des tokens sécurisés (anti-exposition d'ID)
    $resolved_v_id = resolve_resource_token($pdo, $event_token, 'event');
    if ($resolved_v_id) {
        $event_id = $resolved_v_id;
    } else {
        // 2. Fallback pour access_token natif de scrutin privé
        $stmt_tok = $pdo->prepare("SELECT id FROM events WHERE access_token = ? LIMIT 1");
        $stmt_tok->execute([$event_token]);
        $event_id = (int) $stmt_tok->fetchColumn();
    }
}

if (!$event_id) {
    $event_id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int) $_GET['id'] : ((isset($_GET['event_id']) && is_numeric($_GET['event_id'])) ? (int) $_GET['event_id'] : 0);
}

if (!$event_id) {
    if (!empty($event_token)) {
        render_token_security_error(
            "Scrutin introuvable",
            "Le lien de vote sécurisé est invalide, a expiré ou n'existe pas.",
            404,
            "accueil.php?onglet=voter"
        );
    }
    header('Location: accueil.php?onglet=voter');
    exit();
}

// 1. Récupération de l'événement et de son promoteur
$stmt = $pdo->prepare("
    SELECT e.*, 
           COALESCE(p.nom_commercial, u.nom) AS promoteur_nom, 
           COALESCE(p.telephone_contact, u.telephone) AS promoteur_tel 
    FROM events e 
    LEFT JOIN users u ON e.user_id = u.id 
    LEFT JOIN promoters p ON e.user_id = p.user_id 
    WHERE e.id = ? AND e.statut = 'actif'
");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: accueil.php?onglet=voter');
    exit();
}

// 1.1 Événement / Scrutin privé : accès strictement restreint au lien officiel avec jeton (token)
$is_private_event = ($event['visibilite'] ?? 'public') === 'prive';
if ($is_private_event) {
    $expected_token = (string) ($event['access_token'] ?? '');
    $is_token_authorized = (!empty($event_token) && ($event_token === $expected_token || resolve_resource_token($pdo, $event_token, 'event') === (int) $event['id']));
    if (!$is_token_authorized && ($expected_token === '' || !hash_equals($expected_token, $event_token))) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="fr">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Accès restreint — Scrutin Privé | TikeWA</title>
            <meta name="robots" content="noindex, nofollow">
            <style>
                body {
                    font-family: system-ui, -apple-system, sans-serif;
                    background: #0F172A;
                    color: #E2E8F0;
                    min-height: 100vh;
                    margin: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 2rem;
                    box-sizing: border-box;
                }

                .box {
                    max-width: 440px;
                    width: 100%;
                    text-align: center;
                    background: rgba(30, 41, 59, 0.7);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    border-radius: 16px;
                    padding: 2.5rem 2rem;
                    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
                }

                .icon {
                    font-size: 2.75rem;
                    color: #FF4A0D;
                    margin-bottom: 1.25rem;
                    display: block;
                }

                h1 {
                    font-size: 1.35rem;
                    margin: 0 0 0.75rem;
                    font-weight: 800;
                    color: #FFFFFF;
                }

                p {
                    color: #94A3B8;
                    font-size: 0.92rem;
                    line-height: 1.6;
                    margin: 0 0 1.5rem;
                }

                a {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    background: #FF4A0D;
                    color: #FFFFFF;
                    font-weight: 700;
                    font-size: 0.88rem;
                    text-decoration: none;
                    padding: 0.75rem 1.5rem;
                    border-radius: 999px;
                    transition: background 0.2s;
                }

                a:hover {
                    background: #E03E08;
                }
            </style>
        </head>

        <body>
            <div class="box">
                <span class="icon">🔒</span>
                <h1>Accès restreint au scrutin</h1>
                <p>Ce concours de vote est strictement privé. Vous devez obligatoirement utiliser le lien d'invitation sécurisé
                    fourni par l'organisateur pour y accéder et voter.</p>
                <a href="accueil.php">← Retour à l'accueil</a>
            </div>
        </body>

        </html>
        <?php
        exit();
    }
}

$prix_vote = (float) ($event['prix_vote'] ?? 0);
$est_payant = ($prix_vote > 0);
$type_vote = $event['type_vote'] ?? 'concours';

// Cible du compte à rebours du scrutin
$event_time = !empty($event['heure']) ? $event['heure'] : '23:59:59';
$target_timestamp = strtotime($event['date_evenement'] . ' ' . $event_time);
$cd_target_iso = date('Y-m-d\TH:i:s', $target_timestamp);
$is_expired = ($target_timestamp <= time());

// Image de l'événement
$default_vote_img = 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=1200&q=80';
$event_img = resolve_media_url($event['image'] ?? '', $default_vote_img, ['events']);

// 2. Récupération des candidats et de leurs votes
$stmt_cands = $pdo->prepare("
    SELECT c.id, c.event_id, c.nom, c.description, c.photo,
           COALESCE(v.nb_votes_cand, 0) AS nb_votes_cand
    FROM event_candidats c
    LEFT JOIN (
        SELECT candidat_id, COUNT(*) AS nb_votes_cand
        FROM event_votes
        WHERE candidat_id IS NOT NULL AND event_id = ?
        GROUP BY candidat_id
    ) v ON v.candidat_id = c.id
    WHERE c.event_id = ?
    ORDER BY nb_votes_cand DESC, c.nom ASC
");
$stmt_cands->execute([$event_id, $event_id]);
$candidats = $stmt_cands->fetchAll(PDO::FETCH_ASSOC);

// 3. Total des votes enregistrés
$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE event_id = ?");
$stmt_total->execute([$event_id]);
$total_votes = (int) $stmt_total->fetchColumn();

// 4. Votes déjà effectués par l'utilisateur / visiteur courant
$visitor_id = session_id();
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$user_role = $_SESSION['user_role'] ?? 'client';
$peut_agir = empty($user_id) || ($user_role === 'client');

$user_telephone = '';
if ($user_id) {
    try {
        $stmt_tel = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_tel->execute([$user_id]);
        $user_telephone = (string) $stmt_tel->fetchColumn();
    } catch (PDOException $e) {
    }
}

$voted_candidates = [];
$has_voted_event = false;
try {
    if ($user_id) {
        $stmt_v = $pdo->prepare("SELECT candidat_id FROM event_votes WHERE event_id = ? AND user_id = ?");
        $stmt_v->execute([$event_id, $user_id]);
    } else {
        $stmt_v = $pdo->prepare("SELECT candidat_id FROM event_votes WHERE event_id = ? AND visitor_id = ?");
        $stmt_v->execute([$event_id, $visitor_id]);
    }
    foreach ($stmt_v->fetchAll(PDO::FETCH_ASSOC) as $row_v) {
        if ($row_v['candidat_id'] !== null) {
            $voted_candidates[(int) $row_v['candidat_id']] = true;
        }
        $has_voted_event = true;
    }
} catch (PDOException $e) {
    // Table non prête
}

// 5. Vérifier si l'événement dispose également d'une billetterie (tickets d'accès)
$has_tickets = false;
try {
    $stmt_tk = $pdo->prepare("SELECT COUNT(*) FROM ticket_types WHERE event_id = ?");
    $stmt_tk->execute([$event_id]);
    $has_tickets = ((int) $stmt_tk->fetchColumn() > 0);
} catch (PDOException $e) {
    $has_tickets = false;
}

$candidat_focus_id = filter_input(INPUT_GET, 'candidat_id', FILTER_VALIDATE_INT) ?: 0;

// Empêcher tout cache agressif du navigateur pour afficher instantanément les modifications
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$page_title = htmlspecialchars($event['nom']) . " — Vote Officiel | TikeWA";
$body_class = "client-page vote-detail-page";
include __DIR__ . '/header.php';
?>

<link rel="stylesheet" href="../Css/accueil-client.css?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>">

<style>
    /* ==========================================================================
   STYLE TYPOGRAPHIQUE SUISSE & GRILLE MODULAIRE MÜLLER-BROCKMANN (client/vote.php)
   ========================================================================== */
    :root {
        --vote-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        --vote-font-display: 'Outfit', 'Inter', sans-serif;
        --vote-font-mono: 'Space Mono', monospace;
        --vote-orange: #FF4A0D;
        --vote-orange-subtle: #FFF2ED;
        --vote-dark: #0F172A;
        --vote-gray-muted: #64748B;
        --vote-border: #E2E8F0;
        --vote-surface: #FFFFFF;
        --vote-bg: #F8FAFC;
        --vote-radius-sm: 8px;
        --vote-radius-md: 12px;
        --vote-radius-lg: 16px;
        --vote-radius-full: 9999px;
    }

    body.vote-detail-page {
        background-color: var(--vote-bg);
        color: var(--vote-dark);
        font-family: var(--vote-font-sans);
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .vote-page-wrap {
        max-width: 1180px;
        margin: 0 auto;
        padding: clamp(1rem, 3vw, 2.5rem) clamp(1rem, 3vw, 1.5rem) 4rem;
    }

    /* Fil d'Ariane & Navigation Haute */
    .vote-topbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .vote-breadcrumb {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.88rem;
        color: var(--vote-gray-muted);
    }

    .vote-breadcrumb a {
        color: var(--vote-dark);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.15s ease;
    }

    .vote-breadcrumb a:hover {
        color: var(--vote-orange);
    }

    .vote-topbar-actions {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .vote-btn-share-top {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: #ffffff;
        border: 1px solid var(--vote-border);
        color: var(--vote-dark);
        padding: 0.55rem 1rem;
        border-radius: var(--vote-radius-full);
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .vote-btn-share-top:hover {
        background: var(--vote-orange-subtle);
        border-color: var(--vote-orange);
        color: var(--vote-orange);
        transform: translateY(-1px);
    }

    /* Grille Principale de Présentation Réduite & Image Clairement Visible */
    .vote-hero-card {
        background: var(--vote-surface);
        border: 1px solid var(--vote-border);
        border-radius: var(--vote-radius-md);
        box-shadow: 0 2px 12px -2px rgba(15, 23, 42, 0.05);
        overflow: hidden;
        display: grid;
        grid-template-columns: 320px 1fr;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 900px) {
        .vote-hero-card {
            grid-template-columns: 1fr;
        }
    }

    .vote-hero-media {
        position: relative;
        background: #0f172a;
        min-height: 240px;
        height: 100%;
        overflow: hidden;
    }

    .vote-hero-img {
        width: 100%;
        height: 100%;
        min-height: 240px;
        object-fit: cover;
        object-position: center;
        display: block;
    }

    .vote-badge-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: rgba(15, 23, 42, 0.88);
        backdrop-filter: blur(8px);
        color: #ffffff;
        padding: 4px 10px;
        border-radius: var(--vote-radius-full);
        font-size: 0.74rem;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.18);
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
    }

    .vote-badge-chip.orange {
        background: var(--vote-orange);
        border-color: transparent;
    }

    .vote-hero-content {
        padding: 1.15rem 1.4rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .vote-hero-header {
        margin-bottom: 0.4rem;
    }

    .vote-hero-title {
        font-family: var(--vote-font-display);
        font-size: clamp(1.2rem, 1.8vw, 1.45rem);
        font-weight: 800;
        color: var(--vote-dark);
        line-height: 1.25;
        margin: 0.2rem 0 0.4rem;
        letter-spacing: -0.01em;
    }

    .vote-meta-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-bottom: 0.4rem;
    }

    .vote-meta-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: var(--vote-radius-sm);
        font-size: 0.75rem;
        background: #F1F5F9;
        color: var(--vote-dark);
        font-weight: 600;
    }

    .vote-meta-tag.primary {
        background: var(--vote-orange-subtle);
        color: var(--vote-orange);
        font-weight: 700;
    }

    /* Question officielle */
    .vote-question-box {
        background: #F8FAFC;
        border-left: 3px solid var(--vote-orange);
        padding: 0.55rem 0.85rem;
        border-radius: 0 var(--vote-radius-sm) var(--vote-radius-sm) 0;
        margin: 0.4rem 0 0.55rem;
    }

    .vote-question-box strong {
        display: block;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--vote-orange);
        font-family: var(--vote-font-mono);
        margin-bottom: 0.15rem;
    }

    .vote-question-box p {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--vote-dark);
        line-height: 1.35;
    }

    /* Grille de stats KPIs compacte */
    .vote-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 0.5rem;
        margin: 0.5rem 0 0.65rem;
        padding: 0.45rem 0;
        border-top: 1px solid var(--vote-border);
        border-bottom: 1px solid var(--vote-border);
    }

    .vote-stat-item {
        display: flex;
        flex-direction: column;
    }

    .vote-stat-value {
        font-family: var(--vote-font-mono);
        font-size: 1.25rem;
        font-weight: 900;
        color: var(--vote-dark);
        line-height: 1.1;
    }

    .vote-stat-value.highlight {
        color: var(--vote-orange);
    }

    .vote-stat-label {
        font-size: 0.68rem;
        color: var(--vote-gray-muted);
        font-weight: 600;
        margin-top: 0.15rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    /* Description & Détails compacts */
    .vote-description-area {
        margin: 0.35rem 0 0;
        color: #334155;
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .vote-description-area h4 {
        font-size: 0.72rem;
        text-transform: uppercase;
        font-weight: 800;
        letter-spacing: 0.05em;
        color: var(--vote-gray-muted);
        margin: 0 0 0.2rem;
    }

    /* SECTION CANDIDATS / PERSONNES À VOTER */
    .vote-candidates-section {
        margin-top: 2rem;
    }

    .vote-section-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.85rem;
        border-bottom: 2px solid var(--vote-dark);
    }

    .vote-section-title {
        font-family: var(--vote-font-display);
        font-size: clamp(1.4rem, 2.2vw, 1.85rem);
        font-weight: 800;
        color: var(--vote-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .vote-section-subtitle {
        margin: 0.25rem 0 0;
        color: var(--vote-gray-muted);
        font-size: 0.92rem;
    }

    /* Carrousel Horizontal Suisse Müller-Brockmann des Candidats */
    .vote-cands-carousel-wrap {
        position: relative;
        width: 100%;
        margin-top: 1rem;
    }

    .vote-cands-horizontal-track {
        display: flex;
        gap: 1.25rem;
        overflow-x: auto;
        overflow-y: hidden;
        scroll-snap-type: x mandatory;
        scroll-behavior: smooth;
        padding: 0.5rem 0.25rem 1.25rem;
        -webkit-overflow-scrolling: touch;
    }

    .vote-cands-horizontal-track::-webkit-scrollbar {
        height: 6px;
    }

    .vote-cands-horizontal-track::-webkit-scrollbar-track {
        background: #E2E8F0;
        border-radius: 999px;
    }

    .vote-cands-horizontal-track::-webkit-scrollbar-thumb {
        background: #CBD5E1;
        border-radius: 999px;
    }

    .vote-cands-horizontal-track::-webkit-scrollbar-thumb:hover {
        background: #FF4A0D;
    }

    .vote-cands-nav {
        display: inline-flex;
        gap: 6px;
    }

    .vote-carousel-btn {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 1px solid #CBD5E1;
        background: #ffffff;
        color: #0F172A;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.85rem;
    }

    .vote-carousel-btn:hover {
        background: #0F172A;
        color: #ffffff;
        border-color: #0F172A;
    }

    .vote-cand-card {
        flex: 0 0 215px;
        width: 215px;
        scroll-snap-align: start;
        background: #ffffff;
        border: 1px solid #E2E8F0;
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.2s ease;
    }

    .vote-cand-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.12);
        border-color: #FF4A0D;
    }

    .vote-cand-card.is-focused {
        outline: 3px solid #FF4A0D;
        box-shadow: 0 0 0 5px #FFF2ED;
    }

    .vote-cand-photo-wrap {
        position: relative;
        width: 100%;
        height: 230px;
        background: #0F172A;
        overflow: hidden;
        cursor: pointer;
    }

    .vote-cand-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.4s ease;
    }

    .vote-cand-card:hover .vote-cand-photo {
        transform: scale(1.04);
    }

    .vote-cand-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        background: rgba(15, 23, 42, 0.9);
        color: #ffffff;
        font-family: 'Space Mono', monospace;
        font-weight: 700;
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 999px;
        backdrop-filter: blur(4px);
        z-index: 2;
    }

    .vote-cand-badge.top-1 {
        background: #FF4A0D;
        color: #ffffff;
    }

    .vote-cand-body {
        padding: 0.65rem 0.75rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .vote-cand-nom {
        font-size: 0.88rem;
        font-weight: 800;
        color: #0F172A;
        margin: 0 0 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.25;
    }

    .vote-cand-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: 'Space Mono', monospace;
        font-size: 0.72rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }

    .vote-cand-gauge-bg {
        width: 100%;
        height: 4px;
        background: #E2E8F0;
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 0.55rem;
    }

    .vote-cand-gauge-fill {
        height: 100%;
        background: linear-gradient(90deg, #FF4A0D, #FF7A3D);
        border-radius: 999px;
        transition: width 0.5s ease;
    }

    .vote-cand-actions {
        display: grid;
        grid-template-columns: 1fr 1.3fr;
        gap: 6px;
        margin-top: auto;
    }

    .btn-cand-detail {
        background: #F8FAFC;
        color: #0F172A;
        border: 1px solid #CBD5E1;
        font-weight: 700;
        font-size: 0.78rem;
        padding: 0.5rem 0.35rem;
        border-radius: 6px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        transition: all 0.2s ease;
    }

    .btn-cand-detail:hover {
        background: #E2E8F0;
    }

    .btn-cand-vote {
        background: #FF4A0D;
        color: #ffffff;
        border: none;
        font-weight: 700;
        font-size: 0.78rem;
        padding: 0.5rem 0.35rem;
        border-radius: 6px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        box-shadow: 0 2px 6px rgba(255, 74, 13, 0.25);
        transition: all 0.2s ease;
    }

    .btn-cand-vote:hover {
        background: #E03E05;
        transform: translateY(-1px);
    }

    .btn-cand-vote.voted {
        background: #0F172A;
        color: #ffffff;
    }

    .candidat-modal-box {
        max-width: 580px !important;
        padding: 1.5rem !important;
    }

    .candidat-detail-hero {
        display: flex;
        gap: 1rem;
        margin-bottom: 1rem;
        align-items: center;
    }

    .candidat-detail-photo-wrap {
        width: 100px;
        height: 100px;
        flex-shrink: 0;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        background: #0F172A;
        border: 2px solid #E2E8F0;
    }

    .candidat-detail-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .candidat-detail-meta {
        display: flex;
        flex-direction: column;
        justify-content: center;
        flex: 1;
        min-width: 0;
    }

    .candidat-detail-nom {
        font-size: 1.2rem;
        font-weight: 800;
        color: #0F172A;
        margin: 0 0 0.25rem;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .candidat-detail-event {
        font-size: 0.8rem;
        color: #64748B;
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 0.4rem;
    }

    .candidat-detail-kpi-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .candidat-detail-kpi {
        background: #F8FAFC;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.55rem 0.75rem;
    }

    .candidat-detail-kpi small {
        display: block;
        font-size: 0.68rem;
        text-transform: uppercase;
        color: #64748B;
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    .candidat-detail-kpi strong {
        font-family: 'Space Mono', monospace;
        font-size: 1.15rem;
        font-weight: 900;
        color: #0F172A;
    }

    .candidat-detail-desc-box {
        background: #F8FAFC;
        border: 1px solid #E2E8F0;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        margin-bottom: 1.15rem;
        max-height: 140px;
        overflow-y: auto;
    }

    .candidat-detail-desc-box h4 {
        margin: 0 0 0.35rem;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748B;
        font-weight: 800;
    }

    .candidat-detail-desc-text {
        font-size: 0.88rem;
        line-height: 1.55;
        color: #334155;
        margin: 0;
        white-space: pre-line;
    }

    /* Alignement vertical et grande photo des candidats sur mobile */
    @media (max-width: 768px) {
        .vote-cands-nav {
            display: none !important;
        }

        .vote-cands-horizontal-track {
            flex-direction: column !important;
            overflow-x: visible !important;
            overflow-y: visible !important;
            scroll-snap-type: none !important;
            align-items: center !important;
            gap: 1.5rem !important;
            padding: 0.5rem 0 2rem !important;
        }

        .vote-cand-card {
            flex: 0 0 auto !important;
            width: 100% !important;
            max-width: 340px !important;
            scroll-snap-align: none !important;
            border-radius: 14px !important;
        }

        .vote-cand-photo-wrap {
            height: 320px !important;
            min-height: 280px !important;
        }

        .vote-cand-photo {
            object-fit: cover !important;
            object-position: center top !important;
        }

        .candidat-detail-hero {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .candidat-detail-photo-wrap {
            width: 80px;
            height: 80px;
        }
    }

    .poster-floating-share-btn {
        display: none !important;
    }

    .candidate-card {
        border-radius: var(--vote-radius-md);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        position: relative;
    }

    .candidate-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px -6px rgba(15, 23, 42, 0.12);
        border-color: #CBD5E1;
    }

    .candidate-card.is-focused {
        outline: 3px solid var(--vote-orange);
        box-shadow: 0 0 0 6px var(--vote-orange-subtle);
        animation: flashFocus 1.2s ease-in-out;
    }

    @keyframes flashFocus {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.03);
        }
    }

    .candidate-photo-wrap {
        position: relative;
        width: 100%;
        padding-top: 105%;
        background: #0f172a;
        overflow: hidden;
    }

    .candidate-photo {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .candidate-card:hover .candidate-photo {
        transform: scale(1.05);
    }

    .candidate-rank-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: rgba(15, 23, 42, 0.9);
        backdrop-filter: blur(6px);
        color: #ffffff;
        font-family: var(--vote-font-mono);
        font-weight: 700;
        font-size: 0.78rem;
        padding: 3px 9px;
        border-radius: var(--vote-radius-full);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
        z-index: 2;
    }

    .candidate-rank-badge.top-1 {
        background: var(--vote-orange);
        color: #ffffff;
        border-color: transparent;
    }

    .candidate-share-btn-wrap {
        position: absolute;
        top: 12px;
        right: 12px;
        z-index: 2;
    }

    .btn-candidate-share {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(6px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #ffffff;
        display: grid;
        place-items: center;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-candidate-share:hover {
        background: var(--vote-orange);
        border-color: var(--vote-orange);
        transform: scale(1.1);
    }

    .candidate-body {
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .candidate-name {
        font-family: var(--vote-font-display);
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--vote-dark);
        margin: 0 0 0.4rem;
        line-height: 1.3;
    }

    .candidate-desc {
        font-size: 0.85rem;
        color: var(--vote-gray-muted);
        line-height: 1.5;
        margin: 0 0 1rem;
        flex: 1;
    }

    /* Jauge de votes du candidat */
    .candidate-progress-wrap {
        margin: auto 0 1rem;
    }

    .candidate-progress-stats {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 0.8rem;
        margin-bottom: 0.35rem;
    }

    .candidate-votes-count {
        font-family: var(--vote-font-mono);
        font-weight: 700;
        color: var(--vote-dark);
    }

    .candidate-pct-count {
        font-family: var(--vote-font-mono);
        font-weight: 700;
        color: var(--vote-orange);
    }

    .candidate-progress-bar {
        width: 100%;
        height: 8px;
        background: #E2E8F0;
        border-radius: 999px;
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .candidate-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #FF4A0D, #FF7A3D);
        border-radius: 999px;
        transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* Boutons d'action pour voter */
    .btn-vote-candidate {
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.72rem 1rem;
        border-radius: var(--vote-radius-sm);
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        border: none;
        font-family: inherit;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        background: var(--vote-orange);
        color: #ffffff;
        box-shadow: 0 2px 8px rgba(255, 74, 13, 0.25);
    }

    .btn-vote-candidate:hover {
        background: #E03E05;
        transform: translateY(-1px);
        box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);
    }

    .btn-vote-candidate.voted {
        background: #0F172A;
        color: #ffffff;
        box-shadow: none;
    }

    .btn-vote-candidate.voted:hover {
        background: #DC2626;
        color: #ffffff;
    }

    /* Notification Toast */
    .vote-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #0F172A;
        color: #ffffff;
        padding: 1rem 1.35rem;
        border-radius: var(--vote-radius-md);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.92rem;
        font-weight: 600;
        z-index: 9999;
        transform: translateY(120%);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .vote-toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .vote-toast i {
        color: #10B981;
        font-size: 1.2rem;
    }
</style>

<div class="vote-page-wrap">

    <!-- Fil d'Ariane & Boutons de retour / partage -->
    <div class="vote-topbar">
        <div class="vote-breadcrumb">
            <a href="accueil.php"><i class="fa-solid fa-house"></i> Accueil</a>
            <span>/</span>
            <a href="accueil.php?onglet=voter"><i class="fa-solid fa-trophy"></i> Concours & Votes</a>
            <span>/</span>
            <span
                style="color: var(--vote-dark); font-weight: 700;"><?php echo htmlspecialchars($event['nom']); ?></span>
        </div>

        <div class="vote-topbar-actions">
            <button type="button" class="vote-btn-share-top"
                onclick="openShareVotePage(<?php echo (int) $event['id']; ?>, '<?php echo htmlspecialchars(addslashes($event['nom'])); ?>')">
                <i class="fa-solid fa-share-nodes" style="color: var(--vote-orange);"></i> Partager ce concours
            </button>
            <a href="accueil.php?onglet=voter" class="vote-btn-share-top">
                <i class="fa-solid fa-arrow-left"></i> Tous les concours
            </a>
        </div>
    </div>

    <!-- CARTE HERO : PRÉSENTATION DE L'ÉVÉNEMENT & DESCRIPTION -->
    <section class="vote-hero-card">
        <div class="vote-hero-media">
            <img src="<?php echo htmlspecialchars($event_img, ENT_QUOTES, 'UTF-8'); ?>"
                alt="<?php echo htmlspecialchars($event['nom']); ?>" class="vote-hero-img" loading="eager"
                decoding="async" onerror="this.onerror=null; this.src='<?php echo $default_vote_img; ?>';">

            <span class="vote-badge-chip orange" style="position: absolute; top: 12px; left: 12px; z-index: 2;">
                <i class="fa-solid fa-trophy"></i>
                <?php echo $type_vote === 'concours' ? 'Concours Officiel' : 'Vote Événementiel'; ?>
            </span>
        </div>

        <div class="vote-hero-content">
            <div>
                <div class="vote-meta-tags">
                    <span class="vote-meta-tag primary"><i class="fa-solid fa-tag"></i>
                        <?php echo htmlspecialchars($event['categorie']); ?></span>
                    <span class="vote-meta-tag"><i class="fa-regular fa-calendar"></i>
                        <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?></span>
                    <span class="vote-meta-tag"><i class="fa-solid fa-location-dot"></i>
                        <?php echo htmlspecialchars($event['lieu']); ?></span>
                    <?php if ($est_payant): ?>
                        <span class="vote-meta-tag" style="background: #FFF2ED; color: #FF4A0D; font-weight: 700;"><i
                                class="fa-solid fa-coins"></i> <?php echo number_format($prix_vote, 0, ',', ' '); ?> F /
                            vote</span>
                    <?php else: ?>
                        <span class="vote-meta-tag" style="background: #ECFDF5; color: #059669; font-weight: 700;"><i
                                class="fa-solid fa-gift"></i> Vote Gratuit</span>
                    <?php endif; ?>
                    <?php if (!empty($event['promoteur_nom'])): ?>
                        <span class="vote-meta-tag"><i class="fa-solid fa-user-tie"></i>
                            <?php echo htmlspecialchars($event['promoteur_nom']); ?></span>
                    <?php endif; ?>
                </div>

                <h1 class="vote-hero-title"><?php echo htmlspecialchars($event['nom']); ?></h1>

                <?php if ($is_private_event): ?>
                    <div
                        style="display: inline-flex; align-items: center; gap: 8px; background: rgba(255, 74, 13, 0.08); border: 1px solid rgba(255, 74, 13, 0.25); color: #FF4A0D; padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.85rem;">
                        <i class="fa-solid fa-user-shield"></i> Scrutin Privé — Participation réservée exclusivement aux
                        personnes inscrites sur la liste des invités autorisés.
                    </div>
                <?php endif; ?>

                <?php if (!empty($event['vote_question'])): ?>
                    <div class="vote-question-box">
                        <strong>Question officielle du scrutin :</strong>
                        <p>« <?php echo htmlspecialchars($event['vote_question']); ?> »</p>
                    </div>
                <?php endif; ?>

                <!-- ===== TIMER FIN DES VOTES ===== -->
                <div id="voteCountdownCard"
                    style="background: #0F172A; border-radius: 12px; padding: 1rem 1.25rem; margin: 0.85rem 0;<?php echo ($is_expired ?? false) ? ' display: none;' : ''; ?>">
                    <p
                        style="font-family: 'Space Mono', monospace; font-size: 0.6rem; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.1em; margin: 0 0 0.5rem;">
                        <i class="fa-solid fa-hourglass-half" style="color: #FF4A0D;"></i>&nbsp; Fin des votes ·
                        <span
                            style="color: #475569;"><?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?><?php echo !empty($event['heure']) ? ' à ' . substr($event['heure'], 0, 5) : ''; ?></span>
                    </p>
                    <div id="voteCountdownGrid" style="display: flex; align-items: flex-start; gap: 0;">
                        <div style="text-align: center; padding: 0 0.75rem 0 0;">
                            <span id="cdDays"
                                style="display: block; font-family: 'Space Mono', monospace; font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 900; color: #FF4A0D; line-height: 1;">00</span>
                            <span
                                style="font-family: 'Space Mono', monospace; font-size: 0.55rem; font-weight: 700; color: #475569; letter-spacing: 0.08em; text-transform: uppercase;">Jours</span>
                        </div>
                        <span
                            style="font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 900; color: #FF4A0D; line-height: 1; padding-top: 2px;">:</span>
                        <div style="text-align: center; padding: 0 0.75rem;">
                            <span id="cdHours"
                                style="display: block; font-family: 'Space Mono', monospace; font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 900; color: #FF4A0D; line-height: 1;">00</span>
                            <span
                                style="font-family: 'Space Mono', monospace; font-size: 0.55rem; font-weight: 700; color: #475569; letter-spacing: 0.08em; text-transform: uppercase;">Heures</span>
                        </div>
                        <span
                            style="font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 900; color: #FF4A0D; line-height: 1; padding-top: 2px;">:</span>
                        <div style="text-align: center; padding: 0 0.75rem;">
                            <span id="cdMinutes"
                                style="display: block; font-family: 'Space Mono', monospace; font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 900; color: #FF4A0D; line-height: 1;">00</span>
                            <span
                                style="font-family: 'Space Mono', monospace; font-size: 0.55rem; font-weight: 700; color: #475569; letter-spacing: 0.08em; text-transform: uppercase;">Min</span>
                        </div>
                        <span
                            style="font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 900; color: #FF4A0D; line-height: 1; padding-top: 2px;">:</span>
                        <div style="text-align: center; padding: 0 0 0 0.75rem;">
                            <span id="cdSeconds"
                                style="display: block; font-family: 'Space Mono', monospace; font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 900; color: #ffffff; line-height: 1;">00</span>
                            <span
                                style="font-family: 'Space Mono', monospace; font-size: 0.55rem; font-weight: 700; color: #475569; letter-spacing: 0.08em; text-transform: uppercase;">Sec</span>
                        </div>
                    </div>
                </div>

                <!-- Scrutin expiré -->
                <div id="cdExpiredMsg"
                    style="<?php echo ($is_expired ?? false) ? 'display:flex;' : 'display:none;'; ?> align-items:center; gap:8px; background:#FEF2F2; border:1px solid #FCA5A5; border-radius:8px; padding:0.65rem 0.9rem; margin:0.85rem 0; font-size:0.85rem; font-weight:700; color:#DC2626;">
                    <i class="fa-solid fa-circle-exclamation"></i> Scrutin clos
                </div>
            </div>

            <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php if (!empty($candidats)): ?>
                    <a href="#candidats" class="btn-vote-candidate"
                        style="width: auto; padding: 0.55rem 1.15rem; font-size: 0.82rem; text-decoration: none;">
                        <i class="fa-solid fa-check-to-slot"></i> Voir les personnes à voter
                    </a>
                <?php else: ?>
                    <button type="button" class="btn-vote-candidate <?php echo $has_voted_event ? 'voted' : ''; ?>"
                        style="width: auto; padding: 0.55rem 1.15rem; font-size: 0.82rem;"
                        onclick="voteDirectEvent(<?php echo (int) $event['id']; ?>, <?php echo $est_payant ? 'true' : 'false'; ?>, <?php echo (float) $prix_vote; ?>, this)">
                        <i class="fa-solid <?php echo $has_voted_event ? 'fa-check' : 'fa-thumbs-up'; ?>"></i>
                        <?php echo $has_voted_event ? 'Déjà voté' : ($est_payant ? 'Voter (' . number_format($prix_vote, 0, ',', ' ') . ' F)' : 'Voter pour cet événement'); ?>
                    </button>
                <?php endif; ?>

                <?php if ($has_tickets): ?>
                    <a href="evenement/<?php echo rawurlencode($event['slug'] ?: (string) $event['id']); ?>" class="btn-vote-candidate"
                        style="width: auto; padding: 0.55rem 1.15rem; font-size: 0.82rem; text-decoration: none; background: #0F172A;">
                        <i class="fa-solid fa-ticket"></i> Réserver vos places (Billetterie)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- SECTION : LISTE DES PERSONNES À VOTER (CANDIDATS) -->
    <section class="vote-candidates-section" id="candidats">
        <div class="vote-section-header">
            <div>
                <h2 class="vote-section-title">
                    <i class="fa-solid fa-users" style="color: var(--vote-orange);"></i> Liste des personnes à voter
                </h2>
                <p class="vote-section-subtitle">
                    Découvrez les candidats en compétition ci-dessous et votez pour soutenir votre préféré.
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div
                    style="font-family: var(--vote-font-mono); font-weight: 700; color: var(--vote-gray-muted); font-size: 0.85rem;">
                    <?php echo count($candidats); ?> candidat(s) en lice
                </div>
                <?php if (count($candidats) > 1): ?>
                    <div class="vote-cands-nav">
                        <button type="button" class="vote-carousel-btn" onclick="scrollPageCands(-1)" aria-label="Précédent"
                            title="Défiler vers la gauche">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <button type="button" class="vote-carousel-btn" onclick="scrollPageCands(1)" aria-label="Suivant"
                            title="Défiler vers la droite">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($candidats)): ?>
            <div class="vote-cands-carousel-wrap">
                <div class="vote-cands-horizontal-track" id="votePageCandsTrack">
                    <?php
                    $rank = 1;
                    foreach ($candidats as $cand):
                        $cid = (int) $cand['id'];
                        $c_votes = (int) $cand['nb_votes_cand'];
                        $c_pct = ($total_votes > 0) ? round(($c_votes / $total_votes) * 100, 1) : 0;
                        $is_voted = isset($voted_candidates[$cid]);

                        // Photo avec fallback
                        $c_photo = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=600&q=80';
                        if (!empty($cand['photo'])) {
                            if (strpos($cand['photo'], 'http') === 0) {
                                $c_photo = htmlspecialchars($cand['photo']);
                            } elseif (file_exists('../uploads/candidats/' . $cand['photo'])) {
                                $c_photo = '../uploads/candidats/' . htmlspecialchars($cand['photo']);
                            }
                        }
                        $is_focus = ($candidat_focus_id === $cid);
                        ?>
                        <article class="vote-cand-card <?php echo $is_focus ? 'is-focused' : ''; ?>"
                            id="candidat-<?php echo $cid; ?>" style="cursor: pointer;"
                            onclick="openCandidateDetailModal(<?php echo $cid; ?>)"
                            title="Cliquer pour voir la fiche détaillée de <?php echo htmlspecialchars($cand['nom']); ?>">
                            <div class="vote-cand-photo-wrap"
                                title="Voir les détails de <?php echo htmlspecialchars($cand['nom']); ?>">
                                <img src="<?php echo $c_photo; ?>" alt="<?php echo htmlspecialchars($cand['nom']); ?>"
                                    class="vote-cand-photo" loading="lazy">

                                <span class="vote-cand-badge <?php echo ($rank === 1) ? 'top-1' : ''; ?>">
                                    <?php echo ($rank === 1) ? '★ #1 en tête' : '#' . $rank; ?>
                                </span>
                            </div>

                            <div class="vote-cand-body">
                                <h3 class="vote-cand-nom" title="<?php echo htmlspecialchars($cand['nom']); ?>">
                                    <?php echo htmlspecialchars($cand['nom']); ?>
                                </h3>

                                <!-- Baromètre individuel -->
                                <div class="vote-cand-stats">
                                    <span class="candidate-votes-count" id="cand-count-<?php echo $cid; ?>">
                                        <?php echo number_format($c_votes, 0, ',', ' '); ?>
                                        vote<?php echo ($c_votes > 1) ? 's' : ''; ?>
                                    </span>
                                    <span class="candidate-pct-count" id="cand-pct-<?php echo $cid; ?>"
                                        style="color: #FF4A0D; background: #FFF2ED; padding: 1px 4px; border-radius: 3px; font-size: 0.68rem;">
                                        <?php echo $c_pct; ?>%
                                    </span>
                                </div>
                                <div class="vote-cand-gauge-bg">
                                    <div class="vote-cand-gauge-fill" id="cand-bar-<?php echo $cid; ?>"
                                        style="width: <?php echo $c_pct; ?>%;"></div>
                                </div>

                                <!-- Action : Vote direct pleine largeur -->
                                <div class="vote-cand-actions" style="display: grid; grid-template-columns: 1fr; gap: 0;">
                                    <?php if ($est_payant): ?>
                                        <button type="button" class="btn-cand-vote"
                                            style="width: 100%; justify-content: center; padding: 0.55rem 0.75rem; font-size: 0.82rem;"
                                            onclick="event.stopPropagation(); initiatePaidVote(<?php echo (int) $event['id']; ?>, <?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($cand['nom'])); ?>', <?php echo (float) $prix_vote; ?>)"
                                            title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
                                            <i class="fa-solid fa-coins"></i>
                                            <span>Voter (<?php echo number_format($prix_vote, 0, ',', ' '); ?> F)</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-cand-vote <?php echo $is_voted ? 'voted' : ''; ?>"
                                            style="width: 100%; justify-content: center; padding: 0.55rem 0.75rem; font-size: 0.82rem;"
                                            id="btn-vote-cand-<?php echo $cid; ?>"
                                            onclick="event.stopPropagation(); toggleFreeCandVote(<?php echo (int) $event['id']; ?>, <?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($cand['nom'])); ?>', this)"
                                            title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
                                            <i class="fa-solid <?php echo $is_voted ? 'fa-circle-check' : 'fa-thumbs-up'; ?>"></i>
                                            <span><?php echo $is_voted ? 'Voté' : 'Voter'; ?></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                        <?php
                        $rank++;
                    endforeach;
                    ?>
                </div>
            </div>
        <?php else: ?>
            <div
                style="background: #ffffff; border: 1px solid var(--vote-border); border-radius: var(--vote-radius-md); padding: 3rem 1.5rem; text-align: center; color: var(--vote-gray-muted);">
                <i class="fa-solid fa-user-group"
                    style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 1rem; display: block;"></i>
                <h3 style="color: var(--vote-dark); margin: 0 0 0.5rem;">Aucun candidat individuel enregistré</h3>
                <p style="margin: 0 0 1.5rem; font-size: 0.95rem;">Le scrutin pour cet événement est global. Vous pouvez
                    voter directement pour l'événement ci-dessus.</p>
                <button type="button" class="btn-vote-candidate <?php echo $has_voted_event ? 'voted' : ''; ?>"
                    style="width: auto; margin: 0 auto;"
                    onclick="voteDirectEvent(<?php echo (int) $event['id']; ?>, <?php echo $est_payant ? 'true' : 'false'; ?>, <?php echo (float) $prix_vote; ?>, this)">
                    <i class="fa-solid <?php echo $has_voted_event ? 'fa-check' : 'fa-thumbs-up'; ?>"></i>
                    <?php echo $has_voted_event ? 'Déjà voté' : ($est_payant ? 'Voter (' . number_format($prix_vote, 0, ',', ' ') . ' F)' : 'Voter maintenant'); ?>
                </button>
            </div>
        <?php endif; ?>
    </section>

</div>

<!-- TOAST DE CONFIRMATION -->
<div class="vote-toast" id="voteToast">
    <i class="fa-solid fa-circle-check"></i>
    <span id="voteToastMsg">Votre vote a bien été pris en compte !</span>
</div>

<!-- MODAL PARTAGE DÉDIÉE VOTE -->
<div class="share-modal-overlay" id="voteShareModal"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; place-items: center; padding: 1rem;"
    onclick="if (event.target === this) closeVoteShareModal();">
    <div
        style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div
                style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager
            </div>
            <button type="button" onclick="closeVoteShareModal()"
                style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <h4 id="shareModalTargetTitle"
                style="margin: 0 0 0.25rem; font-size: 1.05rem; color: #0F172A; font-weight: 700;"></h4>
            <p id="shareModalTargetSubtitle" style="margin: 0 0 1.25rem; font-size: 0.85rem; color: #64748B;"></p>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748B; margin-bottom: 0.4rem;">Lien
                    direct officiel</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="shareDirectLinkInput" readonly
                        style="flex: 1; padding: 0.65rem 0.85rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.85rem; background: #F8FAFC; color: #0F172A;">
                    <button type="button" id="shareCopyBtn" onclick="copyShareLink()"
                        style="background: #0F172A; color: #ffffff; border: none; padding: 0 1rem; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-regular fa-copy"></i> Copier
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <a id="shareWaLink" href="#" target="_blank"
                    style="background: #25D366; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> WhatsApp
                </a>
                <a id="shareFbLink" href="#" target="_blank"
                    style="background: #1877F2; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-facebook-f"></i> Facebook
                </a>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DÉTAILS CANDIDAT DÉDIÉE (vote.php) -->
<div class="share-modal-overlay" id="candPageDetailModal"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: clamp(0.5rem, 2vw, 1.25rem); box-sizing: border-box; overflow-y: auto;"
    onclick="if (event.target === this) closeCandidateDetailModal();">
    <div
        style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1); margin: auto;">
        <div
            style="padding: 1rem 1.25rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: #ffffff;">
            <div
                style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.05rem;">
                <i class="fa-solid fa-check-to-slot" style="color: #FF4A0D;"></i> Profil Officiel du Candidat
            </div>
            <button type="button" onclick="closeCandidateDetailModal()"
                style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px; line-height: 1;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div
            style="padding: 1.25rem; overflow-y: auto; -webkit-overflow-scrolling: touch; flex: 1 1 auto; min-height: 0;">
            <div class="candidat-detail-hero">
                <div class="candidat-detail-photo-wrap">
                    <img id="candPagePhoto" src="" alt="Photo du candidat" class="candidat-detail-photo">
                    <span id="candPageRankBadge" class="vote-cand-badge top-1">#1</span>
                </div>
                <div class="candidat-detail-meta">
                    <div>
                        <h3 id="candPageNom" class="candidat-detail-nom" style="margin-top: 0;"></h3>
                        <div class="candidat-detail-event">
                            <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i>
                            <span><?php echo htmlspecialchars($event['nom']); ?></span>
                        </div>
                    </div>

                    <div class="candidat-detail-kpi-grid">
                        <div class="candidat-detail-kpi">
                            <small>Total des voix</small>
                            <strong id="candPageVotesCount" style="color: #0F172A;">0</strong>
                        </div>
                        <div class="candidat-detail-kpi">
                            <small>Part des votes</small>
                            <strong id="candPagePctCount" style="color: #FF4A0D;">0%</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="candidat-detail-desc-box">
                <h4><i class="fa-solid fa-id-card" style="color: #FF4A0D; margin-right: 4px;"></i> Biographie &
                    Présentation</h4>
                <p id="candPageBio" class="candidat-detail-desc-text"></p>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <div
                    style="display: flex; justify-content: space-between; font-size: 0.78rem; font-family: var(--vote-font-mono); font-weight: 700; margin-bottom: 4px;">
                    <span style="color: #0F172A;">Baromètre du scrutin</span>
                    <span id="candPageGaugePct" style="color: #FF4A0D;">0%</span>
                </div>
                <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                    <div id="candPageGaugeFill"
                        style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s ease;">
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <button type="button" id="candPageVoteBtn" class="btn-vote-candidate"
                    style="padding: 0.8rem; font-size: 0.95rem;">
                    <i class="fa-solid fa-thumbs-up"></i> <span id="candPageVoteBtnTxt">Voter pour elle</span>
                </button>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                    <button type="button" id="candPageShareBtn" class="btn-cand-detail"
                        style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700;">
                        <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager
                    </button>
                    <button type="button" onclick="closeCandidateDetailModal()" class="btn-cand-detail"
                        style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700; color: #64748B;">
                        <i class="fa-solid fa-xmark"></i> Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modale d'identification pour scrutin privé -->
<div id="modalPrivateVotePhone" class="candidat-modal-overlay" style="display: none; z-index: 9999;">
    <div class="candidat-modal-card" style="max-width: 420px; padding: 1.5rem;">
        <div style="text-align: center; margin-bottom: 1.25rem;">
            <div
                style="width: 50px; height: 50px; background: rgba(255, 74, 13, 0.1); border: 1px solid rgba(255, 74, 13, 0.3); color: #FF4A0D; border-radius: 12px; display: grid; place-items: center; margin: 0 auto 0.75rem; font-size: 1.35rem;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h3 style="margin: 0 0 0.4rem; font-size: 1.15rem; font-weight: 800; color: #0F172A;">Scrutin Privé &
                Confidentiel</h3>
            <p style="margin: 0; font-size: 0.84rem; color: #64748B; line-height: 1.5;">
                Ce vote est strictement réservé aux invités enregistrés au préalable par l'organisateur. Veuillez saisir
                votre numéro de téléphone pour vérifier votre éligibilité.
            </p>
        </div>

        <div style="margin-bottom: 1.25rem;">
            <label for="privateVotePhoneField"
                style="font-size: 0.78rem; font-weight: 700; color: #0F172A; display: block; margin-bottom: 6px;">
                Numéro de téléphone enregistré *
            </label>
            <input type="tel" id="privateVotePhoneField" placeholder="Ex: 07 01 02 03 04"
                style="width: 100%; padding: 0.75rem 0.9rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.95rem; font-family: inherit; box-sizing: border-box;">
            <span id="privateVotePhoneError"
                style="display: none; color: #EF4444; font-size: 0.75rem; font-weight: 600; margin-top: 4px;"></span>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <button type="button" onclick="closePrivatePhoneModal()"
                style="flex: 1; padding: 0.75rem; background: #F1F5F9; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.85rem; font-weight: 700; color: #64748B; cursor: pointer;">
                Annuler
            </button>
            <button type="button" onclick="confirmPrivateVotePhone()"
                style="flex: 1; padding: 0.75rem; background: #FF4A0D; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 700; color: #FFFFFF; cursor: pointer;">
                Valider mon vote
            </button>
        </div>
    </div>
</div>

<script>
    let totalVotesGlobal = <?php echo (int) $total_votes; ?>;
    const pageCandidats = <?php echo json_encode($candidats ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const isPayantGlobal = <?php echo $est_payant ? 'true' : 'false'; ?>;
    const prixVoteGlobal = <?php echo (float) $prix_vote; ?>;
    const eventIdGlobal = <?php echo (int) $event['id']; ?>;
    const eventNomGlobal = '<?php echo htmlspecialchars(addslashes($event['nom'])); ?>';
    const isPrivateGlobal = <?php echo $is_private_event ? 'true' : 'false'; ?>;
    const eventTokenGlobal = '<?php echo htmlspecialchars($event_token, ENT_QUOTES); ?>';
    let userPhoneGlobal = '<?php echo htmlspecialchars($user_telephone, ENT_QUOTES); ?>' || sessionStorage.getItem('tikeli_voter_phone_' + eventIdGlobal) || '';
    let pendingVoteAction = null;

    function openPrivatePhoneModal(callback) {
        pendingVoteAction = callback;
        const modal = document.getElementById('modalPrivateVotePhone');
        const input = document.getElementById('privateVotePhoneField');
        const err = document.getElementById('privateVotePhoneError');
        if (err) err.style.display = 'none';
        if (input) {
            input.value = userPhoneGlobal;
            setTimeout(() => input.focus(), 150);
        }
        if (modal) modal.style.display = 'grid';
    }

    function closePrivatePhoneModal() {
        const modal = document.getElementById('modalPrivateVotePhone');
        if (modal) modal.style.display = 'none';
        pendingVoteAction = null;
    }

    function confirmPrivateVotePhone() {
        const input = document.getElementById('privateVotePhoneField');
        const err = document.getElementById('privateVotePhoneError');
        const val = input ? input.value.trim() : '';
        if (!val || val.length < 8) {
            if (err) {
                err.textContent = "Veuillez saisir un numéro de téléphone valide.";
                err.style.display = 'block';
            }
            return;
        }
        userPhoneGlobal = val;
        try {
            sessionStorage.setItem('tikeli_voter_phone_' + eventIdGlobal, userPhoneGlobal);
        } catch (e) { }
        closePrivatePhoneModal();
        if (typeof pendingVoteAction === 'function') {
            pendingVoteAction();
        }
    }

    // Défilement horizontal des candidats
    function scrollPageCands(direction) {
        const track = document.getElementById('votePageCandsTrack');
        if (track) {
            track.scrollBy({ left: direction * 235, behavior: 'smooth' });
        }
    }

    // Modale de détails d'un candidat
    function openCandidateDetailModal(candId) {
        const modal = document.getElementById('candPageDetailModal');
        if (!modal) return;

        const cand = pageCandidats.find(c => Number(c.id) === Number(candId));
        if (!cand) return;

        const rankIdx = pageCandidats.findIndex(c => Number(c.id) === Number(candId));
        const rankNum = (rankIdx !== -1) ? (rankIdx + 1) : 1;
        const isTop1 = (rankNum === 1);

        const cVotes = Number(cand.nb_votes_cand || 0);
        const cPct = (totalVotesGlobal > 0) ? Math.min(100, Math.round((cVotes / totalVotesGlobal) * 1000) / 10) : 0;

        let cPhoto = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=600&q=80';
        if (cand.photo) {
            cPhoto = cand.photo.startsWith('http') ? cand.photo : ('../uploads/candidats/' + cand.photo);
        }

        document.getElementById('candPagePhoto').src = cPhoto;
        const rankBadge = document.getElementById('candPageRankBadge');
        rankBadge.className = 'vote-cand-badge ' + (isTop1 ? 'top-1' : '');
        rankBadge.textContent = isTop1 ? '★ #1 en tête' : ('#' + rankNum + ' en lice');

        document.getElementById('candPageNom').textContent = cand.nom;
        document.getElementById('candPageVotesCount').textContent = cVotes.toLocaleString('fr-FR');
        document.getElementById('candPagePctCount').textContent = cPct + '%';
        document.getElementById('candPageGaugePct').textContent = cPct + '%';
        document.getElementById('candPageGaugeFill').style.width = cPct + '%';
        document.getElementById('candPageBio').textContent = cand.description && cand.description.trim()
            ? cand.description
            : "Candidat(e) officiel(le) en lice. Soutenez sa candidature avec votre vote !";

        const voteBtn = document.getElementById('candPageVoteBtn');
        const voteBtnTxt = document.getElementById('candPageVoteBtnTxt');
        if (isPayantGlobal) {
            voteBtnTxt.textContent = `Voter pour ${cand.nom} (${prixVoteGlobal.toLocaleString('fr-FR')} F)`;
            voteBtn.onclick = function () {
                initiatePaidVote(eventIdGlobal, candId, cand.nom, prixVoteGlobal);
            };
        } else {
            const isVoted = document.getElementById(`btn-vote-cand-${candId}`)?.classList.contains('voted');
            voteBtnTxt.textContent = isVoted ? `Déjà voté pour ${cand.nom}` : `Voter pour ${cand.nom}`;
            voteBtn.onclick = function () {
                const cardBtn = document.getElementById(`btn-vote-cand-${candId}`);
                toggleFreeCandVote(eventIdGlobal, candId, cand.nom, cardBtn || voteBtn);
            };
        }

        const shareBtn = document.getElementById('candPageShareBtn');
        shareBtn.onclick = function () {
            shareCandidate(eventIdGlobal, eventNomGlobal, candId, cand.nom);
        };

        modal.style.display = 'flex';
    }

    function closeCandidateDetailModal() {
        const modal = document.getElementById('candPageDetailModal');
        if (modal) modal.style.display = 'none';
    }

    // Fermeture avec Échap
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeCandidateDetailModal();
            closePrivatePhoneModal();
        }
    });

    // Affichage d'un toast court
    function showVoteToast(message, isError = false) {
        const toast = document.getElementById('voteToast');
        const msgEl = document.getElementById('voteToastMsg');
        const icon = toast.querySelector('i');
        if (!toast || !msgEl) return;

        msgEl.textContent = message;
        if (isError) {
            icon.className = 'fa-solid fa-circle-exclamation';
            icon.style.color = '#EF4444';
        } else {
            icon.className = 'fa-solid fa-circle-check';
            icon.style.color = '#10B981';
        }
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3500);
    }

    // Vote Gratuit par candidat
    async function toggleFreeCandVote(eventId, candId, candNom, btn) {
        if (isPrivateGlobal && !userPhoneGlobal) {
            openPrivatePhoneModal(() => toggleFreeCandVote(eventId, candId, candNom, btn));
            return;
        }

        btn.disabled = true;
        const oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Traitement...';

        const formData = new FormData();
        formData.append('event_id', eventId);
        formData.append('candidat_id', candId);
        if (isPrivateGlobal) {
            formData.append('telephone', userPhoneGlobal);
            formData.append('token', eventTokenGlobal);
        }

        try {
            const res = await fetch('vote-event.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.error) {
                if (data.requires_phone) {
                    userPhoneGlobal = '';
                    openPrivatePhoneModal(() => toggleFreeCandVote(eventId, candId, candNom, btn));
                } else {
                    showVoteToast(data.error, true);
                }
                btn.innerHTML = oldHtml;
                btn.disabled = false;
                return;
            }

            // Succès : mise à jour UI
            if (data.voted) {
                btn.classList.add('voted');
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span class="btn-text-desktop">Voté pour ce candidat</span><span class="btn-text-mobile">Voté</span>';
                showVoteToast(`Votre vote a bien été enregistré pour ${candNom} !`);
            } else {
                btn.classList.remove('voted');
                btn.innerHTML = `<i class="fa-solid fa-check"></i> <span class="btn-text-desktop">Voter pour ${candNom}</span><span class="btn-text-mobile">Voter</span>`;
                showVoteToast(`Votre vote pour ${candNom} a été retiré.`);
            }

            // Mise à jour compteur candidat
            const countEl = document.getElementById(`cand-count-${candId}`);
            if (countEl && typeof data.cand_votes !== 'undefined') {
                countEl.textContent = `${data.cand_votes} vote${data.cand_votes > 1 ? 's' : ''}`;
            }

            // Mise à jour compteur total
            if (typeof data.votes !== 'undefined') {
                totalVotesGlobal = data.votes;
                const totalEl = document.getElementById('totalVotesDisplay');
                if (totalEl) totalEl.textContent = data.votes.toLocaleString('fr-FR');

                // Recalcul des barres de progression
                updateProgressGauges(candId, data.cand_votes);
            }

        } catch (err) {
            showVoteToast("Erreur de connexion au serveur de vote.", true);
            btn.innerHTML = oldHtml;
        } finally {
            btn.disabled = false;
        }
    }

    // Mise à jour des jauges
    function updateProgressGauges(candId, candVotes) {
        if (totalVotesGlobal <= 0) return;
        const pct = Math.round((candVotes / totalVotesGlobal) * 1000) / 10;
        const pctEl = document.getElementById(`cand-pct-${candId}`);
        const barEl = document.getElementById(`cand-bar-${candId}`);
        if (pctEl) pctEl.textContent = `${pct}%`;
        if (barEl) barEl.style.width = `${pct}%`;
    }

    // Vote Payant par candidat
    async function initiatePaidVote(eventId, candId, candNom, prix) {
        if (isPrivateGlobal && !userPhoneGlobal) {
            openPrivatePhoneModal(() => initiatePaidVote(eventId, candId, candNom, prix));
            return;
        }

        const formData = new FormData();
        formData.append('event_id', eventId);
        if (candId) formData.append('candidat_ids', candId);
        formData.append('phase', '2');
        if (isPrivateGlobal) {
            formData.append('telephone', userPhoneGlobal);
            formData.append('token', eventTokenGlobal);
        }

        try {
            const res = await fetch('vote-event.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.redirect) {
                window.location.href = data.redirect;
            } else if (data.error) {
                if (data.requires_phone) {
                    userPhoneGlobal = '';
                    openPrivatePhoneModal(() => initiatePaidVote(eventId, candId, candNom, prix));
                } else {
                    showVoteToast(data.error, true);
                }
            }
        } catch (err) {
            showVoteToast("Impossible d'initialiser le paiement sécurisé.", true);
        }
    }

    // Vote Direct pour l'événement
    async function voteDirectEvent(eventId, estPayant, prix, btn) {
        if (estPayant) {
            initiatePaidVote(eventId, null, 'Événement', prix);
            return;
        }
        if (isPrivateGlobal && !userPhoneGlobal) {
            openPrivatePhoneModal(() => voteDirectEvent(eventId, estPayant, prix, btn));
            return;
        }

        btn.disabled = true;
        const formData = new FormData();
        formData.append('event_id', eventId);
        if (isPrivateGlobal) {
            formData.append('telephone', userPhoneGlobal);
            formData.append('token', eventTokenGlobal);
        }

        try {
            const res = await fetch('vote-event.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                if (data.requires_phone) {
                    userPhoneGlobal = '';
                    openPrivatePhoneModal(() => voteDirectEvent(eventId, estPayant, prix, btn));
                } else {
                    showVoteToast(data.error, true);
                }
            } else {
                if (data.voted) {
                    btn.classList.add('voted');
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Déjà voté';
                    showVoteToast("Votre vote a été validé avec succès !");
                } else {
                    btn.classList.remove('voted');
                    btn.innerHTML = '<i class="fa-solid fa-thumbs-up"></i> Voter pour cet événement';
                    showVoteToast("Votre vote a été retiré.");
                }
                if (typeof data.votes !== 'undefined') {
                    totalVotesGlobal = data.votes;
                    const totalEl = document.getElementById('totalVotesDisplay');
                    if (totalEl) totalEl.textContent = data.votes.toLocaleString('fr-FR');
                }
            }
        } catch (err) {
            showVoteToast("Erreur lors de l'enregistrement du vote.", true);
        } finally {
            btn.disabled = false;
        }
    }

    // Partage du concours entier
    function openShareVotePage(eventId, eventNom) {
        const shareUrl = window.location.href.split('#')[0];
        showShareModal(eventNom, "Concours officiel de vote en ligne", shareUrl);
    }

    // Partage spécifique pour un candidat
    function shareCandidate(eventId, eventNom, candId, candNom) {
        const baseUrl = window.location.origin + window.location.pathname;
        const privParam = isPrivateGlobal && eventTokenGlobal ? `&token=${encodeURIComponent(eventTokenGlobal)}` : '';
        const shareUrl = `${baseUrl}?id=${eventId}&candidat_id=${candId}${privParam}#candidat-${candId}`;
        showShareModal(`Votez pour ${candNom} !`, `Candidat(e) dans « ${eventNom} » sur TikeWA`, shareUrl, candNom);
    }

    function showShareModal(title, subtitle, url, candNom = null) {
        const modal = document.getElementById('voteShareModal');
        if (!modal) return;

        document.getElementById('shareModalTargetTitle').textContent = title;
        document.getElementById('shareModalTargetSubtitle').textContent = subtitle;
        document.getElementById('shareDirectLinkInput').value = url;

        // Bouton WhatsApp
        const waText = candNom
            ? `🗳️ Votez pour *${candNom}* sur TikeWA !\nCliquez ici : ${url}`
            : `🗳️ Participez au vote officiel pour *${title}* sur TikeWA !\nCliquez ici : ${url}`;
        document.getElementById('shareWaLink').href = `https://api.whatsapp.com/send?text=${encodeURIComponent(waText)}`;

        // Bouton Facebook
        document.getElementById('shareFbLink').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;

        modal.style.display = 'grid';
    }

    function closeVoteShareModal() {
        const modal = document.getElementById('voteShareModal');
        if (modal) modal.style.display = 'none';
    }

    function copyShareLink() {
        const input = document.getElementById('shareDirectLinkInput');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(input.value).then(() => {
            const btn = document.getElementById('shareCopyBtn');
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
            setTimeout(() => {
                btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copier';
            }, 2000);
            showVoteToast("Lien officiel copié dans le presse-papier !");
        });
    }

    // Compte à rebours temps réel
    (function initVoteCountdown() {
        const targetIso = '<?php echo $cd_target_iso ?? date('Y-m-d\TH:i:s', strtotime(($event['date_evenement'] ?? 'now') . ' ' . (!empty($event['heure']) ? $event['heure'] : '23:59:59'))); ?>';
        const targetDate = new Date(targetIso).getTime();

        const cdDays = document.getElementById('cdDays');
        const cdHours = document.getElementById('cdHours');
        const cdMinutes = document.getElementById('cdMinutes');
        const cdSeconds = document.getElementById('cdSeconds');
        const cardEl = document.getElementById('voteCountdownCard');
        const gridEl = document.getElementById('voteCountdownGrid');
        const expiredEl = document.getElementById('cdExpiredMsg');

        function updateCountdown() {
            const now = new Date().getTime();
            const diff = targetDate - now;

            if (diff <= 0) {
                if (cardEl) cardEl.style.display = 'none';
                if (expiredEl) expiredEl.style.display = 'flex';
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            if (cdDays) cdDays.textContent = String(days).padStart(2, '0');
            if (cdHours) cdHours.textContent = String(hours).padStart(2, '0');
            if (cdMinutes) cdMinutes.textContent = String(minutes).padStart(2, '0');
            if (cdSeconds) cdSeconds.textContent = String(seconds).padStart(2, '0');
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);
    })();

    // Scroll automatique vers le candidat si ciblé dans l'URL et ouverture de ses détails
    document.addEventListener('DOMContentLoaded', () => {
        const focusId = <?php echo (int) $candidat_focus_id; ?>;
        if (focusId > 0) {
            const targetCand = document.getElementById('candidat-' + focusId);
            if (targetCand) {
                setTimeout(() => {
                    targetCand.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                    openCandidateDetailModal(focusId);
                }, 350);
            }
        }
    });
</script>

<?php include __DIR__ . '/footer.php'; ?>