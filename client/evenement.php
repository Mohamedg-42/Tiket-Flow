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
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$event_id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : ((isset($_GET['event_id']) && is_numeric($_GET['event_id'])) ? (int)$_GET['event_id'] : 0);
if (!$event_id) {
    header('Location: accueil.php?onglet=evenements');
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
    WHERE e.id = ? AND e.statut IN ('actif', 'termine')
");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: accueil.php?onglet=evenements');
    exit();
}

// 2. Types de billets pour cet événement
$stmt_tickets = $pdo->prepare("
    SELECT id, event_id, nom, description, prix, frais_place, quantite, quantite_vendue, places_choisies 
    FROM ticket_types 
    WHERE event_id = ? 
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
$user_id    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$user_role  = $_SESSION['user_role'] ?? 'client';
$peut_agir  = empty($user_id) || ($user_role === 'client');

$likes_count = 0;
$is_liked = false;
try {
    $stmt_likes_cnt = $pdo->prepare("SELECT COUNT(*) FROM event_likes WHERE event_id = ?");
    $stmt_likes_cnt->execute([$event_id]);
    $likes_count = (int)$stmt_likes_cnt->fetchColumn();

    if ($user_id) {
        $stmt_chk_like = $pdo->prepare("SELECT id FROM event_likes WHERE event_id = ? AND user_id = ?");
        $stmt_chk_like->execute([$event_id, $user_id]);
    } else {
        $stmt_chk_like = $pdo->prepare("SELECT id FROM event_likes WHERE event_id = ? AND visitor_id = ?");
        $stmt_chk_like->execute([$event_id, $visitor_id]);
    }
    $is_liked = (bool)$stmt_chk_like->fetch();
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

$page_title = htmlspecialchars($event['nom']) . " — Détails & Billetterie | Tikéli";
$body_class = "client-page event-detail-page";
include __DIR__ . '/header.php';
?>

<link rel="stylesheet" href="../Css/accueil-client.css?v=<?php echo time(); ?>">

<style>
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
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
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
    filter: drop-shadow(0 14px 28px rgba(0,0,0,0.5));
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
    border: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
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
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
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
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
}
.candidat-detail-photo-wrap {
    width: 100%;
    height: 220px;
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
    justify-content: space-between;
}
.candidat-detail-nom {
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--ev-dark);
    margin: 0.25rem 0 0.5rem;
    line-height: 1.2;
}
.candidat-detail-event {
    font-size: 0.84rem;
    color: var(--ev-gray-muted);
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 0.75rem;
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
    padding: 1rem 1.15rem;
    margin-bottom: 1.25rem;
}
.candidat-detail-desc-box h4 {
    margin: 0 0 0.4rem;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ev-gray-muted);
    font-weight: 800;
}
.candidat-detail-desc-text {
    font-size: 0.9rem;
    line-height: 1.6;
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
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    .candidat-detail-photo-wrap {
        height: 240px;
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
    box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    overflow: hidden;
    animation: evSharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes evSharePop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
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
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
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
            <a href="accueil.php"><i class="fa-solid fa-house"></i> Accueil</a>
            <span>/</span>
            <a href="accueil.php?onglet=evenements">Événements</a>
            <span>/</span>
            <span style="color: var(--ev-dark); font-weight: 700;"><?php echo htmlspecialchars($event['nom']); ?></span>
        </div>

        <div class="event-topbar-actions">
            <!-- Bouton J'aime -->
            <button type="button" class="event-btn-action-top <?php echo $is_liked ? 'is-liked' : ''; ?>" id="btnTopLike"
                onclick="toggleEventLike(<?php echo (int)$event['id']; ?>, this)">
                <i class="fa-<?php echo $is_liked ? 'solid' : 'regular'; ?> fa-heart"></i>
                <span id="topLikeCount"><?php echo $likes_count; ?></span>
            </button>

            <!-- Bouton Partager -->
            <button type="button" class="event-btn-action-top" onclick="openShareEventModal()">
                <i class="fa-solid fa-share-nodes"></i> Partager
            </button>
        </div>
    </div>

    <!-- CARTE HÉRO PRINCIPALE DE L'ÉVÉNEMENT -->
    <article class="event-hero-card">
        <div class="event-hero-media">
            <div class="event-hero-backdrop" style="background-image: url('<?php echo htmlspecialchars($event_img, ENT_QUOTES, 'UTF-8'); ?>');"></div>
            <img src="<?php echo htmlspecialchars($event_img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($event['nom']); ?>" class="event-hero-img"
                onerror="this.onerror=null; this.src='<?php echo $default_event_img; ?>';">

            <div class="event-hero-badges">
                <span class="event-badge-chip orange">
                    <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($event['categorie']); ?>
                </span>
                <span class="event-badge-chip">
                    <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?>
                </span>
                <?php if ($event['statut'] === 'termine'): ?>
                    <span class="event-badge-chip" style="background: #475569;">
                        <i class="fa-solid fa-flag-checkered"></i> Événement Terminé
                    </span>
                <?php else: ?>
                    <span class="event-badge-chip green">
                        <i class="fa-solid fa-circle-check"></i> Billetterie Ouverte
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="event-hero-content">
            <div>
                <span class="event-kicker">
                    <i class="fa-solid fa-calendar-check"></i> Événement Officiel Tikéli
                </span>

                <h1 class="event-title"><?php echo htmlspecialchars($event['nom']); ?></h1>

                <!-- Grille Métrique des caractéristiques clés -->
                <div class="event-metrics-grid">
                    <div class="event-metric-box">
                        <span class="event-metric-label"><i class="fa-regular fa-clock"></i> Date & Heure</span>
                        <strong class="event-metric-val">
                            <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?> à <?php echo substr($event['heure'], 0, 5); ?>
                        </strong>
                    </div>

                    <div class="event-metric-box">
                        <span class="event-metric-label"><i class="fa-solid fa-location-dot"></i> Lieu / Salle</span>
                        <strong class="event-metric-val" title="<?php echo htmlspecialchars($event['lieu']); ?>">
                            <?php echo htmlspecialchars($event['lieu']); ?>
                        </strong>
                    </div>

                    <div class="event-metric-box">
                        <span class="event-metric-label"><i class="fa-solid fa-user-tie"></i> Organisateur</span>
                        <strong class="event-metric-val" title="<?php echo htmlspecialchars($event['promoteur_nom'] ?? 'Organisateur officiel'); ?>">
                            <?php echo htmlspecialchars($event['promoteur_nom'] ?? 'Organisateur officiel'); ?>
                        </strong>
                    </div>

                    <div class="event-metric-box">
                        <span class="event-metric-label"><i class="fa-solid fa-ticket"></i> Tarif d'entrée</span>
                        <strong class="event-metric-val price">
                            <?php echo ($prix_min > 0) ? number_format($prix_min, 0, ',', ' ') . ' F' : 'Entrée Libre'; ?>
                        </strong>
                    </div>

                    <div class="event-metric-box">
                        <span class="event-metric-label"><i class="fa-solid fa-users"></i> Disponibilité</span>
                        <strong class="event-metric-val" style="color: <?php echo ($stock_total > 0) ? '#059669' : '#DC2626'; ?>;">
                            <?php echo ($stock_total > 0) ? $stock_total . ' place(s)' : 'Complet'; ?>
                        </strong>
                    </div>
                </div>

                <!-- Présentation détaillée -->
                <div class="event-desc-box">
                    <h4><i class="fa-solid fa-align-left" style="color: var(--ev-orange);"></i> À propos de l'événement</h4>
                    <?php if (!empty($event['description'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($event['description'])); ?></p>
                    <?php else: ?>
                        <p style="font-style: italic;">Aucune description supplémentaire fournie pour cet événement.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Boutons d'appel à l'action -->
            <div class="event-hero-cta">
                <?php if ($event['statut'] !== 'termine' && $stock_total > 0 && $peut_agir): ?>
                    <a href="#billets" class="btn-primary-reserve">
                        <i class="fa-solid fa-ticket"></i> Réserver mes Billets
                    </a>
                    <button type="button" class="btn-secondary-share" onclick="openClient3DSeating(null, <?php echo (int)$event['id']; ?>)" style="background: #0F172A; color: #FFFFFF; border: 1.5px solid #FF4A0D; font-weight: 800;" title="Visualiser la salle et choisir vos places en 3D">
                        <i class="fa-solid fa-cube" style="color: #FF4A0D;"></i> Choisir mes Places en 3D
                    </button>
                <?php elseif ($event['statut'] === 'termine'): ?>
                    <span class="btn-secondary-share" style="background: #F1F5F9; color: #64748B;">
                        <i class="fa-solid fa-flag-checkered"></i> Événement Terminé
                    </span>
                <?php elseif (!$peut_agir): ?>
                    <span class="btn-secondary-share" style="background: #F1F5F9; color: #64748B;" title="Réservé aux clients">
                        <i class="fa-solid fa-lock"></i> Réservé aux comptes clients
                    </span>
                <?php endif; ?>

                <?php if (!empty($candidats)): ?>
                    <a href="#candidats" class="btn-secondary-share">
                        <i class="fa-solid fa-users"></i> Voir les Candidats (<?php echo count($candidats); ?>)
                    </a>
                <?php endif; ?>

                <button type="button" class="btn-secondary-share" onclick="openShareEventModal()">
                    <i class="fa-solid fa-share-nodes"></i> Partager l'événement
                </button>
            </div>
        </div>
    </article>

    <!-- SECTION BILLETTERIE & TYPES DE PLACES -->
    <section class="event-tickets-section" id="billets">
        <div class="section-title-wrap">
            <div>
                <h2 class="section-title">
                    <i class="fa-solid fa-tags" style="color: var(--ev-orange);"></i> Billets Disponibles
                </h2>
                <small style="color: var(--ev-gray-muted); font-size: 0.88rem;">
                    Sélectionnez vos catégories de places ci-dessous pour finaliser votre commande en ligne.
                </small>
            </div>
            <div style="font-family: var(--ev-font-mono); font-size: 0.85rem; font-weight: 700; color: var(--ev-gray-muted);">
                <?php echo count($tickets); ?> formule(s) d'accès
            </div>
        </div>

        <?php if (!empty($tickets)): ?>
            <form id="eventCheckoutForm" action="commander.php?id=<?php echo (int)$event['id']; ?>" method="POST">
                <input type="hidden" name="event_id" id="event_id" value="<?php echo (int)$event['id']; ?>">
                <div id="seat-hidden-inputs"></div>

                <div class="tickets-grid">
                    <?php foreach ($tickets as $tk): 
                        $t_id = (int)$tk['id'];
                        $t_prix = (float)$tk['prix'];
                        $t_frais_place = (float)($tk['frais_place'] ?? 0);
                        $t_qte = (int)$tk['quantite'];
                        $t_vendus = (int)($tk['quantite_vendue'] ?? 0);
                        $t_rest = max(0, $t_qte - $t_vendus);
                        $is_sold_out = ($t_rest <= 0);
                        $pct_vendus = ($t_qte > 0) ? min(100, round(($t_vendus / $t_qte) * 100)) : 0;
                    ?>
                        <div class="ticket-card <?php echo $is_sold_out ? 'is-sold-out' : ''; ?>" id="ticket-tier-<?php echo $t_id; ?>">
                            <div>
                                <div class="ticket-top">
                                    <h3 class="ticket-name"><?php echo htmlspecialchars($tk['nom']); ?></h3>
                                    <span class="ticket-status-tag <?php echo $is_sold_out ? 'soldout' : 'available'; ?>">
                                        <?php echo $is_sold_out ? 'Épuisé' : $t_rest . ' restant(s)'; ?>
                                    </span>
                                </div>

                                <p class="ticket-desc">
                                    <?php echo !empty($tk['description']) ? htmlspecialchars($tk['description']) : "Accès officiel garanti avec badge et billet sécurisé par QR code."; ?>
                                </p>

                                <div class="ticket-price-wrap">
                                    <span class="ticket-price"><?php echo number_format($t_prix, 0, ',', ' '); ?> <small>FCFA</small></span>
                                    <?php if ($t_frais_place > 0): ?>
                                        <small style="display: block; font-size: 0.74rem; color: var(--ev-orange); font-weight: 700; margin-top: 2px;">
                                            +<?php echo number_format($t_frais_place, 0, ',', ' '); ?> F (choix de place)
                                        </small>
                                    <?php endif; ?>
                                </div>

                                <!-- Jauge de vente du type de billet -->
                                <div class="ticket-progress-wrap">
                                    <div class="ticket-progress-stats">
                                        <span><?php echo $t_vendus; ?> vendu(s)</span>
                                        <span><?php echo $pct_vendus; ?>%</span>
                                    </div>
                                    <div class="ticket-progress-bar">
                                        <div class="ticket-progress-fill" style="width: <?php echo $pct_vendus; ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contrôle de quantité -->
                            <div>
                                <?php if (!$is_sold_out && $event['statut'] !== 'termine' && $peut_agir): ?>
                                    <div class="ticket-qty-control">
                                        <button type="button" class="qty-btn" onclick="updateTicketQty(<?php echo $t_id; ?>, -1, <?php echo $t_rest; ?>)">-</button>
                                        <input type="number" name="tickets[<?php echo $t_id; ?>]" id="qty-input-<?php echo $t_id; ?>"
                                            value="0" min="0" max="<?php echo min(20, $t_rest); ?>" class="qty-input"
                                            data-price="<?php echo $t_prix; ?>"
                                            data-frais-place="<?php echo $t_frais_place; ?>"
                                            onchange="calculateEventTotal()">
                                        <button type="button" class="qty-btn" onclick="updateTicketQty(<?php echo $t_id; ?>, 1, <?php echo $t_rest; ?>)">+</button>
                                    </div>

                                    <!-- Option Choix de Place & Vue Scène 3D -->
                                    <div class="seat-choice-block" style="margin-top: 0.9rem; border-top: 1px dashed var(--ev-border); padding-top: 0.75rem;">
                                        <label class="seat-choice-label" for="seat_toggle_<?php echo $t_id; ?>" style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                                            <input type="checkbox" id="seat_toggle_<?php echo $t_id; ?>" class="seat-choice-checkbox"
                                                style="margin-top: 3px; accent-color: var(--ev-orange); width: 16px; height: 16px; cursor: pointer;"
                                                onchange="toggleSeatMap(<?php echo $t_id; ?>, <?php echo (int)$event['id']; ?>)">
                                            <div style="flex: 1;">
                                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                                    <span style="font-weight: 700; font-size: 0.82rem; color: var(--ev-dark);">
                                                        <i class="fa-solid fa-chair" style="color: var(--ev-orange);"></i> Choisir mes places
                                                    </span>
                                                    <?php if ($t_frais_place > 0): ?>
                                                        <span style="font-family: var(--ev-font-mono); font-weight: 800; font-size: 0.74rem; color: var(--ev-orange); background: var(--ev-orange-subtle); padding: 2px 6px; border-radius: 4px;">
                                                            +<?php echo number_format($t_frais_place, 0, ',', ' '); ?> F
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <small style="display: block; font-size: 0.72rem; color: var(--ev-gray-muted); line-height: 1.3; margin-top: 2px;">
                                                    Sélectionnez précisément vos sièges en 3D face à la scène.
                                                </small>
                                            </div>
                                        </label>

                                        <!-- VUE DE SCÈNE INTERACTIVE (RENDU 3D) -->
                                        <div class="scene-view-card" id="scene_view_<?php echo $t_id; ?>" hidden style="margin-top: 0.75rem;">
                                            <div class="scene-stage-banner">
                                                <div class="scene-stage-podium">
                                                    <i class="fa-solid fa-masks-theater"></i> SCÈNE PRINCIPALE / PODIUM
                                                </div>
                                                <div class="scene-stage-sub">
                                                    <i class="fa-solid fa-arrow-up"></i> Orientation face à la scène
                                                </div>
                                            </div>

                                            <button type="button" class="btn-scene-interactive" onclick="openClient3DSeating(<?php echo $t_id; ?>, <?php echo (int)$event['id']; ?>)" title="Ouvrir le Rendu 3D de la salle">
                                                <div class="btn-scene-left">
                                                    <span class="btn-scene-icon-box">
                                                        <i class="fa-solid fa-cube"></i>
                                                    </span>
                                                    <div class="btn-scene-labels">
                                                        <span class="btn-scene-main-text">Ouvrir le Rendu 3D Immersif</span>
                                                        <span class="btn-scene-sub-text">Immersion temps réel · Cliquez pour sélectionner</span>
                                                    </div>
                                                </div>
                                                <span class="scene-tag-badge">
                                                    <i class="fa-solid fa-cube"></i> Rendu 3D
                                                </span>
                                            </button>

                                            <div class="scene-selected-summary" id="scene_summary_<?php echo $t_id; ?>">
                                                <div style="color: #94A3B8; font-size: 0.76rem; text-align: center;">
                                                    <i class="fa-solid fa-hand-pointer" style="color: #FF4A0D; margin-right: 4px;"></i> Cliquez sur le bouton <strong>Rendu 3D</strong> ci-dessus pour sélectionner vos places.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <button type="button" class="btn-secondary-share" style="width: 100%;" disabled>
                                        <?php echo $is_sold_out ? 'Épuisé' : 'Non disponible'; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Panier et coordonnées de l'acheteur -->
                <?php if ($event['statut'] !== 'termine' && $stock_total > 0 && $peut_agir): ?>
                    <div class="event-checkout-panel">
                        <div class="checkout-header">
                            <div>
                                <h3 style="margin: 0 0 0.25rem; font-size: 1.15rem; color: var(--ev-dark); font-weight: 800;">
                                    <i class="fa-solid fa-cart-shopping" style="color: var(--ev-orange);"></i> Vos Coordonnées & Confirmation
                                </h3>
                                <small style="color: var(--ev-gray-muted);">Renseignez vos coordonnées pour la génération et l'envoi de vos billets QR Code.</small>
                            </div>
                            <div style="text-align: right;">
                                <small style="display: block; font-size: 0.75rem; text-transform: uppercase; color: var(--ev-gray-muted); font-weight: 700;">Total à payer</small>
                                <span class="checkout-total-val" id="checkoutTotalDisplay">0 FCFA</span>
                            </div>
                        </div>

                        <div class="checkout-form-grid">
                            <div class="checkout-field">
                                <label for="client_nom">Nom & Prénoms *</label>
                                <input type="text" id="client_nom" name="client_nom" required
                                    value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>"
                                    placeholder="Ex: Kouamé Jean">
                            </div>

                            <div class="checkout-field">
                                <label for="client_email">Adresse Email *</label>
                                <input type="email" id="client_email" name="client_email" required
                                    value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>"
                                    placeholder="Ex: jean.kouame@gmail.com">
                            </div>

                            <div class="checkout-field">
                                <label for="client_telephone">Numéro de Téléphone *</label>
                                <input type="tel" id="client_telephone" name="client_telephone" required
                                    value="<?php echo htmlspecialchars($_SESSION['user_telephone'] ?? ''); ?>"
                                    placeholder="Ex: +225 07 00 00 00 00">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                            <button type="submit" id="btnSubmitCheckout" class="btn-primary-reserve" style="font-size: 1rem; padding: 0.9rem 2rem;">
                                <i class="fa-solid fa-lock"></i> Valider et Payer ma Commande
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--ev-gray-muted);">
                <i class="fa-solid fa-ticket" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 0.8rem; display: block;"></i>
                <h3 style="color: var(--ev-dark); margin: 0 0 0.5rem;">Aucun type de billet enregistré</h3>
                <p style="margin: 0;">L'organisateur n'a pas encore configuré les tarifs d'entrée pour cet événement.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- SECTION CANDIDATS EN COMPÉTITION (Si présents) -->
    <?php if (!empty($candidats)): ?>
        <section class="event-tickets-section" id="candidats" style="margin-top: 2rem;">
            <div class="section-title-wrap">
                <div>
                    <h2 class="section-title">
                        <i class="fa-solid fa-users" style="color: var(--ev-orange);"></i> Candidats en Compétition
                    </h2>
                    <small style="color: var(--ev-gray-muted); font-size: 0.88rem;">
                        Découvrez les candidats en lice en défilement horizontal et votez directement pour soutenir votre favori.
                    </small>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="font-family: var(--ev-font-mono); font-size: 0.85rem; font-weight: 700; color: var(--ev-gray-muted);">
                        <?php echo count($candidats); ?> candidat(s) en lice
                    </div>
                    <?php if (count($candidats) > 1): ?>
                        <div class="vote-cands-nav">
                            <button type="button" class="vote-carousel-btn" onclick="scrollEvCands(-1)" aria-label="Précédent" title="Défiler vers la gauche">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <button type="button" class="vote-carousel-btn" onclick="scrollEvCands(1)" aria-label="Suivant" title="Défiler vers la droite">
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
                        $cid = (int)$cand['id'];
                        $c_votes = (int)$cand['nb_votes_cand'];
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
                        <article class="vote-cand-card" id="candidat-<?php echo $cid; ?>">
                            <div class="vote-cand-photo-wrap" onclick="openEvCandModal(<?php echo $cid; ?>)" title="Voir la fiche détaillée de <?php echo htmlspecialchars($cand['nom']); ?>">
                                <img src="<?php echo $c_photo; ?>" alt="<?php echo htmlspecialchars($cand['nom']); ?>" class="vote-cand-photo" loading="lazy">
                                <span class="vote-cand-badge <?php echo $is_top1 ? 'top-1' : ''; ?>">
                                    <?php echo $is_top1 ? '★ #1 en tête' : '#' . $rank; ?>
                                </span>
                            </div>

                            <div class="vote-cand-body">
                                <h4 class="vote-cand-nom" title="<?php echo htmlspecialchars($cand['nom']); ?>">
                                    <?php echo htmlspecialchars($cand['nom']); ?>
                                </h4>

                                <div class="vote-cand-stats">
                                    <span style="color: var(--ev-dark);"><?php echo $c_votes; ?> vote<?php echo ($c_votes > 1) ? 's' : ''; ?></span>
                                    <span style="color: var(--ev-orange); background: var(--ev-orange-subtle); padding: 1px 5px; border-radius: 4px;"><?php echo $c_pct; ?>%</span>
                                </div>
                                <div class="vote-cand-gauge-bg">
                                    <div class="vote-cand-gauge-fill" style="width: <?php echo $c_pct; ?>%;"></div>
                                </div>

                                <div class="vote-cand-actions">
                                    <button type="button" class="btn-cand-detail" onclick="openEvCandModal(<?php echo $cid; ?>)" title="Voir le profil complet">
                                        <i class="fa-solid fa-eye"></i> Détails
                                    </button>
                                    <a href="vote.php?id=<?php echo (int)$event['id']; ?>&candidat_id=<?php echo $cid; ?>#candidat-<?php echo $cid; ?>" class="btn-cand-vote" title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
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
<div id="eventShareModal" class="event-share-modal-backdrop" onclick="if (event.target === this) closeShareEventModal();">
    <div class="event-share-box">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager l'événement
            </div>
            <button type="button" onclick="closeShareEventModal()" style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <h4 style="margin: 0 0 0.25rem; font-size: 1.05rem; color: #0F172A; font-weight: 700;"><?php echo htmlspecialchars($event['nom']); ?></h4>
            <p style="margin: 0 0 1.25rem; font-size: 0.85rem; color: #64748B;"><?php echo htmlspecialchars($event['lieu']); ?> &bull; <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?></p>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748B; margin-bottom: 0.4rem;">Lien direct officiel</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="shareEventLinkInput" readonly style="flex: 1; padding: 0.65rem 0.85rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.85rem; background: #F8FAFC; color: #0F172A;">
                    <button type="button" onclick="copyEventShareLink()" style="background: #0F172A; color: #ffffff; border: none; padding: 0 1rem; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-regular fa-copy"></i> Copier
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <a id="shareEventWaLink" href="#" target="_blank" style="background: #25D366; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> WhatsApp
                </a>
                <a id="shareEventFbLink" href="#" target="_blank" style="background: #1877F2; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-facebook-f"></i> Facebook
                </a>
                <a id="shareEventXLink" href="#" target="_blank" style="background: #000000; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-x-twitter"></i> X (Twitter)
                </a>
                <button type="button" onclick="nativeEventShare()" style="background: #F1F5F9; color: #0F172A; border: 1px solid #CBD5E1; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Plus d'options
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DÉTAILS CANDIDAT (client/evenement.php) -->
<div class="share-modal-overlay" id="evCandidateDetailModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; place-items: center; padding: 1rem;" onclick="if (event.target === this) closeEvCandModal();">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 560px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-check-to-slot" style="color: #FF4A0D;"></i> Profil Officiel du Candidat
            </div>
            <button type="button" onclick="closeEvCandModal()" style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <div class="candidat-detail-hero">
                <div class="candidat-detail-photo-wrap">
                    <img id="evCandModalPhoto" src="" alt="Photo du candidat" class="candidat-detail-photo">
                    <span id="evCandModalRankBadge" class="vote-cand-badge top-1">#1</span>
                </div>
                <div class="candidat-detail-meta">
                    <div>
                        <h3 id="evCandModalNom" class="candidat-detail-nom" style="margin-top: 0;"></h3>
                        <div class="candidat-detail-event">
                            <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i> <span><?php echo htmlspecialchars($event['nom']); ?></span>
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
                <h4><i class="fa-solid fa-id-card" style="color: #FF4A0D; margin-right: 4px;"></i> Biographie & Présentation</h4>
                <p id="evCandModalBio" class="candidat-detail-desc-text"></p>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; font-family: var(--ev-font-mono); font-weight: 700; margin-bottom: 4px;">
                    <span style="color: #0F172A;">Baromètre du scrutin</span>
                    <span id="evCandModalGaugePct" style="color: #FF4A0D;">0%</span>
                </div>
                <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                    <div id="evCandModalGaugeFill" style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s ease;"></div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <a id="evCandModalVoteBtn" href="#" class="btn-primary-reserve" style="padding: 0.8rem; font-size: 0.95rem; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fa-solid fa-thumbs-up"></i> <span>Voter pour elle</span>
                </a>
                <button type="button" onclick="closeEvCandModal()" class="btn-cand-detail" style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700; color: #64748B;">
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
window.EV_TOTAL_VOTES = <?php echo (int)$cand_total_votes; ?>;
window.EV_EVENT_ID = <?php echo (int)$event['id']; ?>;

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
    voteBtn.href = `vote.php?id=${window.EV_EVENT_ID}&candidat_id=${candId}#candidat-${candId}`;

    modal.style.display = 'grid';
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

// Initialisation au chargement
document.addEventListener('DOMContentLoaded', () => {
    calculateEventTotal();
    
    // Si un tarif est cliqué au départ
    const firstAvailableInput = document.querySelector('.qty-input');
    if (firstAvailableInput) {
        firstAvailableInput.value = 1;
        calculateEventTotal();
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
    document.getElementById('shareEventWaLink').href = `https://api.whatsapp.com/send?text=${encodeURIComponent('Découvrez l\'événement « ' + eventNom + ' » sur Tikéli : ' + url)}`;
    document.getElementById('shareEventFbLink').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
    document.getElementById('shareEventXLink').href = `https://twitter.com/intent/tweet?text=${encodeURIComponent('Découvrez « ' + eventNom + ' » sur Tikéli')}&url=${encodeURIComponent(url)}`;

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
            text: 'Découvrez cet événement sur Tikéli',
            url: url
        }).catch(() => {});
    } else {
        copyEventShareLink();
    }
}
</script>

<!-- ============================================================
     MODALE DE CHOIX DE PLACE IMMERSIF EN 3D (RESPONSIVE)
     ============================================================ -->
<div id="client3DSeatingModal" class="client-modal s3d-modal-overlay" role="dialog" aria-modal="true" style="display: none;" hidden>
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
                    <i class="fa-solid fa-hand-pointer"></i> Touchez un siège · Glissez pour pivoter · Pincez pour zoomer
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
window.EV_EVENT_ID = <?php echo (int)$event['id']; ?>;
</script>
<script src="../js/venue-3d-engine.js?v=<?php echo time(); ?>"></script>
<script src="../js/accueil-client.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/footer.php'; ?>
