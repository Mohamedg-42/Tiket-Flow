<?php
// ==============================================================================
// PAGE DÉDIÉE DE VOTE & CONCOURS (client/vote.php)
// Présente la description complète de l'événement et la liste des candidats à voter
// Standard Typographique Suisse Müller-Brockmann & Performance
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$event_id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : ((isset($_GET['event_id']) && is_numeric($_GET['event_id'])) ? (int)$_GET['event_id'] : 0);
if (!$event_id) {
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

$prix_vote  = (float)($event['prix_vote'] ?? 0);
$est_payant = ($prix_vote > 0);
$type_vote  = $event['type_vote'] ?? 'concours';

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
$total_votes = (int)$stmt_total->fetchColumn();

// 4. Votes déjà effectués par l'utilisateur / visiteur courant
$visitor_id = session_id();
$user_id    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$user_role  = $_SESSION['user_role'] ?? 'client';
$peut_agir  = empty($user_id) || ($user_role === 'client');

$voted_candidates = [];
$has_voted_event  = false;
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
            $voted_candidates[(int)$row_v['candidat_id']] = true;
        }
        $has_voted_event = true;
    }
} catch (PDOException $e) {
    // Table non prête
}

$candidat_focus_id = filter_input(INPUT_GET, 'candidat_id', FILTER_VALIDATE_INT) ?: 0;

// Empêcher tout cache agressif du navigateur pour afficher instantanément les modifications
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$page_title = htmlspecialchars($event['nom']) . " — Vote Officiel | Tikéli";
$body_class = "client-page vote-detail-page";
include __DIR__ . '/header.php';
?>

<link rel="stylesheet" href="../Css/accueil-client.css?v=<?php echo time(); ?>">

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
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.vote-btn-share-top:hover {
    background: var(--vote-orange-subtle);
    border-color: var(--vote-orange);
    color: var(--vote-orange);
    transform: translateY(-1px);
}

/* Grille Principale de Présentation */
.vote-hero-card {
    background: var(--vote-surface);
    border: 1px solid var(--vote-border);
    border-radius: var(--vote-radius-lg);
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    display: grid;
    grid-template-columns: 440px 1fr;
    margin-bottom: 2.5rem;
}
@media (max-width: 960px) {
    .vote-hero-card {
        grid-template-columns: 1fr;
    }
}

.vote-hero-media {
    position: relative;
    background: #0f172a;
    min-height: 320px;
    overflow: hidden;
}
.vote-hero-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center 25%;
    display: block;
}
.vote-hero-badges {
    position: absolute;
    top: 16px;
    left: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 2;
}
.vote-badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(15, 23, 42, 0.88);
    backdrop-filter: blur(8px);
    color: #ffffff;
    padding: 5px 12px;
    border-radius: var(--vote-radius-full);
    font-size: 0.78rem;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
}
.vote-badge-chip.orange {
    background: var(--vote-orange);
    border-color: transparent;
}

.vote-hero-content {
    padding: clamp(1.5rem, 3vw, 2.5rem);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.vote-hero-header {
    margin-bottom: 1.25rem;
}
.vote-hero-title {
    font-family: var(--vote-font-display);
    font-size: clamp(1.6rem, 2.5vw, 2.2rem);
    font-weight: 800;
    color: var(--vote-dark);
    line-height: 1.2;
    margin: 0.4rem 0 0.85rem;
    letter-spacing: -0.02em;
}

.vote-meta-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.vote-meta-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: var(--vote-radius-sm);
    font-size: 0.82rem;
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
    border-left: 4px solid var(--vote-orange);
    padding: 1rem 1.25rem;
    border-radius: 0 var(--vote-radius-sm) var(--vote-radius-sm) 0;
    margin: 1rem 0 1.25rem;
}
.vote-question-box strong {
    display: block;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--vote-orange);
    font-family: var(--vote-font-mono);
    margin-bottom: 0.3rem;
}
.vote-question-box p {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--vote-dark);
    line-height: 1.4;
}

/* Grille de stats KPIs */
.vote-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 0.75rem;
    margin: 1.25rem 0 1.5rem;
    padding: 1rem 0;
    border-top: 1px solid var(--vote-border);
    border-bottom: 1px solid var(--vote-border);
}
.vote-stat-item {
    display: flex;
    flex-direction: column;
}
.vote-stat-value {
    font-family: var(--vote-font-mono);
    font-size: 1.6rem;
    font-weight: 900;
    color: var(--vote-dark);
    line-height: 1;
}
.vote-stat-value.highlight {
    color: var(--vote-orange);
}
.vote-stat-label {
    font-size: 0.78rem;
    color: var(--vote-gray-muted);
    font-weight: 600;
    margin-top: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* Description & Détails */
.vote-description-area {
    margin: 1rem 0 0;
    color: #334155;
    font-size: 0.95rem;
    line-height: 1.65;
}
.vote-description-area h4 {
    font-size: 0.85rem;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: var(--vote-gray-muted);
    margin: 0 0 0.5rem;
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
    flex: 0 0 240px;
    width: 240px;
    scroll-snap-align: start;
    background: #ffffff;
    border: 1px solid #E2E8F0;
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
    border-color: #FF4A0D;
}
.vote-cand-card.is-focused {
    outline: 3px solid #FF4A0D;
    box-shadow: 0 0 0 6px #FFF2ED;
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
    padding: 0.9rem;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.vote-cand-nom {
    font-size: 0.95rem;
    font-weight: 800;
    color: #0F172A;
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
    font-family: 'Space Mono', monospace;
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
    border: 1px solid #E2E8F0;
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
    color: #0F172A;
    margin: 0.25rem 0 0.5rem;
    line-height: 1.2;
}
.candidat-detail-event {
    font-size: 0.84rem;
    color: #64748B;
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
    padding: 1rem 1.15rem;
    margin-bottom: 1.25rem;
}
.candidat-detail-desc-box h4 {
    margin: 0 0 0.4rem;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748B;
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

.poster-floating-share-btn {
    display: none !important;
}

.candidate-card {
    border-radius: var(--vote-radius-md);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
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
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.03); }
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
    border: 1px solid rgba(255,255,255,0.2);
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
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
    border: 1px solid rgba(255,255,255,0.25);
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
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
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
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
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
            <span style="color: var(--vote-dark); font-weight: 700;"><?php echo htmlspecialchars($event['nom']); ?></span>
        </div>

        <div class="vote-topbar-actions">
            <button type="button" class="vote-btn-share-top"
                onclick="openShareVotePage(<?php echo (int)$event['id']; ?>, '<?php echo htmlspecialchars(addslashes($event['nom'])); ?>')">
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
            <img src="<?php echo htmlspecialchars($event_img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($event['nom']); ?>" class="vote-hero-img"
                onerror="this.onerror=null; this.src='<?php echo $default_vote_img; ?>';">

            <div class="vote-hero-badges">
                <span class="vote-badge-chip orange">
                    <i class="fa-solid fa-trophy"></i> <?php echo $type_vote === 'concours' ? 'Concours Officiel' : 'Vote Événementiel'; ?>
                </span>
                <span class="vote-badge-chip">
                    <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?>
                </span>
                <?php if ($est_payant): ?>
                    <span class="vote-badge-chip" style="background: #FFF2ED; color: #FF4A0D; font-weight: 800;">
                        <i class="fa-solid fa-coins"></i> <?php echo number_format($prix_vote, 0, ',', ' '); ?> F CFA / vote
                    </span>
                <?php else: ?>
                    <span class="vote-badge-chip" style="background: #ECFDF5; color: #059669; font-weight: 800;">
                        <i class="fa-solid fa-gift"></i> Vote 100% Gratuit
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="vote-hero-content">
            <div>
                <div class="vote-meta-tags">
                    <span class="vote-meta-tag primary"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($event['categorie']); ?></span>
                    <span class="vote-meta-tag"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($event['lieu']); ?></span>
                    <?php if (!empty($event['promoteur_nom'])): ?>
                        <span class="vote-meta-tag"><i class="fa-solid fa-user-tie"></i> Organisé par <?php echo htmlspecialchars($event['promoteur_nom']); ?></span>
                    <?php endif; ?>
                </div>

                <h1 class="vote-hero-title"><?php echo htmlspecialchars($event['nom']); ?></h1>

                <?php if (!empty($event['vote_question'])): ?>
                    <div class="vote-question-box">
                        <strong>Question officielle du scrutin :</strong>
                        <p>« <?php echo htmlspecialchars($event['vote_question']); ?> »</p>
                    </div>
                <?php endif; ?>

                <!-- Baromètre de stats -->
                <div class="vote-stats-grid">
                    <div class="vote-stat-item">
                        <span class="vote-stat-value highlight" id="totalVotesDisplay"><?php echo number_format($total_votes, 0, ',', ' '); ?></span>
                        <span class="vote-stat-label">Votes enregistrés</span>
                    </div>
                    <div class="vote-stat-item">
                        <span class="vote-stat-value"><?php echo count($candidats); ?></span>
                        <span class="vote-stat-label">Personnes en lice</span>
                    </div>
                    <div class="vote-stat-item">
                        <span class="vote-stat-value"><?php echo $est_payant ? number_format($prix_vote, 0, ',', ' ') . ' F' : 'Gratuit'; ?></span>
                        <span class="vote-stat-label">Tarif par vote</span>
                    </div>
                </div>

                <!-- Description complète -->
                <div class="vote-description-area">
                    <h4>Description de l'événement</h4>
                    <?php if (!empty($event['description'])): ?>
                        <div><?php echo nl2br(htmlspecialchars($event['description'])); ?></div>
                    <?php else: ?>
                        <p style="color: var(--vote-gray-muted); font-style: italic;">Aucune description supplémentaire fournie pour cet événement.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <?php if (!empty($candidats)): ?>
                    <a href="#candidats" class="btn-vote-candidate" style="width: auto; padding: 0.8rem 1.4rem; text-decoration: none;">
                        <i class="fa-solid fa-check-to-slot"></i> Voir les personnes à voter
                    </a>
                <?php else: ?>
                    <!-- Vote direct pour l'événement (aucun candidat listé) -->
                    <button type="button" class="btn-vote-candidate <?php echo $has_voted_event ? 'voted' : ''; ?>"
                        style="width: auto; padding: 0.8rem 1.4rem;"
                        onclick="voteDirectEvent(<?php echo (int)$event['id']; ?>, <?php echo $est_payant ? 'true' : 'false'; ?>, <?php echo (float)$prix_vote; ?>, this)">
                        <i class="fa-solid <?php echo $has_voted_event ? 'fa-check' : 'fa-thumbs-up'; ?>"></i>
                        <?php echo $has_voted_event ? 'Déjà voté' : ($est_payant ? 'Voter (' . number_format($prix_vote, 0, ',', ' ') . ' F)' : 'Voter pour cet événement'); ?>
                    </button>
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
                <div style="font-family: var(--vote-font-mono); font-weight: 700; color: var(--vote-gray-muted); font-size: 0.85rem;">
                    <?php echo count($candidats); ?> candidat(s) en lice
                </div>
                <?php if (count($candidats) > 1): ?>
                    <div class="vote-cands-nav">
                        <button type="button" class="vote-carousel-btn" onclick="scrollPageCands(-1)" aria-label="Précédent" title="Défiler vers la gauche">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <button type="button" class="vote-carousel-btn" onclick="scrollPageCands(1)" aria-label="Suivant" title="Défiler vers la droite">
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
                        $cid = (int)$cand['id'];
                        $c_votes = (int)$cand['nb_votes_cand'];
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
                        <article class="vote-cand-card <?php echo $is_focus ? 'is-focused' : ''; ?>" id="candidat-<?php echo $cid; ?>">
                            <div class="vote-cand-photo-wrap" onclick="openCandidateDetailModal(<?php echo $cid; ?>)" title="Cliquer pour voir la fiche détaillée de <?php echo htmlspecialchars($cand['nom']); ?>">
                                <img src="<?php echo $c_photo; ?>" alt="<?php echo htmlspecialchars($cand['nom']); ?>" class="vote-cand-photo" loading="lazy">
                                
                                <span class="vote-cand-badge <?php echo ($rank === 1) ? 'top-1' : ''; ?>">
                                    <?php echo ($rank === 1) ? '★ #1 en tête' : '#' . $rank; ?>
                                </span>
                            </div>

                            <div class="vote-cand-body">
                                <h3 class="vote-cand-nom" title="<?php echo htmlspecialchars($cand['nom']); ?>"><?php echo htmlspecialchars($cand['nom']); ?></h3>
                                
                                <!-- Baromètre individuel -->
                                <div class="vote-cand-stats">
                                    <span class="candidate-votes-count" id="cand-count-<?php echo $cid; ?>">
                                        <?php echo number_format($c_votes, 0, ',', ' '); ?> vote<?php echo ($c_votes > 1) ? 's' : ''; ?>
                                    </span>
                                    <span class="candidate-pct-count" id="cand-pct-<?php echo $cid; ?>" style="color: #FF4A0D; background: #FFF2ED; padding: 1px 5px; border-radius: 4px;">
                                        <?php echo $c_pct; ?>%
                                    </span>
                                </div>
                                <div class="vote-cand-gauge-bg">
                                    <div class="vote-cand-gauge-fill" id="cand-bar-<?php echo $cid; ?>" style="width: <?php echo $c_pct; ?>%;"></div>
                                </div>

                                <!-- Actions : Détails & Voter pour elle -->
                                <div class="vote-cand-actions">
                                    <button type="button" class="btn-cand-detail" onclick="openCandidateDetailModal(<?php echo $cid; ?>)" title="Voir le profil complet">
                                        <i class="fa-solid fa-eye"></i> Détails
                                    </button>

                                    <?php if ($est_payant): ?>
                                        <button type="button" class="btn-cand-vote"
                                            onclick="initiatePaidVote(<?php echo (int)$event['id']; ?>, <?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($cand['nom'])); ?>', <?php echo (float)$prix_vote; ?>)"
                                            title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
                                            <i class="fa-solid fa-coins"></i> 
                                            <span class="btn-text-desktop">Voter (<?php echo number_format($prix_vote, 0, ',', ' '); ?> F)</span>
                                            <span class="btn-text-mobile"><?php echo number_format($prix_vote, 0, ',', ' '); ?> F</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-cand-vote <?php echo $is_voted ? 'voted' : ''; ?>"
                                            id="btn-vote-cand-<?php echo $cid; ?>"
                                            onclick="toggleFreeCandVote(<?php echo (int)$event['id']; ?>, <?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($cand['nom'])); ?>', this)"
                                            title="Voter pour <?php echo htmlspecialchars($cand['nom']); ?>">
                                            <i class="fa-solid <?php echo $is_voted ? 'fa-circle-check' : 'fa-thumbs-up'; ?>"></i>
                                            <span class="btn-text-desktop"><?php echo $is_voted ? 'Voté' : 'Voter'; ?></span>
                                            <span class="btn-text-mobile"><?php echo $is_voted ? 'Voté' : 'Voter'; ?></span>
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
            <div style="background: #ffffff; border: 1px solid var(--vote-border); border-radius: var(--vote-radius-md); padding: 3rem 1.5rem; text-align: center; color: var(--vote-gray-muted);">
                <i class="fa-solid fa-user-group" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 1rem; display: block;"></i>
                <h3 style="color: var(--vote-dark); margin: 0 0 0.5rem;">Aucun candidat individuel enregistré</h3>
                <p style="margin: 0 0 1.5rem; font-size: 0.95rem;">Le scrutin pour cet événement est global. Vous pouvez voter directement pour l'événement ci-dessus.</p>
                <button type="button" class="btn-vote-candidate <?php echo $has_voted_event ? 'voted' : ''; ?>"
                    style="width: auto; margin: 0 auto;"
                    onclick="voteDirectEvent(<?php echo (int)$event['id']; ?>, <?php echo $est_payant ? 'true' : 'false'; ?>, <?php echo (float)$prix_vote; ?>, this)">
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
<div class="share-modal-overlay" id="voteShareModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; place-items: center; padding: 1rem;" onclick="if (event.target === this) closeVoteShareModal();">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager
            </div>
            <button type="button" onclick="closeVoteShareModal()" style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <h4 id="shareModalTargetTitle" style="margin: 0 0 0.25rem; font-size: 1.05rem; color: #0F172A; font-weight: 700;"></h4>
            <p id="shareModalTargetSubtitle" style="margin: 0 0 1.25rem; font-size: 0.85rem; color: #64748B;"></p>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748B; margin-bottom: 0.4rem;">Lien direct officiel</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="shareDirectLinkInput" readonly style="flex: 1; padding: 0.65rem 0.85rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 0.85rem; background: #F8FAFC; color: #0F172A;">
                    <button type="button" id="shareCopyBtn" onclick="copyShareLink()" style="background: #0F172A; color: #ffffff; border: none; padding: 0 1rem; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-regular fa-copy"></i> Copier
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <a id="shareWaLink" href="#" target="_blank" style="background: #25D366; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> WhatsApp
                </a>
                <a id="shareFbLink" href="#" target="_blank" style="background: #1877F2; color: #ffffff; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fa-brands fa-facebook-f"></i> Facebook
                </a>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DÉTAILS CANDIDAT DÉDIÉE (vote.php) -->
<div class="share-modal-overlay" id="candPageDetailModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; place-items: center; padding: 1rem;" onclick="if (event.target === this) closeCandidateDetailModal();">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 560px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: sharePop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 800; color: #0F172A; font-size: 1.1rem;">
                <i class="fa-solid fa-check-to-slot" style="color: #FF4A0D;"></i> Profil Officiel du Candidat
            </div>
            <button type="button" onclick="closeCandidateDetailModal()" style="background: none; border: none; font-size: 1.25rem; color: #64748B; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 1.5rem;">
            <div class="candidat-detail-hero">
                <div class="candidat-detail-photo-wrap">
                    <img id="candPagePhoto" src="" alt="Photo du candidat" class="candidat-detail-photo">
                    <span id="candPageRankBadge" class="vote-cand-badge top-1">#1</span>
                </div>
                <div class="candidat-detail-meta">
                    <div>
                        <h3 id="candPageNom" class="candidat-detail-nom" style="margin-top: 0;"></h3>
                        <div class="candidat-detail-event">
                            <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i> <span><?php echo htmlspecialchars($event['nom']); ?></span>
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
                <h4><i class="fa-solid fa-id-card" style="color: #FF4A0D; margin-right: 4px;"></i> Biographie & Présentation</h4>
                <p id="candPageBio" class="candidat-detail-desc-text"></p>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; font-family: var(--vote-font-mono); font-weight: 700; margin-bottom: 4px;">
                    <span style="color: #0F172A;">Baromètre du scrutin</span>
                    <span id="candPageGaugePct" style="color: #FF4A0D;">0%</span>
                </div>
                <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                    <div id="candPageGaugeFill" style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s ease;"></div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <button type="button" id="candPageVoteBtn" class="btn-vote-candidate" style="padding: 0.8rem; font-size: 0.95rem;">
                    <i class="fa-solid fa-thumbs-up"></i> <span id="candPageVoteBtnTxt">Voter pour elle</span>
                </button>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                    <button type="button" id="candPageShareBtn" class="btn-cand-detail" style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700;">
                        <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager
                    </button>
                    <button type="button" onclick="closeCandidateDetailModal()" class="btn-cand-detail" style="padding: 0.65rem; font-size: 0.85rem; font-weight: 700; color: #64748B;">
                        <i class="fa-solid fa-xmark"></i> Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let totalVotesGlobal = <?php echo (int)$total_votes; ?>;
const pageCandidats = <?php echo json_encode($candidats ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const isPayantGlobal = <?php echo $est_payant ? 'true' : 'false'; ?>;
const prixVoteGlobal = <?php echo (float)$prix_vote; ?>;
const eventIdGlobal = <?php echo (int)$event['id']; ?>;
const eventNomGlobal = '<?php echo htmlspecialchars(addslashes($event['nom'])); ?>';

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
        voteBtnTxt.textContent = `Voter pour elle (${prixVoteGlobal.toLocaleString('fr-FR')} F)`;
        voteBtn.onclick = function() {
            initiatePaidVote(eventIdGlobal, candId, cand.nom, prixVoteGlobal);
        };
    } else {
        const isVoted = document.getElementById(`btn-vote-cand-${candId}`)?.classList.contains('voted');
        voteBtnTxt.textContent = isVoted ? 'Voté pour elle' : 'Voter pour elle';
        voteBtn.onclick = function() {
            const cardBtn = document.getElementById(`btn-vote-cand-${candId}`);
            toggleFreeCandVote(eventIdGlobal, candId, cand.nom, cardBtn || voteBtn);
        };
    }

    const shareBtn = document.getElementById('candPageShareBtn');
    shareBtn.onclick = function() {
        shareCandidate(eventIdGlobal, eventNomGlobal, candId, cand.nom);
    };

    modal.style.display = 'grid';
}

function closeCandidateDetailModal() {
    const modal = document.getElementById('candPageDetailModal');
    if (modal) modal.style.display = 'none';
}

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
    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Traitement...';

    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('candidat_id', candId);

    try {
        const res = await fetch('vote-event.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.error) {
            showVoteToast(data.error, true);
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
    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('candidat_ids', candId);
    formData.append('phase', '2');

    try {
        const res = await fetch('vote-event.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.redirect) {
            window.location.href = data.redirect;
        } else if (data.error) {
            showVoteToast(data.error, true);
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
    btn.disabled = true;
    const formData = new FormData();
    formData.append('event_id', eventId);

    try {
        const res = await fetch('vote-event.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) {
            showVoteToast(data.error, true);
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
    } catch(err) {
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
    const shareUrl = `${baseUrl}?id=${eventId}&candidat_id=${candId}#candidat-${candId}`;
    showShareModal(`Votez pour ${candNom} !`, `Candidat(e) dans « ${eventNom} » sur Tikéli`, shareUrl, candNom);
}

function showShareModal(title, subtitle, url, candNom = null) {
    const modal = document.getElementById('voteShareModal');
    if (!modal) return;

    document.getElementById('shareModalTargetTitle').textContent = title;
    document.getElementById('shareModalTargetSubtitle').textContent = subtitle;
    document.getElementById('shareDirectLinkInput').value = url;

    // Bouton WhatsApp
    const waText = candNom 
        ? `🗳️ Votez pour *${candNom}* sur Tikéli !\nCliquez ici : ${url}`
        : `🗳️ Participez au vote officiel pour *${title}* sur Tikéli !\nCliquez ici : ${url}`;
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

// Scroll automatique vers le candidat si ciblé dans l'URL et ouverture de ses détails
document.addEventListener('DOMContentLoaded', () => {
    const focusId = <?php echo (int)$candidat_focus_id; ?>;
    if (focusId > 0) {
        const targetCand = document.getElementById('candidat-' + focusId);
        if (targetCand) {
            setTimeout(() => {
                targetCand.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                openCandidateDetailModal(focusId);
            }, 350);
        }
    }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
