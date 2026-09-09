<?php
// ==============================================================================
// PAGE D'ACCUEIL CLIENT & CATALOGUE (client/accueil.php)
// Recherche et réservation multi-tickets (choix de plusieurs types et quantités)
// ==============================================================================

require_once '../config/database.php';
session_start();

// Empêcher le cache agressif du navigateur pour refléter immédiatement les modifications
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$page_title = "Tikéli - Billetterie en ligne & Événements";
$body_class = "client-page client-home-page";
include 'header.php';

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Message flash de confirmation après déconnexion (affiché une seule fois, disparaît à l'actualisation)
$logout_msg = false;
if (!empty($_SESSION['logout_success'])) {
    $logout_msg = true;
    unset($_SESSION['logout_success']);
} elseif (isset($_GET['logout'])) {
    $logout_msg = true;
}

// Rôle du visiteur : les promoteurs et administrateurs ne peuvent pas effectuer
// d'actions client (achat de billets, cotisation, vote, like) — consultation seule
$user_role = $_SESSION['user_role'] ?? '';
$peut_agir = !$is_logged_in || $user_role === 'client';
?>

<?php if (!empty($logout_msg)): ?>
    <div id="logoutAlertBox" style="max-width: 1200px; margin: 1rem auto 0; padding: 0 1rem;">
        <div
            style="background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 10px; padding: 0.85rem 1.25rem; color: #000000; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; font-weight: 600;">
            <i class="fa-solid fa-circle-check" style="color: var(--tikeli-orange, #FF4A0D);"></i>
            <span>Vous avez été déconnecté avec succès. À bientôt sur Tikéli !</span>
        </div>
    </div>
    <script>
        // Nettoie l'URL pour empêcher le réaffichage lors d'une actualisation (F5)
        if (window.history && window.history.replaceState) {
            const cleanUrl = window.location.pathname + window.location.search.replace(/[?&]logout=[^&]*/, '').replace(/^&/, '?');
            window.history.replaceState(null, '', cleanUrl || window.location.pathname);
        }
    </script>
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
$sql = "SELECT e.*, 
               COALESCE(p.nom_commercial, u.nom) AS promoteur_nom, 
               COALESCE(p.telephone_contact, u.telephone) AS promoteur_tel 
        FROM events e 
        LEFT JOIN users u ON e.user_id = u.id 
        LEFT JOIN promoters p ON e.user_id = p.user_id 
        WHERE e.statut = 'actif'";
$params = [];

if (!empty($q)) {
    $sql .= " AND (e.nom LIKE ? OR e.description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if (!empty($lieu)) {
    $sql .= " AND e.lieu LIKE ?";
    $params[] = "%$lieu%";
}

if (!empty($categorie) && $categorie !== 'Toutes') {
    $sql .= " AND e.categorie = ?";
    $params[] = $categorie;
}

$sql .= " ORDER BY e.date_evenement ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();
$all_event_ids = array_map('intval', array_column($events, 'id'));

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

// Redirection automatique vers la nouvelle page dédiée vote.php si vote_id est présent
if (isset($_GET['vote_id']) && !empty($_GET['vote_id'])) {
    $v_id = (int)$_GET['vote_id'];
    $c_id = !empty($_GET['candidat_id']) ? '&candidat_id=' . (int)$_GET['candidat_id'] : '';
    header("Location: vote.php?id={$v_id}{$c_id}");
    exit();
}

// Redirection automatique vers la page dédiée evenement.php si event_id est présent (hors modal)
if (isset($_GET['event_id']) && !empty($_GET['event_id']) && !isset($_GET['modal'])) {
    $e_id = (int)$_GET['event_id'];
    header("Location: evenement.php?id={$e_id}");
    exit();
}

// Onglet actif (événements / cotisations / voter) avec support du deep-linking
if (isset($_GET['campagne_id']) && !isset($_GET['onglet'])) {
    $onglet = 'cotisations';
} else {
    $onglet = trim($_GET['onglet'] ?? 'evenements');
}
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
               COALESCE(p.nom_commercial, u.nom) AS promoteur_nom, 
               COALESCE(p.telephone_contact, u.telephone) AS promoteur_tel,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN promoters p ON c.user_id = p.user_id
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

// Pré-chargement universel des votes et candidats pour les modales de détails et deep-links
$classement = [];
try {
    $classement = $pdo->query("
        SELECT e.id, e.nom, e.categorie, e.date_evenement, e.heure, e.lieu, e.image, e.description, e.prix_vote, e.vote_question,
               COALESCE(p.nom_commercial, u.nom) AS promoteur_nom, 
               COALESCE(p.telephone_contact, u.telephone) AS promoteur_tel,
               COALESCE(v.nb_votes, 0) AS nb_votes
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN (
            SELECT event_id, COUNT(*) AS nb_votes
            FROM event_votes
            GROUP BY event_id
        ) v ON v.event_id = e.id
        WHERE e.statut = 'actif'
        ORDER BY nb_votes DESC, e.date_evenement ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $classement = [];
}

$candidats_par_event = [];
try {
    $stmt_cands_all = $pdo->query("
        SELECT c.id, c.event_id, c.nom, c.description, c.photo,
               COALESCE(v.nb_votes_cand, 0) AS nb_votes_cand
        FROM event_candidats c
        LEFT JOIN (
            SELECT candidat_id, COUNT(*) AS nb_votes_cand
            FROM event_votes
            WHERE candidat_id IS NOT NULL
            GROUP BY candidat_id
        ) v ON v.candidat_id = c.id
        ORDER BY nb_votes_cand DESC, c.nom ASC
    ");
    foreach ($stmt_cands_all->fetchAll() as $row_c) {
        $candidats_par_event[(int) $row_c['event_id']][] = $row_c;
    }
} catch (PDOException $e) {
    $candidats_par_event = [];
}

// Pré-chargement des billets par événement
$tickets_by_event = [];
if (!empty($all_event_ids)) {
    try {
        $in_event_ids = implode(',', $all_event_ids);
        $all_tickets_stmt = $pdo->query("SELECT id, event_id, nom, description, prix, frais_place, quantite, quantite_vendue, places_choisies FROM ticket_types WHERE event_id IN ($in_event_ids) ORDER BY prix ASC");
        foreach ($all_tickets_stmt->fetchAll() as $t) {
            $tickets_by_event[(int) $t['event_id']][] = $t;
        }
    } catch (PDOException $e) {
        $tickets_by_event = [];
    }
}

// Totaux pour les badges des onglets
$nb_events_total = count($events);
$nb_campagnes_total = count($campagnes);
$nb_votes_total = count($classement);
?>

<link rel="stylesheet" href="../Css/accueil-client.css?v=<?php echo time(); ?>">

<style>
/* Failsafe carrousel horizontal des candidats et modales */
.vote-cands-section { margin: 1.25rem 0 1rem; }
.vote-cands-header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.vote-cands-header-title { font-size: 0.95rem; font-weight: 800; color: var(--navy, #0F172A); margin: 0; display: flex; align-items: center; gap: 6px; }
.vote-cands-nav { display: flex; align-items: center; gap: 6px; }
.vote-carousel-btn { width: 32px; height: 32px; border-radius: 50%; background: #ffffff; border: 1px solid var(--line, #E2E8F0); color: var(--navy, #0F172A); display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.vote-carousel-btn:hover { background: var(--navy, #0F172A); color: #ffffff; border-color: var(--navy, #0F172A); transform: scale(1.06); }
.vote-cands-carousel-wrap { position: relative; width: 100%; }
.vote-cands-horizontal-track { display: flex; gap: 14px; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scroll-behavior: smooth; padding: 4px 4px 14px; scrollbar-width: thin; scrollbar-color: #CBD5E1 transparent; }
.vote-cands-horizontal-track::-webkit-scrollbar { height: 5px; }
.vote-cands-horizontal-track::-webkit-scrollbar-track { background: #F1F5F9; border-radius: 999px; }
.vote-cands-horizontal-track::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 999px; }
.vote-cand-card { flex: 0 0 215px; width: 215px; scroll-snap-align: start; background: #ffffff; border: 1px solid var(--line, #E2E8F0); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; transition: all 0.25s ease; position: relative; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.vote-cand-card:hover { transform: translateY(-3px); box-shadow: 0 10px 24px -4px rgba(15, 23, 42, 0.12); border-color: #CBD5E1; }
.vote-cand-photo-wrap { position: relative; width: 100%; height: 190px; background: #0f172a; overflow: hidden; cursor: pointer; }
.vote-cand-photo { width: 100%; height: 100%; object-fit: cover; transition: transform 0.35s ease; display: block; }
.vote-cand-card:hover .vote-cand-photo { transform: scale(1.05); }
.vote-cand-badge { position: absolute; top: 8px; left: 8px; font-family: 'Space Mono', monospace; font-size: 0.72rem; font-weight: 700; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(4px); color: #ffffff; padding: 2px 7px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.2); z-index: 2; }
.vote-cand-badge.top-1 { background: #FF4A0D; border-color: transparent; }
.vote-cand-body { padding: 10px 12px 12px; display: flex; flex-direction: column; flex: 1; }
.vote-cand-nom { font-size: 0.92rem; font-weight: 800; color: var(--navy, #0F172A); margin: 0 0 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.25; }
.vote-cand-stats { display: flex; justify-content: space-between; align-items: baseline; font-size: 0.74rem; font-family: 'Space Mono', monospace; font-weight: 700; margin-bottom: 4px; }
.vote-cand-gauge-bg { height: 5px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-bottom: 9px; }
.vote-cand-gauge-fill { height: 100%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.4s ease; }
.vote-cand-actions { display: grid; grid-template-columns: 1fr 1.2fr; gap: 6px; margin-top: auto; }
.btn-cand-detail { background: #F8FAFC; color: var(--navy, #0F172A); border: 1px solid #CBD5E1; font-weight: 700; font-size: 0.74rem; padding: 6px 4px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease; text-decoration: none; }
.btn-cand-detail:hover { background: #E2E8F0; color: var(--navy, #0F172A); }
.btn-cand-vote { background: #FF4A0D; color: #ffffff; border: none; font-weight: 700; font-size: 0.74rem; padding: 6px 4px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease; text-decoration: none; }
.btn-cand-vote:hover { background: #E03E05; transform: translateY(-1px); }
.btn-cand-vote.voted { background: #0F172A; color: #ffffff; }
.candidat-modal-box { max-width: 580px !important; padding: 1.5rem !important; }
.candidat-detail-hero { display: grid; grid-template-columns: 180px 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
.candidat-detail-photo-wrap { width: 100%; height: 220px; border-radius: 12px; overflow: hidden; position: relative; background: #0F172A; border: 1px solid var(--line, #E2E8F0); }
.candidat-detail-photo { width: 100%; height: 100%; object-fit: cover; display: block; }
.candidat-detail-meta { display: flex; flex-direction: column; justify-content: space-between; }
.candidat-detail-nom { font-size: 1.45rem; font-weight: 800; color: var(--navy, #0F172A); margin: 0.25rem 0 0.5rem; line-height: 1.2; }
.candidat-detail-event { font-size: 0.84rem; color: var(--muted, #64748B); display: flex; align-items: center; gap: 5px; margin-bottom: 0.75rem; }
.candidat-detail-kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 0.5rem; }
.candidat-detail-kpi { background: #F8FAFC; border: 1px solid var(--line, #E2E8F0); border-radius: 8px; padding: 0.55rem 0.75rem; }
.candidat-detail-kpi small { display: block; font-size: 0.68rem; text-transform: uppercase; color: var(--muted, #64748B); font-weight: 700; letter-spacing: 0.04em; }
.candidat-detail-kpi strong { font-family: 'Space Mono', monospace; font-size: 1.15rem; font-weight: 900; color: var(--navy, #0F172A); }
.candidat-detail-desc-box { background: #F8FAFC; border: 1px solid var(--line, #E2E8F0); border-radius: 10px; padding: 1rem 1.15rem; margin-bottom: 1.25rem; }
.candidat-detail-desc-box h4 { margin: 0 0 0.4rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted, #64748B); font-weight: 800; }
.candidat-detail-desc-text { font-size: 0.9rem; line-height: 1.6; color: #334155; margin: 0; white-space: pre-line; }
@media (max-width: 600px) {
    .vote-cand-card { flex: 0 0 185px; width: 185px; }
    .vote-cand-photo-wrap { height: 165px; }
    .candidat-detail-hero { grid-template-columns: 1fr; gap: 1rem; }
    .candidat-detail-photo-wrap { height: 240px; }
}

.poster-floating-share-btn,
.btn-card-share {
    display: none !important;
}

/* Poignée tactile mobile pour Bottom-Sheets (Menu mobile) */
.mobile-sheet-handle {
    display: none;
}
@media (max-width: 768px) {
    .mobile-sheet-handle {
        display: block;
        width: 42px;
        height: 4px;
        background: #CBD5E1;
        border-radius: 999px;
        margin: -4px auto 14px;
    }
}

/* Modale Vote Événement : Focus Prioritaire sur les Candidats */
.vote-modal-box {
    max-width: 860px !important;
    width: min(96vw, 860px) !important;
    max-height: 92vh;
    padding: 1.25rem clamp(1rem, 3vw, 1.5rem) !important;
    overflow-y: auto;
    overflow-x: hidden;
}
.vote-modal-compact-hero {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 0.85rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid var(--line, #E2E8F0);
}
.vote-modal-hero-thumb {
    width: 68px;
    height: 68px;
    border-radius: 10px;
    object-fit: cover;
    flex-shrink: 0;
    border: 1px solid var(--line, #E2E8F0);
    background: #0F172A;
}
.vote-modal-title {
    margin: 0.15rem 0 0.35rem;
    font-size: clamp(1.15rem, 2.4vw, 1.4rem);
    color: var(--navy, #0F172A);
    font-weight: 800;
    line-height: 1.25;
}
.vote-modal-meta-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.vote-meta-pill {
    background: #F8FAFC;
    border: 1px solid var(--line, #E2E8F0);
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 0.76rem;
    color: var(--navy, #0F172A);
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
@media (max-width: 550px) {
    .vote-modal-compact-hero {
        align-items: flex-start;
    }
    .vote-modal-hero-thumb {
        width: 52px;
        height: 52px;
    }
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

                        // Image : résolution propre et fallback solidaire
                        $default_cotisation_img = 'https://images.unsplash.com/photo-1532629345422-7515f3d16bb7?auto=format&fit=crop&w=800&q=80';
                        $campagne_img = resolve_media_url($campagne['image'] ?? '', $default_cotisation_img, ['cotisations', 'events']);

                        // Description tronquée (comme les cartes d'événements)
                        $camp_desc = trim($campagne['description'] ?? '');
                        $camp_long = (mb_strlen($camp_desc) > 110);
                        $camp_short = $camp_long ? mb_strimwidth($camp_desc, 0, 110, '...') : $camp_desc;
                        ?>
                        <article class="event-card-item" id="campagne-card-<?php echo (int) $campagne['id']; ?>" <?php echo ($peut_contribuer && $peut_agir) ? ' style="cursor: pointer;" onclick="openCotisationModal(this.querySelector(\'button[data-campagne-id]\'))"' : ''; ?>>
                            <div style="position: relative; overflow: hidden; background: #0f172a; border-radius: 12px 12px 0 0;">
                                <img src="<?php echo htmlspecialchars($campagne_img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($campagne['titre']); ?>"
                                    class="event-poster" width="400" height="240" loading="lazy" decoding="async"
                                    onerror="this.onerror=null; this.src='<?php echo $default_cotisation_img; ?>';">
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
                                <h3 style="margin: 0 0 0.5rem; color: var(--navy); font-size: 1.2rem; cursor: pointer;"
                                    onclick="event.stopPropagation(); openCotisationDetails(<?php echo (int) $campagne['id']; ?>)">
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
                                            style="width: 100%; margin: 0; padding: 0.7rem 1rem; font-size: 0.9rem;"
                                            data-campagne-id="<?php echo (int) $campagne['id']; ?>"
                                            data-campagne-titre="<?php echo htmlspecialchars($campagne['titre'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-campagne-motivation="<?php echo htmlspecialchars($campagne['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="openCotisationModal(this)">
                                            <i class="fa-solid fa-paper-plane"></i> Contribuer
                                        </button>
                                    <?php elseif (!$peut_agir): ?>
                                        <span
                                            style="flex: 1; background: #fff7ed; color: #FF4A0D; border: 1px solid #FF4A0D; padding: 0.55rem 0.75rem; border-radius: 6px; font-weight: 800; font-size: 0.82rem; text-transform: uppercase; display: block; text-align: center;">
                                            <i class="fa-solid fa-lock"></i> Réservé
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="flex: 1; background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5; padding: 0.55rem 0.75rem; border-radius: 6px; font-weight: 800; font-size: 0.82rem; text-transform: uppercase; display: block; text-align: center;">
                                            <i class="fa-solid fa-flag-checkered"></i>
                                            <?php echo $est_terminee ? 'Terminée' : 'Atteint'; ?>
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

            <?php if (!empty($classement)): ?>
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: clamp(1rem, 2.5vw, 1.75rem);">
                    <?php foreach ($classement as $rang => $ev): ?>
                        <?php
                        $deja_vote = !empty($voted_events[$ev['id']]);
                        $ev_candidats = $candidats_par_event[(int) $ev['id']] ?? [];
                        $est_payant = ((float) ($ev['prix_vote'] ?? 0) > 0);

                        // Image : résolution propre et fallback concours / gala
                        $default_vote_img = 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=800&q=80';
                        $ev_img = resolve_media_url($ev['image'] ?? '', $default_vote_img, ['events']);
                        ?>
                        <article class="event-card-item" id="vote-card-<?php echo (int) $ev['id']; ?>"
                            style="cursor: pointer;"
                            onclick="openVoteDetailsModal(<?php echo (int) $ev['id']; ?>)">
                            <div style="display: block; position: relative; overflow: hidden; background: #0f172a; border-radius: 12px 12px 0 0;">
                                <img src="<?php echo htmlspecialchars($ev_img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($ev['nom']); ?>"
                                    class="event-poster" width="400" height="240" loading="lazy" decoding="async"
                                    onerror="this.onerror=null; this.src='<?php echo $default_vote_img; ?>';">
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
                                        <span style="color: inherit; text-decoration: none;">
                                            <?php echo htmlspecialchars($ev['nom']); ?>
                                        </span>
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
                                    <!-- Aperçu des candidats en lice avec photos et baromètre -->
                                    <div
                                        style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.9rem; transition: border-color 0.2s;"
                                        onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--line)'">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <small
                                                style="color: var(--navy); font-weight: 700; text-transform: uppercase; font-size: 0.72rem; display: flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-users" style="color: var(--primary);"></i> Candidats en lice :
                                            </small>
                                            <span style="font-size: 0.74rem; color: var(--primary); font-weight: 700; display: inline-flex; align-items: center; gap: 3px;">
                                                Défilement <i class="fa-solid fa-arrow-right"></i>
                                            </span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 0.65rem;">
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
                                                $cand_v = (int) $cand['nb_votes_cand'];
                                                $event_v = (int) ($ev['nb_votes'] ?? 0);
                                                $sum_c = array_sum(array_column($ev_candidats, 'nb_votes_cand'));
                                                $base_v = max(1, $event_v, $sum_c);
                                                $cand_p = ($base_v > 0) ? min(100, round(($cand_v / $base_v) * 100, 1)) : 0;
                                                ?>
                                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                                                        <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                                                            <img src="<?php echo $c_thumb; ?>"
                                                                alt="<?php echo htmlspecialchars($cand['nom']); ?>"
                                                                style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--line);"
                                                                width="28" height="28" loading="lazy" decoding="async">
                                                            <span
                                                                style="font-size: 0.82rem; font-weight: 600; color: var(--navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;">
                                                                <?php echo htmlspecialchars($cand['nom']); ?>
                                                            </span>
                                                        </div>
                                                        <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.74rem; font-family: 'Space Mono', monospace; font-weight: 700; white-space: nowrap;">
                                                            <span style="color: var(--navy);"><?php echo $cand_v; ?> vote<?php echo ($cand_v > 1) ? 's' : ''; ?></span>
                                                            <span style="color: #FF4A0D; background: #FFF2ED; padding: 1px 6px; border-radius: 4px; font-weight: 800;"><?php echo $cand_p; ?>%</span>
                                                        </div>
                                                    </div>
                                                    <!-- Barre de progression individuelle du candidat -->
                                                    <div style="height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-left: 36px;">
                                                        <div style="height: 100%; width: <?php echo $cand_p; ?>%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($ev_candidats) > 3): ?>
                                                <small
                                                    style="color: var(--primary); font-weight: 700; font-size: 0.75rem; text-align: center; margin-top: 4px; display: block;">
                                                    + <?php echo (count($ev_candidats) - 3); ?> autre(s) choix (cliquez pour voir)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Actions : Compteur, Partager, Détails & Vote -->
                                <div
                                    style="margin-top: auto; border-top: 1px solid var(--line-light); padding-top: 0.85rem; display: flex; flex-direction: column; gap: 0.65rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="vote-counter" style="color: var(--navy); font-weight: 800; font-size: 0.95rem;">
                                            <i class="fa-solid fa-star" style="color: #FF4A0D;"></i>
                                            <?php echo (int) $ev['nb_votes']; ?>
                                            vote<?php echo ((int) $ev['nb_votes'] > 1) ? 's' : ''; ?>
                                        </span>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #FF4A0D; background: #FFF2ED; padding: 2px 8px; border-radius: 999px;">
                                            <i class="fa-solid fa-trophy"></i> #<?php echo ($rang + 1); ?>
                                        </span>
                                    </div>

                                    <div>
                                        <button type="button" class="vote-btn <?php echo $deja_vote ? 'voted' : ''; ?>"
                                            style="margin: 0; width: 100%; justify-content: center; padding: 0.65rem 0.75rem; font-size: 0.88rem;"
                                            onclick="event.stopPropagation(); openVoteDetailsModal(<?php echo (int) $ev['id']; ?>)">
                                            <i class="fa-solid fa-check-to-slot"></i> Voir & Voter
                                        </button>
                                    </div>
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
                        $event_tickets = $tickets_by_event[(int) $event['id']] ?? [];
                        $prix_min = !empty($event_tickets) ? min(array_column($event_tickets, 'prix')) : 0;

                        // Places restantes & capacité totale (infos de la commande)
                        $capacite_totale = (int) array_sum(array_column($event_tickets, 'quantite'));
                        $stock_total = 0;
                        foreach ($event_tickets as $t) {
                            $stock_total += max(0, (int) $t['quantite'] - (int) ($t['quantite_vendue'] ?? 0));
                        }

                        // Tarifs légers pour les options du modal (places 3D chargées via AJAX)
                        $event_tickets_json = $event_tickets;
                        // Image : résolution propre et fallback événementiel pro
                        $default_event_img = 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=800&q=80';
                        $img_url = resolve_media_url($event['image'] ?? '', $default_event_img, ['events']);
                        ?>
                        <article class="event-card-item" id="event-card-<?php echo (int) $event['id']; ?>"
                            style="cursor: pointer;"
                            onclick="handleEventCardClick(<?php echo (int) $event['id']; ?>, this, event)">
                            <div style="display: block; position: relative; text-decoration: none; overflow: hidden; background: #0f172a; border-radius: 12px 12px 0 0;">
                                <img src="<?php echo htmlspecialchars($img_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($event['nom']); ?>"
                                    class="event-poster" width="400" height="240" loading="lazy" decoding="async"
                                    onerror="this.onerror=null; this.src='<?php echo $default_event_img; ?>';">
                                <span class="event-category-badge"><?php echo htmlspecialchars($event['categorie']); ?></span>
                                <span class="event-date-chip">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                                    <?php echo date('d/m/Y', strtotime($event['date_evenement'])); ?>
                                </span>
                            </div>

                            <div style="padding: 1.4rem; display: flex; flex-direction: column; flex: 1;">
                                <div style="color: var(--primary); font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem;">
                                    <i class="fa-regular fa-clock"></i> Début à <?php echo substr($event['heure'], 0, 5); ?>
                                </div>

                                <h3 style="margin: 0 0 0.5rem; color: var(--navy); font-size: 1.25rem;">
                                    <a href="evenement.php?id=<?php echo (int) $event['id']; ?>" style="color: inherit; text-decoration: none;"
                                       onclick="if (window.innerWidth <= 768) { event.preventDefault(); event.stopPropagation(); handleEventCardClick(<?php echo (int) $event['id']; ?>, this.closest('.event-card-item'), event); }">
                                        <?php echo htmlspecialchars($event['nom']); ?>
                                    </a>
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

                                <?php 
                                $event_cands = $candidats_par_event[(int) $event['id']] ?? [];
                                if (!empty($event_cands)): 
                                    $cand_total_v = array_sum(array_column($event_cands, 'nb_votes_cand'));
                                    $ev_denom = max(1, $cand_total_v, (int)($event['nb_votes'] ?? 0));
                                ?>
                                    <!-- Progression des candidats en lice -->
                                    <a href="vote.php?id=<?php echo (int) $event['id']; ?>#candidats" style="display: block; text-decoration: none; color: inherit; margin-bottom: 1.1rem;"
                                        onclick="event.preventDefault(); event.stopPropagation(); openVoteDetailsModal(<?php echo (int) $event['id']; ?>);">
                                        <div style="background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem; transition: border-color 0.2s;"
                                             onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--line)'">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                                <small style="color: var(--navy); font-weight: 700; text-transform: uppercase; font-size: 0.72rem; display: flex; align-items: center; gap: 5px;">
                                                    <i class="fa-solid fa-users" style="color: var(--primary);"></i> Progression des Candidats
                                                </small>
                                                <small style="color: var(--primary); font-weight: 700; font-size: 0.72rem; font-family: 'Space Mono', monospace;">
                                                    <?php echo $cand_total_v; ?> vote<?php echo ($cand_total_v > 1) ? 's' : ''; ?>
                                                </small>
                                            </div>
                                            <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                                                <?php foreach (array_slice($event_cands, 0, 3) as $c_idx => $c_item): 
                                                    $c_v = (int) $c_item['nb_votes_cand'];
                                                    $c_pct = round(($c_v / $ev_denom) * 100, 1);
                                                    $c_img = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                                                    if (!empty($c_item['photo'])) {
                                                        if (strpos($c_item['photo'], 'http') === 0) {
                                                            $c_img = htmlspecialchars($c_item['photo']);
                                                        } elseif (file_exists('../uploads/candidats/' . $c_item['photo'])) {
                                                            $c_img = '../uploads/candidats/' . htmlspecialchars($c_item['photo']);
                                                        }
                                                    }
                                                ?>
                                                    <div style="display: flex; flex-direction: column; gap: 3px;">
                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                                                            <div style="display: flex; align-items: center; gap: 0.45rem; min-width: 0;">
                                                                <img src="<?php echo $c_img; ?>" alt="<?php echo htmlspecialchars($c_item['nom']); ?>"
                                                                    style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover; border: 1px solid var(--line);"
                                                                    width="24" height="24" loading="lazy">
                                                                <span style="font-size: 0.8rem; font-weight: 600; color: var(--navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;">
                                                                    <?php echo htmlspecialchars($c_item['nom']); ?>
                                                                </span>
                                                            </div>
                                                            <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-family: 'Space Mono', monospace; font-weight: 700; white-space: nowrap;">
                                                                <span style="color: var(--navy);"><?php echo $c_v; ?>v</span>
                                                                <span style="color: #FF4A0D; background: #FFF2ED; padding: 1px 5px; border-radius: 4px; font-weight: 800;"><?php echo $c_pct; ?>%</span>
                                                            </div>
                                                        </div>
                                                        <!-- Barre de progression -->
                                                        <div style="height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-left: 28px;">
                                                            <div style="height: 100%; width: <?php echo $c_pct; ?>%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.4s ease;"></div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($event_cands) > 3): ?>
                                                    <small style="color: var(--primary); font-weight: 700; font-size: 0.72rem; text-align: center; margin-top: 2px; display: block;">
                                                        + <?php echo (count($event_cands) - 3); ?> autre(s) candidat(s) — Voir le défilé & Voter <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem;"></i>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
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

                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
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
                                                data-has-salle-3d="1"
                                                data-ticket-options="<?php echo htmlspecialchars(json_encode($event_tickets_json), ENT_QUOTES, 'UTF-8'); ?>"
                                                <?php if ($peut_agir): ?>onclick="event.stopPropagation(); handleReservationAction(this)">
                                                    <i class="fa-solid fa-ticket"></i> Réserver
                                                <?php else: ?>
                                                    disabled title="Réservé aux clients">
                                                    <i class="fa-solid fa-lock"></i> Réservé aux clients
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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
        <div class="mobile-sheet-handle"></div>
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
        <div style="margin: 0 0 0.6rem;">
            <p id="voteModalEventName"
                style="font-weight: 700; color: var(--primary); font-size: 0.98rem; margin: 0; overflow-wrap: break-word;">
            </p>
        </div>

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

<!-- Scripts interactifs externalisés dans ../js/accueil-client.js -->

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

<!-- ============================================================
     MODALE DE PARTAGE MULTICANALE (VOTES & CONCOURS)
     ============================================================ -->
<div id="shareModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle" hidden>
    <div class="client-modal-box" style="max-width: 500px;">
        <button type="button" class="client-modal-close" onclick="closeShareModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <span class="page-kicker"><i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partage officiel</span>
        <h2 id="shareModalTitle" style="margin: 0.2rem 0 0.4rem; font-size: 1.35rem; overflow-wrap: break-word;">
            Partager le concours
        </h2>
        <p id="shareModalSubtitle" style="color: var(--muted); font-size: 0.88rem; margin: 0 0 1.25rem; line-height: 1.45;">
            Invitez vos proches et votre communauté à voter en ligne en un clic !
        </p>

        <!-- Champ d'URL directe avec bouton de copie -->
        <label style="font-size: 0.78rem; font-weight: 700; color: var(--navy); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 0.35rem;">
            Lien permanent du vote :
        </label>
        <div class="share-link-box">
            <input type="text" id="shareLinkInput" class="share-link-input" readonly value="">
            <button type="button" id="btnCopyShareLink" class="btn-copy-link" onclick="copyShareLink()">
                <i class="fa-regular fa-copy"></i> <span>Copier</span>
            </button>
        </div>

        <!-- Réseaux sociaux & Canaux -->
        <label style="font-size: 0.78rem; font-weight: 700; color: var(--navy); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 0.55rem;">
            Partager instantanément :
        </label>
        <div class="share-channels-grid">
            <a id="shareWaBtn" href="#" target="_blank" rel="noopener" class="share-channel-btn share-wa">
                <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> WhatsApp
            </a>
            <a id="shareFbBtn" href="#" target="_blank" rel="noopener" class="share-channel-btn share-fb">
                <i class="fa-brands fa-facebook" style="font-size: 1.1rem;"></i> Facebook
            </a>
            <a id="shareTwBtn" href="#" target="_blank" rel="noopener" class="share-channel-btn share-tw">
                <i class="fa-brands fa-x-twitter" style="font-size: 1.1rem;"></i> X (Twitter)
            </a>
            <a id="shareSmsBtn" href="#" class="share-channel-btn share-sms">
                <i class="fa-solid fa-comment-sms" style="font-size: 1.1rem;"></i> SMS
            </a>
        </div>

        <div id="nativeShareWrapper" style="display: none; margin-top: 0.5rem;">
            <button type="button" class="share-channel-btn share-native" style="width: 100%;" onclick="triggerNativeShare()">
                <i class="fa-solid fa-arrow-up-from-bracket"></i> Plus d'options de partage (Mobile)
            </button>
        </div>

        <div style="margin-top: 1.25rem; padding-top: 0.85rem; border-top: 1px solid var(--line); text-align: right;">
            <button type="button" class="btn-submit" onclick="closeShareModal()"
                style="width: 100%; margin: 0; background: #F1F5F9; color: var(--navy); border: 1px solid var(--line); font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-xmark"></i> Fermer
            </button>
        </div>
    </div>
</div>


<!-- ============================================================
     MODALE DE DÉTAILS D'UN ÉVÉNEMENT (#eventDetailsModal)
     ============================================================ -->
<div id="eventDetailsModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="eventDetailsModalTitle" hidden>
    <div class="client-modal-box client-modal-box-lg">
        <button type="button" class="client-modal-close" onclick="closeEventDetailsModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="detail-banner-box" style="position: relative;">
            <img id="eventDetailImg" src="" alt="Affiche événement">
            <span id="eventDetailCategory" class="event-category-badge" style="position: absolute; top: 12px; left: 12px;"></span>
        </div>

        <span class="page-kicker"><i class="fa-solid fa-calendar-check" style="color: var(--primary);"></i> Événement Officiel</span>
        <h2 id="eventDetailsModalTitle" style="margin: 0.25rem 0 0.5rem; font-size: 1.5rem; color: var(--navy); line-height: 1.25;"></h2>

        <div class="detail-metrics-grid">
            <div class="detail-metric-card">
                <small>Date & Heure</small>
                <strong id="eventDetailDateTime">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Lieu / Salle</small>
                <strong id="eventDetailLieu">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Tarif d'entrée</small>
                <strong id="eventDetailTarif" style="color: var(--primary);">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Disponibilité</small>
                <strong id="eventDetailPlaces">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Organisateur</small>
                <strong id="eventDetailPromoteur">-</strong>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--navy); margin: 0 0 0.45rem;">
                <i class="fa-solid fa-align-left" style="color: var(--primary);"></i> Présentation
            </h4>
            <p id="eventDetailDesc" style="color: var(--muted); font-size: 0.9rem; line-height: 1.6; margin: 0; white-space: pre-line;"></p>
        </div>

        <div id="eventDetailTicketsList" style="margin-bottom: 1.5rem;">
            <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--navy); margin: 0 0 0.65rem;">
                <i class="fa-solid fa-tags" style="color: var(--primary);"></i> Types de Billets Disponibles
            </h4>
            <div id="eventDetailTicketsGrid" style="display: grid; gap: 0.65rem;"></div>
        </div>

        <!-- Section Progression des candidats dans la modale de détails -->
        <div id="eventDetailCandidatsList" style="margin-bottom: 1.5rem; display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--navy); margin: 0;">
                    <i class="fa-solid fa-users" style="color: var(--primary);"></i> Progression des Candidats
                </h4>
                <a id="eventDetailVoteLink" href="#" style="font-size: 0.8rem; font-weight: 700; color: var(--primary); text-decoration: none;">
                    Voir le concours complet <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
            <div id="eventDetailCandidatsGrid" style="display: grid; gap: 0.65rem; background: #F8FAFC; border: 1px solid var(--line); border-radius: 8px; padding: 0.85rem;"></div>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <button type="button" class="btn-submit"
                onclick="openShareEventFromModal()"
                style="flex: 1; margin: 0; background: #FFF2ED; color: #EA580C; border: 1px solid #FFEDD5; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-share-nodes"></i> Partager
            </button>
            <button type="button" id="btnEventDetailBook" class="btn-submit"
                style="flex: 2; margin: 0; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-ticket"></i> Réserver mes Billets
            </button>
            <button type="button" class="btn-submit" onclick="closeEventDetailsModal()"
                style="flex: 0.8; margin: 0; background: #F1F5F9; color: var(--navy); border: 1px solid #CBD5E1; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-xmark"></i> Fermer
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODALE DE DÉTAILS D'UNE COTISATION (#cotisationDetailsModal)
     ============================================================ -->
<div id="cotisationDetailsModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="cotisationDetailsModalTitle" hidden>
    <div class="client-modal-box client-modal-box-lg">
        <button type="button" class="client-modal-close" onclick="closeCotisationDetailsModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="detail-banner-box">
            <img id="cotisationDetailImg" src="" alt="Affiche campagne">
            <span id="cotisationDetailStatut" class="event-category-badge" style="position: absolute; top: 12px; left: 12px;"></span>
        </div>

        <span class="page-kicker"><i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D;"></i> Campagne Solidaire</span>
        <h2 id="cotisationDetailsModalTitle" style="margin: 0.25rem 0 0.5rem; font-size: 1.5rem; color: var(--navy); line-height: 1.25;"></h2>

        <div class="detail-metrics-grid">
            <div class="detail-metric-card">
                <small>Montant Collecté</small>
                <strong id="cotisationDetailCollecte" style="color: #059669;">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Objectif Visé</small>
                <strong id="cotisationDetailObjectif">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Contributeurs</small>
                <strong id="cotisationDetailContributeurs">-</strong>
            </div>
            <div class="detail-metric-card">
                <small>Organisateur</small>
                <strong id="cotisationDetailPromoteur">-</strong>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700; margin-bottom: 6px;">
                <span style="color: var(--navy);">Progression globale</span>
                <span id="cotisationDetailPct" style="color: #FF4A0D;">0%</span>
            </div>
            <div style="height: 10px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                <div id="cotisationDetailBar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #EA580C); border-radius: 999px; transition: width 0.5s ease;"></div>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--navy); margin: 0 0 0.45rem;">
                <i class="fa-solid fa-align-left" style="color: var(--primary);"></i> Motivation & Objectif du projet
            </h4>
            <p id="cotisationDetailDesc" style="color: var(--muted); font-size: 0.9rem; line-height: 1.6; margin: 0; white-space: pre-line;"></p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <button type="button" id="btnCotisationDetailAction" class="btn-submit"
                style="flex: 2; margin: 0; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-paper-plane"></i> Participer à cette Cotisation
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODALE DE DÉTAILS D'UN ÉVÉNEMENT DE VOTE (#voteDetailsModal)
     ============================================================ -->
<div id="voteDetailsModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="voteDetailsModalTitle" hidden>
    <div class="client-modal-box client-modal-box-lg vote-modal-box">
        <button type="button" class="client-modal-close" onclick="closeVoteDetailsModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- En-tête Compact : Image vignette + Titre + Badges d'infos clés -->
        <div class="vote-modal-compact-hero" style="position: relative;">
            <div style="position: relative; flex-shrink: 0;">
                <img id="voteDetailImg" src="" alt="Affiche du concours" class="vote-modal-hero-thumb">
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 3px; flex-wrap: wrap;">
                    <span id="voteDetailCategory" class="event-category-badge" style="position: static; font-size: 0.72rem; padding: 2px 7px;"></span>
                    <span id="voteDetailTarifBadge" class="event-date-chip" style="position: static; background: #FFF2ED; color: #FF4A0D; font-weight: 800; font-size: 0.72rem; padding: 2px 7px; border: 1px solid rgba(255, 74, 13, 0.2);"></span>
                </div>
                <h2 id="voteDetailsModalTitle" class="vote-modal-title"></h2>
                
                <!-- Métadonnées compactes en badges horizontaux -->
                <div class="vote-modal-meta-pills">
                    <span class="vote-meta-pill">
                        <i class="fa-solid fa-location-dot" style="color: #64748B;"></i>
                        <span id="voteDetailLieu">-</span>
                    </span>
                    <span class="vote-meta-pill">
                        <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i>
                        <strong id="voteDetailTotalVotes" style="font-family: 'Space Mono', monospace;">-</strong>
                    </span>
                    <span class="vote-meta-pill">
                        <i class="fa-solid fa-coins" style="color: #FF4A0D;"></i>
                        <span id="voteDetailTarif">-</span>
                    </span>
                    <span class="vote-meta-pill" style="display: none;">
                        <span id="voteDetailDateTime">-</span>
                        <span id="voteDetailPromoteur">-</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Question officielle du scrutin si présente -->
        <div id="voteDetailQuestionBox" style="background: #FFF2ED; border-left: 4px solid #FF4A0D; border-radius: 0 8px 8px 0; padding: 0.65rem 0.85rem; margin-bottom: 0.85rem; display: none;">
            <small style="text-transform: uppercase; font-size: 0.68rem; font-weight: 800; color: #FF4A0D; letter-spacing: 0.05em; display: block;">Question officielle :</small>
            <p id="voteDetailQuestionText" style="margin: 0; font-size: 0.92rem; font-weight: 700; color: var(--navy);"></p>
        </div>

        <!-- ============================================================
             SECTION CARROUSEL HORIZONTAL DES CANDIDATS (AU PREMIER PLAN)
             ============================================================ -->
        <div class="vote-cands-section" id="voteModalCandsSection" style="margin-top: 0.25rem; margin-bottom: 1rem;">
            <div class="vote-cands-header" style="margin-bottom: 0.4rem;">
                <h3 class="vote-cands-header-title" style="font-size: 1.1rem; font-weight: 800; color: var(--navy); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-users" style="color: #FF4A0D;"></i>
                    <span>Candidats & Votants en compétition</span>
                    <span id="voteModalCandsCount" style="font-family: 'Space Mono', monospace; font-size: 0.78rem; background: #FFF2ED; color: #FF4A0D; padding: 2px 8px; border-radius: 999px; font-weight: 800; border: 1px solid rgba(255, 74, 13, 0.2);">0</span>
                </h3>
                <div class="vote-cands-nav">
                    <button type="button" class="vote-carousel-btn" onclick="scrollVoteCands(-1)" aria-label="Précédent" title="Défiler vers la gauche">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <button type="button" class="vote-carousel-btn" onclick="scrollVoteCands(1)" aria-label="Suivant" title="Défiler vers la droite">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>

            <p style="margin: 0 0 0.65rem; font-size: 0.82rem; color: var(--muted); display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrows-left-right" style="color: #FF4A0D;"></i>
                <span>Faites défiler horizontalement. Cliquez sur <strong>Détails</strong> pour voir le profil complet ou <strong>Voter</strong> pour voter directement.</span>
            </p>

            <div class="vote-cands-carousel-wrap">
                <div class="vote-cands-horizontal-track" id="voteModalCandsTrack">
                    <!-- Généré dynamiquement en JS -->
                </div>
            </div>
        </div>

        <!-- Description de l'événement en dessous du carrousel -->
        <div style="background: #F8FAFC; border: 1px solid var(--line, #E2E8F0); border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 1rem;">
            <h4 style="font-size: 0.84rem; font-weight: 800; color: var(--navy); margin: 0 0 0.3rem;">
                <i class="fa-solid fa-circle-info" style="color: #FF4A0D;"></i> À propos du concours
            </h4>
            <p id="voteDetailDesc" style="color: var(--muted); font-size: 0.84rem; line-height: 1.5; margin: 0; white-space: pre-line; max-height: 80px; overflow-y: auto;"></p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <button type="button" class="btn-submit" id="btnVoteModalShare"
                onclick="openShareVoteFromModal()"
                style="flex: 1.2; margin: 0; background: #FFF2ED; color: #EA580C; border: 1px solid #FFEDD5; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.7rem 1rem;">
                <i class="fa-solid fa-share-nodes"></i> Partager ce concours
            </button>
            <a id="btnVoteDetailFullPage" href="#" class="btn-submit"
                style="flex: 1.5; margin: 0; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; padding: 0.7rem 1rem;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir la page complète
            </a>
            <button type="button" class="btn-submit" onclick="closeVoteDetailsModal()"
                style="flex: 0.8; margin: 0; background: #F1F5F9; color: var(--navy); border: 1px solid #CBD5E1; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.7rem 1rem;">
                <i class="fa-solid fa-xmark"></i> Fermer
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODALE DE DÉTAILS COMPLETS D'UN CANDIDAT (#candidatDetailsModal)
     ============================================================ -->
<div id="candidatDetailsModal" class="client-modal" role="dialog" aria-modal="true" aria-labelledby="candModalNom" hidden style="z-index: 10005;">
    <div class="client-modal-box candidat-modal-box">
        <button type="button" class="client-modal-close" onclick="closeCandidatDetailsModal()" aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="candidat-detail-hero">
            <div class="candidat-detail-photo-wrap">
                <img id="candModalPhoto" src="" alt="Photo du candidat" class="candidat-detail-photo">
                <span id="candModalRankBadge" class="vote-cand-badge top-1">#1</span>
            </div>
            <div class="candidat-detail-meta">
                <div>
                    <span class="page-kicker" style="margin-bottom: 2px;"><i class="fa-solid fa-check-to-slot" style="color: #FF4A0D;"></i> Profil Officiel</span>
                    <h2 id="candModalNom" class="candidat-detail-nom"></h2>
                    <div id="candModalEventName" class="candidat-detail-event">
                        <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i> <span></span>
                    </div>
                </div>

                <div class="candidat-detail-kpi-grid">
                    <div class="candidat-detail-kpi">
                        <small>Total des voix</small>
                        <strong id="candModalVotesCount" style="color: var(--navy);">0</strong>
                    </div>
                    <div class="candidat-detail-kpi">
                        <small>Part des votes</small>
                        <strong id="candModalPctCount" style="color: #FF4A0D;">0%</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="candidat-detail-desc-box">
            <h4><i class="fa-solid fa-id-card" style="color: #FF4A0D; margin-right: 4px;"></i> Biographie & Présentation</h4>
            <p id="candModalBio" class="candidat-detail-desc-text"></p>
        </div>

        <!-- Jauge globale pour ce candidat -->
        <div style="margin-bottom: 1.25rem;">
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; font-family: 'Space Mono', monospace; font-weight: 700; margin-bottom: 4px;">
                <span style="color: var(--navy);">Baromètre du scrutin</span>
                <span id="candModalGaugePct" style="color: #FF4A0D;">0%</span>
            </div>
            <div style="height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                <div id="candModalGaugeFill" style="height: 100%; width: 0%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.5s ease;"></div>
            </div>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-direction: column;">
            <button type="button" id="candModalVoteBtn" class="btn-submit"
                style="margin: 0; background: #FF4A0D; color: #ffffff; font-size: 0.95rem; padding: 0.8rem 1rem; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-thumbs-up"></i>
                <span id="candModalVoteBtnText">Voter pour elle</span>
            </button>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                <button type="button" id="candModalShareBtn" class="btn-submit"
                    style="margin: 0; background: #F8FAFC; color: var(--navy); border: 1px solid #CBD5E1; font-weight: 700; font-size: 0.85rem; padding: 0.65rem 0.75rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager
                </button>
                <button type="button" class="btn-submit" onclick="closeCandidatDetailsModal()"
                    style="margin: 0; background: #F1F5F9; color: var(--muted); border: 1px solid #E2E8F0; font-weight: 700; font-size: 0.85rem; padding: 0.65rem 0.75rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-xmark"></i> Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.EVENTS_DATA = <?php echo json_encode($events ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.CAMPAGNES_DATA = <?php echo json_encode($campagnes ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.VOTES_DATA = <?php echo json_encode($classement ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.CANDIDATS_DATA = <?php echo json_encode($candidats_par_event ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.TICKETS_BY_EVENT = <?php echo json_encode($tickets_by_event ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
function handleReservationAction(button) {
    if (!button) return;
    var eventId = button.getAttribute('data-event-id') || (button.dataset ? button.dataset.eventId : '');
    // Détection PC / Desktop (> 768px) : redirection vers la page dédiée complète
    if (window.innerWidth > 768 && eventId) {
        window.location.href = 'evenement.php?id=' + encodeURIComponent(eventId) + '#billets';
        return;
    }
    // Mobile (<= 768px) : menu modale dédié
    if (typeof openEventModal === 'function') {
        openEventModal(button);
    }
}
window.handleReservationAction = handleReservationAction;

function handleEventCardClick(eventId, cardElement, ev) {
    // Si le clic provient du bouton de partage flottant ou d'un bouton d'action interne, ne pas interférer
    if (ev && ev.target && (ev.target.closest('.poster-floating-share-btn') || ev.target.closest('.btn-card-share'))) {
        return;
    }

    // Détection Mobile (<= 768px) : ouverture directe du menu mobile de l'événement
    if (window.innerWidth <= 768) {
        if (ev && ev.preventDefault) ev.preventDefault();
        var resBtn = cardElement ? cardElement.querySelector('button[data-event-id]') : null;
        if (resBtn && typeof openEventModal === 'function') {
            openEventModal(resBtn);
        } else if (typeof openEventDetailsModal === 'function') {
            openEventDetailsModal(eventId);
        } else {
            window.location.href = 'evenement.php?id=' + encodeURIComponent(eventId);
        }
    } else {
        // Détection PC / Desktop (> 768px) : redirection vers la page dédiée complète
        window.location.href = 'evenement.php?id=' + encodeURIComponent(eventId);
    }
}
window.handleEventCardClick = handleEventCardClick;
</script>

<script src="../js/venue-3d-engine.js?v=<?php echo time(); ?>"></script>
<script src="../js/accueil-client.js?v=<?php echo time(); ?>"></script>

<?php include 'footer.php'; ?>