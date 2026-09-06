<?php
// ==============================================================================
// PAGE D'ACCUEIL CLIENT & CATALOGUE (client/accueil.php)
// Recherche et réservation multi-tickets (choix de plusieurs types et quantités)
// ==============================================================================

require_once '../config/database.php';
session_start();

$page_title = "Eventia - Billetterie en ligne & Événements";
$body_class = "client-page client-home-page";
include 'header.php';

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Message de confirmation après déconnexion (redirection depuis deconnexion.php)
$logout_msg = isset($_GET['logout']);


// Rôle du visiteur : les promoteurs et administrateurs ne peuvent pas effectuer
// d'actions client (achat de billets, cotisation, vote, like) — consultation seule
$user_role = $_SESSION['user_role'] ?? '';
$peut_agir = !$is_logged_in || $user_role === 'client';
?>

<?php if (!empty($logout_msg)): ?>
    <div style="max-width: 1200px; margin: 1rem auto 0; padding: 0 1rem;">
        <div
            style="background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 10px; padding: 0.85rem 1.25rem; color: #000000; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; font-weight: 600;">
            <i class="fa-solid fa-circle-check"></i>
            <span>Vous avez été déconnecté avec succès. À bientôt sur Eventia !</span>
        </div>
    </div>
<?php endif; ?>

<?php // Le contenu de la page continue ci-dessous

// Téléphone du client connecté (pré-rempli automatiquement, sans ressaisie)
$user_telephone = '';
if ($is_logged_in) {
    try {
        $stmt_utel = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_utel->execute([$_SESSION['user_id']]);
        $user_telephone = (string) $stmt_utel->fetchColumn();
    } catch (PDOException $e) {
        $user_telephone = '';
    }
}

// 1. Paramètres de recherche multi-critères
$q = trim($_GET['q'] ?? '');
$lieu = trim($_GET['lieu'] ?? '');
$categorie = trim($_GET['categorie'] ?? '');

// 2. Requête SQL
$sql = "SELECT * FROM events WHERE statut = 'actif'";
$params = [];

if (!empty($q)) {
    $sql .= " AND (nom LIKE ? OR description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if (!empty($lieu)) {
    $sql .= " AND lieu LIKE ?";
    $params[] = "%$lieu%";
}

if (!empty($categorie) && $categorie !== 'Toutes') {
    $sql .= " AND categorie = ?";
    $params[] = $categorie;
}

$sql .= " ORDER BY date_evenement ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Compteurs de likes & événements déjà likés par le visiteur courant
$visitor_id = session_id();
$likes_counts = [];
$liked_events = [];
try {
    foreach ($pdo->query("SELECT event_id, COUNT(*) AS total FROM event_likes GROUP BY event_id") as $row) {
        $likes_counts[(int) $row['event_id']] = (int) $row['total'];
    }
    $stmt_likes = $pdo->prepare("SELECT event_id FROM event_likes WHERE user_id = ? OR visitor_id = ?");
    $stmt_likes->execute([$is_logged_in ? (int) $_SESSION['user_id'] : 0, $visitor_id]);
    foreach ($stmt_likes->fetchAll() as $row) {
        $liked_events[(int) $row['event_id']] = true;
    }
} catch (PDOException $e) {
    // Table event_likes pas encore migrée : likes masqués
}

// Onglet actif (événements / cotisations / voter)
$onglet = trim($_GET['onglet'] ?? 'evenements');
if (!in_array($onglet, ['evenements', 'cotisations', 'voter'], true)) {
    $onglet = 'evenements';
}

// Compteurs de votes & événements déjà votés par le visiteur courant
$votes_counts = [];
$voted_events = [];
try {
    foreach ($pdo->query("SELECT event_id, COUNT(*) AS total FROM event_votes GROUP BY event_id") as $row) {
        $votes_counts[(int) $row['event_id']] = (int) $row['total'];
    }
    $stmt_votes = $pdo->prepare("SELECT event_id FROM event_votes WHERE user_id = ? OR visitor_id = ?");
    $stmt_votes->execute([$is_logged_in ? (int) $_SESSION['user_id'] : 0, $visitor_id]);
    foreach ($stmt_votes->fetchAll() as $row) {
        $voted_events[(int) $row['event_id']] = true;
    }
} catch (PDOException $e) {
    // Table event_votes pas encore migrée : votes masqués
}

// Total des cotisations collectées
$total_cotisations = 0;
try {
    $total_cotisations = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM cotisations WHERE statut = 'en_attente'")->fetchColumn();
} catch (PDOException $e) {
    $total_cotisations = 0;
}

// Campagnes de cotisation (affichées comme des événements) + montants collectés
// Une contribution compte dès qu'elle est enregistrée ('payee' ou 'en_attente')
$campagnes = [];
try {
    $campagnes = $pdo->query("
        SELECT c.*,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        WHERE c.statut IN ('active', 'terminee')
        ORDER BY c.statut = 'terminee' ASC, c.date_limite IS NULL ASC, c.date_limite ASC, c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $campagnes = []; // Table cotisation_campagnes pas encore migrée
}

// Message de notification éventuel
$order_message = $_SESSION['order_message'] ?? '';
if (!empty($order_message)) {
    unset($_SESSION['order_message']);
}
$cotisation_message = $_SESSION['cotisation_message'] ?? '';
$cotisation_type = $_SESSION['cotisation_type'] ?? 'success';
if (!empty($cotisation_message)) {
    unset($_SESSION['cotisation_message'], $_SESSION['cotisation_type']);
}
// Message flash du vote payant (succès/erreur après le retour de paiement)
$vote_message = $_SESSION['vote_message'] ?? '';
$vote_type = $_SESSION['vote_type'] ?? 'success';
if (!empty($vote_message)) {
    unset($_SESSION['vote_message'], $_SESSION['vote_type']);
}

// Totaux pour les badges des onglets
$nb_events_total = count($events);
$nb_campagnes_total = count($campagnes);
$nb_votes_total = 0;
try {
    $nb_votes_total = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE statut = 'actif'")->fetchColumn();
} catch (PDOException $e) {
    $nb_votes_total = 0;
}
?>

<style>
    /* ---- Hero Banner & Recherche — Charte Tikéli Officielle ---- */
    .hero-banner {
        background: radial-gradient(circle at 10% 15%, rgba(255, 74, 13, 0.22) 0%, transparent 45%),
                    radial-gradient(circle at 90% 85%, rgba(255, 74, 13, 0.12) 0%, transparent 50%),
                    linear-gradient(145deg, #000000 0%, #0e0e0e 60%, #1a1a1a 100%);
        color: #ffffff;
        padding: clamp(2rem, 5vw, 3.5rem) clamp(1.25rem, 4vw, 2.5rem);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 20px;
        margin-bottom: 2rem;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35);
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .hero-banner .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--tikeli-orange, #FF4A0D);
        background: rgba(255, 74, 13, 0.12);
        border: 1px solid rgba(255, 74, 13, 0.35);
        padding: 6px 16px;
        border-radius: 9999px;
        margin: 0 auto 1.25rem;
        box-shadow: 0 2px 10px rgba(255, 74, 13, 0.15);
    }

    .hero-banner h1 {
        color: #ffffff;
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-size: clamp(1.65rem, 4.8vw, 3rem);
        font-weight: 900;
        margin: 0 auto 0.75rem;
        letter-spacing: -0.03em;
        line-height: 1.18;
        text-align: center;
        max-width: 800px;
        overflow-wrap: break-word;
    }

    .hero-banner p {
        color: #737373;
        font-size: clamp(0.88rem, 1.8vw, 1.05rem);
        max-width: 640px;
        margin: 0 auto 1.75rem;
        line-height: 1.55;
        text-align: center;
        overflow-wrap: break-word;
    }

    .search-box-wrapper {
        background: #ffffff;
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 16px;
        padding: 0.5rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        max-width: 880px;
        margin: 0 auto;
        box-shadow: 0 14px 35px rgba(6, 19, 41, 0.28);
        align-items: center;
    }

    .search-input-field {
        flex: 1;
        min-width: 180px;
        display: flex;
        align-items: center;
        padding: 0.5rem 0.85rem;
        border-right: 1px solid #F5F5F5;
        gap: 0.5rem;
    }

    .search-input-field:last-of-type {
        border-right: none;
    }

    .search-input-field i {
        color: var(--eventia-amber-dark, #FF4A0D);
        font-size: 0.95rem;
        flex-shrink: 0;
    }

    .search-input-field input,
    .search-input-field select {
        width: 100%;
        border: none;
        outline: none;
        padding: 0.35rem 0;
        font-size: 0.92rem;
        font-family: var(--font-body, 'Inter', sans-serif);
        color: var(--eventia-navy, #000000);
        background: transparent;
        font-weight: 500;
    }

    .search-box-wrapper .btn-submit-hero {
        background: linear-gradient(135deg, var(--tikeli-orange, #FF4A0D), #E03E08);
        color: #ffffff;
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-weight: 800;
        font-size: 0.95rem;
        padding: 0.75rem 1.65rem;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
    }

    .search-box-wrapper .btn-submit-hero:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(224, 62, 8, 0.45);
    }

    .search-box-wrapper .btn-submit-hero:active {
        transform: scale(0.98);
    }

    @media (max-width: 768px) {
        .search-box-wrapper {
            flex-direction: column;
            gap: 0.35rem;
            padding: 0.75rem;
        }

        .search-input-field {
            width: 100%;
            border-right: none;
            border-bottom: 1px solid #F5F5F5;
            padding: 0.65rem 0.5rem;
        }

        .search-input-field:last-of-type {
            border-bottom: none;
            margin-bottom: 0.35rem;
        }

        .search-box-wrapper .btn-submit-hero {
            width: 100%;
            padding: 0.85rem;
        }
    }

    /* ---- Cartes Événements Eventia ---- */
    .event-card-item {
        background: #ffffff;
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: var(--eventia-radius-lg, 14px);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: var(--eventia-transition);
        box-shadow: var(--eventia-shadow-sm, 0 2px 4px rgba(16, 24, 40, 0.06));
        text-align: left;
    }

    .event-card-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--eventia-shadow-lg, 0 12px 24px -4px rgba(16, 24, 40, 0.12));
        border-color: #E5E5E5;
    }

    .event-card-item h3 {
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-weight: 700;
        color: var(--eventia-navy, #000000);
        overflow-wrap: break-word;
        word-break: normal;
        line-height: 1.25;
    }

    .event-card-item p {
        overflow-wrap: break-word;
        word-break: normal;
    }

    .event-poster {
        height: 216px;
        width: 100%;
        object-fit: cover;
        background: #E5E5E5;
        display: block;
    }

    .event-category-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--tikeli-black, #000000);
        color: var(--tikeli-orange, #FF4A0D);
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-size: 0.72rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: var(--eventia-radius-pill, 9999px);
        border: 1px solid rgba(255, 74, 13, 0.35);
        letter-spacing: 0.04em;
        text-transform: uppercase;
        max-width: calc(100% - 96px);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        z-index: 2;
    }

    .event-date-chip {
        position: absolute;
        bottom: 12px;
        right: 12px;
        background: #ffffff;
        color: var(--eventia-navy, #000000);
        font-family: var(--font-body, 'Inter', sans-serif);
        font-size: 0.75rem;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: var(--eventia-radius-sm, 8px);
        border: 1px solid var(--eventia-border, #E5E5E5);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        max-width: calc(100% - 24px);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        z-index: 2;
    }

    .ticket-tier-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        background: #F5F5F5;
        border: 1px solid var(--line);
        border-radius: var(--radius-md);
        padding: 0.85rem 1.1rem;
        margin-bottom: 0.65rem;
        transition: var(--transition);
        overflow: hidden;
    }

    .ticket-tier-row:hover {
        border-color: var(--primary-light);
        background: #ffffff;
    }

    .ticket-tier-info {
        flex: 1 1 190px;
        min-width: 0;
        overflow-wrap: break-word;
    }

    .ticket-tier-info strong {
        display: block;
        color: var(--navy);
        font-size: 0.95rem;
        overflow-wrap: break-word;
        word-break: normal;
        line-height: 1.35;
    }

    .ticket-tier-info small,
    .ticket-tier-info span {
        overflow-wrap: break-word;
    }

    .ticket-qty-control {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-shrink: 0;
    }

    .ticket-qty-btn {
        width: 32px;
        height: 32px;
        border: 1px solid var(--line);
        background: #ffffff;
        border-radius: 6px;
        display: grid;
        place-items: center;
        cursor: pointer;
        font-weight: bold;
        font-size: 1rem;
        color: var(--navy);
        transition: var(--transition);
    }

    .ticket-qty-btn:hover {
        background: var(--primary-soft);
        border-color: var(--primary);
        color: var(--primary);
    }

    .ticket-qty-input {
        width: 48px;
        text-align: center;
        padding: 0.35rem 0.2rem;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.95rem;
    }

    /* Bulles de catégories (Carousel fluide & moderne) */
    .category-chips {
        display: flex;
        gap: 0.5rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding: 4px 2px 14px;
        margin-top: 1.25rem;
        margin-bottom: 1.75rem;
        scroll-snap-type: x mandatory;
    }

    .category-chips::-webkit-scrollbar {
        display: none;
    }

    .category-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.5rem 1rem;
        background: #ffffff;
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 9999px;
        color: var(--eventia-navy, #000000);
        font-family: var(--font-heading, 'Outfit', sans-serif);
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        scroll-snap-align: start;
        box-shadow: 0 1px 3px rgba(11, 29, 58, 0.04);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        flex-shrink: 0;
    }

    .category-chip i {
        color: var(--eventia-navy-light, #000000);
        font-size: 0.85rem;
    }

    .category-chip:hover {
        background: #F5F5F5;
        border-color: #E5E5E5;
        transform: translateY(-1px);
        color: var(--eventia-navy, #000000);
    }

    .category-chip.active {
        background: var(--tikeli-orange, #FF4A0D);
        color: #ffffff;
        border-color: var(--tikeli-orange, #FF4A0D);
        box-shadow: 0 4px 12px rgba(255, 74, 13, 0.28);
    }

    .category-chip.active i {
        color: #ffffff;
    }

    /* ===== Bouton like (cœur) sur les cartes ===== */
    .event-like-btn {
        position: absolute;
        top: 12px;
        right: 12px;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 5px 12px;
        background: rgba(255, 255, 255, 0.92);
        border: none;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 800;
        font-family: inherit;
        color: var(--navy);
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        transition: var(--transition);
        z-index: 2;
    }

    .event-like-btn i {
        color: #737373;
        transition: var(--transition);
    }

    .event-like-btn:hover {
        transform: scale(1.08);
    }

    .event-like-btn:hover i {
        color: #000000;
    }

    .event-like-btn.liked i {
        color: #000000;
    }

    .event-like-btn.liked {
        color: #000000;
    }

    /* ===== Choix de place : plan interactif ===== */
    .ticket-tier-row {
        flex-wrap: wrap;
    }

    .seat-choice-block {
        width: 100%;
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px dashed var(--line);
    }

    .seat-map-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: var(--radius-full);
        color: var(--ink);
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
    }

    .seat-map-toggle i {
        color: var(--primary);
    }

    .seat-map-toggle strong {
        color: var(--primary);
    }

    .seat-map-toggle:hover,
    .seat-map-toggle.active {
        border-color: var(--primary);
        background: var(--primary-soft);
    }

    .seat-choice-label {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        font-size: 0.82rem;
        color: var(--ink);
        cursor: pointer;
        font-weight: 600;
        width: 100%;
        min-width: 0;
    }

    .seat-choice-label span {
        flex: 1;
        min-width: 0;
        overflow-wrap: break-word;
        word-break: normal;
        line-height: 1.45;
    }

    .seat-choice-label input {
        accent-color: var(--primary);
        width: 16px;
        height: 16px;
        cursor: pointer;
        margin-top: 2px;
        flex-shrink: 0;
    }

    .seat-choice-label strong {
        color: var(--primary);
        overflow-wrap: break-word;
    }

    .seat-choice-label small {
        color: var(--muted);
        font-weight: 500;
        display: block;
        overflow-wrap: break-word;
    }

    /* ==========================================================================
       VUE DE SCÈNE INTERACTIVE & CHOIX DE PLACE RESPONSIVE
       ========================================================================== */
    .scene-view-card {
        margin-top: 0.75rem;
        background: #000000;
        border: 1px solid #000000;
        border-radius: 10px;
        padding: clamp(0.65rem, 2vw, 0.85rem);
        color: #F5F5F5;
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.4);
    }

    .scene-view-card[hidden] {
        display: none !important;
    }

    .scene-stage-banner {
        background: linear-gradient(180deg, #000000 0%, #000000 100%);
        border: 1px solid rgba(56, 189, 248, 0.35);
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .scene-stage-podium {
        font-size: clamp(0.72rem, 2vw, 0.8rem);
        font-weight: 800;
        letter-spacing: 1px;
        color: #FF4A0D;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .btn-scene-interactive {
        width: 100%;
        min-height: 44px;
        padding: 0.65rem 0.9rem;
        background: linear-gradient(135deg, #000000 0%, #000000 100%);
        color: #ffffff;
        border: 1.5px solid #FF4A0D;
        border-radius: 8px;
        font-weight: 800;
        font-size: clamp(0.78rem, 2.2vw, 0.86rem);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(56, 189, 248, 0.25);
        transition: all 0.2s ease;
        flex-wrap: wrap;
        box-sizing: border-box;
    }

    .btn-scene-interactive:hover {
        background: linear-gradient(135deg, #000000 0%, #FF4A0D 100%);
        border-color: #FF4A0D;
        transform: translateY(-1px);
    }

    .scene-tag-badge {
        background: rgba(56, 189, 248, 0.2);
        color: #FF4A0D;
        font-size: 0.72rem;
        padding: 3px 8px;
        border-radius: 12px;
        white-space: nowrap;
    }

    .scene-selected-summary {
        background: rgba(15, 23, 42, 0.85);
        border: 1px solid #000000;
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        font-size: 0.8rem;
    }

    .seat-hint {
        display: block;
        width: 100%;
        margin-top: 0.55rem;
        font-size: 0.78rem;
        color: var(--muted);
        font-weight: 600;
    }

    .seat-hint strong {
        color: var(--primary);
    }

    /* ===== MODALE VUE DE SCÈNE 3D RESPONSIVE (MÜLLER-BROCKMANN) ===== */
    .s3d-modal-overlay {
        z-index: 100000;
        background: rgba(3, 7, 18, 0.94);
        backdrop-filter: blur(8px);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .s3d-modal-window {
        background: #000000;
        border: 1px solid #000000;
        border-radius: 18px;
        width: 100%;
        max-width: 1200px;
        height: 92vh;
        height: 92dvh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.85);
        position: relative;
    }

    /* Header */
    .s3d-header {
        padding: 0.75rem 1.25rem;
        background: #000000;
        border-bottom: 1px solid #000000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        flex-shrink: 0;
    }

    .s3d-header-main {
        min-width: 0;
        flex: 1 1 240px;
    }

    .s3d-header-title-row {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .s3d-badge-3d {
        background: rgba(56, 189, 248, 0.18);
        color: #FF4A0D;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .s3d-event-title {
        margin: 0;
        font-size: clamp(0.95rem, 2.5vw, 1.15rem);
        color: #F5F5F5;
        font-weight: 800;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .s3d-venue-subtitle {
        font-size: 0.76rem;
        color: #737373;
        margin-top: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .s3d-header-controls {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .s3d-tabs {
        display: flex;
        gap: 4px;
        background: #000000;
        padding: 3px;
        border-radius: 8px;
        border: 1px solid #000000;
    }

    .studio-tab-btn {
        background: transparent;
        border: 1px solid transparent;
        color: #737373;
        padding: 0.35rem 0.65rem;
        border-radius: 6px;
        font-size: 0.76rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .studio-tab-btn:hover {
        color: #ffffff;
    }

    .studio-tab-btn.active {
        background: rgba(56, 189, 248, 0.16);
        border-color: rgba(56, 189, 248, 0.4);
        color: #FF4A0D;
    }

    .s3d-cameras-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .s3d-cameras {
        display: flex;
        gap: 4px;
    }

    .s3d-cam-btn {
        background: #000000;
        color: #F5F5F5;
        border: 1px solid #000000;
        font-size: 0.76rem;
        padding: 0.35rem 0.6rem;
        border-radius: 6px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: background 0.15s ease;
    }

    .s3d-cam-btn:hover {
        background: #000000;
    }

    .s3d-cam-reset {
        color: #FF4A0D;
    }

    .s3d-close-btn {
        background: rgba(239, 68, 68, 0.18);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #000000;
        border-radius: 8px;
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        cursor: pointer;
        font-size: 0.95rem;
        transition: all 0.15s ease;
    }

    .s3d-close-btn:hover {
        background: #000000;
        color: #ffffff;
    }

    /* Body */
    .s3d-body {
        display: flex;
        flex: 1;
        min-height: 0;
        overflow: hidden;
        position: relative;
    }

    .s3d-canvas-panel {
        flex: 1;
        min-width: 0;
        position: relative;
        background: #000000;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .s3d-canvas-panel canvas {
        width: 100%;
        height: 100%;
        display: block;
        touch-action: none;
    }

    .s3d-tariff-bar {
        position: absolute;
        top: 10px;
        left: 10px;
        right: 10px;
        display: flex;
        gap: 6px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
        z-index: 10;
        scrollbar-width: none;
    }

    .s3d-tariff-bar::-webkit-scrollbar {
        display: none;
    }

    .studio-filter-btn {
        background: rgba(15, 23, 42, 0.9);
        backdrop-filter: blur(6px);
        border: 1px solid #000000;
        color: #E5E5E5;
        padding: 0.35rem 0.7rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.15s ease;
    }

    .studio-filter-btn.active {
        background: #FF4A0D;
        color: #000000;
        border-color: #FF4A0D;
        box-shadow: 0 0 10px rgba(56, 189, 248, 0.4);
    }

    .s3d-touch-hint {
        position: absolute;
        top: 48px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(15, 23, 42, 0.85);
        color: #737373;
        font-size: 0.7rem;
        padding: 3px 10px;
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.08);
        pointer-events: none;
        white-space: nowrap;
        opacity: 0.85;
        z-index: 5;
    }

    .s3d-legend {
        position: absolute;
        bottom: 10px;
        left: 10px;
        background: rgba(15, 23, 42, 0.9);
        backdrop-filter: blur(8px);
        border: 1px solid #000000;
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        display: flex;
        gap: 0.75rem;
        align-items: center;
        font-size: 0.72rem;
        color: #F5F5F5;
        z-index: 10;
        pointer-events: none;
    }

    .s3d-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .s3d-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }

    .dot-libre { background: #FF4A0D; }
    .dot-selected { background: #FF4A0D; box-shadow: 0 0 6px #FF4A0D; }
    .dot-occupe { background: #737373; }

    /* Other panels */
    .s3d-plan-panel,
    .s3d-photos-panel {
        flex: 1;
        display: none;
        background: #000000;
        padding: 1.25rem;
        overflow-y: auto;
    }

    .s3d-plan-panel {
        text-align: center;
    }

    .s3d-photos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
    }

    /* Sidebar / Drawer */
    .s3d-sidebar {
        width: 320px;
        flex-shrink: 0;
        background: #000000;
        border-left: 1px solid #000000;
        padding: 1.15rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow-y: auto;
        box-sizing: border-box;
    }

    .s3d-sidebar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .s3d-sidebar-title {
        font-weight: 800;
        color: #F5F5F5;
        font-size: 0.88rem;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .s3d-seats-badge {
        background: rgba(56, 189, 248, 0.2);
        color: #FF4A0D;
        font-size: 0.72rem;
        padding: 2px 7px;
        border-radius: 10px;
        font-weight: 800;
    }

    .s3d-mobile-toggle-btn {
        display: none;
        background: rgba(56, 189, 248, 0.15);
        color: #FF4A0D;
        border: 1px solid rgba(56, 189, 248, 0.3);
        border-radius: 6px;
        padding: 3px 8px;
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
    }

    .s3d-selected-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: 220px;
        overflow-y: auto;
        margin-bottom: 0.85rem;
        padding-right: 2px;
    }

    .s3d-empty-msg {
        color: #737373;
        font-size: 0.78rem;
        font-style: italic;
        text-align: center;
        padding: 1.25rem 0;
    }

    .s3d-sightline-box {
        background: #000000;
        border: 1px solid #000000;
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        margin-bottom: 0.85rem;
    }

    .s3d-sightline-title {
        font-size: 0.72rem;
        font-weight: 700;
        color: #FF4A0D;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .s3d-sightline-desc {
        font-size: 0.76rem;
        color: #E5E5E5;
        line-height: 1.35;
    }

    .s3d-sidebar-footer {
        flex-shrink: 0;
    }

    .s3d-total-card {
        background: #000000;
        border: 1px solid #000000;
        border-radius: 10px;
        padding: 0.75rem 0.9rem;
        margin-bottom: 0.75rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .s3d-total-label {
        font-size: 0.72rem;
        color: #737373;
        text-transform: uppercase;
        display: block;
        font-weight: 700;
    }

    .s3d-sub-count {
        color: #FF4A0D;
        font-weight: 700;
        font-size: 0.75rem;
    }

    .s3d-total-amount {
        color: #FF4A0D;
        font-size: 1.15rem;
        font-weight: 800;
    }

    .s3d-btn-validate {
        width: 100%;
        margin: 0;
        background: #FF4A0D;
        font-size: 0.88rem;
        padding: 0.75rem 1rem;
        min-height: 44px;
        border-radius: 8px;
    }

    .s3d-btn-cancel {
        width: 100%;
        margin-top: 6px;
        background: transparent;
        border: none;
        color: #737373;
        font-size: 0.78rem;
        cursor: pointer;
        text-decoration: underline;
        padding: 4px;
    }

    /* ===== ADAPTATIONS MOBILES (< 992px & < 640px) ===== */
    @media (max-width: 991px) {
        .s3d-modal-overlay {
            padding: 0 !important;
        }

        .s3d-modal-window {
            width: 100% !important;
            max-width: 100% !important;
            height: 100vh !important;
            height: 100dvh !important;
            border-radius: 0 !important;
            border: none !important;
        }

        .s3d-body {
            flex-direction: column !important;
        }

        .s3d-canvas-panel {
            flex: 1 1 auto !important;
            min-height: 250px !important;
            height: auto !important;
        }

        .s3d-sidebar {
            width: 100% !important;
            border-left: none !important;
            border-top: 1px solid #000000 !important;
            padding: 0.75rem 1rem !important;
            max-height: 48vh !important;
            background: #000000 !important;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.5) !important;
        }

        .s3d-mobile-toggle-btn {
            display: inline-flex !important;
            align-items: center;
            gap: 4px;
        }

        /* Mode rétracté sur mobile pour voir le canvas */
        .s3d-sidebar.is-collapsed .s3d-sidebar-content {
            display: none !important;
        }

        .s3d-selected-list {
            max-height: 110px !important;
        }
    }

    @media (max-width: 640px) {
        .s3d-header {
            padding: 0.5rem 0.75rem !important;
            gap: 0.4rem !important;
        }

        .s3d-header-controls {
            width: 100% !important;
            justify-content: space-between !important;
        }

        .s3d-venue-subtitle {
            display: none !important;
        }

        .s3d-touch-hint {
            display: none !important;
        }

        .s3d-legend {
            bottom: 6px !important;
            left: 6px !important;
            padding: 0.25rem 0.5rem !important;
            font-size: 0.65rem !important;
            gap: 0.4rem !important;
        }

        .s3d-cam-btn span {
            display: none !important;
        }

        .s3d-cam-btn {
            padding: 0.35rem 0.5rem !important;
        }

        .s3d-total-card {
            padding: 0.5rem 0.75rem !important;
            margin-bottom: 0.5rem !important;
        }

        .s3d-total-amount {
            font-size: 1.05rem !important;
        }

        .s3d-photos-grid {
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)) !important;
            gap: 0.5rem !important;
        }
    }

    /* ===== Onglets principaux (Segmented Control Pro) ===== */
    .main-tabs {
        display: flex;
        gap: 0.35rem;
        margin: 0 auto 1.75rem;
        background: #ffffff;
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 9999px;
        padding: 5px;
        box-shadow: 0 4px 15px rgba(11, 29, 58, 0.06);
        width: fit-content;
        max-width: 100%;
        justify-content: center;
        box-sizing: border-box;
    }

    .main-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.55rem 1.25rem;
        border-radius: 9999px;
        color: var(--eventia-muted, #737373);
        font-size: 0.88rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: var(--font-heading, 'Outfit', sans-serif);
        white-space: nowrap;
    }

    .main-tab:hover {
        color: var(--eventia-navy, #000000);
        background: #F5F5F5;
    }

    .main-tab.active {
        background: var(--eventia-navy, #000000);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(11, 29, 58, 0.25);
    }

    .main-tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 800;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: #F5F5F5;
        color: var(--eventia-navy, #000000);
        margin-left: 0.35rem;
        transition: all 0.2s ease;
    }

    .main-tab.active .main-tab-badge {
        background: var(--eventia-amber, #FF4A0D);
        color: var(--eventia-navy-dark, #000000);
    }

    @media (max-width: 640px) {
        .main-tabs {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2px;
            padding: 4px;
            border-radius: 14px;
        }

        .main-tab {
            padding: 0.55rem 0.35rem;
            font-size: 0.78rem;
            border-radius: 10px;
            flex-direction: column;
            gap: 3px;
            text-align: center;
        }

        .main-tab-badge {
            font-size: 0.68rem;
            min-width: 18px;
            height: 18px;
            margin-left: 0;
            padding: 0 4px;
        }
    }

    /* ===== Section Cotisations ===== */
    .cotisation-card {
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        padding: 2rem;
        box-shadow: var(--shadow-sm);
        max-width: 640px;
        margin: 0 auto;
    }

    .cotisation-amounts {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .cotisation-amount-btn {
        padding: 0.5rem 1.1rem;
        border: 1px solid var(--line);
        border-radius: var(--radius-full);
        background: #ffffff;
        color: var(--ink);
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: var(--transition);
    }

    .cotisation-amount-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    /* ===== Section Voter : classement des événements ===== */
    .vote-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: var(--radius-md);
        padding: 0.9rem 1.1rem;
        margin-bottom: 0.65rem;
        transition: var(--transition);
    }

    .vote-rank {
        width: 34px;
        height: 34px;
        flex-shrink: 0;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: #F5F5F5;
        color: var(--muted);
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        font-size: 0.9rem;
    }

    .vote-rank.top1 {
        background: #FFF2ED;
        color: #FF4A0D;
    }

    .vote-rank.top2 {
        background: #E5E5E5;
        color: #737373;
    }

    .vote-rank.top3 {
        background: #FFF2ED;
        color: #FF4A0D;
    }

    .vote-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 1rem;
        border: 1px solid var(--line);
        border-radius: var(--radius-full);
        background: #ffffff;
        color: var(--ink);
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: var(--transition);
        flex-shrink: 0;
    }

    .vote-btn i {
        color: #737373;
    }

    .vote-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    .vote-btn.voted {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-color: transparent;
        color: #ffffff;
        box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
    }

    .vote-btn.voted i {
        color: #ffffff;
    }

    /* ===== Choix multiples & Candidats du vote payant ===== */
    .candidats-vote-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 310px;
        overflow-y: auto;
        padding-right: 4px;
        margin-bottom: 1.25rem;
    }

    .candidats-vote-list::-webkit-scrollbar {
        width: 5px;
    }

    .candidats-vote-list::-webkit-scrollbar-thumb {
        background: #E5E5E5;
        border-radius: 999px;
    }

    .candidat-choice-card {
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
        padding: 0.85rem 1rem;
        border: 2px solid var(--line);
        border-radius: 10px;
        background: #ffffff;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
        user-select: none;
    }

    .candidat-choice-card:hover {
        border-color: #FFF2ED;
        background: #F5F5F5;
        transform: translateY(-1px);
    }

    .candidat-choice-card.selected {
        border-color: var(--primary);
        background: linear-gradient(135deg, #FFF2ED 0%, #FFF2ED 100%);
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.15);
    }

    .candidat-checkbox {
        margin-top: 4px;
        transform: scale(1.3);
        accent-color: var(--primary);
        cursor: pointer;
        flex-shrink: 0;
    }

    .candidat-choice-photo {
        width: 56px;
        height: 56px;
        border-radius: 8px;
        object-fit: cover;
        flex-shrink: 0;
        border: 2px solid var(--line);
        transition: transform 0.2s ease, border-color 0.2s ease;
    }

    .candidat-choice-card.selected .candidat-choice-photo {
        border-color: var(--primary);
        transform: scale(1.05);
        box-shadow: 0 3px 8px rgba(13, 148, 136, 0.25);
    }

    .candidat-choice-info {
        flex: 1;
        min-width: 0;
    }

    .candidat-choice-nom {
        font-weight: 700;
        color: var(--navy);
        font-size: 0.95rem;
        margin-bottom: 0.2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        overflow-wrap: break-word;
        word-break: normal;
    }

    .candidat-choice-desc {
        color: var(--muted);
        font-size: 0.82rem;
        line-height: 1.45;
        margin: 0;
        transition: color 0.2s ease;
        overflow-wrap: break-word;
        word-break: normal;
    }

    .candidat-choice-card.selected .candidat-choice-desc {
        color: #000000;
    }

    .candidat-badge-selected {
        font-size: 0.72rem;
        background: #FF4A0D;
        color: #ffffff;
        padding: 2px 7px;
        border-radius: 999px;
        font-weight: 700;
        display: none;
    }

    .candidat-choice-card.selected .candidat-badge-selected {
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
</style>

<main class="client-main"
    style="max-width: 1200px; margin: 0 auto; padding: clamp(1rem, 2.5vw, 2rem) clamp(0.75rem, 2vw, 1.5rem);">
    <!-- ===== Onglets principaux (tout en haut) ===== -->
    <div class="main-tabs">
        <a href="accueil.php?onglet=evenements"
            class="main-tab <?php echo ($onglet === 'evenements') ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-days"></i> Événements
            <span class="main-tab-badge"><?php echo $nb_events_total; ?></span>
        </a>
        <a href="accueil.php?onglet=cotisations"
            class="main-tab <?php echo ($onglet === 'cotisations') ? 'active' : ''; ?>">
            <i class="fa-solid fa-hand-holding-heart"></i> Cotisations
            <span class="main-tab-badge"><?php echo $nb_campagnes_total; ?></span>
        </a>
        <a href="accueil.php?onglet=voter" class="main-tab <?php echo ($onglet === 'voter') ? 'active' : ''; ?>">
            <i class="fa-solid fa-vote-yea"></i> Vote
            <span class="main-tab-badge"><?php echo $nb_votes_total; ?></span>
        </a>
    </div>

    <?php if ($onglet === 'cotisations'): ?>
        <!-- ============================================================
         SECTION COTISATIONS
         ============================================================ -->
        <section>
            <div style="text-align: center; margin-bottom: 2rem;">
                <span class="page-kicker"><i class="fa-solid fa-hand-holding-heart"></i> Soutenez nos événements</span>
                <h2 style="font-size: 1.6rem; color: var(--navy);">Cotisations & Contributions</h2>
                <p style="color: var(--muted); font-size: 0.92rem; max-width: 560px; margin: 0.5rem auto 0;">
                    Contribuez au financement des événements à venir. Chaque cotisation aide les promoteurs à organiser de
                    plus grandes expériences.
                </p>
            </div>

            <?php if (!empty($cotisation_message)): ?>
                <div class="alert <?php echo ($cotisation_type === 'success') ? 'alert-success' : 'alert-error'; ?>"
                    style="max-width: 640px; margin: 0 auto 1.5rem;">
                    <i class="fa-solid fa-circle-<?php echo ($cotisation_type === 'success') ? 'check' : 'exclamation'; ?>"></i>
                    <?php echo htmlspecialchars($cotisation_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($campagnes)): ?>
                <!-- Grille des campagnes de cotisation (présentées comme des événements) -->
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: clamp(1rem, 2.5vw, 1.75rem);">
                    <?php foreach ($campagnes as $campagne): ?>
                        <?php
                        $collecte = (float) $campagne['montant_collecte'];
                        $objectif = (float) $campagne['montant_objectif'];
                        $pct_collecte = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100)) : 0;
                        $objectif_atteint = ($objectif > 0 && $collecte >= $objectif);
                        $est_terminee = ($campagne['statut'] === 'terminee');
                        // On peut toujours contribuer tant que la campagne n'est pas terminée,
                        // même si l'objectif est déjà atteint
                        $peut_contribuer = !$est_terminee;

                        // Image : URL complète ou fichier local dans uploads/events/
                        $campagne_img = 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=800&q=80';
                        if (!empty($campagne['image'])) {
                            if (strpos($campagne['image'], 'http') === 0) {
                                $campagne_img = htmlspecialchars($campagne['image']);
                            } elseif (file_exists('../uploads/events/' . $campagne['image'])) {
                                $campagne_img = '../uploads/events/' . htmlspecialchars($campagne['image']);
                            }
                        }

                        // Description tronquée (comme les cartes d'événements)
                        $camp_desc = trim($campagne['description'] ?? '');
                        $camp_long = (mb_strlen($camp_desc) > 110);
                        $camp_short = $camp_long ? mb_strimwidth($camp_desc, 0, 110, '...') : $camp_desc;
                        ?>
                        <article class="event-card-item" <?php echo ($peut_contribuer && $peut_agir) ? ' style="cursor: pointer;" onclick="openCotisationModal(this.querySelector(\'button[data-campagne-id]\'))"' : ''; ?>>
                            <div style="position: relative;">
                                <img src="<?php echo $campagne_img; ?>" alt="<?php echo htmlspecialchars($campagne['titre']); ?>"
                                    class="event-poster">
                                <span class="event-category-badge">
                                    <i class="fa-solid fa-hand-holding-heart" style="margin-right: 4px;"></i>
                                    <?php echo $est_terminee ? 'Terminée' : 'En cours'; ?>
                                </span>
                                <span class="event-date-chip">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                                    <?php echo $campagne['date_limite'] ? 'Jusqu\'au ' . date('d/m/Y', strtotime($campagne['date_limite'])) : 'Sans limite'; ?>
                                </span>
                            </div>

                            <div style="padding: 1.4rem; display: flex; flex-direction: column; flex: 1;">
                                <h3 style="margin: 0 0 0.5rem; color: var(--navy); font-size: 1.2rem;">
                                    <?php echo htmlspecialchars($campagne['titre']); ?>
                                </h3>

                                <div style="margin: 0 0 0.9rem;">
                                    <?php if (!empty($camp_desc)): ?>
                                        <p id="desc_campagne_<?php echo (int) $campagne['id']; ?>"
                                            data-full="<?php echo htmlspecialchars($camp_desc, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-short="<?php echo htmlspecialchars($camp_short, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-expanded="0"
                                            style="color: var(--muted); font-size: 0.88rem; line-height: 1.5; margin: 0;">
                                            <?php echo htmlspecialchars($camp_short); ?>
                                        </p>
                                        <?php if ($camp_long): ?>
                                            <button type="button"
                                                onclick="event.stopPropagation(); toggleDesc('campagne_<?php echo (int) $campagne['id']; ?>', this)"
                                                style="background: none; border: none; padding: 0; margin-top: 0.3rem; color: var(--primary); font-size: 0.8rem; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                Voir plus <i class="fa-solid fa-chevron-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div
                                    style="font-size: 0.82rem; color: var(--ink); margin-bottom: 0.9rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 600;">
                                    <i class="fa-solid fa-users" style="color: var(--primary);"></i>
                                    <?php echo (int) $campagne['nb_contributeurs']; ?> contributeur(s)
                                </div>

                                <!-- Barre de progression : avancement du montant collecté vers le montant à atteindre -->
                                <div style="margin-top: auto; border-top: 1px solid var(--line-light); padding-top: 1rem;">
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.78rem; font-weight: 700; margin-bottom: 6px;">
                                        <span style="color: var(--navy);">
                                            <i class="fa-solid fa-coins" style="color: var(--primary); font-size: 0.7rem;"></i>
                                            Collecté : <?php echo number_format($collecte, 0, ',', ' '); ?> FCFA
                                        </span>
                                        <span style="color: <?php echo $objectif_atteint ? '#FF4A0D' : 'var(--primary-dark)'; ?>;">
                                            <?php echo $pct_collecte; ?>%
                                        </span>
                                    </div>
                                    <div style="height: 10px; background: #E5E5E5; border-radius: 999px; overflow: hidden;">
                                        <div
                                            style="height: 100%; width: <?php echo $pct_collecte; ?>%; background: <?php echo $objectif_atteint ? '#FF4A0D' : 'linear-gradient(90deg, var(--primary), var(--primary-light))'; ?>; border-radius: 999px; transition: width 0.4s ease;">
                                        </div>
                                    </div>
                                    <small style="color: var(--muted); display: block; margin-top: 6px; font-weight: 600;">
                                        Objectif : <?php echo number_format($objectif, 0, ',', ' '); ?> FCFA
                                        <?php if ($objectif_atteint): ?>
                                            <span style="color: #FF4A0D;"><i class="fa-solid fa-circle-check"></i> Objectif atteint, les
                                                contributions restent ouvertes !</span>
                                        <?php endif; ?>
                                    </small>
                                </div>

                                <div style="margin-top: 1.1rem;">
                                    <?php if ($peut_contribuer && $peut_agir): ?>
                                        <button type="button" class="btn-submit"
                                            style="width: 100%; margin: 0; padding: 0.7rem 1.25rem; font-size: 0.9rem;"
                                            data-campagne-id="<?php echo (int) $campagne['id']; ?>"
                                            data-campagne-titre="<?php echo htmlspecialchars($campagne['titre'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-campagne-motivation="<?php echo htmlspecialchars($campagne['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="openCotisationModal(this)">
                                            <i class="fa-solid fa-paper-plane"></i> Contribuer
                                        </button>
                                    <?php elseif (!$peut_agir): ?>
                                        <span
                                            style="background: #fff7ed; color: #FF4A0D; border: 1px solid #FF4A0D; padding: 0.55rem 1rem; border-radius: 6px; font-weight: 800; font-size: 0.82rem; text-transform: uppercase; display: block; text-align: center;">
                                            <i class="fa-solid fa-lock"></i> Réservé aux clients
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5; padding: 0.55rem 1rem; border-radius: 6px; font-weight: 800; font-size: 0.82rem; text-transform: uppercase; display: block; text-align: center;">
                                            <i class="fa-solid fa-flag-checkered"></i>
                                            <?php echo $est_terminee ? 'Campagne terminée' : 'Objectif atteint'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- Aucune campagne : formulaire de contribution générale -->
                <div class="cotisation-card">
                    <?php if ($peut_agir): ?>
                        <form method="POST" action="cotisation.php">

                            <?php if ($is_logged_in): ?>
                                <!-- Client connecté : aucune saisie d'identité, uniquement le montant -->
                                <input type="hidden" name="nom"
                                    value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="email"
                                    value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="telephone"
                                    value="<?php echo htmlspecialchars($user_telephone, ENT_QUOTES, 'UTF-8'); ?>">

                                <div
                                    style="background: #FFF2ED; border: 1px solid var(--line); border-left: 3px solid var(--primary); border-radius: 8px; padding: 0.7rem 0.9rem; margin-bottom: 1rem; font-size: 0.85rem; color: var(--ink);">
                                    <i class="fa-solid fa-circle-user" style="color: var(--primary);"></i>
                                    Contribution au nom de <strong><?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?></strong>
                                    <span style="color: var(--muted);">·
                                        <?php echo htmlspecialchars($user_telephone !== '' ? $user_telephone : ($_SESSION['user_email'] ?? '')); ?></span>
                                </div>
                            <?php else: ?>
                                <!-- Visiteur : formulaire complet à remplir -->
                                <div class="form-group">
                                    <label for="cot_nom"><i class="fa-solid fa-user"></i> Nom & Prénom *</label>
                                    <input type="text" id="cot_nom" name="nom" required placeholder="Ex: Jean Koffi"
                                        value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="cot_email"><i class="fa-regular fa-envelope"></i> Email</label>
                                    <input type="email" id="cot_email" name="email" placeholder="votre.email@exemple.com"
                                        value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="cot_tel"><i class="fa-solid fa-phone"></i> Téléphone (Mobile Money) *</label>
                                    <input type="tel" id="cot_tel" name="telephone" required placeholder="Ex: 07 00 00 00 00">
                                </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="cot_montant"><i class="fa-solid fa-coins"></i> Montant de la cotisation (FCFA) *</label>
                                <input type="number" id="cot_montant" name="montant" required min="500" step="500"
                                    placeholder="Ex: 5000">
                                <div class="cotisation-amounts" style="margin-top: 0.6rem;">
                                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(1000)">1 000
                                        F</button>
                                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(2500)">2 500
                                        F</button>
                                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(5000)">5 000
                                        F</button>
                                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(10000)">10 000
                                        F</button>
                                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(25000)">25 000
                                        F</button>
                                </div>
                            </div>

                            <button type="submit" class="btn-submit" style="margin-top: 0.5rem;">
                                <i class="fa-solid fa-heart"></i> Je cotise maintenant
                            </button>
                            <p style="color: var(--muted); font-size: 0.78rem; margin-top: 0.75rem; text-align: center;">
                                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Paiement sécurisé par
                                Mobile Money
                            </p>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-error" style="margin: 0;">
                            <i class="fa-solid fa-lock"></i>
                            Les contributions sont réservées aux clients. Votre compte
                            <?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?> ne peut pas cotiser.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($onglet === 'voter'): ?>
        <!-- ============================================================
         SECTION VOTER (classement des événements par votes)
         ============================================================ -->
        <section>
            <div style="text-align: center; margin-bottom: 2rem;">
                <span class="page-kicker"><i class="fa-solid fa-vote-yea"></i> Votre avis compte</span>
                <h2 style="font-size: 1.6rem; color: var(--navy);">Votez pour la Réalisation des Événements</h2>
                <p style="color: var(--muted); font-size: 0.92rem; max-width: 560px; margin: 0.5rem auto 0;">
                    Soutenez la réalisation des événements proposés par les promoteurs : les événements les plus votés sont
                    mis en avant. Un vote par personne et par événement.
                </p>
            </div>

            <?php if (!empty($vote_message)): ?>
                <div class="alert <?php echo ($vote_type === 'success') ? 'alert-success' : 'alert-error'; ?>"
                    style="max-width: 640px; margin: 0 auto 1.5rem;">
                    <i class="fa-solid fa-circle-<?php echo ($vote_type === 'success') ? 'check' : 'exclamation'; ?>"></i>
                    <?php echo htmlspecialchars($vote_message); ?>
                </div>
            <?php endif; ?>

            <?php
            // Classement des événements actifs par nombre de votes
            try {
                $stmt_rank = $pdo->query("
                SELECT e.id, e.nom, e.categorie, e.date_evenement, e.lieu, e.image, e.description, e.prix_vote,
                       (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes
                FROM events e
                WHERE e.statut = 'actif'
                ORDER BY nb_votes DESC, e.date_evenement ASC
            ");
                $classement = $stmt_rank->fetchAll();
            } catch (PDOException $e) {
                $classement = [];
            }

            // Récupérer les candidats de chaque événement avec leur décompte de votes respectif
            $candidats_par_event = [];
            try {
                $stmt_cands_all = $pdo->query("
                SELECT c.*,
                       (SELECT COUNT(*) FROM event_votes v WHERE v.candidat_id = c.id) AS nb_votes_cand
                FROM event_candidats c
                ORDER BY nb_votes_cand DESC, c.nom ASC
            ");
                foreach ($stmt_cands_all->fetchAll() as $row_c) {
                    $candidats_par_event[(int) $row_c['event_id']][] = $row_c;
                }
            } catch (PDOException $e) {
                $candidats_par_event = [];
            }
            ?>

            <?php if (!empty($classement)): ?>
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: clamp(1rem, 2.5vw, 1.75rem);">
                    <?php foreach ($classement as $rang => $ev): ?>
                        <?php
                        $deja_vote = !empty($voted_events[$ev['id']]);
                        $ev_candidats = $candidats_par_event[(int) $ev['id']] ?? [];
                        $est_payant = ((float) ($ev['prix_vote'] ?? 0) > 0);

                        // Image : URL complète ou fichier local dans uploads/events/
                        $ev_img = 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=800&q=80';
                        if (!empty($ev['image'])) {
                            if (strpos($ev['image'], 'http') === 0) {
                                $ev_img = htmlspecialchars($ev['image']);
                            } elseif (file_exists('../uploads/events/' . $ev['image'])) {
                                $ev_img = '../uploads/events/' . htmlspecialchars($ev['image']);
                            }
                        }
                        ?>
                        <article class="event-card-item">
                            <div style="position: relative;">
                                <img src="<?php echo $ev_img; ?>" alt="<?php echo htmlspecialchars($ev['nom']); ?>"
                                    class="event-poster">
                                <span class="event-category-badge">
                                    <i class="fa-solid fa-trophy" style="margin-right: 4px; color: #FF4A0D;"></i>
                                    <?php echo $rang === 0 ? '1er au classement' : ($rang + 1) . 'e au classement'; ?>
                                </span>
                                <span class="event-date-chip">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                                    <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                </span>
                            </div>

                            <div style="padding: 1.4rem; display: flex; flex-direction: column; flex: 1;">
                                <div
                                    style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.4rem;">
                                    <h3 style="margin: 0; color: var(--navy); font-size: 1.2rem; line-height: 1.3;">
                                        <?php echo htmlspecialchars($ev['nom']); ?>
                                    </h3>
                                </div>

                                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                                    <?php if ($est_payant): ?>
                                        <span
                                            style="font-size: 0.76rem; background: #FFF2ED; color: #000000; padding: 2px 8px; border-radius: 999px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-coins"></i> Vote payant :
                                            <?php echo number_format((float) $ev['prix_vote'], 0, ',', ' '); ?> F / choix
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="font-size: 0.76rem; background: #FFF2ED; color: #000000; padding: 2px 8px; border-radius: 999px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-gift"></i> Vote gratuit
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($ev_candidats)): ?>
                                        <span
                                            style="font-size: 0.76rem; background: #FFF2ED; color: #FF4A0D; padding: 2px 8px; border-radius: 999px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-users"></i> <?php echo count($ev_candidats); ?> candidat(s) au choix
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div
                                    style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.85rem; display: flex; flex-direction: column; gap: 0.3rem;">
                                    <span><i class="fa-solid fa-tag" style="color: var(--primary);"></i>
                                        <?php echo htmlspecialchars($ev['categorie']); ?></span>
                                    <span><i class="fa-solid fa-location-dot" style="color: #000000;"></i>
                                        <?php echo htmlspecialchars($ev['lieu']); ?></span>
                                </div>

                                <?php
                                $vote_full = trim($ev['description'] ?? '');
                                $vote_long = (mb_strlen($vote_full) > 120);
                                $vote_short = $vote_long ? mb_strimwidth($vote_full, 0, 120, '...') : $vote_full;
                                ?>
                                <div style="margin: 0 0 0.9rem;">
                                    <?php if (!empty($vote_full)): ?>
                                        <p id="desc_vote_<?php echo (int) $ev['id']; ?>"
                                            data-full="<?php echo htmlspecialchars($vote_full, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-short="<?php echo htmlspecialchars($vote_short, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-expanded="0"
                                            style="color: var(--muted); font-size: 0.88rem; line-height: 1.5; margin: 0;">
                                            <?php echo htmlspecialchars($vote_short); ?>
                                        </p>
                                        <?php if ($vote_long): ?>
                                            <button type="button"
                                                onclick="event.stopPropagation(); toggleDesc('vote_<?php echo (int) $ev['id']; ?>', this)"
                                                style="background: none; border: none; padding: 0; margin-top: 0.3rem; color: var(--primary); font-size: 0.8rem; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                Voir plus <i class="fa-solid fa-chevron-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($ev_candidats)): ?>
                                    <!-- Aperçu des candidats en lice avec photos et descriptions -->
                                    <div
                                        style="background: #F5F5F5; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.9rem;">
                                        <small
                                            style="color: var(--navy); font-weight: 700; text-transform: uppercase; font-size: 0.72rem; display: block; margin-bottom: 0.5rem;">
                                            <i class="fa-solid fa-user-group" style="color: var(--primary);"></i> Candidats en lice
                                            (sélection multiple disponible) :
                                        </small>
                                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                            <?php foreach (array_slice($ev_candidats, 0, 3) as $cand): ?>
                                                <?php
                                                $c_thumb = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                                                if (!empty($cand['photo'])) {
                                                    if (strpos($cand['photo'], 'http') === 0) {
                                                        $c_thumb = htmlspecialchars($cand['photo']);
                                                    } elseif (file_exists('../uploads/candidats/' . $cand['photo'])) {
                                                        $c_thumb = '../uploads/candidats/' . htmlspecialchars($cand['photo']);
                                                    }
                                                }
                                                ?>
                                                <div
                                                    style="display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;">
                                                    <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                                                        <img src="<?php echo $c_thumb; ?>"
                                                            alt="<?php echo htmlspecialchars($cand['nom']); ?>"
                                                            style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--line);">
                                                        <span
                                                            style="font-size: 0.82rem; font-weight: 600; color: var(--navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;">
                                                            <?php echo htmlspecialchars($cand['nom']); ?>
                                                        </span>
                                                    </div>
                                                    <span style="font-size: 0.75rem; color: #FF4A0D; font-weight: 700;">
                                                        <i class="fa-solid fa-star"></i> <?php echo (int) $cand['nb_votes_cand']; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($ev_candidats) > 3): ?>
                                                <small
                                                    style="color: var(--primary); font-weight: 700; font-size: 0.75rem; text-align: center; margin-top: 2px;">
                                                    + <?php echo (count($ev_candidats) - 3); ?> autre(s) choix disponible(s)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Compteur de votes pour la réalisation -->
                                <div
                                    style="margin-top: auto; border-top: 1px solid var(--line-light); padding-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                                    <span class="vote-counter" style="color: var(--navy); font-weight: 800; font-size: 1rem;">
                                        <i class="fa-solid fa-star" style="color: #FF4A0D;"></i>
                                        <?php echo (int) $ev['nb_votes']; ?>
                                        vote<?php echo ((int) $ev['nb_votes'] > 1) ? 's' : ''; ?>
                                    </span>
                                    <button type="button" class="vote-btn <?php echo $deja_vote ? 'voted' : ''; ?>"
                                        data-event-id="<?php echo (int) $ev['id']; ?>" <?php if ($peut_agir): ?>onclick="toggleVote(event, this)" <?php else: ?>disabled title="Réservé aux clients" <?php endif; ?>>
                                        <i class="fa-solid <?php echo $peut_agir ? 'fa-thumbs-up' : 'fa-lock'; ?>"></i>
                                        <?php echo $peut_agir ? ($deja_vote ? 'Voté' : ($est_payant ? 'Vote (Choix)' : 'Vote')) : 'Réservé aux clients'; ?>
                                    </button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div
                    style="text-align: center; background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 3.5rem 1rem; max-width: 640px; margin: 0 auto;">
                    <i class="fa-solid fa-vote-yea"
                        style="font-size: 3rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
                    <h3 style="color: var(--navy); margin-bottom: 0.5rem;">Aucun événement à voter pour le moment</h3>
                    <p style="color: var(--muted); margin-bottom: 1.5rem;">Revenez dès qu'un événement est publié !</p>
                    <a href="accueil.php?onglet=evenements" class="btn-submit"
                        style="display: inline-block; width: auto; text-decoration: none; padding: 0.65rem 1.5rem;">
                        Voir les événements
                    </a>
                </div>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <!-- ============================================================
         SECTION ÉVÉNEMENTS (onglet par défaut)
         ============================================================ -->
        <!-- Message de notification éventuel -->
        <?php if (!empty($order_message)): ?>
            <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($order_message); ?>
            </div>
        <?php endif; ?>

        <!-- 1. Hero Banner moderne -->
        <section class="hero-banner">
            <h1>Vivez des Événements Inoubliables</h1>
            <p>Réservez vos places de concert, festival et spectacle en quelques secondes par Mobile Money.</p>

            <form method="GET" action="accueil.php" class="search-box-wrapper">
                <div class="search-input-field">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" placeholder="Artiste, groupe, concert..."
                        value="<?php echo htmlspecialchars($q); ?>">
                </div>

                <div class="search-input-field">
                    <i class="fa-solid fa-location-dot"></i>
                    <input type="text" name="lieu" placeholder="Ville ou salle (Abidjan, Dakar...)"
                        value="<?php echo htmlspecialchars($lieu); ?>">
                </div>

                <div class="search-input-field">
                    <i class="fa-solid fa-layer-group"></i>
                    <select name="categorie">
                        <option value="">Toutes les catégories</option>
                        <option value="Concert" <?php echo ($categorie === 'Concert') ? 'selected' : ''; ?>>Concert / Musique
                        </option>
                        <option value="Festival" <?php echo ($categorie === 'Festival') ? 'selected' : ''; ?>>Festival
                        </option>
                        <option value="Spectacle" <?php echo ($categorie === 'Spectacle') ? 'selected' : ''; ?>>Spectacle /
                            Humour</option>
                        <option value="Conférence" <?php echo ($categorie === 'Conférence') ? 'selected' : ''; ?>>Conférence
                        </option>
                        <option value="Sport" <?php echo ($categorie === 'Sport') ? 'selected' : ''; ?>>Sport</option>
                        <option value="Soirée" <?php echo ($categorie === 'Soirée') ? 'selected' : ''; ?>>Soirée & Gala
                        </option>
                    </select>
                </div>

                <button type="submit" class="btn-submit-hero">
                    <i class="fa-solid fa-magnifying-glass"></i> Rechercher
                </button>
            </form>
        </section>

        <!-- Bulles de filtre par catégorie (harmonisées sous la recherche) -->
        <div class="category-chips" style="margin-top: 1.5rem; margin-bottom: 2rem;">
            <a href="accueil.php" class="category-chip <?php echo empty($categorie) ? 'active' : ''; ?>"><i
                    class="fa-solid fa-border-all"></i> Tous</a>
            <a href="accueil.php?categorie=Concert"
                class="category-chip <?php echo ($categorie === 'Concert') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-music"></i> Concert</a>
            <a href="accueil.php?categorie=Festival"
                class="category-chip <?php echo ($categorie === 'Festival') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-umbrella-beach"></i> Festival</a>
            <a href="accueil.php?categorie=Spectacle"
                class="category-chip <?php echo ($categorie === 'Spectacle') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-masks-theater"></i> Spectacle</a>
            <a href="accueil.php?categorie=Conférence"
                class="category-chip <?php echo ($categorie === 'Conférence') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-microphone"></i> Conférence</a>
            <a href="accueil.php?categorie=Sport"
                class="category-chip <?php echo ($categorie === 'Sport') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-futbol"></i> Sport</a>
            <a href="accueil.php?categorie=Soirée"
                class="category-chip <?php echo ($categorie === 'Soirée') ? 'active' : ''; ?>"><i
                    class="fa-solid fa-champagne-glasses"></i> Soirée</a>
        </div>

        <!-- 2. Catalogue des Événements -->
        <section>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <span class="page-kicker">À l'affiche en ce moment</span>
                    <h2 style="margin: 0; font-size: 1.6rem; color: var(--navy);">
                        <?php echo (!empty($q) || !empty($lieu) || !empty($categorie)) ? 'Résultats de votre recherche' : 'Événements Populaires'; ?>
                    </h2>
                </div>
                <span style="color: var(--muted); font-size: 0.9rem; font-weight: 600;">
                    <strong><?php echo count($events); ?></strong> événement(s) disponible(s)
                </span>
            </div>

            <?php if (count($events) > 0): ?>
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: clamp(1rem, 2.5vw, 1.75rem);">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $ticket_stmt = $pdo->prepare('SELECT * FROM ticket_types WHERE event_id = ? ORDER BY prix ASC');
                        $ticket_stmt->execute([$event['id']]);
                        $event_tickets = $ticket_stmt->fetchAll();

                        $prix_min = !empty($event_tickets) ? min(array_column($event_tickets, 'prix')) : 0;

                        // Places restantes & capacité totale (infos de la commande)
                        $capacite_totale = (int) array_sum(array_column($event_tickets, 'quantite'));
                        $stock_total = 0;
                        foreach ($event_tickets as $t) {
                            $stock_total += max(0, (int) $t['quantite'] - (int) ($t['quantite_vendue'] ?? 0));
                        }

                        // Places par type de billet (tri naturel : A1, A2... A10) avec statut
                        $places_by_type = [];
                        try {
                            $type_ids = array_map('intval', array_column($event_tickets, 'id'));
                            if (!empty($type_ids)) {
                                $in = implode(',', $type_ids);
                                $places_stmt = $pdo->query("SELECT id, ticket_type_id, numero, statut FROM places WHERE ticket_type_id IN ($in) ORDER BY LENGTH(numero) ASC, numero ASC");
                                foreach ($places_stmt->fetchAll() as $p) {
                                    $places_by_type[$p['ticket_type_id']][] = [
                                        'id' => (int) $p['id'],
                                        'numero' => $p['numero'],
                                        'statut' => $p['statut']
                                    ];
                                }
                            }
                        } catch (PDOException $e) {
                            $places_by_type = []; // Table pas encore migrée : option masquée
                        }

                        // Fusionne les places libres dans les options JSON des tarifs
                        $event_tickets_json = [];
                        foreach ($event_tickets as $t) {
                            $t['places'] = $places_by_type[$t['id']] ?? [];
                            $event_tickets_json[] = $t;
                        }

                        $img_url = (!empty($event['image']) && $event['image'] !== 'default.jpg' && file_exists('../uploads/events/' . $event['image']))
                            ? '../uploads/events/' . htmlspecialchars($event['image'])
                            : 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=800&q=80';
                        ?>
                        <article class="event-card-item" <?php echo ($event['statut'] !== 'termine' && $peut_agir) ? ' style="cursor: pointer;" onclick="openEventModal(this.querySelector(\'button[data-ticket-options]\'))"' : ''; ?>>
                            <div style="position: relative;">
                                <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($event['nom']); ?>"
                                    class="event-poster">
                                <span class="event-category-badge"><?php echo htmlspecialchars($event['categorie']); ?></span>
                                <span class="event-date-chip">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                                    <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?>
                                </span>
                                <button type="button"
                                    class="event-like-btn <?php echo !empty($liked_events[$event['id']]) ? 'liked' : ''; ?>"
                                    data-event-id="<?php echo (int) $event['id']; ?>" <?php if ($peut_agir): ?>onclick="toggleLike(event, this)" aria-label="J'aime cet événement" <?php else: ?>disabled
                                        title="Réservé aux clients" style="opacity: 0.55; cursor: not-allowed;" <?php endif; ?>>
                                    <i
                                        class="fa-<?php echo !empty($liked_events[$event['id']]) ? 'solid' : 'regular'; ?> fa-heart"></i>
                                    <span class="like-count"><?php echo $likes_counts[$event['id']] ?? 0; ?></span>
                                </button>
                            </div>

                            <div style="padding: 1.4rem; display: flex; flex-direction: column; flex: 1;">
                                <div style="color: var(--primary); font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem;">
                                    <i class="fa-regular fa-clock"></i> Début à <?php echo substr($event['heure'], 0, 5); ?>
                                </div>

                                <h3 style="margin: 0 0 0.5rem; color: var(--navy); font-size: 1.25rem;">
                                    <?php echo htmlspecialchars($event['nom']); ?>
                                </h3>

                                <?php
                                $full_desc = trim($event['description'] ?? '');
                                $desc_long = (mb_strlen($full_desc) > 120);
                                $short_desc = $desc_long ? mb_strimwidth($full_desc, 0, 120, '...') : $full_desc;
                                ?>
                                <div style="margin: 0 0 1.25rem; flex: 1;">
                                    <?php if (!empty($full_desc)): ?>
                                        <p id="desc_<?php echo (int) $event['id']; ?>"
                                            data-full="<?php echo htmlspecialchars($full_desc, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-short="<?php echo htmlspecialchars($short_desc, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-expanded="0"
                                            style="color: var(--muted); font-size: 0.88rem; line-height: 1.5; margin: 0;">
                                            <?php echo htmlspecialchars($short_desc); ?>
                                        </p>
                                        <?php if ($desc_long): ?>
                                            <button type="button"
                                                onclick="event.stopPropagation(); toggleDesc(<?php echo (int) $event['id']; ?>, this)"
                                                style="background: none; border: none; padding: 0; margin-top: 0.3rem; color: var(--primary); font-size: 0.8rem; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                Voir plus <i class="fa-solid fa-chevron-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Billets restants par type avec barre de progression des ventes -->
                                <?php if (!empty($event_tickets)): ?>
                                    <div style="display: grid; gap: 0.55rem; margin-bottom: 1.25rem;">
                                        <?php foreach ($event_tickets as $tk): ?>
                                            <?php
                                            $total_type = (int) $tk['quantite'];
                                            $vendus = (int) ($tk['quantite_vendue'] ?? 0);
                                            $restant = max(0, $total_type - $vendus);
                                            $epuise = ($restant <= 0);
                                            $pct_vendu = ($total_type > 0) ? min(100, round(($vendus / $total_type) * 100)) : 0;
                                            ?>
                                            <div>
                                                <div
                                                    style="display: flex; justify-content: space-between; align-items: center; font-size: 0.76rem; font-weight: 700; margin-bottom: 3px;">
                                                    <span style="color: var(--navy);">
                                                        <i class="fa-solid fa-ticket"
                                                            style="color: var(--primary); font-size: 0.68rem;"></i>
                                                        <?php echo htmlspecialchars($tk['nom']); ?>
                                                    </span>
                                                    <span style="color: <?php echo $epuise ? '#000000' : 'var(--primary-dark)'; ?>;">
                                                        <?php echo $epuise ? 'Épuisé' : $restant . ' restant(s)'; ?>
                                                    </span>
                                                </div>
                                                <div style="height: 6px; background: #E5E5E5; border-radius: 999px; overflow: hidden;">
                                                    <div
                                                        style="height: 100%; width: <?php echo $pct_vendu; ?>%; background: <?php echo $epuise ? '#000000' : 'linear-gradient(90deg, var(--primary), var(--primary-light))'; ?>; border-radius: 999px; transition: width 0.4s ease;">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div
                                    style="font-size: 0.88rem; color: var(--ink); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fa-solid fa-location-dot" style="color: #000000;"></i>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($event['lieu']); ?></span>
                                </div>

                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--line-light); padding-top: 1rem;">
                                    <div>
                                        <small
                                            style="color: var(--muted); display: block; font-family: 'Space Mono', monospace; font-size: 0.68rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">À
                                            partir de</small>
                                        <strong class="swiss-numeral"
                                            style="color: var(--navy); font-size: 1.25rem;"><?php echo number_format($prix_min, 0, ',', ' '); ?>
                                            <span
                                                style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 700; color: var(--muted);">FCFA</span></strong>
                                    </div>

                                    <?php if ($event['statut'] === 'termine'): ?>
                                        <span
                                            style="background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5; padding: 0.55rem 1rem; border-radius: 6px; font-weight: 800; font-size: 0.82rem; text-transform: uppercase;">
                                            <i class="fa-solid fa-flag-checkered"></i> Terminé
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="btn-submit"
                                            style="width: auto; margin: 0; padding: 0.65rem 1.25rem; font-size: 0.88rem;"
                                            data-event-name="<?php echo htmlspecialchars($event['nom'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-event-date="<?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?>"
                                            data-event-time="<?php echo substr($event['heure'], 0, 5); ?>"
                                            data-event-place="<?php echo htmlspecialchars($event['lieu'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-event-desc="<?php echo htmlspecialchars($event['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-event-capacity="<?php echo $capacite_totale; ?>"
                                            data-event-stock="<?php echo $stock_total; ?>"
                                            data-event-id="<?php echo (int) $event['id']; ?>"
                                            data-ticket-options="<?php echo htmlspecialchars(json_encode($event_tickets_json), ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php if ($peut_agir): ?>onclick="openEventModal(this)">
                                                <i class="fa-solid fa-ticket"></i> Réserver
                                            <?php else: ?>
                                                disabled title="Réservé aux clients">
                                                <i class="fa-solid fa-lock"></i> Réservé aux clients
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div
                    style="text-align: center; background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 4rem 1rem;">
                    <i class="fa-regular fa-calendar-xmark"
                        style="font-size: 3.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
                    <h3 style="color: var(--navy); margin-bottom: 0.5rem;">Aucun événement ne correspond à votre recherche</h3>
                    <p style="color: var(--muted); margin-bottom: 1.5rem;">Modifiez vos filtres ou réessayez avec d'autres
                        mots-clés.</p>
                    <a href="accueil.php" class="btn-submit"
                        style="display: inline-block; width: auto; text-decoration: none; padding: 0.75rem 1.75rem;">
                        Voir tous les événements
                    </a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- =========================================================================
         SECTION PROMOTIONNELLE : DEVENIR PROMOTEUR OFFICIEL EVENTIA
         ========================================================================= -->
    <section class="band" style="margin-top: 3.5rem; margin-bottom: 2rem;">
        <div
            style="background: linear-gradient(135deg, #000000 0%, #000000 100%); border-radius: var(--radius-xl, 12px); padding: clamp(2rem, 5vw, 3.25rem) clamp(1.5rem, 4vw, 3rem); color: #ffffff; position: relative; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: var(--shadow-lg);">
            <div
                style="position: absolute; top: -30px; right: -30px; width: 220px; height: 220px; background: rgba(217, 119, 6, 0.14); border-radius: 50%; pointer-events: none; filter: blur(40px);">
            </div>

            <div style="max-width: 780px; position: relative; z-index: 1;">
                <span class="page-kicker"
                    style="color: var(--accent, #FF4A0D); margin-bottom: 0.5rem; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-bullhorn"></i> Espace Organisateurs & Producteurs
                </span>
                <h2
                    style="color: #ffffff; font-size: clamp(1.6rem, 3.5vw, 2.3rem); font-weight: 900; margin: 0.25rem 0 0.85rem; letter-spacing: -0.03em; line-height: 1.2;">
                    Vous organisez des événements ? Devenez Promoteur Officiel sur Eventia.
                </h2>
                <p style="color: #E5E5E5; font-size: 1rem; line-height: 1.6; margin-bottom: 1.75rem; max-width: 680px;">
                    Que vous soyez une personne physique (organisateur indépendant) ou une personne morale (agence,
                    société ou association), créez et vendez vos billets, configurez vos plans de salle interactifs et
                    encaissez vos recettes en toute transparence par Mobile Money.
                </p>

                <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                    <a href="devenir-promoteur.php" class="btn-submit"
                        style="width: auto; margin: 0; padding: 0.85rem 1.75rem; font-size: 0.95rem; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-paper-plane"></i> Déposer mon Dossier d'Éligibilité
                    </a>
                    <span style="color: #737373; font-size: 0.85rem; font-weight: 600;">
                        <i class="fa-solid fa-shield-check" style="color: #FF4A0D;"></i> Examen & Activation officielle
                        par l'administration sous 24h
                    </span>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- =========================================================================
     MODALE DE COMMANDE MULTI-TICKETS & ACHAT GUEST SANS INSCRIPTION
     ========================================================================= -->
<div id="clientEventModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="clientModalTitle"
    hidden>
    <div class="client-modal-box" style="max-width: 540px;">
        <button type="button" class="client-modal-close" onclick="closeEventModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <span class="page-kicker"><i class="fa-solid fa-bolt"></i> Billetterie Multi-Tarifs</span>
        <h2 id="clientModalTitle" style="margin: 0.2rem 0 0.4rem; font-size: 1.4rem; overflow-wrap: break-word;">
            Sélectionnez vos Billets</h2>
        <p class="client-modal-event-name" id="clientModalEventName"
            style="font-weight: 700; color: var(--primary); font-size: 1.05rem; margin-bottom: 0.4rem; overflow-wrap: break-word;">
        </p>

        <!-- Description complète -->
        <p id="clientModalDesc"
            style="color: var(--muted); font-size: 0.88rem; line-height: 1.55; margin-bottom: 1rem; display: none; overflow-wrap: break-word;">
        </p>

        <div
            style="background: #F5F5F5; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; color: var(--muted); display: grid; gap: 0.35rem;">
            <div><i class="fa-regular fa-calendar" style="color: var(--primary);"></i> Date : <strong
                    id="clientModalDate" style="color: var(--navy);"></strong> à <strong id="clientModalTime"
                    style="color: var(--navy);"></strong></div>
            <div style="overflow-wrap: break-word;"><i class="fa-solid fa-location-dot" style="color: #000000;"></i>
                Salle / Lieu : <strong id="clientModalPlace"
                    style="color: var(--navy); overflow-wrap: break-word;"></strong></div>
            <div><i class="fa-solid fa-chair" style="color: var(--primary);"></i> Places disponibles : <strong
                    id="clientModalCapacity" style="color: var(--navy);"></strong></div>
        </div>

        <form id="clientOrderForm" method="POST" action="commander.php">
            <input type="hidden" name="event_id" id="clientModalEventId">
            <!-- Places choisies sur le plan (rempli dynamiquement en JS) -->
            <div id="seat-hidden-inputs"></div>

            <!-- 1. Présentation de tous les tarifs disponibles avec choix multiple -->
            <div style="font-weight: 700; color: var(--navy); font-size: 0.9rem; margin-bottom: 0.65rem;">
                <i class="fa-solid fa-tags" style="color: var(--primary);"></i> Tarifs & Nombre de places :
            </div>

            <div id="ticket-tiers-container"
                style="max-height: 220px; overflow-y: auto; margin-bottom: 1.25rem; padding-right: 2px;">
                <!-- Rempli dynamiquement en JS avec toutes les catégories de billets -->
            </div>

            <!-- 2. Coordonnées de l'acheteur (automatique si connecté, demandé uniquement si invité) -->
            <?php if ($is_logged_in): ?>
                <input type="hidden" name="client_nom" value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>">
                <input type="hidden" name="client_email"
                    value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>">
                <div
                    style="background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.65rem;">
                    <div
                        style="width: 32px; height: 32px; border-radius: 50%; background: #FFF2ED; color: #FF4A0D; display: grid; place-items: center; flex-shrink: 0;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <span style="color: #000000; font-weight: bold; display: block;">Compte connecté :
                            <?php echo htmlspecialchars($_SESSION['user_nom'] ?? 'Client'); ?></span>
                        <small style="color: var(--muted);"><?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?> ·
                            Vos billets seront directement liés à votre compte</small>
                    </div>
                </div>
            <?php else: ?>
                <!-- Formulaire demandé UNIQUEMENT pour les visiteurs non connectés -->
                <div
                    style="background: #F5F5F5; border: 1px solid var(--line); border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                    <div style="font-weight: 700; color: var(--navy); font-size: 0.85rem; margin-bottom: 0.75rem;">
                        <i class="fa-solid fa-address-card" style="color: var(--primary);"></i> Coordonnées de réception des
                        billets
                    </div>

                    <div class="form-group" style="margin-bottom: 0.75rem;">
                        <label for="client_nom" style="font-size: 0.8rem;">Nom & Prénom du titulaire *</label>
                        <input type="text" id="client_nom" name="client_nom" required placeholder="Ex: Jean Koffi">
                    </div>

                    <div class="form-group" style="margin-bottom: 0.75rem;">
                        <label for="client_email" style="font-size: 0.8rem;">Adresse Email (réception des billets & QR
                            codes) *</label>
                        <input type="email" id="client_email" name="client_email" required
                            placeholder="votre.email@exemple.com">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="client_telephone" style="font-size: 0.8rem;">Numéro de Téléphone (Mobile Money)
                            *</label>
                        <input type="tel" id="client_telephone" name="client_telephone" required
                            placeholder="Ex: 07 00 00 00 00">
                    </div>
                </div>
            <?php endif; ?>

            <!-- Total à payer et décompte des places -->
            <div
                style="background: var(--navy); color: #ffffff; padding: 1rem 1.25rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div>
                    <span
                        style="font-weight: 600; font-size: 0.82rem; color: #737373; display: block; text-transform: uppercase;">Total
                        Commande</span>
                    <small id="clientModalTicketsCount" style="color: #FF4A0D; font-weight: bold;">0 place(s)
                        sélectionnée(s)</small>
                </div>
                <strong id="clientModalTotal" style="color: #FF4A0D; font-size: 1.4rem;">0 FCFA</strong>
            </div>

            <div style="display: flex; gap: 0.75rem;">
                <button type="button" class="btn-submit" onclick="closeEventModal()"
                    style="flex: 1; background: transparent; color: var(--muted); border: 1px solid var(--line);">
                    Annuler
                </button>
                <button type="submit" id="btnSubmitOrder" class="btn-submit" style="flex: 2; margin: 0;">
                    <i class="fa-solid fa-credit-card"></i> Payer par Mobile Money
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODALE DE CONTRIBUTION À UNE CAMPAGNE DE COTISATION
     ============================================================ -->
<div id="cotisationModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="cotisationModalTitle"
    hidden>
    <div class="client-modal-box" style="max-width: 520px;">
        <button type="button" class="client-modal-close" onclick="closeCotisationModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <h2 id="cotisationModalTitle" style="margin: 0.2rem 0 0.4rem; font-size: 1.4rem; overflow-wrap: break-word;">Je
            contribue</h2>
        <p id="cotisationModalCampagneName"
            style="font-weight: 700; color: var(--primary); font-size: 0.95rem; margin: 0 0 0.75rem; overflow-wrap: break-word;">
            Contribution générale
        </p>

        <!-- Motivation complète du créateur de la campagne -->
        <p id="cotisationModalMotivation"
            style="display: none; color: var(--muted); font-size: 0.88rem; line-height: 1.55; background: var(--primary-soft, #FFF2ED); border-left: 3px solid var(--primary); border-radius: 6px; padding: 0.75rem 0.9rem; margin: 0 0 1rem; overflow-wrap: break-word;">
        </p>

        <form method="POST" action="cotisation.php">
            <input type="hidden" name="campagne_id" id="cotCampagneId">

            <?php if ($is_logged_in): ?>
                <!-- Client connecté : aucune saisie d'identité, uniquement le montant -->
                <input type="hidden" name="nom"
                    value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="email"
                    value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="telephone"
                    value="<?php echo htmlspecialchars($user_telephone, ENT_QUOTES, 'UTF-8'); ?>">

                <div
                    style="background: #FFF2ED; border: 1px solid var(--line); border-left: 3px solid var(--primary); border-radius: 8px; padding: 0.7rem 0.9rem; margin-bottom: 1rem; font-size: 0.85rem; color: var(--ink);">
                    <i class="fa-solid fa-circle-user" style="color: var(--primary);"></i>
                    Contribution au nom de <strong><?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?></strong>
                    <span style="color: var(--muted);">·
                        <?php echo htmlspecialchars($user_telephone !== '' ? $user_telephone : ($_SESSION['user_email'] ?? '')); ?></span>
                </div>
            <?php else: ?>
                <!-- Visiteur : formulaire complet à remplir -->
                <div class="form-group">
                    <label for="cot_nom"><i class="fa-solid fa-user"></i> Nom & Prénom *</label>
                    <input type="text" id="cot_nom" name="nom" required placeholder="Ex: Jean Koffi"
                        value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="cot_email"><i class="fa-regular fa-envelope"></i> Email</label>
                    <input type="email" id="cot_email" name="email" placeholder="votre.email@exemple.com"
                        value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="cot_tel"><i class="fa-solid fa-phone"></i> Téléphone (Mobile Money) *</label>
                    <input type="tel" id="cot_tel" name="telephone" required placeholder="Ex: 07 00 00 00 00">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="cot_montant"><i class="fa-solid fa-coins"></i> Montant de la contribution (FCFA) *</label>
                <input type="number" id="cot_montant" name="montant" required min="500" step="500"
                    placeholder="Ex: 5000">
                <div class="cotisation-amounts" style="margin-top: 0.6rem;">
                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(1000)">1 000 F</button>
                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(2500)">2 500 F</button>
                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(5000)">5 000 F</button>
                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(10000)">10 000 F</button>
                    <button type="button" class="cotisation-amount-btn" onclick="setCotisation(25000)">25 000 F</button>
                </div>
            </div>

            <button type="submit" class="btn-submit" style="margin-top: 0.5rem;">
                <i class="fa-solid fa-<?php echo $is_logged_in ? 'credit-card' : 'paper-plane'; ?>"></i>
                <?php echo $is_logged_in ? 'Payer ma contribution' : 'Continuer vers le paiement'; ?>
            </button>
            <p style="color: var(--muted); font-size: 0.78rem; margin-top: 0.75rem; text-align: center;">
                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Paiement sécurisé par Mobile
                Money (Wave, Orange, MTN, Moov)
            </p>
        </form>
    </div>
</div>

<!-- Modale de paiement d'un VOTE PAYANT (événement avec prix_vote > 0) -->
<div id="voteModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="voteModalTitle" hidden>
    <div class="client-modal-box" style="max-width: 580px; max-height: 90vh; overflow-y: auto;">
        <button type="button" class="client-modal-close" onclick="closeVoteModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <h2 id="voteModalTitle" style="margin: 0.2rem 0 0.3rem; font-size: 1.4rem; overflow-wrap: break-word;">
            <i class="fa-solid fa-up-long" style="color: var(--primary);"></i> Vote
        </h2>
        <p id="voteModalEventName"
            style="font-weight: 700; color: var(--primary); font-size: 0.98rem; margin: 0 0 0.6rem; overflow-wrap: break-word;">
        </p>

        <!-- L'opérateur Mobile Money et le numéro sont demandés sur la page de paiement (comme pour les événements) -->
        <div
            style="color: var(--navy); font-size: 0.88rem; margin: 0 0 1rem; background: var(--primary-soft, #FFF2ED); border-left: 3px solid var(--primary); border-radius: 6px; padding: 0.75rem 0.9rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <i class="fa-solid fa-circle-info" style="color: var(--primary); margin-right: 4px;"></i>
                Tarif du vote : <strong id="voteModalPrix" style="color: var(--navy); font-size: 0.98rem;"></strong> /
                choix
            </div>
            <small style="color: var(--muted); font-weight: 600;"><i class="fa-solid fa-check-double"
                    style="color: var(--primary);"></i> Choix multiples autorisés</small>
        </div>

        <form id="votePaymentForm" onsubmit="return submitVotePayment(event);">
            <!-- Section dynamique des Candidats / Choix Multiples -->
            <div id="voteModalCandidatsWrapper" style="display: none; margin-bottom: 1.25rem;">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                    <label style="font-weight: 700; color: var(--navy); font-size: 0.9rem; margin: 0;">
                        <i class="fa-solid fa-users" style="color: var(--primary);"></i> Choisissez vos candidats /
                        options *
                    </label>
                    <span id="voteModalChoicesCount"
                        style="font-size: 0.78rem; font-weight: 700; color: var(--primary); background: var(--primary-soft); padding: 2px 8px; border-radius: 12px;">
                        0 sélectionné(s)
                    </span>
                </div>
                <p style="font-size: 0.8rem; color: var(--muted); margin: 0 0 0.65rem;">
                    Cochez un ou plusieurs choix ci-dessous. Les images et descriptions complètes de vos choix sont
                    présentées ci-dessous :
                </p>
                <div id="voteModalCandidatsList" class="candidats-vote-list"></div>
            </div>

            <!-- Total calculé en direct -->
            <div id="voteModalTotalBox"
                style="background: linear-gradient(135deg, #000000 0%, #000000 100%); color: #ffffff; padding: 0.9rem 1.2rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.2rem;">
                <div>
                    <span
                        style="font-weight: 600; font-size: 0.78rem; color: #737373; display: block; text-transform: uppercase;">Total
                        du vote</span>
                    <small id="voteModalTotalDetail" style="color: #FF4A0D; font-weight: bold;">1 vote
                        sélectionné</small>
                </div>
                <strong id="voteModalTotalAmount" style="color: #FF4A0D; font-size: 1.35rem;">0 FCFA</strong>
            </div>

            <!-- L'opérateur Mobile Money sera choisi sur la page de paiement sécurisée (comme pour l'achat de billets) -->
            <p style="color: var(--muted); font-size: 0.82rem; margin: 0 0 1rem; text-align: center;">
                Après confirmation, vous choisirez votre opérateur Mobile Money (Wave, Orange Money, MTN MoMo ou Moov
                Money) sur la page de paiement sécurisée.
            </p>

            <button type="submit" id="votePaySubmit" class="btn-submit" style="margin-top: 0.5rem;">
                <i class="fa-solid fa-credit-card"></i> Continuer vers le paiement
            </button>
            <p style="color: var(--muted); font-size: 0.78rem; margin-top: 0.75rem; text-align: center;">
                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Paiement sécurisé par Mobile
                Money
            </p>
        </form>
    </div>
</div>

<script>
    const clientEventModal = document.getElementById('clientEventModal');
    const clientOrderForm = document.getElementById('clientOrderForm');
    const tiersContainer = document.getElementById('ticket-tiers-container');

    // Places sélectionnées par tarif : { ticketId: [{id, numero}, ...] }
    let selectedSeats = {};

    function openEventModal(button) {
        document.getElementById('clientModalEventName').textContent = button.dataset.eventName;
        document.getElementById('clientModalDate').textContent = button.dataset.eventDate;
        document.getElementById('clientModalTime').textContent = button.dataset.eventTime;
        document.getElementById('clientModalPlace').textContent = button.dataset.eventPlace;

        // Description complète
        const descEl = document.getElementById('clientModalDesc');
        const desc = button.dataset.eventDesc || '';
        if (desc) {
            descEl.textContent = desc;
            descEl.style.display = 'block';
        } else {
            descEl.style.display = 'none';
        }

        // Places disponibles sur capacité totale
        const capacite = Number(button.dataset.eventCapacity) || 0;
        const stockRestant = Number(button.dataset.eventStock) || 0;
        document.getElementById('clientModalCapacity').textContent = stockRestant + ' place(s) disponible(s) sur ' + capacite;

        // Réinitialisation des sélections de places
        selectedSeats = {};
        const seatHidden = document.getElementById('seat-hidden-inputs');
        if (seatHidden) seatHidden.innerHTML = '';

        const eventId = button.dataset.eventId;
        document.getElementById('clientModalEventId').value = eventId;
        clientOrderForm.action = 'commander.php?id=' + eventId;

        // Génération de la liste de TOUS les tarifs disponibles pour cet événement
        tiersContainer.innerHTML = '';
        const options = JSON.parse(button.dataset.ticketOptions || '[]');

        if (options.length === 0) {
            tiersContainer.innerHTML = '<div style="color: var(--danger); padding: 1rem; text-align: center;">Aucun billet disponible pour cet événement.</div>';
        }

        options.forEach(function (ticket, index) {
            const stock = Math.max(0, Number(ticket.quantite) - Number(ticket.quantite_vendue || 0));
            const isSoldOut = (stock <= 0);
            const fraisPlace = Number(ticket.frais_place) > 0 ? Number(ticket.frais_place) : 1000;
            const placesLibres = Array.isArray(ticket.places) ? ticket.places : [];

            const row = document.createElement('div');
            row.className = 'ticket-tier-row';
            row.innerHTML = `
                <div class="ticket-tier-info">
                    <strong>${ticket.nom}</strong>
                    <div style="display: flex; align-items: center; gap: 0.4rem 0.65rem; flex-wrap: wrap; margin-top: 3px;">
                        <span style="color: var(--primary); font-weight: 800; font-size: 0.95rem;">${Number(ticket.prix).toLocaleString('fr-FR')} FCFA</span>
                        <small style="color: ${isSoldOut ? 'var(--danger)' : '#FF4A0D'}; font-weight: 600;">
                            ${isSoldOut ? '• Épuisé' : `• ${stock} restante(s)`}
                        </small>
                    </div>
                </div>

                <div class="ticket-qty-control">
                    <button type="button" class="ticket-qty-btn" onclick="changeQty(${ticket.id}, -1)" ${isSoldOut ? 'disabled' : ''}>-</button>
                    <input type="number" name="tickets[${ticket.id}]" id="qty_input_${ticket.id}" class="ticket-qty-input"
                           min="0" max="${Math.min(10, stock)}" value="${index === 0 && !isSoldOut ? 1 : 0}"
                           data-prix="${ticket.prix}" data-frais-place="${fraisPlace}" data-stock="${stock}"
                           oninput="updateMultiTicketTotal()" ${isSoldOut ? 'disabled' : ''}>
                    <button type="button" class="ticket-qty-btn" onclick="changeQty(${ticket.id}, 1)" ${isSoldOut ? 'disabled' : ''}>+</button>
                </div>

                ${!isSoldOut ? `
                <div class="seat-choice-block" id="seat_block_${ticket.id}">
                    <label class="seat-choice-label">
                        <input type="checkbox" id="seat_toggle_${ticket.id}" onchange="toggleSeatMap(${ticket.id})">
                        <span>
                            <i class="fa-solid fa-masks-theater" style="color: var(--primary);"></i>
                            <strong>Oui, je veux choisir ma place sur la Vue de Scène</strong>
                            <span style="display: inline-block; background: #FFF2ED; color: #FF4A0D; border: 1px solid #E5E5E5; padding: 1px 7px; border-radius: 6px; font-size: 0.74rem; font-weight: 800; margin-left: 4px;">
                                +${Number(fraisPlace).toLocaleString('fr-FR')} FCFA / place
                            </span><br>
                            <small style="color: var(--muted); font-size: 0.74rem;">
                                Option payante : choisissez votre angle et votre siège face au podium (+${Number(fraisPlace).toLocaleString('fr-FR')} FCFA). Sans cette option, la place est attribuée automatiquement sans supplément.
                            </small>
                        </span>
                    </label>

                    <!-- VUE DE SCÈNE INTERACTIVE (Remplace les places numérotées) -->
                    <div class="scene-view-card" id="scene_view_${ticket.id}" hidden>
                        <div class="scene-stage-banner">
                            <div class="scene-stage-podium">
                                <i class="fa-solid fa-masks-theater"></i> ★ SCÈNE PRINCIPALE / PODIUM ★
                            </div>
                            <div style="font-size: 0.68rem; color: #737373; margin-top: 3px;">
                                <i class="fa-solid fa-arrow-up"></i> Orientation face à la scène
                            </div>
                        </div>

                        <button type="button" class="btn-scene-interactive" onclick="openClient3DSeating(${ticket.id})">
                            <i class="fa-solid fa-cube" style="color: #FF4A0D;"></i>
                            <span>Ouvrir la Vue de Scène (Rendu Immersif)</span>
                            <span class="scene-tag-badge">Vue Scène</span>
                        </button>

                        <div class="scene-selected-summary" id="scene_summary_${ticket.id}">
                            <div style="color: #737373; font-size: 0.75rem; text-align: center; font-style: italic;">
                                Cliquez sur le bouton ci-dessus pour sélectionner vos places directement face à la scène.
                            </div>
                        </div>
                    </div>
                </div>` : ''}
            `;
            tiersContainer.appendChild(row);
        });

        updateMultiTicketTotal();
        clientEventModal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function changeQty(ticketId, delta) {
        const input = document.getElementById('qty_input_' + ticketId);
        if (!input || input.disabled) return;

        let val = Number(input.value) || 0;
        const max = Number(input.max) || 10;
        const min = Number(input.min) || 0;

        val = Math.min(max, Math.max(min, val + delta));
        input.value = val;
        updateMultiTicketTotal();
    }

    /* ===== Vue de Scène & Choix de place ===== */
    function toggleSeatMap(ticketId) {
        const sceneView = document.getElementById('scene_view_' + ticketId);
        const toggleCb = document.getElementById('seat_toggle_' + ticketId);
        const qtyInput = document.getElementById('qty_input_' + ticketId);
        if (!sceneView || !toggleCb) return;

        const opening = toggleCb.checked;
        sceneView.hidden = !opening;

        if (opening) {
            // Mode "place au choix sur la vue de scène"
            if (qtyInput) {
                qtyInput.dataset.seatMode = '1';
                qtyInput.disabled = true;
                if (Number(qtyInput.value) <= 0) qtyInput.value = 1;
            }
            // Ouvre directement la vue de scène immersive
            openClient3DSeating(ticketId);
        } else {
            // Fermeture de la vue de scène : on efface la sélection pour ce tarif
            clearSeatSelection(ticketId);
            if (qtyInput) {
                qtyInput.dataset.seatMode = '0';
                qtyInput.disabled = false;
                qtyInput.value = Math.min(1, Number(qtyInput.max) || 1);
            }
        }
        syncTierSeats(ticketId);
        updateMultiTicketTotal();
    }

    function clearSeatSelection(ticketId) {
        selectedSeats[ticketId] = [];
    }

    function syncTierSeats(ticketId) {
        const seats = selectedSeats[ticketId] || [];
        const qtyInput = document.getElementById('qty_input_' + ticketId);
        const summary = document.getElementById('scene_summary_' + ticketId);
        const container = document.getElementById('seat-hidden-inputs');

        // Champs cachés envoyés au serveur (places[ticketId][] = id)
        if (container) {
            container.querySelectorAll('input[data-tier="' + ticketId + '"]').forEach(function (i) { i.remove(); });
            seats.forEach(function (s) {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'places[' + ticketId + '][]';
                inp.value = s.id;
                inp.setAttribute('data-tier', ticketId);
                container.appendChild(inp);
            });
        }

        if (qtyInput && qtyInput.dataset.seatMode === '1') {
            qtyInput.value = seats.length;
        }
        if (summary) {
            if (seats.length > 0) {
                summary.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-weight: 800; color: #FF4A0D; font-size: 0.8rem;">
                            <i class="fa-solid fa-masks-theater"></i> ${seats.length} place(s) Vue Scène :
                        </span>
                        <button type="button" onclick="openClient3DSeating(${ticketId})" style="background: none; border: none; color: #FF4A0D; font-size: 0.74rem; text-decoration: underline; cursor: pointer; padding: 0;">
                            Modifier sur la scène
                        </button>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                        ${seats.map(s => `<span style="background: #000000; border: 1px solid #000000; color: #F5F5F5; font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; font-weight: 700;"><i class="fa-solid fa-chair" style="color: #FF4A0D; font-size: 0.65rem;"></i> Place ${s.numero}</span>`).join('')}
                    </div>
                `;
                summary.style.display = 'block';
            } else {
                summary.innerHTML = `
                    <div style="color: #737373; font-size: 0.75rem; text-align: center; font-style: italic;">
                        Cliquez ci-dessus pour sélectionner vos places directement sur la vue de scène.
                    </div>
                `;
                summary.style.display = 'block';
            }
        }
    }

    function updateMultiTicketTotal() {
        const inputs = document.querySelectorAll('.ticket-qty-input:not(:disabled)');
        let totalCount = 0;
        let totalPrice = 0;

        inputs.forEach(function (input) {
            const qty = Number(input.value) || 0;
            const prix = Number(input.dataset.prix) || 0;

            totalCount += qty;
            totalPrice += (qty * prix);
        });

        // Tarifs en mode "place au choix" : prix du billet + supplément par place choisie
        Object.keys(selectedSeats).forEach(function (ticketId) {
            const qtyInput = document.getElementById('qty_input_' + ticketId);
            if (!qtyInput || qtyInput.dataset.seatMode !== '1') return;
            const seats = selectedSeats[ticketId] || [];
            const prix = Number(qtyInput.dataset.prix) || 0;
            const frais = Number(qtyInput.dataset.fraisPlace) > 0 ? Number(qtyInput.dataset.fraisPlace) : 1000;
            totalCount += seats.length;
            totalPrice += seats.length * (prix + frais);
        });

        document.getElementById('clientModalTicketsCount').textContent = totalCount + ' place(s) sélectionnée(s)';
        document.getElementById('clientModalTotal').textContent = totalPrice.toLocaleString('fr-FR') + ' FCFA';

        const submitBtn = document.getElementById('btnSubmitOrder');
        if (totalCount <= 0) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        } else {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
    }

    /* ===== Vote pour un événement ===== */
    async function toggleVote(e, btn) {
        e.stopPropagation();
        const eventId = btn.dataset.eventId;
        try {
            const res = await fetch('vote-event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'event_id=' + encodeURIComponent(eventId)
            });
            const data = await res.json();
            if (data.error) {
                alert(data.error);
                return;
            }
            // VOTE PAYANT : le promoteur a fixé un prix → ouverture de la modale de paiement
            if (data.needs_payment) {
                openVoteModal(eventId, data.event_nom, data.prix, data.candidats || [], data.type_vote, data.vote_question);
                return;
            }
            // Phase 2 validée : redirection vers la page de paiement Mobile Money
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            // VOTE GRATUIT : bascule voter / voté
            btn.classList.toggle('voted', data.voted);
            btn.innerHTML = data.voted
                ? '<i class="fa-solid fa-thumbs-up"></i> Voté'
                : '<i class="fa-solid fa-thumbs-up"></i> Vote';
            // Met à jour le compteur de votes affiché dans la carte (sans recharger la page)
            const card = btn.closest('.event-card-item, .vote-item');
            if (card) {
                const counter = card.querySelector('.vote-counter');
                if (counter) {
                    counter.innerHTML = '<i class="fa-solid fa-star" style="color:#FF4A0D;"></i> '
                        + data.votes + (data.votes > 1 ? ' votes' : ' vote');
                }
            }
        } catch (err) {
            alert("Impossible d'enregistrer le vote. Exécutez config/migration-votes-cotisations.sql pour créer la table event_votes.");
        }
    }

    /* ===== Modale de paiement d'un vote payant (avec choix multiples) ===== */
    const voteModal = document.getElementById('voteModal');
    let voteModalEventId = null;
    let currentVotePrix = 0;
    let currentVoteCandidats = [];

    function openVoteModal(eventId, eventNom, prix, candidats, typeVote, voteQuestion) {
        voteModalEventId = eventId;
        currentVotePrix = Number(prix) || 0;
        currentVoteCandidats = Array.isArray(candidats) ? candidats : [];

        const titleEl = document.getElementById('voteModalEventName');
        if (titleEl) {
            if (typeVote === 'realisation_evenement' && voteQuestion) {
                titleEl.innerHTML = `<span style="display:block; color:var(--primary); font-size:0.85rem; font-weight:800; text-transform:uppercase;">Vote de Réalisation : ${eventNom}</span>` +
                    `<strong style="color:var(--navy); font-size:1.05rem; display:block; margin-top:2px;">« ${voteQuestion} »</strong>`;
            } else {
                titleEl.textContent = eventNom || '';
            }
        }
        document.getElementById('voteModalPrix').textContent = currentVotePrix.toLocaleString('fr-FR') + ' FCFA';

        const candWrapper = document.getElementById('voteModalCandidatsWrapper');
        const candList = document.getElementById('voteModalCandidatsList');

        if (currentVoteCandidats.length > 0) {
            candWrapper.style.display = 'block';
            candList.innerHTML = '';

            currentVoteCandidats.forEach((c, idx) => {
                const card = document.createElement('div');
                card.className = 'candidat-choice-card selected'; // Coche par défaut pour guider
                card.dataset.id = c.id;

                const photoSrc = c.photo || 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                const descText = c.description ? c.description : 'Option de vote pour cet événement.';

                card.innerHTML = `
                    <input type="checkbox" class="vote-candidat-cb candidat-checkbox" value="${c.id}" checked>
                    <img src="${photoSrc}" alt="${c.nom}" class="candidat-choice-photo">
                    <div class="candidat-choice-info">
                        <div class="candidat-choice-nom">
                            <span>${c.nom}</span>
                            <span class="candidat-badge-selected"><i class="fa-solid fa-check"></i> Sélectionné</span>
                        </div>
                        <p class="candidat-choice-desc">${descText}</p>
                    </div>
                `;

                // Clic sur l'ensemble de la carte
                card.addEventListener('click', function (e) {
                    if (e.target.tagName !== 'INPUT') {
                        const cb = card.querySelector('.vote-candidat-cb');
                        cb.checked = !cb.checked;
                    }
                    card.classList.toggle('selected', card.querySelector('.vote-candidat-cb').checked);
                    updateVoteModalTotal();
                });

                const cb = card.querySelector('.vote-candidat-cb');
                cb.addEventListener('change', function () {
                    card.classList.toggle('selected', cb.checked);
                    updateVoteModalTotal();
                });

                candList.appendChild(card);
            });
        } else {
            candWrapper.style.display = 'none';
            candList.innerHTML = '';
        }

        updateVoteModalTotal();
        voteModal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function updateVoteModalTotal() {
        const selectedCbs = document.querySelectorAll('.vote-candidat-cb:checked');
        const count = selectedCbs.length;

        if (currentVoteCandidats.length > 0) {
            document.getElementById('voteModalChoicesCount').textContent = count + ' sélectionné(s)';
            const total = count * currentVotePrix;
            document.getElementById('voteModalTotalDetail').textContent = count + ' choix × ' + currentVotePrix.toLocaleString('fr-FR') + ' F';
            document.getElementById('voteModalTotalAmount').textContent = total.toLocaleString('fr-FR') + ' FCFA';

            const submitBtn = document.getElementById('votePaySubmit');
            if (count === 0) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
                submitBtn.innerHTML = '<i class="fa-solid fa-hand-pointer"></i> Cochez au moins 1 choix';
            } else {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Continuer vers le paiement (' + count + ' vote' + (count > 1 ? 's' : '') + ')';
            }
        } else {
            document.getElementById('voteModalTotalDetail').textContent = '1 vote pour l\'événement';
            document.getElementById('voteModalTotalAmount').textContent = currentVotePrix.toLocaleString('fr-FR') + ' FCFA';
            const submitBtn = document.getElementById('votePaySubmit');
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Continuer vers le paiement';
        }
    }

    function closeVoteModal() {
        voteModal.hidden = true;
        document.body.classList.remove('modal-open');
        voteModalEventId = null;
        currentVoteCandidats = [];
    }

    async function submitVotePayment(e) {
        e.preventDefault();
        if (!voteModalEventId) return false;

        const submitBtn = document.getElementById('votePaySubmit');
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.6';

        try {
            const selectedCbs = Array.from(document.querySelectorAll('.vote-candidat-cb:checked')).map(cb => cb.value);

            if (currentVoteCandidats.length > 0 && selectedCbs.length === 0) {
                alert('Veuillez sélectionner au moins un choix.');
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                return false;
            }

            let bodyParams = 'event_id=' + encodeURIComponent(voteModalEventId) + '&phase=2';
            if (selectedCbs.length > 0) {
                bodyParams += '&candidat_ids=' + encodeURIComponent(selectedCbs.join(','));
            }

            const res = await fetch('vote-event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: bodyParams
            });
            const data = await res.json();
            if (data.redirect) {
                window.location.href = data.redirect;
                return false;
            }
            alert(data.error || 'Une erreur est survenue, veuillez réessayer.');
        } catch (err) {
            alert('Erreur réseau lors de la création du paiement.');
        }
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        return false;
    }

    // Fermeture de la modale de vote par clic sur le fond
    voteModal.addEventListener('click', function (ev) {
        if (ev.target === voteModal) closeVoteModal();
    });

    /* ===== Montants rapides de cotisation ===== */
    function setCotisation(montant) {
        const input = document.getElementById('cot_montant');
        if (input) input.value = montant;
    }

    /* ===== Modale de contribution à une campagne ===== */
    const cotisationModal = document.getElementById('cotisationModal');

    function openCotisationModal(button) {
        document.getElementById('cotCampagneId').value = button.dataset.campagneId || '';
        document.getElementById('cotisationModalCampagneName').textContent = button.dataset.campagneTitre || 'Contribution générale';

        // Motivation complète du créateur de la campagne (texte intégral)
        const motivationEl = document.getElementById('cotisationModalMotivation');
        const motivation = button.dataset.campagneMotivation || '';
        if (motivation) {
            motivationEl.textContent = motivation;
            motivationEl.style.display = 'block';
        } else {
            motivationEl.textContent = '';
            motivationEl.style.display = 'none';
        }

        cotisationModal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function closeCotisationModal() {
        cotisationModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    cotisationModal.addEventListener('click', function (e) {
        if (e.target === cotisationModal) closeCotisationModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !cotisationModal.hidden) closeCotisationModal();
    });

    /* ===== Description rétractable (Voir plus / Voir moins) ===== */
    function toggleDesc(eventId, btn) {
        const p = document.getElementById('desc_' + eventId);
        if (!p) return;

        const full = p.dataset.full || '';
        const short = p.dataset.short || '';
        const expanded = (p.dataset.expanded === '1');

        if (expanded) {
            p.textContent = short;
            p.dataset.expanded = '0';
            btn.innerHTML = 'Voir plus <i class="fa-solid fa-chevron-down"></i>';
        } else {
            p.textContent = full;
            p.dataset.expanded = '1';
            btn.innerHTML = 'Voir moins <i class="fa-solid fa-chevron-up"></i>';
        }
    }

    /* ===== Like d'un événement (n'ouvre pas la modale) ===== */
    async function toggleLike(e, btn) {
        e.stopPropagation();
        const eventId = btn.dataset.eventId;
        try {
            const res = await fetch('like-event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'event_id=' + encodeURIComponent(eventId)
            });
            const data = await res.json();
            if (data.error) {
                alert(data.error);
                return;
            }
            btn.classList.toggle('liked', data.liked);
            btn.querySelector('i').className = data.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
            btn.querySelector('.like-count').textContent = data.likes;
        } catch (err) {
            alert("Impossible d'enregistrer le like. Exécutez config/migration-likes.sql pour créer la table event_likes.");
        }
    }

    function closeEventModal() {
        clientEventModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    clientEventModal.addEventListener('click', function (e) {
        if (e.target === clientEventModal) closeEventModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !clientEventModal.hidden) closeEventModal();
    });

    /* ==============================================================================
       RENDU 3D CLIENT & SÉLECTION DE PLACES SELON LE TARIF
       ============================================================================== */
    let client3DEngine = null;
    let currentEvent3DData = null;

    async function openClient3DSeating(targetTicketId = null) {
        const eventId = document.getElementById('clientModalEventId').value;
        const eventName = document.getElementById('clientModalEventName').textContent;
        const eventPlace = document.getElementById('clientModalPlace').textContent;

        if (!eventId) return;

        document.getElementById('client3DEventTitle').textContent = eventName;
        document.getElementById('client3DVenueSubtitle').textContent = 'Lieu : ' + eventPlace + ' • Orientation interactive & choix de places';
        
        const modal3D = document.getElementById('client3DSeatingModal');
        modal3D.hidden = false;
        document.body.classList.add('modal-open');

        const canvas = document.getElementById('client3DCanvas');
        if (!client3DEngine) {
            client3DEngine = new EventiaVenue3D(canvas, {
                readOnly: false,
                maxSeats: 10,
                onSeatSelect: (seat, all) => {
                    updateClient3DSidebar(all);
                },
                onSeatDeselect: (seat, all) => {
                    updateClient3DSidebar(all);
                },
                onHoverSeat: (seat) => {
                    if (seat) {
                        const sightBox = document.getElementById('client3DSightlineBox');
                        const sightDesc = document.getElementById('client3DSightlineDesc');
                        if (sightBox && sightDesc) {
                            sightBox.style.display = 'block';
                            const p = Number(seat.prix) || 0;
                            const f = Number(seat.frais_place) > 0 ? Number(seat.frais_place) : 1000;
                            sightDesc.innerHTML = `<strong>Place ${seat.code}</strong> (${seat.zone_name})<br>
                            Distance estimée : <strong>${Math.max(6, Math.round(seat.z / 10))} m</strong><br>
                            <span style="color: #FF4A0D; font-weight: 700;">Billet : ${p.toLocaleString('fr-FR')} F + Choix place : ${f.toLocaleString('fr-FR')} F = ${(p + f).toLocaleString('fr-FR')} FCFA</span>`;
                        }
                    }
                }
            });
        }

        // Chargement des données 3D pour cet événement
        const data = await client3DEngine.loadFromEndpoint('../ajax/salle_3d_data.php?event_id=' + eventId);
            if (data) {
                currentEvent3DData = data;

                // Remplissage du Plan Architectural
                const planContainer = document.getElementById('clientPlanContent');
                if (planContainer) {
                    if (data.salle && data.salle.plan_image) {
                        planContainer.innerHTML = `
                            <div style="max-width: 800px; margin: 0 auto;">
                                <div style="color: #FF4A0D; font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem; text-transform: uppercase;">
                                    <i class="fa-solid fa-map-location-dot"></i> Plan Architectural / Schéma de la salle
                                </div>
                                <img src="../uploads/salles/${data.salle.plan_image}" alt="Plan de la salle" style="max-width: 100%; max-height: 60vh; border-radius: 12px; border: 1px solid #000000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); object-fit: contain;">
                            </div>
                        `;
                    } else {
                        planContainer.innerHTML = `
                            <div style="color: #737373; padding: 3rem 1rem;">
                                <i class="fa-solid fa-map" style="font-size: 3rem; color: #000000; margin-bottom: 1rem; display: block;"></i>
                                <h4 style="color: #F5F5F5; margin-bottom: 0.5rem;">Plan Architectural schématique</h4>
                                <p style="font-size: 0.85rem; max-width: 450px; margin: 0 auto 1rem;">La disposition des rangées et des zones est directement générée et navigable dans l'onglet <strong>Rendu 3D</strong>.</p>
                                <button type="button" class="btn-submit" onclick="switchClient3DTab('3d')" style="display: inline-block; width: auto; padding: 0.6rem 1.2rem; background: #FF4A0D; font-size: 0.85rem;">
                                    <i class="fa-solid fa-cube"></i> Ouvrir le Rendu 3D
                                </button>
                            </div>
                        `;
                    }
                }

                // Remplissage de la Galerie Photos Réelles
                const photosContainer = document.getElementById('clientPhotosGrid');
                if (photosContainer) {
                    photosContainer.innerHTML = '';
                    let photosList = [];
                    if (data.salle && data.salle.image_principale) {
                        photosList.push({ src: data.salle.image_principale, title: 'Façade / Vue Principale' });
                    }
                    if (data.salle && Array.isArray(data.salle.galerie_photos)) {
                        data.salle.galerie_photos.forEach((ph, idx) => {
                            photosList.push({ src: ph, title: `Vue de Salle #${idx + 1}` });
                        });
                    }

                    if (photosList.length > 0) {
                        photosList.forEach(item => {
                            const card = document.createElement('div');
                            card.style.cssText = 'background: #000000; border: 1px solid #000000; border-radius: 12px; overflow: hidden; box-shadow: 0 6px 16px rgba(0,0,0,0.3);';
                            card.innerHTML = `
                                <img src="../uploads/salles/${item.src}" alt="${item.title}" style="width: 100%; height: 160px; object-fit: cover; display: block;">
                                <div style="padding: 0.6rem 0.8rem; font-size: 0.78rem; font-weight: 700; color: #F5F5F5;">
                                    <i class="fa-solid fa-camera" style="color: #FF4A0D; margin-right: 4px;"></i> ${item.title}
                                </div>
                            `;
                            photosContainer.appendChild(card);
                        });
                    } else {
                        photosContainer.innerHTML = `
                            <div style="grid-column: 1 / -1; text-align: center; color: #737373; padding: 3rem 1rem;">
                                <i class="fa-solid fa-images" style="font-size: 3rem; color: #000000; margin-bottom: 1rem; display: block;"></i>
                                <h4 style="color: #F5F5F5; margin-bottom: 0.5rem;">Visualisation interactive disponible en 3D</h4>
                                <p style="font-size: 0.85rem; max-width: 450px; margin: 0 auto;">Les angles de vue réels sont simulés en temps réel avec le moteur 3D d'Eventia.</p>
                            </div>
                        `;
                    }
                }

                // Barre de boutons de filtrage par Tarif
                const tariffBar = document.getElementById('client3DTariffBar');
                tariffBar.innerHTML = '<button type="button" class="studio-filter-btn active" onclick="filterClient3D(this, \'all\')">Tous les Tarifs</button>';

                if (data.ticket_types && data.ticket_types.length > 0) {
                    let targetBtn = null;
                    data.ticket_types.forEach(tt => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'studio-filter-btn';
                        btn.dataset.tierId = tt.id;
                        btn.innerHTML = `<span style="font-weight: 800;">${tt.nom}</span> · <span style="color: #FF4A0D;">${Number(tt.prix).toLocaleString('fr-FR')} F</span>`;
                        btn.onclick = () => filterClient3D(btn, tt.id);
                        tariffBar.appendChild(btn);
                        if (targetTicketId && Number(tt.id) === Number(targetTicketId)) {
                            targetBtn = btn;
                        }
                    });
                    if (targetBtn) {
                        filterClient3D(targetBtn, targetTicketId);
                    }
                } else if (data.zones) {
                    data.zones.forEach(z => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'studio-filter-btn';
                        btn.innerHTML = `<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ${z.couleur || '#FF4A0D'}; margin-right: 4px;"></span> ${z.nom_zone} (${Number(z.tarif_indicatif || 10000).toLocaleString('fr-FR')} F)`;
                        btn.onclick = () => filterClient3D(btn, z.id);
                        tariffBar.appendChild(btn);
                    });
                }

                updateClient3DSidebar([]);
                switchClient3DTab('3d');
                setTimeout(() => {
                    if (client3DEngine) {
                        client3DEngine.resize();
                        client3DEngine.render();
                    }
                }, 80);
            }
        }

        function switchClient3DTab(tab) {
            const p3D = document.getElementById('panelClient3D');
            const pPlan = document.getElementById('panelClientPlan');
            const pPhotos = document.getElementById('panelClientPhotos');
            const camCtrls = document.getElementById('client3DCameraControls');

            document.querySelectorAll('#client3DSeatingModal .studio-tab-btn').forEach(b => b.classList.remove('active'));

            if (tab === '3d') {
                if (p3D) p3D.style.display = 'flex';
                if (pPlan) pPlan.style.display = 'none';
                if (pPhotos) pPhotos.style.display = 'none';
                if (camCtrls) camCtrls.style.display = 'flex';
                const btn = document.getElementById('tabBtnClient3D');
                if (btn) btn.classList.add('active');
                if (client3DEngine) {
                    client3DEngine.resize();
                    client3DEngine.render();
                }
            } else if (tab === 'plan') {
                if (p3D) p3D.style.display = 'none';
                if (pPlan) pPlan.style.display = 'block';
                if (pPhotos) pPhotos.style.display = 'none';
                if (camCtrls) camCtrls.style.display = 'none';
                const btn = document.getElementById('tabBtnClientPlan');
                if (btn) btn.classList.add('active');
            } else if (tab === 'photos') {
                if (p3D) p3D.style.display = 'none';
                if (pPlan) pPlan.style.display = 'none';
                if (pPhotos) pPhotos.style.display = 'block';
                if (camCtrls) camCtrls.style.display = 'none';
                const btn = document.getElementById('tabBtnClientPhotos');
                if (btn) btn.classList.add('active');
            }
        }

        function filterClient3D(btn, filterId) {
            document.querySelectorAll('#client3DTariffBar .studio-filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            if (client3DEngine) {
                client3DEngine.filterByTariff(filterId);
            }
        }

        function setClient3DView(view) {
            if (client3DEngine) {
                client3DEngine.setViewPreset(view);
            }
        }

        function updateClient3DSidebar(seats) {
            const listEl = document.getElementById('client3DSelectedList');
            const badgeEl = document.getElementById('client3DSeatsBadge');
            const subCountEl = document.getElementById('client3DSubCount');
            const totalEl = document.getElementById('client3DTotalAmount');

            if (!listEl) return;

            badgeEl.textContent = seats.length + (seats.length > 1 ? ' places' : ' place');
            subCountEl.textContent = seats.length + (seats.length > 1 ? ' places choisies' : ' place choisie');

            let total = 0;
            seats.forEach(s => {
                const p = Number(s.prix) || 0;
                const f = Number(s.frais_place) > 0 ? Number(s.frais_place) : 1000;
                total += (p + f);
            });
            totalEl.textContent = total.toLocaleString('fr-FR') + ' FCFA';

            if (seats.length === 0) {
                listEl.innerHTML = '<div style="color: #737373; font-size: 0.8rem; font-style: italic; text-align: center; padding: 1.5rem 0;">Cliquez sur les sièges disponibles dans la vue 3D pour les ajouter à votre sélection.</div>';
                return;
            }

            listEl.innerHTML = '';
            seats.forEach(s => {
                const p = Number(s.prix) || 0;
                const f = Number(s.frais_place) > 0 ? Number(s.frais_place) : 1000;
                const item = document.createElement('div');
                item.style.cssText = 'background: #000000; border: 1px solid #000000; border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; justify-content: space-between; align-items: center;';
                item.innerHTML = `
                    <div>
                        <strong style="color: #F5F5F5; font-size: 0.82rem;"><i class="fa-solid fa-chair" style="color: #FF4A0D;"></i> Place ${s.code}</strong>
                        <div style="font-size: 0.72rem; color: #737373;">${s.zone_name} • Rang ${s.row}</div>
                        <div style="font-size: 0.68rem; color: #FF4A0D; font-weight: 700;">Choix de place : +${f.toLocaleString('fr-FR')} F</div>
                    </div>
                    <div style="text-align: right;">
                        <div style="color: #FF4A0D; font-weight: 800; font-size: 0.85rem;">${(p + f).toLocaleString('fr-FR')} F</div>
                        <button type="button" onclick="removeClient3DSeat(${s.id})" style="background: none; border: none; color: #000000; font-size: 0.72rem; cursor: pointer; padding: 0;">Retirer</button>
                    </div>
                `;
                listEl.appendChild(item);
            });
        }

        function removeClient3DSeat(seatId) {
            if (client3DEngine) {
                const seat = client3DEngine.seats.find(s => s.id === seatId);
                if (seat) {
                    client3DEngine.toggleSeatSelection(seat);
                }
            }
        }

        function applyClient3DSelection() {
            if (!client3DEngine) return;
            const chosenSeats = Array.from(client3DEngine.selectedSeats.values());

            if (chosenSeats.length === 0) {
                alert('Veuillez sélectionner au moins un siège 3D sur le plan.');
                return;
            }

            // Grouper les places par type de billet (ticket_type_id)
            const seatsByTier = {};
            chosenSeats.forEach(s => {
                const tId = s.ticket_type_id;
                seatsByTier[tId] = seatsByTier[tId] || [];
                seatsByTier[tId].push(s);
            });

            // Appliquer dans le formulaire de commande principal
            Object.keys(seatsByTier).forEach(tId => {
                const seats = seatsByTier[tId];
                const qtyInput = document.getElementById('qty_input_' + tId);
                const toggleCb = document.getElementById('seat_toggle_' + tId);
                const sceneView = document.getElementById('scene_view_' + tId);

                if (toggleCb) toggleCb.checked = true;
                if (sceneView) sceneView.hidden = false;

                if (qtyInput) {
                    qtyInput.dataset.seatMode = '1';
                    qtyInput.disabled = true;
                    qtyInput.value = seats.length;
                }

                // Enregistrer dans selectedSeats
                selectedSeats[tId] = seats.map(s => ({ id: s.id, numero: s.code }));
                syncTierSeats(tId);
            });

            updateMultiTicketTotal();
            closeClient3DSeating();
        }

        function closeClient3DSeating() {
            const modal3D = document.getElementById('client3DSeatingModal');
            if (modal3D) modal3D.hidden = true;
            document.body.classList.remove('modal-open');
        }

        function toggleMobileSeatList() {
            const sidebar = document.getElementById('client3DSidebar');
            const text = document.getElementById('s3dToggleText');
            const icon = document.getElementById('s3dToggleIcon');
            if (!sidebar) return;
            sidebar.classList.toggle('is-collapsed');
            const isCollapsed = sidebar.classList.contains('is-collapsed');
            if (text) text.textContent = isCollapsed ? 'Voir détails' : 'Masquer';
            if (icon) icon.className = isCollapsed ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
            if (client3DEngine) {
                setTimeout(() => {
                    client3DEngine.resize();
                    client3DEngine.render();
                }, 50);
            }
        }

        // Réactivité immédiate au redimensionnement d'écran & rotation mobile
        window.addEventListener('resize', () => {
            const modal3D = document.getElementById('client3DSeatingModal');
            if (client3DEngine && modal3D && !modal3D.hidden) {
                client3DEngine.resize();
                client3DEngine.render();
            }
        });
        window.addEventListener('orientationchange', () => {
            const modal3D = document.getElementById('client3DSeatingModal');
            if (client3DEngine && modal3D && !modal3D.hidden) {
                setTimeout(() => {
                    client3DEngine.resize();
                    client3DEngine.render();
                }, 150);
            }
        });
    </script>

    <!-- ============================================================
         MODALE DE CHOIX DE PLACE IMMERSIF EN 3D (RESPONSIVE)
         ============================================================ -->
    <div id="client3DSeatingModal" class="client-modal s3d-modal-overlay" role="dialog" aria-modal="true" hidden>
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
                        <button type="button" id="tabBtnClient3D" class="studio-tab-btn active" onclick="switchClient3DTab('3d')">
                            <i class="fa-solid fa-cube"></i> <span>Rendu 3D</span>
                        </button>
                        <button type="button" id="tabBtnClientPlan" class="studio-tab-btn" onclick="switchClient3DTab('plan')">
                            <i class="fa-solid fa-map"></i> <span>Plan</span>
                        </button>
                        <button type="button" id="tabBtnClientPhotos" class="studio-tab-btn" onclick="switchClient3DTab('photos')">
                            <i class="fa-solid fa-images"></i> <span>Photos</span>
                        </button>
                    </div>

                    <!-- Caméras & Commandes -->
                    <div class="s3d-cameras-group">
                        <div id="client3DCameraControls" class="s3d-cameras">
                            <button type="button" class="s3d-cam-btn" onclick="setClient3DView('isometric')" title="Vue 3D Isométrique">
                                <i class="fa-solid fa-cubes"></i> <span>3D</span>
                            </button>
                            <button type="button" class="s3d-cam-btn" onclick="setClient3DView('top')" title="Vue du Haut">
                                <i class="fa-solid fa-eye"></i> <span>Haut</span>
                            </button>
                            <button type="button" class="s3d-cam-btn" onclick="setClient3DView('stage')" title="Vue Scène">
                                <i class="fa-solid fa-masks-theater"></i> <span>Scène</span>
                            </button>
                            <button type="button" class="s3d-cam-btn s3d-cam-reset" onclick="setClient3DView('reset')" title="Recentrer la vue">
                                <i class="fa-solid fa-arrows-rotate"></i>
                            </button>
                        </div>
                        <button type="button" class="s3d-close-btn" onclick="closeClient3DSeating()" title="Fermer la vue de scène">
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
                        <button type="button" class="s3d-mobile-toggle-btn" onclick="toggleMobileSeatList()" id="s3dMobileToggleBtn">
                            <span id="s3dToggleText">Voir détails</span> <i class="fa-solid fa-chevron-up" id="s3dToggleIcon"></i>
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

    <script src="../js/venue-3d-engine.js"></script>

    <?php include 'footer.php'; ?>