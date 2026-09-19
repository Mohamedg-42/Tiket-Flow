<?php
// ==============================================================================
// PAGE DÉDIÉE D'ÉVÉNEMENT (client/evenement.php)
// Présentation exhaustive, réservation de billets, plan de salle et détails
// Standard Typographique Suisse Müller-Brockmann & Performance
// ==============================================================================

// Empêcher tout cache navigateur pour garantir l'affichage immédiat du rendu 3D
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';
require_once __DIR__ . '/../includes/slug_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$event_slug = trim((string) ($_GET['slug'] ?? ''));
$event_token = trim((string) ($_GET['token'] ?? ''));
$event_id = 0;

// 1. Résolution prioritaire par slug convivial sans ID
if (!empty($event_slug)) {
    $stmt_slug = $pdo->prepare("SELECT id FROM events WHERE slug = ? LIMIT 1");
    $stmt_slug->execute([$event_slug]);
    $event_id = (int) $stmt_slug->fetchColumn();

    // Si non trouvé par slug exact, vérifier s'il s'agit d'un ID numérique direct
    if (!$event_id && is_numeric($event_slug)) {
        $stmt_id = $pdo->prepare("SELECT id FROM events WHERE id = ? LIMIT 1");
        $stmt_id->execute([(int) $event_slug]);
        $event_id = (int) $stmt_id->fetchColumn();
    }

    // Si non trouvé, vérifier s'il s'agit d'un token sécurisé passé en slug
    if (!$event_id) {
        $resolved_tok_id = resolve_resource_token($pdo, $event_slug, 'event');
        if ($resolved_tok_id) {
            $event_id = $resolved_tok_id;
            $event_token = $event_slug;
        } else {
            $stmt_tok = $pdo->prepare("SELECT id FROM events WHERE access_token = ? LIMIT 1");
            $stmt_tok->execute([$event_slug]);
            $found_by_tok = (int) $stmt_tok->fetchColumn();
            if ($found_by_tok) {
                $event_id = $found_by_tok;
                $event_token = $event_slug;
            }
        }
    }
}

// 2. Résolution via paramètre token sécurisé (?token=...)
if (!$event_id && !empty($event_token)) {
    $resolved_e_id = resolve_resource_token($pdo, $event_token, 'event');
    if ($resolved_e_id) {
        $event_id = $resolved_e_id;
    } else {
        $stmt_tok = $pdo->prepare("SELECT id FROM events WHERE access_token = ? LIMIT 1");
        $stmt_tok->execute([$event_token]);
        $event_id = (int) $stmt_tok->fetchColumn();
    }
}

// 3. Fallback rétrocompatible si l'ancien paramètre id numérique a été envoyé
if (!$event_id) {
    $event_id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int) $_GET['id'] : ((isset($_GET['event_id']) && is_numeric($_GET['event_id'])) ? (int) $_GET['event_id'] : 0);
}

if (!$event_id) {
    if (!empty($event_token) || !empty($event_slug)) {
        render_token_security_error(
            "Événement introuvable",
            "Le lien d'accès à cet événement est invalide, a expiré ou n'existe pas.",
            404,
            "accueil"
        );
    }
    header('Location: accueil');
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
    WHERE e.id = ? AND e.statut IN ('actif', 'termine') AND e.deleted_at IS NULL
");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: accueil');
    exit();
}

// 1.0 Détection stricte : fermeture des ventes 4 heures avant le début ou si l'événement est déjà arrivé / terminé
$event_time_str = !empty($event['heure']) ? ($event['date_evenement'] . ' ' . $event['heure']) : ($event['date_evenement'] . ' 00:00:00');
$event_ts = strtotime($event_time_str);
$event_cutoff_ts = ($event_ts !== false) ? ($event_ts - (4 * 3600)) : false;

// L'événement en lui-même est arrivé / passé
$is_event_passed = ($event_ts !== false && $event_ts <= time()) || ($event['statut'] === 'termine');

// Les ventes sont fermées (4h avant le début OU événement déjà passé)
$is_ventes_fermees = $is_event_passed || ($event_cutoff_ts !== false && $event_cutoff_ts <= time());
$is_event_arrived = $is_ventes_fermees; // Maintien de compatibilité avec les contrôles d'interface existants

// Synchronisation en base de données si l'événement est réellement débuté / passé
if ($is_event_passed && $event['statut'] !== 'termine') {
    try {
        $pdo->prepare("UPDATE events SET statut = 'termine' WHERE id = ?")->execute([$event['id']]);
        $event['statut'] = 'termine';
    } catch (\Throwable $t) {}
}

// 1.0 Masquage des ID : si l'accès a été fait via un paramètre numérique (?id= ou ?event_id=),
// redirection 301 automatique vers l'URL conviviale propre sans ID
if ((isset($_GET['id']) || isset($_GET['event_id'])) && empty($event_slug) && !empty($event['slug'])) {
    $cleanUrl = 'evenement/' . rawurlencode($event['slug']);
    $otherParams = $_GET;
    unset($otherParams['id'], $otherParams['event_id']);
    if (!empty($otherParams)) {
        $cleanUrl .= '?' . http_build_query($otherParams);
    }
    header('Location:  ' . $cleanUrl, true, 301);
    exit();
}

// 1.1 Événement privé/restreint : accès strictement limité au lien direct sécurisé
// partagé par l'organisateur (jeton dans l'URL). Aucune indexation, aucun accès
// via la recherche/les listes publiques — voir client/accueil.php, client/vote.php.
$is_private_event = ($event['visibilite'] ?? 'public') === 'prive';
if ($is_private_event) {
    $provided_token = (string) ($_GET['token'] ?? '');
    $expected_token = (string) ($event['access_token'] ?? '');
    $is_token_authorized = (!empty($event_token) && ($event_token === $expected_token || resolve_resource_token($pdo, $event_token, 'event') === (int)$event['id']));
    if (!$is_token_authorized && ($expected_token === '' || !hash_equals($expected_token, $provided_token))) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <title>Accès restreint — Tike WA</title>
            <meta name="robots" content="noindex, nofollow">
            <style>
                body { font-family: system-ui, sans-serif; background: #0F172A; color: #E2E8F0; min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; padding: 2rem; }
                .box { max-width: 420px; text-align: center; }
                .box i { font-size: 2.5rem; color: #FF4A0D; margin-bottom: 1rem; display: block; }
                h1 { font-size: 1.3rem; margin: 0 0 0.6rem; }
                p { color: #94A3B8; font-size: 0.92rem; line-height: 1.6; }
                a { display: inline-block; margin-top: 1.4rem; color: #FF4A0D; font-weight: 700; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class="box">
                <i>🔒</i>
                <h1>Accès restreint</h1>
                <p>Cet événement est privé. Vous devez utiliser le lien d'invitation exact fourni par l'organisateur pour y accéder.</p>
                <a href="accueil">← Retour à l'accueil</a>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

// 2. Types de billets pour cet événement
$stmt_tickets = $pdo->prepare("
    SELECT id, event_id, nom, description, prix, frais_place, quantite, quantite_vendue, places_choisies 
    FROM ticket_types 
    WHERE event_id = ? AND deleted_at IS NULL
    ORDER BY prix ASC
");
$stmt_tickets->execute([$event_id]);
$tickets = $stmt_tickets->fetchAll(PDO::FETCH_ASSOC);

$capacite_totale = (int) array_sum(array_column($tickets, 'quantite'));
$stock_total = 0;
$prix_min = 0;
if (!empty($tickets)) {
    $prix_min = (float) $tickets[0]['prix'];
    foreach ($tickets as $t) {
        $rest = max(0, (int) $t['quantite'] - (int) ($t['quantite_vendue'] ?? 0));
        $stock_total += $rest;
    }
}

// 3. Candidats en lice (si cet événement comporte un concours/vote)
$candidats = [];
try {
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
} catch (PDOException $e) {
    $candidats = [];
}
$cand_total_votes = array_sum(array_column($candidats, 'nb_votes_cand'));

// 4. Likes & statut like
$visitor_id = session_id();
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$user_role = $_SESSION['user_role'] ?? 'client';
$peut_agir = empty($user_id) || ($user_role === 'client');

$likes_count = 0;
$is_liked = false;
try {
    $stmt_likes_cnt = $pdo->prepare("SELECT COUNT(*) FROM event_likes WHERE event_id = ?");
    $stmt_likes_cnt->execute([$event_id]);
    $likes_count = (int) $stmt_likes_cnt->fetchColumn();

    if ($user_id) {
        $stmt_chk_like = $pdo->prepare("SELECT id FROM event_likes WHERE event_id = ? AND user_id = ?");
        $stmt_chk_like->execute([$event_id, $user_id]);
    } else {
        $stmt_chk_like = $pdo->prepare("SELECT id FROM event_likes WHERE event_id = ? AND visitor_id = ?");
        $stmt_chk_like->execute([$event_id, $visitor_id]);
    }
    $is_liked = (bool) $stmt_chk_like->fetch();
} catch (PDOException $e) {
    // Likes table fallback
}

// Image de l'événement
$default_event_img = 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=1200&q=80';
$event_img = resolve_media_url($event['image'] ?? '', $default_event_img, ['events']);

// Empêcher tout cache agressif du navigateur pour afficher instantanément les modifications
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!function_exists('get_event_cat_icon')) {
    function get_event_cat_icon($cat)
    {
        $c = mb_strtolower(trim((string) $cat));
        if (strpos($c, 'concert') !== false || strpos($c, 'musique') !== false)
            return 'fa-solid fa-music';
        if (strpos($c, 'festival') !== false)
            return 'fa-solid fa-umbrella-beach';
        if (strpos($c, 'spectacle') !== false || strpos($c, 'humour') !== false || strpos($c, 'théâtre') !== false || strpos($c, 'theatre') !== false)
            return 'fa-solid fa-masks-theater';
        if (strpos($c, 'conf') !== false || strpos($c, 'seminaire') !== false || strpos($c, 'forum') !== false)
            return 'fa-solid fa-microphone';
        if (strpos($c, 'sport') !== false || strpos($c, 'tournoi') !== false || strpos($c, 'match') !== false)
            return 'fa-solid fa-futbol';
        if (strpos($c, 'soir') !== false || strpos($c, 'gala') !== false || strpos($c, 'clubbing') !== false)
            return 'fa-solid fa-champagne-glasses';
        if (strpos($c, 'foire') !== false || strpos($c, 'salon') !== false || strpos($c, 'expo') !== false)
            return 'fa-solid fa-store';
        if (strpos($c, 'ciné') !== false || strpos($c, 'cine') !== false || strpos($c, 'film') !== false || strpos($c, 'projection') !== false)
            return 'fa-solid fa-film';
        if (strpos($c, 'anniversaire') !== false || strpos($c, 'fete') !== false || strpos($c, 'fête') !== false)
            return 'fa-solid fa-cake-candles';
        if (strpos($c, 'mode') !== false || strpos($c, 'defile') !== false || strpos($c, 'défilé') !== false)
            return 'fa-solid fa-shirt';
        if (strpos($c, 'vote') !== false || strpos($c, 'concours') !== false)
            return 'fa-solid fa-trophy';
        if (strpos($c, 'autre') !== false)
            return 'fa-solid fa-shapes';
        return 'fa-solid fa-tag';
    }
}

$page_title = htmlspecialchars($event['nom']) . " — Détails & Billetterie | Tike WA";
$body_class = "client-page event-detail-page";
include __DIR__ . '/header.php';
?>

<link rel="stylesheet" href="../Css/accueil-client.css?v=<?php echo file_exists(__DIR__ . '/../Css/accueil-client.css') ? filemtime(__DIR__ . '/../Css/accueil-client.css') : '1.2.0'; ?>">
<link rel="stylesheet" href="../Css/seating-booking.css?v=<?php echo file_exists(__DIR__ . '/../Css/seating-booking.css') ? filemtime(__DIR__ . '/../Css/seating-booking.css') : time(); ?>">

<style>
    /* Correction contraste bouton actif filtre 3D */
    .studio-filter-btn.active,
    .studio-filter-btn.active * {
        color: #FFFFFF !important;
    }
    .studio-filter-btn.active .s3d-price-tag {
        color: #FFFFFF !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.25);
    }

    /* ==========================================================================
   STYLE TYPOGRAPHIQUE SUISSE & GRILLE MODULAIRE MÜLLER-BROCKMANN (client/evenement.php)
   ========================================================================== */
    :root {
        --ev-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        --ev-font-display: 'Outfit', 'Inter', sans-serif;
        --ev-font-mono: 'Space Mono', monospace;
        --ev-orange: #FF4A0D;
        --ev-orange-subtle: #FFF2ED;
        --ev-dark: #0F172A;
        --ev-gray-muted: #64748B;
        --ev-border: #E2E8F0;
        --ev-surface: #FFFFFF;
        --ev-bg: #F8FAFC;
        --ev-radius-sm: 8px;
        --ev-radius-md: 12px;
        --ev-radius-lg: 16px;
        --ev-radius-full: 9999px;
    }

    body.event-detail-page {
        background-color: var(--ev-bg);
        color: var(--ev-dark);
        font-family: var(--ev-font-sans);
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .event-page-wrap {
        max-width: 1180px;
        margin: 0 auto;
        padding: clamp(1rem, 3vw, 2.5rem) clamp(1rem, 3vw, 1.5rem) 4rem;
    }

    /* Fil d'Ariane & Actions hautes */
    .event-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .event-breadcrumb {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.88rem;
        color: var(--ev-gray-muted);
    }

    .event-breadcrumb a {
        color: var(--ev-dark);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.15s ease;
    }

    .event-breadcrumb a:hover {
        color: var(--ev-orange);
    }

    .event-topbar-actions {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
    }

    .event-btn-action-top {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: #ffffff;
        border: 1px solid var(--ev-border);
        color: var(--ev-dark);
        padding: 0.55rem 1rem;
        border-radius: var(--ev-radius-full);
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .event-btn-action-top:hover {
        background: var(--ev-orange-subtle);
        border-color: var(--ev-orange);
        color: var(--ev-orange);
        transform: translateY(-1px);
    }

    .event-btn-action-top.is-liked {
        background: #FFF1F2;
        border-color: #F43F5E;
        color: #E11D48;
    }

    /* Carte Héro Principale */
    .event-hero-card {
        background: var(--ev-surface);
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-lg);
        box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        display: grid;
        grid-template-columns: 460px 1fr;
        margin-bottom: 2.5rem;
    }

    @media (max-width: 980px) {
        .event-hero-card {
            grid-template-columns: 1fr;
        }
    }

    .event-hero-media {
        position: relative;
        background: #090d16;
        min-height: 520px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .event-hero-backdrop {
        position: absolute;
        inset: -30px;
        background-size: cover;
        background-position: center;
        filter: blur(28px) brightness(0.35);
        opacity: 0.85;
        transform: scale(1.1);
        pointer-events: none;
    }

    .event-hero-img {
        position: relative;
        z-index: 1;
        width: 100%;
        height: 100%;
        max-height: 560px;
        object-fit: contain;
        object-position: center;
        display: block;
        margin: auto;
        filter: drop-shadow(0 14px 28px rgba(0, 0, 0, 0.5));
    }

    @media (max-width: 980px) {
        .event-hero-media {
            min-height: 380px;
            max-height: 520px;
        }

        .event-hero-img {
            max-height: 480px;
        }
    }

    .event-hero-badges {
        position: absolute;
        top: 16px;
        left: 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        z-index: 2;
    }

    .event-badge-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(15, 23, 42, 0.88);
        backdrop-filter: blur(8px);
        color: #ffffff;
        padding: 5px 12px;
        border-radius: var(--ev-radius-full);
        font-size: 0.78rem;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.18);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
    }

    .event-badge-chip.orange {
        background: var(--ev-orange);
        border-color: transparent;
    }

    .event-badge-chip.green {
        background: #059669;
        border-color: transparent;
    }

    .event-hero-content {
        padding: clamp(1.5rem, 3vw, 2.5rem);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .event-kicker {
        font-family: var(--ev-font-mono);
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        color: var(--ev-orange);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 0.4rem;
    }

    .event-title {
        font-family: var(--ev-font-display);
        font-size: clamp(1.6rem, 2.8vw, 2.3rem);
        font-weight: 800;
        line-height: 1.15;
        color: var(--ev-dark);
        margin: 0 0 1.25rem;
        letter-spacing: -0.02em;
    }

    /* Grille Métrique Suisse */
    .event-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .event-metric-box {
        background: #F8FAFC;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-sm);
        padding: 0.75rem 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .event-metric-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ev-gray-muted);
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .event-metric-val {
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--ev-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .event-metric-val.price {
        font-family: var(--ev-font-mono);
        color: var(--ev-orange);
        font-size: 1.15rem;
    }

    /* Description */
    .event-desc-box {
        margin-bottom: 1.75rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--ev-border);
    }

    .event-desc-box h4 {
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--ev-dark);
        margin: 0 0 0.5rem;
    }

    .event-desc-box p {
        font-size: 0.92rem;
        color: var(--ev-gray-muted);
        line-height: 1.65;
        margin: 0;
    }

    /* Boutons principaux héro */
    .event-hero-cta {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .btn-primary-reserve {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        background: var(--ev-orange);
        color: #ffffff;
        padding: 0.85rem 1.6rem;
        border-radius: var(--ev-radius-sm);
        font-size: 0.95rem;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        border: none;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3);
    }

    .btn-primary-reserve:hover {
        background: #E03E05;
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(255, 74, 13, 0.4);
    }

    .btn-secondary-share {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.55rem;
        background: #F1F5F9;
        color: var(--ev-dark);
        border: 1px solid var(--ev-border);
        padding: 0.85rem 1.4rem;
        border-radius: var(--ev-radius-sm);
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .btn-secondary-share:hover {
        background: #E2E8F0;
    }

    /* Section Billetterie & Réservation */
    .event-tickets-section {
        background: #ffffff;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-lg);
        padding: clamp(1.25rem, 3vw, 2rem);
        margin-bottom: 2.5rem;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        scroll-margin-top: 90px;
    }

    .section-title-wrap {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid var(--ev-border);
        padding-bottom: 1rem;
    }

    .section-title {
        font-family: var(--ev-font-display);
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--ev-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tickets-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .ticket-card {
        background: #F8FAFC;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-md);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        position: relative;
    }

    .ticket-card:hover {
        border-color: var(--ev-orange);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.08);
    }

    .ticket-card.is-sold-out {
        opacity: 0.65;
        filter: grayscale(0.2);
        pointer-events: none;
    }

    .ticket-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
    }

    .ticket-name {
        font-family: var(--ev-font-display);
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--ev-dark);
        margin: 0;
    }

    .ticket-status-tag {
        font-family: var(--ev-font-mono);
        font-size: 0.72rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 4px;
    }

    .ticket-status-tag.available {
        background: #DCFCE7;
        color: #15803D;
    }

    .ticket-status-tag.soldout {
        background: #FEE2E2;
        color: #B91C1C;
    }

    .ticket-desc {
        font-size: 0.84rem;
        color: var(--ev-gray-muted);
        line-height: 1.45;
        margin: 0 0 1rem;
        flex: 1;
    }

    .ticket-price-wrap {
        margin-bottom: 1rem;
    }

    .ticket-price {
        font-family: var(--ev-font-mono);
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--ev-dark);
    }

    .ticket-price small {
        font-size: 0.8rem;
        color: var(--ev-gray-muted);
        font-weight: 700;
    }

    .ticket-progress-wrap {
        margin-bottom: 1rem;
    }

    .ticket-progress-stats {
        display: flex;
        justify-content: space-between;
        font-size: 0.74rem;
        font-family: var(--ev-font-mono);
        font-weight: 700;
        color: var(--ev-gray-muted);
        margin-bottom: 3px;
    }

    .ticket-progress-bar {
        height: 6px;
        background: #E2E8F0;
        border-radius: 999px;
        overflow: hidden;
    }

    .ticket-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--ev-orange), #FF7A3D);
        border-radius: 999px;
    }

    /* Sélecteur de quantité */
    .ticket-qty-control {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-sm);
        padding: 4px;
    }

    .qty-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #F1F5F9;
        border: none;
        font-size: 1rem;
        font-weight: 700;
        color: var(--ev-dark);
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: background 0.15s;
    }

    .qty-btn:hover:not(:disabled) {
        background: #E2E8F0;
    }

    .qty-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .qty-input {
        width: 50px;
        text-align: center;
        font-family: var(--ev-font-mono);
        font-weight: 800;
        font-size: 1.05rem;
        border: none;
        background: transparent;
        color: var(--ev-dark);
    }

    /* Panier & Formulaire de Réservation Intégré */
    .event-checkout-panel {
        background: #F8FAFC;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-md);
        padding: 1.5rem;
    }

    .checkout-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .checkout-total-val {
        font-family: var(--ev-font-mono);
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--ev-orange);
    }

    .checkout-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .checkout-field label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--ev-gray-muted);
        margin-bottom: 0.4rem;
    }

    .checkout-field input {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--ev-border);
        border-radius: var(--ev-radius-sm);
        font-size: 0.9rem;
        font-family: inherit;
        background: #ffffff;
        box-sizing: border-box;
        color: var(--ev-dark);
    }

    .checkout-field input:focus {
        outline: none;
        border-color: var(--ev-orange);
        box-shadow: 0 0 0 3px rgba(255, 74, 13, 0.15);
    }

    /* Carrousel Horizontal Suisse Müller-Brockmann des Candidats (client/evenement.php) */
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
        background: var(--ev-orange);
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
        color: var(--ev-dark);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.85rem;
    }

    .vote-carousel-btn:hover {
        background: var(--ev-dark);
        color: #ffffff;
        border-color: var(--ev-dark);
    }

    .vote-cand-card {
        flex: 0 0 240px;
        width: 240px;
        scroll-snap-align: start;
        background: #ffffff;
        border: 1px solid var(--ev-border);
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.2s ease;
    }

    .vote-cand-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px -4px rgba(15, 23, 42, 0.12);
        border-color: var(--ev-orange);
    }

    .vote-cand-photo-wrap {
        position: relative;
        width: 100%;
        height: 200px;
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
        font-family: var(--ev-font-mono);
        font-weight: 700;
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 999px;
        backdrop-filter: blur(4px);
        z-index: 2;
    }

    .vote-cand-badge.top-1 {
        background: var(--ev-orange);
        color: #ffffff;
    }

    .vote-cand-body {
        padding: 0.9rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .vote-cand-nom {
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--ev-dark);
        margin: 0 0 0.4rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
    }

    .vote-cand-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: var(--ev-font-mono);
        font-size: 0.76rem;
        font-weight: 700;
        margin-bottom: 0.35rem;
    }

    .vote-cand-gauge-bg {
        width: 100%;
        height: 6px;
        background: #E2E8F0;
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 0.85rem;
    }

    .vote-cand-gauge-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--ev-orange), #FF7A3D);
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
        color: var(--ev-dark);
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
        text-decoration: none;
    }

    .btn-cand-detail:hover {
        background: #E2E8F0;
    }

    .btn-cand-vote {
        background: var(--ev-orange);
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
        text-decoration: none;
    }

    .btn-cand-vote:hover {
        background: #E03E05;
        transform: translateY(-1px);
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
        border: 1px solid var(--ev-border);
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
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--ev-dark);
        margin: 0 0 0.25rem;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .candidat-detail-event {
        font-size: 0.8rem;
        color: var(--ev-gray-muted);
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
        border: 1px solid var(--ev-border);
        border-radius: 8px;
        padding: 0.55rem 0.75rem;
    }

    .candidat-detail-kpi small {
        display: block;
        font-size: 0.68rem;
        text-transform: uppercase;
        color: var(--ev-gray-muted);
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    .candidat-detail-kpi strong {
        font-family: var(--ev-font-mono);
        font-size: 1.15rem;
        font-weight: 900;
        color: var(--ev-dark);
    }

    .candidat-detail-desc-box {
        background: #F8FAFC;
        border: 1px solid var(--ev-border);
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
        color: var(--ev-gray-muted);
        font-weight: 800;
    }

    .candidat-detail-desc-text {
        font-size: 0.88rem;
        line-height: 1.55;
        color: #334155;
        margin: 0;
        white-space: pre-line;
    }

    @media (max-width: 600px) {
        .vote-cand-card {
            flex: 0 0 190px;
            width: 190px;
        }

        .vote-cand-photo-wrap {
            height: 165px;
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

    /* Modal Partage */
    .event-share-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(4px);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .event-share-modal-backdrop.active {
        display: flex;
    }

    .event-share-box {
        background: #ffffff;
        border-radius: 16px;
        width: 100%;
        max-width: 480px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        animation: evSharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes evSharePop {
        from {
            opacity: 0;
            transform: scale(0.95);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .poster-floating-share-btn {
        display: none !important;
    }

    /* Toast */
    .ev-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: var(--ev-dark);
        color: #ffffff;
        padding: 0.85rem 1.25rem;
        border-radius: var(--ev-radius-sm);
        font-size: 0.88rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        z-index: 999999;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        pointer-events: none;
    }

    .ev-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
</style>

<div class="event-page-wrap">

    <!-- BARRE HAUTE DE NAVIGATION -->
    <div class="event-topbar">
        <div class="event-breadcrumb">
            <a href="accueil"><i class="fa-solid fa-house"></i> Accueil</a>
            <span>/</span>
            <a href="accueil?onglet=evenements">Événements</a>
            <span>/</span>
            <span style="color: var(--ev-dark); font-weight: 700;"><?php echo htmlspecialchars($event['nom']); ?></span>
        </div>

        <div class="event-topbar-actions">
            <!-- Bouton J'aime -->
            <button type="button" class="event-btn-action-top <?php echo $is_liked ? 'is-liked' : ''; ?>"
                id="btnTopLike" onclick="toggleEventLike(<?php echo (int) $event['id']; ?>, this)">
                <i class="fa-<?php echo $is_liked ? 'solid' : 'regular'; ?> fa-heart"></i>
                <span id="topLikeCount"><?php echo $likes_count; ?></span>
            </button>

            <!-- Bouton Partager -->
            <button type="button" class="event-btn-action-top" onclick="openShareEventModal()">
                <i class="fa-solid fa-share-nodes"></i> Partager
            </button>
        </div>
    </div>

    <!-- ============================================================
         GRILLE PRINCIPALE EN 3 COLONNES CONFORME AU MODÈLE TIKÉLI
         Fiche Événement & Tarifs | Plan Interactif | Panier & Paiement
         ============================================================ -->
    <div class="booking-layout-grid" id="billets">

        <!-- ============================================================
             COLONNE 1 : FICHE ÉVÉNEMENT & TARIFS (GAUCHE)
             ============================================================ -->
        <aside class="sb-card event-summary-card">
            <div class="event-summary-img-wrap">
                <img src="<?php echo htmlspecialchars($event_img, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="<?php echo htmlspecialchars($event['nom']); ?>" class="event-summary-img"
                    onerror="this.onerror=null; this.src='<?php echo $default_event_img; ?>';">
            </div>

            <h1 class="event-summary-title"><?php echo htmlspecialchars($event['nom']); ?></h1>

            <div class="event-summary-meta">
                <div class="event-summary-meta-item">
                    <i class="fa-regular fa-calendar"></i>
                    <span>
                        <?php 
                        $ts_ev = strtotime($event['date_evenement']);
                        $jours = ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'];
                        $mois = ['', 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
                        $j_sem = $jours[(int)date('w', $ts_ev)];
                        $m_nom = $mois[(int)date('n', $ts_ev)];
                        echo $j_sem . ' ' . date('d', $ts_ev) . ' ' . $m_nom . ' ' . date('Y', $ts_ev);
                        if (!empty($event['heure'])) {
                            echo ' · ' . str_replace(':', 'h', substr($event['heure'], 0, 5));
                        }
                        ?>
                    </span>
                </div>

                <div class="event-summary-meta-item">
                    <i class="fa-solid fa-location-dot"></i>
                    <span title="<?php echo htmlspecialchars($event['lieu']); ?>">
                        <?php echo htmlspecialchars($event['lieu']); ?>
                    </span>
                </div>
            </div>

            <div class="event-summary-cat-pill">
                <i class="<?php echo get_event_cat_icon($event['categorie']); ?>"></i>
                <span><?php echo htmlspecialchars($event['categorie']); ?></span>
            </div>

            <div class="event-summary-desc">
                <?php if (!empty($event['description'])): ?>
                    <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                <?php else: ?>
                    Vivez une expérience unique et inoubliable avec cet événement officiel sur la billetterie Tike WA.
                <?php endif; ?>
            </div>

            <div class="event-summary-divider"></div>

            <!-- Liste des Tarifs -->
            <div class="event-tarifs-section">
                <h3 class="event-tarifs-title">Tarifs</h3>
                <div class="event-tarifs-list">
                    <?php if (!empty($tickets)): 
                        $sorted_tickets = $tickets;
                        usort($sorted_tickets, function($a, $b) {
                            return (float)$b['prix'] <=> (float)$a['prix'];
                        });
                    ?>
                        <?php foreach ($sorted_tickets as $idx => $tk): 
                            $tk_name = mb_strtolower($tk['nom']);
                            $dot_cls = 'dot-standard';
                            if (strpos($tk_name, 'vip') !== false || strpos($tk_name, 'vvip') !== false) {
                                $dot_cls = 'dot-vip';
                            } elseif (strpos($tk_name, 'prem') !== false || strpos($tk_name, 'or') !== false) {
                                $dot_cls = 'dot-premium';
                            } elseif ($idx === 0) {
                                $dot_cls = 'dot-vip';
                            } elseif ($idx === 1) {
                                $dot_cls = 'dot-premium';
                            }
                        ?>
                            <div class="event-tarif-row">
                                <div class="event-tarif-name-wrap">
                                    <span class="event-tarif-dot <?php echo $dot_cls; ?>"></span>
                                    <span><?php echo htmlspecialchars($tk['nom']); ?></span>
                                </div>
                                <strong class="event-tarif-price"><?php echo number_format($tk['prix'], 0, ',', ' '); ?> FCFA</strong>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="event-tarif-row">
                            <div class="event-tarif-name-wrap">
                                <span class="event-tarif-dot dot-standard"></span>
                                <span style="text-transform: capitalize;">Entrée Standard</span>
                            </div>
                            <strong class="event-tarif-price">Gratuit</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- ============================================================
             COLONNE 2 : PLAN INTERACTIF DE LA SALLE (CENTRE)
             ============================================================ -->
        <main class="sb-card seating-plan-card">
            <div class="seating-plan-header">
                <div>
                    <h2 class="seating-plan-heading">Choisissez vos places</h2>
                    <p class="seating-plan-sub">Sélectionnez vos sièges sur le plan de la salle. Vous pouvez zoomer et déplacer la vue.</p>
                </div>
                <div class="seating-orientation-badge">
                    <i class="fa-solid fa-location-arrow" style="transform: rotate(-45deg); color: #0F172A;"></i>
                    <span>Vue de face</span>
                </div>
            </div>

            <?php if ($is_ventes_fermees): ?>
                <!-- Bannière d'alerte de fermeture (H-4 ou arrivé) -->
                <div style="background: #FEF2F2; border: 1.5px solid #FECACA; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 12px; color: #991B1B;">
                    <i class="fa-solid fa-lock" style="font-size: 1.3rem; color: #DC2626;"></i>
                    <div style="font-size: 0.88rem; line-height: 1.45;">
                        <strong>Ventes clôturées pour cet événement :</strong>
                        <?php if ($is_event_passed): ?>
                            La date ou l'heure de cet événement est déjà arrivée. Les réservations sont terminées.
                        <?php else: ?>
                            La billetterie ferme automatiquement 4 heures avant le début de l'événement. Les réservations et paiements sont désormais clos.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Scène et Plan Vectoriel Amphithéâtre -->
            <div class="seating-stage-container">
                <!-- Conteneur SVG du plan amphithéâtre -->
                <div id="amphitheatrePlanContainer" style="width: 100%; display: flex; justify-content: center; position: relative; overflow: hidden;">
                    <!-- Rendu dynamique complet via js/seating-booking.js -->
                </div>

                <!-- Contrôles flottants de Zoom -->
                <div class="sb-zoom-controls">
                    <button type="button" class="sb-zoom-btn" onclick="handlePlanZoom('in')" title="Zoom avant">+</button>
                    <button type="button" class="sb-zoom-btn" onclick="handlePlanZoom('out')" title="Zoom arrière">−</button>
                </div>
            </div>

            <!-- Barre de Légende Inférieure -->
            <div class="seating-legend-bar">
                <div class="seating-legend-item">
                    <span class="legend-dot dot-disp"></span>
                    <span>Siège disponible</span>
                </div>
                <div class="seating-legend-item">
                    <span class="legend-dot dot-sel"></span>
                    <span>Siège sélectionné</span>
                </div>
                <div class="seating-legend-item">
                    <span class="legend-dot dot-occ"></span>
                    <span>Siège occupé</span>
                </div>
                <div class="seating-legend-item">
                    <span class="legend-dot dot-vip"></span>
                    <span>Siège VIP</span>
                </div>
                <div class="seating-legend-item">
                    <i class="fa-solid fa-wheelchair" style="color: #2563EB; font-size: 0.95rem;"></i>
                    <span>Place PMR</span>
                </div>
            </div>
        </main>

        <!-- ============================================================
             COLONNE 3 : PANIER "VOS PLACES" & PAIEMENT (DROITE)
             ============================================================ -->
        <aside class="sb-card cart-summary-card">
            <div class="cart-header">
                <div class="cart-header-icon">
                    <i class="fa-solid fa-chair"></i>
                </div>
                <h3 class="cart-header-title">
                    <span>Vos places</span>
                    <span id="sbCartCountBadge" class="cart-count-badge">0</span>
                </h3>
            </div>

            <!-- Liste dynamique des sièges -->
            <div id="sbCartSeatsList" class="cart-seats-list">
                <!-- Rempli en temps réel par js/seating-booking.js -->
            </div>

            <!-- Décompte Financier -->
            <div class="cart-financial-breakdown">
                <div class="cart-fin-row">
                    <span id="sbCartSubtotalLabel">Sous-total (0 billet)</span>
                    <strong id="sbCartSubtotal" class="price">0 FCFA</strong>
                </div>
                <div class="cart-fin-row">
                    <span>Frais de service <i class="fa-solid fa-circle-info" style="color: #94A3B8; font-size: 0.78rem;" title="Frais de traitement billetterie"></i></span>
                    <strong id="sbCartFees" class="price">0 FCFA</strong>
                </div>
                <div class="cart-fin-divider"></div>
                <div class="cart-fin-row total-row">
                    <span>Total</span>
                    <strong id="sbCartTotal" class="price">0 FCFA</strong>
                </div>
            </div>

            <!-- Bouton d'action Continuer vers le paiement -->
            <button type="button" id="sbBtnContinuePay" class="btn-continue-payment" onclick="proceedToCheckout()" <?php echo $is_ventes_fermees ? 'disabled' : ''; ?>>
                <span>Continuer vers le paiement</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>

            <!-- Réassurance Sécurité -->
            <div class="sb-security-badge">
                <i class="fa-solid fa-shield-halved sb-security-icon"></i>
                <div>
                    <div class="sb-security-title">Paiement sécurisé</div>
                    <p class="sb-security-sub">Vos informations sont protégées et cryptées.</p>
                </div>
            </div>
        </aside>
    </div>

    <!-- ============================================================
         BARRE INFÉRIEURE : RETOUR & BESOIN D'AIDE
         ============================================================ -->
    <div class="booking-bottom-bar">
        <a href="accueil?onglet=evenements" class="booking-back-link">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Retour aux événements</span>
        </a>
        <a href="reclamations" class="booking-help-link">
            <i class="fa-regular fa-circle-question"></i>
            <span>Besoin d'aide ?</span>
        </a>
    </div>

    <!-- Formulaire caché de commande (soumis directement vers client/commander.php) -->
    <form id="sbOrderHiddenForm" method="POST" action="commander.php?id=<?php echo (int) $event['id']; ?>" style="display:none;">
        <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
        <input type="hidden" name="client_nom" id="sbHiddenClientNom" value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>">
        <input type="hidden" name="client_telephone" id="sbHiddenClientTel" value="<?php echo htmlspecialchars($_SESSION['user_telephone'] ?? ($_SESSION['user_phone'] ?? '')); ?>">
        <input type="hidden" name="client_email" id="sbHiddenClientEmail" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>">
        <div id="sbHiddenInputsContainer"></div>
    </form>

    <!-- Modale de coordonnées acheteur pour visiteur non connecté -->
    <div id="sbCheckoutModal" class="sb-checkout-modal-overlay" style="display:none;">
        <div class="sb-checkout-modal">
            <div class="sb-checkout-modal-header">
                <h3 class="sb-checkout-modal-title">Finaliser votre réservation</h3>
                <button type="button" class="sb-checkout-modal-close" onclick="closeCheckoutModal()">&times;</button>
            </div>
            <form onsubmit="return submitGuestCheckout(event)">
                <p style="font-size: 0.84rem; color: #64748B; margin: 0 0 1.25rem;">
                    Renseignez vos coordonnées pour recevoir vos billets officiels avec QR Code sécurisé.
                </p>
                <div class="sb-form-group">
                    <label class="sb-form-label" for="sbModalClientNom">Nom & Prénoms *</label>
                    <input type="text" id="sbModalClientNom" class="sb-form-input" required placeholder="Ex: Jean Kouassi">
                </div>
                <div class="sb-form-group">
                    <label class="sb-form-label" for="sbModalClientTel">Numéro de Téléphone *</label>
                    <input type="tel" id="sbModalClientTel" class="sb-form-input" required placeholder="Ex: 07 00 00 00 00">
                </div>
                <div class="sb-form-group">
                    <label class="sb-form-label" for="sbModalClientEmail">Adresse Email (facultatif)</label>
                    <input type="email" id="sbModalClientEmail" class="sb-form-input" placeholder="Ex: jean.kouassi@gmail.com">
                </div>
                <button type="submit" class="btn-continue-payment" style="margin-top: 1rem;">
                    <span>Procéder au règlement</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- SECTION CANDIDATS EN COMPÉTITION (Si présents) -->
    <?php if (!empty($candidats)): ?>
        <section class="event-tickets-section" id="candidats" style="margin-top: 2rem;">
            <div class="section-title-wrap">
                <div>
                    <h2 class="section-title">
                        <i class="fa-solid fa-users" style="color: var(--ev-orange);"></i> Candidats en Compétition
                    </h2>
                    <small style="color: var(--ev-gray-muted); font-size: 0.88rem;">
                        Découvrez les candidats en lice en défilement horizontal et votez directement pour soutenir votre
                        favori.
                    </small>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div
                        style="font-family: var(--ev-font-mono); font-size: 0.85rem; font-weight: 700; color: var(--ev-gray-muted);">
                        <?php echo count($candidats); ?> candidat(s) en lice
                    </div>
                    <?php if (count($candidats) > 1): ?>
                        <div class="vote-cands-nav">
                            <button type="button" class="vote-carousel-btn" onclick="scrollEvCands(-1)" aria-label="Précédent"
                                title="Défiler vers la gauche">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <button type="button" class="vote-carousel-btn" onclick="scrollEvCands(1)" aria-label="Suivant"
                                title="Défiler vers la droite">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Carrousel Horizontal Suisse des Candidats -->
            <div class="vote-cands-carousel-wrap">
                <div class="vote-cands-horizontal-track" id="evCandsTrack">
                    <?php
                    $rank = 1;
                    foreach ($candidats as $cand):
                        $cid = (int) $cand['id'];
                        $c_votes = (int) $cand['nb_votes_cand'];
                        $c_pct = ($cand_total_votes > 0) ? round(($c_votes / $cand_total_votes) * 100, 1) : 0;
                        $is_top1 = ($rank === 1);

                        $c_photo = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                        if (!empty($cand['photo'])) {
                            if (strpos($cand['photo'], 'http') === 0) {
                                $c_photo = htmlspecialchars($cand['photo']);
                            } elseif (file_exists('../uploads/candidats/' . $cand['photo'])) {
                                $c_photo = '../uploads/candidats/' . htmlspecialchars($cand['photo']);
                            }
                        }
                        ?>
                        <article class="vote-cand-card" id="candidat-<?php echo $cid; ?>"
                            style="cursor: pointer;"
                            onclick="openEvCandModal(<?php echo $cid; ?>)"
                            title="Voir le profil de <?php echo htmlspecialchars($cand['nom']); ?>">
                            <div class="vote-cand-photo-wrap">
                                <img src="<?php echo $c_photo; ?>" alt="<?php echo htmlspecialchars($cand['nom']); ?>"
                                    class="vote-cand-photo" loading="lazy">
                                <span class="vote-cand-badge <?php echo $is_top1 ? 'top-1' : ''; ?>">
                                    <?php echo $is_top1 ? '★ #1 en tête' : '#' . $rank; ?>
                                </span>
                            </div>

                            <div class="vote-cand-body">
                                <h4 class="vote-cand-nom" title="<?php echo htmlspecialchars($cand['nom']); ?>">
                                    <?php echo htmlspecialchars($cand['nom']); ?>
                                </h4>

                                <div class="vote-cand-stats">
                                    <span style="color: var(--ev-dark);"><?php echo $c_votes; ?>
                                        vote<?php echo ($c_votes > 1) ? 's' : ''; ?></span>
                                    <span
                                        style="color: var(--ev-orange); background: var(--ev-orange-subtle); padding: 1px 5px; border-radius: 4px;"><?php echo $c_pct; ?>%</span>
                                </div>
                                <div class="vote-cand-gauge-bg">
                                    <div class="vote-cand-gauge-fill" style="width: <?php echo $c_pct; ?>%;"></div>
                                </div>

                                <div class="vote-cand-actions" style="display: grid; grid-template-columns: 1fr; gap: 0;">
                                    <a href="vote?id=<?php echo (int) $event['id']; ?>&candidat_id=<?php echo $cid; ?>#candidat-<?php echo $cid; ?>"
                                        class="btn-cand-vote" style="width: 100%; justify-content: center; padding: 0.55rem 0.75rem; font-size: 0.82rem;"
                                        onclick="event.stopPropagation();"
                                        title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
                                        <i class="fa-solid fa-thumbs-up"></i> Voter
                                    </a>
                                </div>
                            </div>
                        </article>
                        <?php
                        $rank++;
                    endforeach;
                    ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

</div>

<!-- MODALE DE PARTAGE MULTI-CANAUX -->
<div id="eventShareModal" class="event-share-modal-backdrop"
    onclick="if (event.target === this) closeShareEventModal();">
    <div class="event-share-box">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div
                style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager l'événement
            </div>
            <button type="button" onclick="closeShareEventModal()"
                style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <h4 style="margin: 0 0 0.25rem; font-size: 1.05rem; color: #0F172A; font-weight: 700;">
                <?php echo htmlspecialchars($event['nom']); ?></h4>
            <p style="margin: 0 0 1.25rem; font-size: 0.85rem; color: #64748B;">
                <?php echo htmlspecialchars($event['lieu']); ?> &bull;
                <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?></p>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748B; margin-bottom: 0.4rem;">Lien
                    direct officiel</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="shareEventLinkInput" readonly
                        style="flex: 1; padding: 0.65rem 0.85rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.85rem; background: #F8FAFC; color: #0F172A;">
                    <button type="button" onclick="copyEventShareLink()"
                        style="background: #0F172A; color: #ffffff; border: none; padding: 0 1rem; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-regular fa-copy"></i> Copier
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <a id="shareEventWaLink" href="#" target="_blank"
                    style="background: #25D366; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> WhatsApp
                </a>
                <a id="shareEventFbLink" href="#" target="_blank"
                    style="background: #1877F2; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-facebook-f"></i> Facebook
                </a>
                <a id="shareEventXLink" href="#" target="_blank"
                    style="background: #000000; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-x-twitter"></i> X (Twitter)
                </a>
                <button type="button" onclick="nativeEventShare()"
                    style="background: #F1F5F9; color: #0F172A; border: 1px solid #CBD5E1; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Plus d'options
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DÉTAILS CANDIDAT (client/evenement.php) -->
<div class="share-modal-overlay" id="evCandidateDetailModal"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: clamp(0.5rem, 2vw, 1.25rem); box-sizing: border-box; overflow-y: auto;"
    onclick="if (event.target === this) closeEvCandModal();">
    <div
        style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 520px; max-height: 88vh; max-height: 88dvh; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1); margin: auto;">
        <div
            style="padding: 1rem 1.25rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: #ffffff;">
            <div
                style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.05rem;">
                <i class="fa-solid fa-check-to-slot" style="color: #FF4A0D;"></i> Profil Officiel du Candidat
            </div>
            <button type="button" onclick="closeEvCandModal()"
                style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px; line-height: 1;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.25rem; overflow-y: auto; -webkit-overflow-scrolling: touch; flex: 1 1 auto; min-height: 0;">
            <div class="candidat-detail-hero">
                <div class="candidat-detail-photo-wrap">
                    <img id="evCandModalPhoto" src="" alt="Photo du candidat" class="candidat-detail-photo">
                    <span id="evCandModalRankBadge" class="vote-cand-badge top-1">#1</span>
                </div>
                <div class="candidat-detail-meta">
                    <div>
                        <h3 id="evCandModalNom" class="candidat-detail-nom" style="margin-top: 0;"></h3>
                        <div class="candidat-detail-event">
                            <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i>
                            <span><?php echo htmlspecialchars($event['nom']); ?></span>
                        </div>
                    </div>

                    <div class="candidat-detail-kpi-grid">
                        <div class="candidat-detail-kpi">
                            <small>Total des voix</small>
                            <strong id="evCandModalVotesCount" style="color: #0F172A;">0</strong>
                        </div>
                        <div class="candidat-detail-kpi">
                            <small>Part des votes</small>
                            <strong id="evCandModalPctCount" style="color: #FF4A0D;">0%</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="candidat-detail-desc-box">
                <h4><i class="fa-solid fa-id-card" style="color: #FF4A0D; margin-right: 4px;"></i> Biographie &
                    Présentation</h4>
                <p id="evCandModalBio" class="candidat-detail-desc-text"></p>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <div
                    style="display: flex; justify-content: space-between; font-size: 0.78rem; font-family: var(--ev-font-mono); font-weight: 700; margin-bottom: 4px;">
                    <span style="color: #0F172A;">Baromètre du scrutin</span>
                    <span id="evCandModalGaugePct" style="color: #FF4A0D;">0%</span>
                </div>
                <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                    <div id="evCandModalGaugeFill"
                        style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s ease;">
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <a id="evCandModalVoteBtn" href="#" class="btn-primary-reserve"
                    style="padding: 0.8rem; font-size: 0.95rem; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fa-solid fa-thumbs-up"></i> <span>Voter pour elle</span>
                </a>
                <button type="button" onclick="closeEvCandModal()" class="btn-cand-detail"
                    style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700; color: #64748B;">
                    <i class="fa-solid fa-xmark"></i> Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- TOAST NOTIFICATION -->
<div id="eventToast" class="ev-toast">
    <i class="fa-solid fa-circle-check" style="color: #22C55E;"></i>
    <span id="eventToastMsg">Message</span>
</div>

<script>
    window.EV_CANDIDATS = <?php echo json_encode($candidats ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.EV_TOTAL_VOTES = <?php echo (int) $cand_total_votes; ?>;
    window.EV_EVENT_ID = <?php echo (int) $event['id']; ?>;

    function scrollEvCands(direction) {
        const track = document.getElementById('evCandsTrack');
        if (track) {
            track.scrollBy({ left: direction * 240, behavior: 'smooth' });
        }
    }

    function openEvCandModal(candId) {
        const modal = document.getElementById('evCandidateDetailModal');
        if (!modal) return;

        const candList = window.EV_CANDIDATS || [];
        const cand = candList.find(c => Number(c.id) === Number(candId));
        if (!cand) return;

        const rankIdx = candList.findIndex(c => Number(c.id) === Number(candId));
        const rankNum = (rankIdx !== -1) ? (rankIdx + 1) : 1;
        const isTop1 = (rankNum === 1);

        const cVotes = Number(cand.nb_votes_cand || 0);
        const totalV = Number(window.EV_TOTAL_VOTES || 0);
        const cPct = (totalV > 0) ? Math.min(100, Math.round((cVotes / totalV) * 1000) / 10) : 0;

        let cPhoto = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=600&q=80';
        if (cand.photo) {
            cPhoto = cand.photo.startsWith('http') ? cand.photo : ('../uploads/candidats/' + cand.photo);
        }

        document.getElementById('evCandModalPhoto').src = cPhoto;
        const rankBadge = document.getElementById('evCandModalRankBadge');
        rankBadge.className = 'vote-cand-badge ' + (isTop1 ? 'top-1' : '');
        rankBadge.textContent = isTop1 ? '★ #1 en tête' : ('#' + rankNum + ' en lice');

        document.getElementById('evCandModalNom').textContent = cand.nom;
        document.getElementById('evCandModalVotesCount').textContent = cVotes.toLocaleString('fr-FR');
        document.getElementById('evCandModalPctCount').textContent = cPct + '%';
        document.getElementById('evCandModalGaugePct').textContent = cPct + '%';
        document.getElementById('evCandModalGaugeFill').style.width = cPct + '%';
        document.getElementById('evCandModalBio').textContent = cand.description && cand.description.trim()
            ? cand.description
            : "Candidat(e) officiel(le) en lice. Soutenez sa candidature avec votre vote !";

        const voteBtn = document.getElementById('evCandModalVoteBtn');
        voteBtn.href = `vote?id=${window.EV_EVENT_ID}&candidat_id=${candId}#candidat-${candId}`;

        modal.style.display = 'flex';
    }

    function closeEvCandModal() {
        const modal = document.getElementById('evCandidateDetailModal');
        if (modal) modal.style.display = 'none';
    }

    // Gestion des quantités de billets et calcul du total
    function updateTicketQty(ticketId, delta, maxStock) {
        const input = document.getElementById('qty-input-' + ticketId);
        if (!input) return;
        let current = parseInt(input.value, 10) || 0;
        let max = Math.min(20, maxStock);
        let newVal = Math.max(0, Math.min(max, current + delta));
        input.value = newVal;
        calculateEventTotal();
    }

    function calculateEventTotal() {
        const inputs = document.querySelectorAll('.qty-input');
        let total = 0;
        let count = 0;
        inputs.forEach(input => {
            const qty = parseInt(input.value, 10) || 0;
            const price = parseFloat(input.getAttribute('data-price')) || 0;
            const fraisPlace = parseFloat(input.getAttribute('data-frais-place')) || 0;
            const isSeatMode = (input.dataset.seatMode === '1');

            if (qty > 0) {
                total += (qty * price);
                if (isSeatMode) {
                    total += (qty * (fraisPlace > 0 ? fraisPlace : 1000));
                }
                count += qty;
            }
        });

        const displayEl = document.getElementById('checkoutTotalDisplay');
        if (displayEl) {
            displayEl.textContent = total.toLocaleString('fr-FR') + ' FCFA';
        }

        const submitBtn = document.getElementById('btnSubmitCheckout');
        if (submitBtn) {
            if (count === 0) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.innerHTML = '<i class="fa-solid fa-ticket"></i> Sélectionnez au moins 1 billet';
            } else {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.innerHTML = `<i class="fa-solid fa-lock"></i> Valider et Payer (${total.toLocaleString('fr-FR')} FCFA)`;
            }
        }
    }

    // Navigation fluide sécurisée vers la billetterie sans déviation par <base href>
    function scrollToBillets(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const el = document.getElementById('billets');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try {
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', '#billets');
                }
            } catch (_) {}
        }
        return false;
    }
    window.scrollToBillets = scrollToBillets;

    // Navigation fluide vers la section des candidats
    function scrollToCandidats(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const el = document.getElementById('candidats');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try {
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', '#candidats');
                }
            } catch (_) {}
        }
        return false;
    }
    window.scrollToCandidats = scrollToCandidats;

    // Initialisation au chargement
    document.addEventListener('DOMContentLoaded', () => {
        calculateEventTotal();

        // Si un tarif est cliqué au départ
        const firstAvailableInput = document.querySelector('.qty-input');
        if (firstAvailableInput) {
            firstAvailableInput.value = 1;
            calculateEventTotal();
        }

        // Si l'URL contient #billets ou #candidats, défiler fluidement vers la section
        if (window.location.hash === '#billets') {
            setTimeout(() => { scrollToBillets(); }, 300);
        } else if (window.location.hash === '#candidats') {
            setTimeout(() => { scrollToCandidats(); }, 300);
        }
    });

    // Interception globale des ancres internes de la page pour neutraliser <base href>
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href^="#"]');
        if (!a) return;
        const targetHash = a.getAttribute('href');
        if (targetHash && targetHash.length > 1 && targetHash !== '#') {
            const targetEl = document.querySelector(targetHash);
            if (targetEl) {
                e.preventDefault();
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                try {
                    if (window.history && window.history.pushState) {
                        window.history.pushState(null, '', targetHash);
                    }
                } catch (_) {}
            }
        }
    });

    // Toast notification
    function showEventToast(msg, isError = false) {
        const toast = document.getElementById('eventToast');
        const msgEl = document.getElementById('eventToastMsg');
        if (!toast || !msgEl) return;
        msgEl.textContent = msg;
        toast.querySelector('i').className = isError ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-check';
        toast.querySelector('i').style.color = isError ? '#EF4444' : '#22C55E';
        toast.classList.add('show');
        setTimeout(() => { toast.classList.remove('show'); }, 3500);
    }

    // Like d'événement en AJAX
    async function toggleEventLike(eventId, btn) {
        btn.disabled = true;
        const formData = new FormData();
        formData.append('event_id', eventId);

        try {
            const res = await fetch('like-event.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showEventToast(data.error, true);
            } else {
                if (data.liked) {
                    btn.classList.add('is-liked');
                    btn.querySelector('i').className = 'fa-solid fa-heart';
                    showEventToast("Ajouté à vos événements favoris !");
                } else {
                    btn.classList.remove('is-liked');
                    btn.querySelector('i').className = 'fa-regular fa-heart';
                    showEventToast("Retiré de vos favoris.");
                }
                const countEl = document.getElementById('topLikeCount');
                if (countEl && typeof data.likes !== 'undefined') {
                    countEl.textContent = data.likes;
                }
            }
        } catch (e) {
            showEventToast("Erreur lors de la mise à jour des favoris.", true);
        } finally {
            btn.disabled = false;
        }
    }

    // Partage
    function openShareEventModal() {
        const url = window.location.href.split('#')[0];
        const eventNom = <?php echo json_encode($event['nom']); ?>;

        document.getElementById('shareEventLinkInput').value = url;
        document.getElementById('shareEventWaLink').href = `https://api.whatsapp.com/send?text=${encodeURIComponent('Découvrez l\'événement « ' + eventNom + ' » sur Tike WA : ' + url)}`;
        document.getElementById('shareEventFbLink').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
        document.getElementById('shareEventXLink').href = `https://twitter.com/intent/tweet?text=${encodeURIComponent('Découvrez « ' + eventNom + ' » sur Tike WA')}&url=${encodeURIComponent(url)}`;

        document.getElementById('eventShareModal').classList.add('active');
    }

    function closeShareEventModal() {
        document.getElementById('eventShareModal').classList.remove('active');
    }

    function copyEventShareLink() {
        const input = document.getElementById('shareEventLinkInput');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 99999);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(input.value).then(() => {
                showEventToast("Lien officiel copié dans le presse-papier !");
            });
        } else {
            document.execCommand('copy');
            showEventToast("Lien officiel copié !");
        }
    }

    function nativeEventShare() {
        const url = window.location.href.split('#')[0];
        const eventNom = <?php echo json_encode($event['nom']); ?>;
        if (navigator.share) {
            navigator.share({
                title: eventNom,
                text: 'Découvrez cet événement sur Tike WA',
                url: url
            }).catch(() => { });
        } else {
            copyEventShareLink();
        }
    }
</script>

<!-- ============================================================
     MODALE DE CHOIX DE PLACE IMMERSIF EN 3D (RESPONSIVE)
     ============================================================ -->
<div id="client3DSeatingModal" class="client-modal s3d-modal-overlay" role="dialog" aria-modal="true"
    style="display: none;" hidden>
    <div class="s3d-modal-window">

        <!-- Header 3D Client -->
        <div class="s3d-header">
            <div class="s3d-header-main">
                <div class="s3d-header-title-row">
                    <span class="s3d-badge-3d">
                        <i class="fa-solid fa-cube"></i> Plan & 3D
                    </span>
                    <h3 id="client3DEventTitle" class="s3d-event-title"></h3>
                </div>
                <div class="s3d-venue-subtitle" id="client3DVenueSubtitle">
                    Cliquez sur vos sièges pour réserver selon votre tarif · Vue dynamique vers la scène
                </div>
            </div>

            <!-- Onglets & Contrôles Caméras -->
            <div class="s3d-header-controls">
                <!-- Onglets de Visualisation (3D / Plan 2D / Photos) -->
                <div class="s3d-tabs">
                    <button type="button" id="tabBtnClient3D" class="studio-tab-btn active"
                        onclick="switchClient3DTab('3d')">
                        <i class="fa-solid fa-cube"></i> <span>Rendu 3D</span>
                    </button>
                    <button type="button" id="tabBtnClientPlan" class="studio-tab-btn"
                        onclick="switchClient3DTab('plan')">
                        <i class="fa-solid fa-map"></i> <span>Plan</span>
                    </button>
                    <button type="button" id="tabBtnClientPhotos" class="studio-tab-btn"
                        onclick="switchClient3DTab('photos')">
                        <i class="fa-solid fa-images"></i> <span>Photos</span>
                    </button>
                </div>

                <!-- Caméras & Commandes -->
                <div class="s3d-cameras-group">
                    <div id="client3DCameraControls" class="s3d-cameras">
                        <button type="button" class="s3d-cam-btn" onclick="setClient3DView('isometric')"
                            title="Vue 3D Isométrique">
                            <i class="fa-solid fa-cubes"></i> <span>3D</span>
                        </button>
                        <button type="button" class="s3d-cam-btn" onclick="setClient3DView('top')" title="Vue du Haut">
                            <i class="fa-solid fa-eye"></i> <span>Haut</span>
                        </button>
                        <button type="button" class="s3d-cam-btn" onclick="setClient3DView('stage')" title="Vue Scène">
                            <i class="fa-solid fa-masks-theater"></i> <span>Scène</span>
                        </button>
                        <button type="button" class="s3d-cam-btn s3d-cam-reset" onclick="setClient3DView('reset')"
                            title="Recentrer la vue">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </button>
                    </div>
                    <button type="button" class="s3d-close-btn" onclick="closeClient3DSeating()"
                        title="Fermer la vue de scène">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Corps : Canvas 3D + Sidebar Panier 3D -->
        <div class="s3d-body">

            <!-- Zone Canvas 3D -->
            <div id="panelClient3D" class="s3d-canvas-panel">
                <canvas id="client3DCanvas"></canvas>

                <!-- Barre de Filtrage par Tarif / Catégorie -->
                <div id="client3DTariffBar" class="s3d-tariff-bar">
                    <!-- Rempli en JS avec les boutons de tarifs -->
                </div>

                <!-- Indication d'interaction tactile mobile -->
                <div class="s3d-touch-hint">
                    <i class="fa-solid fa-hand-pointer"></i> Touchez un siège · Glissez pour pivoter · Pincez pour
                    zoomer
                </div>

                <!-- Légende Flottante -->
                <div class="s3d-legend">
                    <span class="s3d-legend-item"><span class="s3d-dot dot-libre"></span> Libre</span>
                    <span class="s3d-legend-item"><span class="s3d-dot dot-selected"></span> Choisi</span>
                    <span class="s3d-legend-item"><span class="s3d-dot dot-occupe"></span> Occupé</span>
                </div>
            </div>

            <!-- Panel 2 : Plan Architectural (Blueprint) -->
            <div id="panelClientPlan" class="s3d-plan-panel">
                <div id="clientPlanContent"></div>
            </div>

            <!-- Panel 3 : Galerie Photos & Vues Réelles -->
            <div id="panelClientPhotos" class="s3d-photos-panel">
                <div id="clientPhotosGrid" class="s3d-photos-grid"></div>
            </div>

            <!-- Barre latérale / Drawer Inférieur : Sélection & Validation -->
            <div class="s3d-sidebar" id="client3DSidebar">
                <div class="s3d-sidebar-header">
                    <div class="s3d-sidebar-title">
                        <span><i class="fa-solid fa-chair" style="color: #FF4A0D;"></i> Mes Places 3D</span>
                        <span id="client3DSeatsBadge" class="s3d-seats-badge">0 place</span>
                    </div>
                    <button type="button" class="s3d-mobile-toggle-btn" onclick="toggleMobileSeatList()"
                        id="s3dMobileToggleBtn">
                        <span id="s3dToggleText">Voir détails</span> <i class="fa-solid fa-chevron-up"
                            id="s3dToggleIcon"></i>
                    </button>
                </div>

                <div class="s3d-sidebar-content" id="client3DSidebarContent">
                    <!-- Liste des places sélectionnées en 3D -->
                    <div id="client3DSelectedList" class="s3d-selected-list">
                        <div class="s3d-empty-msg">
                            Cliquez sur les sièges disponibles dans la vue 3D pour les ajouter à votre sélection.
                        </div>
                    </div>

                    <!-- Vue simulée / Distance Scène -->
                    <div id="client3DSightlineBox" class="s3d-sightline-box" style="display: none;">
                        <div class="s3d-sightline-title">
                            <i class="fa-solid fa-eye"></i> Visibilité Scène
                        </div>
                        <div id="client3DSightlineDesc" class="s3d-sightline-desc"></div>
                    </div>
                </div>

                <!-- Footer Total & Bouton de validation -->
                <div class="s3d-sidebar-footer">
                    <div class="s3d-total-card">
                        <div>
                            <span class="s3d-total-label">Total 3D</span>
                            <small id="client3DSubCount" class="s3d-sub-count">0 place choisie</small>
                        </div>
                        <strong id="client3DTotalAmount" class="s3d-total-amount">0 FCFA</strong>
                    </div>

                    <button type="button" onclick="applyClient3DSelection()" class="btn-submit s3d-btn-validate">
                        <i class="fa-solid fa-check-circle"></i> Valider mes Places 3D
                    </button>
                    <button type="button" onclick="closeClient3DSeating()" class="s3d-btn-cancel">
                        Retour sans modifier
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.EV_EVENT_ID = <?php echo (int) $event['id']; ?>;
    window.BOOKING_EVENT_ID = <?php echo (int) $event['id']; ?>;
    window.BOOKING_IS_VENTES_FERMEES = <?php echo $is_ventes_fermees ? 'true' : 'false'; ?>;
    window.BOOKING_TICKETS = <?php echo json_encode(array_values(array_map(function($t) {
        return [
            'id' => (int) $t['id'],
            'nom' => $t['nom'],
            'prix' => (float) $t['prix'],
            'frais_place' => (float) ($t['frais_place'] ?? 0),
            'quantite' => (int) $t['quantite'],
            'quantite_vendue' => (int) ($t['quantite_vendue'] ?? 0),
            'disponible' => max(0, (int) $t['quantite'] - (int) ($t['quantite_vendue'] ?? 0))
        ];
    }, $tickets)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.BOOKING_CONFIG = {
        has_user: <?php echo !empty($_SESSION['user_id']) ? 'true' : 'false'; ?>,
        user_nom: <?php echo json_encode($_SESSION['user_nom'] ?? ''); ?>,
        user_tel: <?php echo json_encode($_SESSION['user_telephone'] ?? ($_SESSION['user_phone'] ?? '')); ?>,
        user_email: <?php echo json_encode($_SESSION['user_email'] ?? ''); ?>
    };
</script>
<script src="../js/venue-3d-engine.js?v=<?php echo file_exists(__DIR__ . '/../js/venue-3d-engine.js') ? filemtime(__DIR__ . '/../js/venue-3d-engine.js') : time(); ?>"></script>
<script src="../js/accueil-client.js?v=<?php echo file_exists(__DIR__ . '/../js/accueil-client.js') ? filemtime(__DIR__ . '/../js/accueil-client.js') : time(); ?>"></script>
<script src="../js/seating-booking.js?v=<?php echo file_exists(__DIR__ . '/../js/seating-booking.js') ? filemtime(__DIR__ . '/../js/seating-booking.js') : time(); ?>"></script>

<?php include __DIR__ . '/footer.php'; ?>