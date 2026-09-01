<?php
// ==============================================================================
// GESTION DE MES DEMANDES (promoteur/demandes.php)
// Gestion complète des soumissions d'événements, concours, votes et cotisations
// Filtres dynamiques, annulation, consultation détaillée et suivi des statuts
// Design System Dashboard Pro
// ==============================================================================

$page_title = "Gestion de mes Demandes - Espace Promoteur";
include 'header.php';

$user_id = (int) $_SESSION['user_id'];
$message = '';
$msg_type = '';

// ===== ACTIONS POST : ANNULATION / SUPPRESSION D'UNE DEMANDE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Annuler une demande d'événement / concours / vote
    if ($action === 'annuler_demande_event') {
        $req_id = (int) ($_POST['request_id'] ?? 0);
        $stmt_del = $pdo->prepare("DELETE FROM event_requests WHERE id = ? AND user_id = ? AND statut IN ('en_attente', 'refuse')");
        $stmt_del->execute([$req_id, $user_id]);

        if ($stmt_del->rowCount() > 0) {
            $message = "La demande a été annulée et retirée de vos soumissions avec succès.";
            $msg_type = "success";
        } else {
            $message = "Impossible d'annuler cette demande (elle est peut-être déjà validée par l'administration).";
            $msg_type = "error";
        }
    }

    // Annuler une campagne de cotisation
    if ($action === 'annuler_demande_cotisation') {
        $camp_id = (int) ($_POST['campagne_id'] ?? 0);
        $stmt_del = $pdo->prepare("DELETE FROM cotisation_campagnes WHERE id = ? AND user_id = ? AND statut IN ('en_attente', 'refuse')");
        $stmt_del->execute([$camp_id, $user_id]);

        if ($stmt_del->rowCount() > 0) {
            $message = "La campagne de cotisation a été annulée avec succès.";
            $msg_type = "success";
        } else {
            $message = "Impossible d'annuler cette campagne.";
            $msg_type = "error";
        }
    }
}

// Onglet actif
$tab = $_GET['tab'] ?? 'evenements';
if (!in_array($tab, ['evenements', 'concours_votes', 'cotisations', 'engagement'], true)) {
    $tab = 'evenements';
}

// Filtre par statut (tous, en_attente, approuve, refuse)
$filter_statut = $_GET['statut'] ?? 'tous';
if (!in_array($filter_statut, ['tous', 'en_attente', 'approuve', 'refuse'], true)) {
    $filter_statut = 'tous';
}

// Filtre par période
$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', 'ce_mois', 'cette_annee', '30_jours', '7_jours'], true)) {
    $periode = 'toutes';
}

// Recherche par mot-clé
$search_q = trim($_GET['q'] ?? '');

// ---- 1. Récupération de toutes les demandes d'événements du promoteur ----
$sql_events = "SELECT * FROM event_requests WHERE user_id = ?";
$params_events = [$user_id];

if ($filter_statut !== 'tous') {
    $sql_events .= " AND statut = ?";
    $params_events[] = $filter_statut;
}
if ($periode === 'ce_mois') {
    $sql_events .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_events .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_events .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_events .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_events .= " AND (nom LIKE ? OR lieu LIKE ? OR description LIKE ?)";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
}
$sql_events .= " ORDER BY (statut = 'en_attente') DESC, created_at DESC";

$stmt_ev = $pdo->prepare($sql_events);
$stmt_ev->execute($params_events);
$all_event_requests = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);

// Séparer les événements classiques des concours & votes pour une meilleure clarté
$demandes_evenements = array_filter($all_event_requests, function ($d) {
    $type = $d['type_vote'] ?? '';
    $cat = strtolower($d['categorie'] ?? '');
    return $type !== 'realisation_evenement' && $cat !== 'concours' && $cat !== 'vote' && empty($d['candidats_data']);
});

$demandes_concours_votes = array_filter($all_event_requests, function ($d) {
    $type = $d['type_vote'] ?? '';
    $cat = strtolower($d['categorie'] ?? '');
    return $type === 'realisation_evenement' || $cat === 'concours' || $cat === 'vote' || !empty($d['candidats_data']);
});

// ---- 2. Récupération des campagnes de cotisation ----
$mes_campagnes = [];
try {
    $sql_cot = "
        SELECT c.*,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        WHERE c.user_id = ?
    ";
    $params_cot = [$user_id];
    if ($filter_statut !== 'tous') {
        $sql_cot .= " AND c.statut = ?";
        $params_cot[] = $filter_statut;
    }
    if ($periode === 'ce_mois') {
        $sql_cot .= " AND c.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
    } elseif ($periode === 'cette_annee') {
        $sql_cot .= " AND c.created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
    } elseif ($periode === '30_jours') {
        $sql_cot .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($periode === '7_jours') {
        $sql_cot .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    }
    if ($search_q !== '') {
        $sql_cot .= " AND (c.titre LIKE ? OR c.description LIKE ?)";
        $params_cot[] = "%$search_q%";
        $params_cot[] = "%$search_q%";
    }
    $sql_cot .= " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC";
    $stmt_cot = $pdo->prepare($sql_cot);
    $stmt_cot->execute($params_cot);
    $mes_campagnes = $stmt_cot->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mes_campagnes = [];
}

// ---- 3. Engagement sur les événements publiés (Votes & Likes) ----
$mon_classement = [];
try {
    $stmt_cl = $pdo->prepare("
        SELECT * FROM (
            SELECT e.id, e.nom, e.categorie, e.type_vote,
                   (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes,
                   (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) AS nb_likes
            FROM events e WHERE e.user_id = ?
        ) t
        ORDER BY t.nb_votes DESC, t.nb_likes DESC
    ");
    $stmt_cl->execute([$user_id]);
    $mon_classement = $stmt_cl->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mon_classement = [];
}

// ---- STATISTIQUES GLOBALES POUR LES KPI CARDS ----
$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(statut = 'en_attente') AS en_attente,
        SUM(statut = 'approuve') AS approuves,
        SUM(statut = 'refuse') AS refuses
    FROM event_requests WHERE user_id = ?
");
$stmt_stats->execute([$user_id]);
$stats_ev = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$nb_total_demandes = (int) ($stats_ev['total'] ?? 0) + count($mes_campagnes);
$nb_en_attente = (int) ($stats_ev['en_attente'] ?? 0) + count(array_filter($mes_campagnes, fn($c) => $c['statut'] === 'en_attente'));
$nb_approuves = (int) ($stats_ev['approuves'] ?? 0) + count(array_filter($mes_campagnes, fn($c) => in_array($c['statut'], ['approuve', 'active'], true)));
$nb_refuses = (int) ($stats_ev['refuses'] ?? 0) + count(array_filter($mes_campagnes, fn($c) => $c['statut'] === 'refuse'));

function get_dem_badge($statut)
{
    switch ($statut) {
        case 'en_attente':
            return ['En attente de validation', '#fef3c7', '#92400e', 'fa-hourglass-half'];
        case 'approuve':
        case 'active':
            return ['Validée & Active', '#dcfce7', '#166534', 'fa-circle-check'];
        case 'refuse':
            return ['Refusée', '#fee2e2', '#b91c1c', 'fa-circle-xmark'];
        case 'annulee':
            return ['Annulée', '#fee2e2', '#b91c1c', 'fa-ban'];
        case 'terminee':
            return ['Terminée', '#e2e8f0', '#475569', 'fa-flag-checkered'];
    }
    return [$statut, '#e2e8f0', '#475569', 'fa-info-circle'];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-folder-open" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion de mes Demandes
            </h1>
            <p>Pilotez toutes vos demandes : événements, concours avec candidats, votes de réalisation et campagnes de
                cotisation.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES : ONGLETS & PÉRIODE (SUR LA MÊME LIGNE, AU-DESSUS DES SECTIONS)
         ============================================================================== -->
    <div
        style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Onglets Catégories sur la même ligne -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?tab=evenements&periode=<?php echo $periode; ?>"
                class="dash-chart-tab <?php echo $tab === 'evenements' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-calendar-check"></i> Événements (<?php echo count($demandes_evenements); ?>)
            </a>

            <a href="?tab=concours_votes&periode=<?php echo $periode; ?>"
                class="dash-chart-tab <?php echo $tab === 'concours_votes' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Concours & Votes
                (<?php echo count($demandes_concours_votes); ?>)
            </a>

            <a href="?tab=cotisations&periode=<?php echo $periode; ?>"
                class="dash-chart-tab <?php echo $tab === 'cotisations' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899;"></i> Cotisations
                (<?php echo count($mes_campagnes); ?>)
            </a>

            <a href="?tab=engagement&periode=<?php echo $periode; ?>"
                class="dash-chart-tab <?php echo $tab === 'engagement' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-chart-simple"></i> Engagement
            </a>
        </div>

        <!-- Filtre Période sur la même ligne (Statut retiré) -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">

            <div
                style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
                <i class="fa-regular fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="periode" onchange="this.form.submit()"
                    style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.3rem 0.2rem; outline: none;">
                    <option value="toutes" <?php echo $periode === 'toutes' ? 'selected' : ''; ?>>Toutes les périodes
                    </option>
                    <option value="7_jours" <?php echo $periode === '7_jours' ? 'selected' : ''; ?>>7 derniers jours
                    </option>
                    <option value="30_jours" <?php echo $periode === '30_jours' ? 'selected' : ''; ?>>30 derniers jours
                    </option>
                    <option value="ce_mois" <?php echo $periode === 'ce_mois' ? 'selected' : ''; ?>>Ce mois-ci</option>
                    <option value="cette_annee" <?php echo $periode === 'cette_annee' ? 'selected' : ''; ?>>Cette année
                    </option>
                </select>
            </div>

            <?php if ($periode !== 'toutes'): ?>
                <a href="?tab=<?php echo $tab; ?>"
                    style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. SECTIONS KPI : SYNTHÈSE DE GESTION
         ============================================================================== -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <a href="?tab=<?php echo $tab; ?>&statut=tous&periode=<?php echo $periode; ?>"
            style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card"
                style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'tous' ? 'var(--dash-primary)' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span
                        style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total
                        Demandes</span>
                    <span
                        style="background: #f1f5f9; color: var(--dash-text); width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                            class="fa-solid fa-layer-group"></i></span>
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: var(--dash-text);">
                    <?php echo $nb_total_demandes; ?></div>
                <small style="color: var(--dash-muted); font-size: 0.75rem;">Toutes vos soumissions</small>
            </div>
        </a>

        <a href="?tab=<?php echo $tab; ?>&statut=en_attente&periode=<?php echo $periode; ?>"
            style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card"
                style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'en_attente' ? '#f59e0b' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">En
                        Attente</span>
                    <span
                        style="background: #fef3c7; color: #b45309; width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                            class="fa-solid fa-hourglass-half"></i></span>
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: #b45309;"><?php echo $nb_en_attente; ?></div>
                <small style="color: #b45309; font-size: 0.75rem;">En cours d'examen admin</small>
            </div>
        </a>

        <a href="?tab=<?php echo $tab; ?>&statut=approuve&periode=<?php echo $periode; ?>"
            style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card"
                style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'approuve' ? '#10b981' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span
                        style="font-size: 0.8rem; font-weight: 700; color: #047857; text-transform: uppercase;">Validées
                        & Publiées</span>
                    <span
                        style="background: #dcfce7; color: #047857; width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                            class="fa-solid fa-check"></i></span>
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: #047857;"><?php echo $nb_approuves; ?></div>
                <small style="color: #047857; font-size: 0.75rem;">Actives sur le site</small>
            </div>
        </a>

        <a href="?tab=<?php echo $tab; ?>&statut=refuse&periode=<?php echo $periode; ?>"
            style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card"
                style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'refuse' ? '#ef4444' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #b91c1c; text-transform: uppercase;">À
                        Réviser / Refusées</span>
                    <span
                        style="background: #fee2e2; color: #b91c1c; width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                            class="fa-solid fa-triangle-exclamation"></i></span>
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: #b91c1c;"><?php echo $nb_refuses; ?></div>
                <small style="color: #b91c1c; font-size: 0.75rem;">Commentaires à corriger</small>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         ONGLET 1 : ÉVÉNEMENTS & BILLETTERIE
         ============================================================================== -->
    <?php if ($tab === 'evenements'): ?>
        <?php if (empty($demandes_evenements)): ?>
            <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-calendar-xmark"
                    style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucune
                    demande d'événement trouvée</strong>
                <p style="font-size: 0.85rem; margin: 0 0 1.25rem;">Proposez votre événement avec ses tarifs et quotas de
                    billets pour ouvrir sa billetterie.</p>
                <a href="demande-evenement.php?onglet=evenement" class="dash-btn-action btn-primary"
                    style="display: inline-flex; width: auto;">
                    <i class="fa-solid fa-plus"></i> Proposer un Événement
                </a>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 1000px;">
                <?php foreach ($demandes_evenements as $d):
                    list($s_label, $s_bg, $s_fg, $s_icon) = get_dem_badge($d['statut']);
                    $tickets = json_decode($d['ticket_types_data'] ?? '[]', true) ?: [];
                    $img_src = (!empty($d['image']) && file_exists('../uploads/events/' . $d['image']))
                        ? '../uploads/events/' . htmlspecialchars($d['image'])
                        : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=400&q=80';
                    ?>
                    <div class="dash-card"
                        style="padding: 1.35rem 1.5rem; border-left: 4px solid <?php echo $d['statut'] === 'en_attente' ? '#f59e0b' : ($d['statut'] === 'approuve' ? '#10b981' : '#ef4444'); ?>;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1.25rem; flex-wrap: wrap;">
                            <!-- Visuel miniature + Infos principales -->
                            <div style="display: flex; gap: 1rem; min-width: 0; flex: 1;">
                                <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($d['nom']); ?>"
                                    style="width: 75px; height: 75px; border-radius: 10px; object-fit: cover; border: 1px solid var(--dash-border); flex-shrink: 0;">

                                <div style="min-width: 0; flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                        <strong style="color: var(--dash-text); font-size: 1.1rem;">
                                            <?php echo htmlspecialchars($d['nom']); ?>
                                        </strong>
                                        <span
                                            style="background: #eeedfd; color: #5b50e6; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($d['categorie']); ?>
                                        </span>
                                    </div>

                                    <div
                                        style="display: flex; flex-wrap: wrap; gap: 12px; color: var(--dash-muted); font-size: 0.82rem; align-items: center;">
                                        <span><i class="fa-regular fa-calendar"></i> Le
                                            <?php echo date('d/m/Y', strtotime($d['date_evenement'])); ?> à
                                            <?php echo date('H\hi', strtotime($d['heure'])); ?></span>
                                        <span>•</span>
                                        <span><i class="fa-solid fa-location-dot"></i>
                                            <?php echo htmlspecialchars($d['lieu']); ?></span>
                                        <span>•</span>
                                        <span><i class="fa-solid fa-ticket"></i> <?php echo count($tickets); ?> catégorie(s) de
                                            billet</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Statut & Actions -->
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.65rem;">
                                <span
                                    style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 5px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid <?php echo $s_icon; ?>"></i> <?php echo $s_label; ?>
                                </span>

                                <div style="display: flex; gap: 6px;">
                                    <button type="button"
                                        onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="dash-btn-action"
                                        style="padding: 4px 10px; font-size: 0.78rem; background: #f8fafc; border: 1px solid var(--dash-border); color: var(--dash-text);"
                                        title="Voir les détails complets">
                                        <i class="fa-solid fa-eye"></i> Détails
                                    </button>

                                    <?php if ($d['statut'] === 'en_attente' || $d['statut'] === 'refuse'): ?>
                                        <form method="POST"
                                            onsubmit="return confirm('Voulez-vous vraiment annuler et supprimer cette demande d\'événement ?');"
                                            style="margin: 0;">
                                            <input type="hidden" name="action" value="annuler_demande_event">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $d['id']; ?>">
                                            <button type="submit" class="dash-btn-action"
                                                style="padding: 4px 8px; font-size: 0.78rem; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626;"
                                                title="Annuler la demande">
                                                <i class="fa-solid fa-trash-can"></i> Annuler
                                            </button>
                                        </form>
                                    <?php elseif ($d['statut'] === 'approuve'): ?>
                                        <a href="mes-evenements.php" class="dash-btn-action"
                                            style="padding: 4px 10px; font-size: 0.78rem; background: #dcfce7; border: 1px solid #bbf7d0; color: #166534;">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Gérer
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Remarques admin ou bandeau attente -->
                        <?php if ($d['statut'] === 'en_attente'): ?>
                            <div
                                style="margin-top: 1rem; padding: 0.65rem 1rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; color: #92400e; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-hourglass-half" style="color: #f59e0b;"></i>
                                <span>Demande en cours d'examen par la modération. La billetterie sera activée dès validation.</span>
                            </div>
                        <?php elseif (!empty($d['commentaire_admin'])): ?>
                            <div
                                style="margin-top: 1rem; padding: 0.65rem 1rem; background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; color: #991b1b; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                                <span>Remarque de l'administration : <?php echo htmlspecialchars($d['commentaire_admin']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ==============================================================================
         ONGLET 2 : CONCOURS & VOTES DE RÉALISATION
         ============================================================================== -->
    <?php elseif ($tab === 'concours_votes'): ?>
        <?php if (empty($demandes_concours_votes)): ?>
            <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-trophy"
                    style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun
                    concours ou vote de réalisation demandé</strong>
                <p style="font-size: 0.85rem; margin: 0 0 1.25rem;">Lancez une compétition avec candidats ou un plébiscite de
                    spectateurs pour confirmer un projet.</p>
                <a href="demande-evenement.php?onglet=vote" class="dash-btn-action btn-primary"
                    style="display: inline-flex; width: auto;">
                    <i class="fa-solid fa-plus"></i> Créer un Concours ou Vote
                </a>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 1000px;">
                <?php foreach ($demandes_concours_votes as $cv):
                    list($s_label, $s_bg, $s_fg, $s_icon) = get_dem_badge($cv['statut']);
                    $is_realisation = (($cv['type_vote'] ?? '') === 'realisation_evenement');
                    $candidats = json_decode($cv['candidats_data'] ?? '[]', true) ?: [];
                    $img_src = (!empty($cv['image']) && file_exists('../uploads/events/' . $cv['image']))
                        ? '../uploads/events/' . htmlspecialchars($cv['image'])
                        : 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=400&q=80';
                    ?>
                    <div class="dash-card"
                        style="padding: 1.35rem 1.5rem; border-left: 4px solid <?php echo $is_realisation ? '#0284c7' : '#f59e0b'; ?>;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1.25rem; flex-wrap: wrap;">
                            <div style="display: flex; gap: 1rem; min-width: 0; flex: 1;">
                                <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($cv['nom']); ?>"
                                    style="width: 75px; height: 75px; border-radius: 10px; object-fit: cover; border: 1px solid var(--dash-border); flex-shrink: 0;">

                                <div style="min-width: 0; flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                        <strong style="color: var(--dash-text); font-size: 1.1rem;">
                                            <?php echo htmlspecialchars($cv['nom']); ?>
                                        </strong>
                                        <span
                                            style="background: <?php echo $is_realisation ? '#eff6ff' : '#fffbeb'; ?>; color: <?php echo $is_realisation ? '#1d4ed8' : '#b45309'; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">
                                            <i
                                                class="fa-solid <?php echo $is_realisation ? 'fa-square-poll-vertical' : 'fa-trophy'; ?>"></i>
                                            <?php echo $is_realisation ? 'Vote de Réalisation' : 'Concours & Compétition'; ?>
                                        </span>
                                    </div>

                                    <?php if ($is_realisation && !empty($cv['vote_question'])): ?>
                                        <p style="margin: 0 0 6px; font-size: 0.85rem; color: #1e40af; font-weight: 700;">
                                            « <?php echo htmlspecialchars($cv['vote_question']); ?> »
                                        </p>
                                    <?php endif; ?>

                                    <div
                                        style="display: flex; flex-wrap: wrap; gap: 12px; color: var(--dash-muted); font-size: 0.82rem; align-items: center;">
                                        <span><i class="fa-regular fa-calendar"></i> Clôture :
                                            <?php echo date('d/m/Y', strtotime($cv['date_evenement'])); ?></span>
                                        <span>•</span>
                                        <span><i class="fa-solid fa-coins" style="color: #f59e0b;"></i> Tarif vote :
                                            <?php echo (float) $cv['prix_vote'] > 0 ? number_format((float) $cv['prix_vote'], 0, ',', ' ') . ' FCFA' : 'Gratuit'; ?></span>
                                        <?php if (!$is_realisation): ?>
                                            <span>•</span>
                                            <span style="color: var(--dash-primary); font-weight: 700;"><i
                                                    class="fa-solid fa-users"></i> <?php echo count($candidats); ?> candidat(s)
                                                enregistré(s)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.65rem;">
                                <span
                                    style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 5px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid <?php echo $s_icon; ?>"></i> <?php echo $s_label; ?>
                                </span>

                                <div style="display: flex; gap: 6px;">
                                    <button type="button"
                                        onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($cv), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="dash-btn-action"
                                        style="padding: 4px 10px; font-size: 0.78rem; background: #f8fafc; border: 1px solid var(--dash-border); color: var(--dash-text);"
                                        title="Voir les détails et participants">
                                        <i class="fa-solid fa-eye"></i> Voir Détails
                                    </button>

                                    <?php if ($cv['statut'] === 'en_attente' || $cv['statut'] === 'refuse'): ?>
                                        <form method="POST"
                                            onsubmit="return confirm('Confirmez-vous l\'annulation de cette demande ?');"
                                            style="margin: 0;">
                                            <input type="hidden" name="action" value="annuler_demande_event">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $cv['id']; ?>">
                                            <button type="submit" class="dash-btn-action"
                                                style="padding: 4px 8px; font-size: 0.78rem; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626;">
                                                <i class="fa-solid fa-trash-can"></i> Annuler
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Vignettes des candidats pour les concours -->
                        <?php if (!$is_realisation && !empty($candidats)): ?>
                            <div style="margin-top: 1rem; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                                <small
                                    style="display: block; color: var(--dash-muted); font-size: 0.75rem; margin-bottom: 6px; font-weight: 700;">Aperçu
                                    des candidats enregistrés :</small>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <?php foreach (array_slice($candidats, 0, 5) as $cd): ?>
                                        <?php
                                        $cd_photo = (!empty($cd['photo']) && file_exists('../uploads/candidats/' . $cd['photo']))
                                            ? '../uploads/candidats/' . htmlspecialchars($cd['photo'])
                                            : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80';
                                        ?>
                                        <div
                                            style="display: flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 20px; padding: 3px 10px 3px 4px;">
                                            <img src="<?php echo $cd_photo; ?>" alt="<?php echo htmlspecialchars($cd['nom']); ?>"
                                                style="width: 22px; height: 22px; border-radius: 50%; object-fit: cover;">
                                            <span
                                                style="font-size: 0.75rem; font-weight: 700; color: var(--dash-text);"><?php echo htmlspecialchars($cd['nom']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($candidats) > 5): ?>
                                        <span
                                            style="font-size: 0.75rem; color: var(--dash-muted); align-self: center;">+<?php echo count($candidats) - 5; ?>
                                            autre(s)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ==============================================================================
         ONGLET 3 : CAMPAGNES DE COTISATION
         ============================================================================== -->
    <?php elseif ($tab === 'cotisations'): ?>
        <?php if (empty($mes_campagnes)): ?>
            <div class="dash-card"
                style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted); max-width: 1000px;">
                <i class="fa-solid fa-hand-holding-heart"
                    style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucune
                    campagne de cotisation proposée</strong>
                <p style="font-size: 0.85rem; margin: 0 0 1.25rem;">Lancez une collecte de fonds participative pour financer la
                    réalisation de votre projet.</p>
                <a href="demande-evenement.php?onglet=cotisation" class="dash-btn-action btn-primary"
                    style="display: inline-flex; width: auto;">
                    <i class="fa-solid fa-plus"></i> Proposer une Cotisation
                </a>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 1000px;">
                <?php foreach ($mes_campagnes as $c):
                    list($s_label, $s_bg, $s_fg, $s_icon) = get_dem_badge($c['statut']);
                    $objectif = (float) $c['montant_objectif'];
                    $collecte = (float) $c['montant_collecte'];
                    $pct = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100)) : 0;
                    $c_img = (!empty($c['image']) && file_exists('../uploads/cotisations/' . $c['image']))
                        ? '../uploads/cotisations/' . htmlspecialchars($c['image'])
                        : 'https://images.unsplash.com/photo-1559027615-cd4628902d4a?auto=format&fit=crop&w=400&q=80';
                    ?>
                    <div class="dash-card" style="padding: 1.35rem 1.5rem; border-left: 4px solid #ec4899;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1.25rem; flex-wrap: wrap;">
                            <div style="display: flex; gap: 1rem; min-width: 0; flex: 1;">
                                <img src="<?php echo $c_img; ?>" alt="<?php echo htmlspecialchars($c['titre']); ?>"
                                    style="width: 75px; height: 75px; border-radius: 10px; object-fit: cover; border: 1px solid var(--dash-border); flex-shrink: 0;">

                                <div style="min-width: 0; flex: 1;">
                                    <strong style="color: var(--dash-text); font-size: 1.1rem; display: block; margin-bottom: 4px;">
                                        <?php echo htmlspecialchars($c['titre']); ?>
                                    </strong>
                                    <div
                                        style="display: flex; flex-wrap: wrap; gap: 12px; color: var(--dash-muted); font-size: 0.82rem; align-items: center;">
                                        <span><strong>Objectif :</strong> <?php echo number_format($objectif, 0, ',', ' '); ?>
                                            FCFA</span>
                                        <span>•</span>
                                        <span style="color: #10b981; font-weight: 800;"><strong>Collecté :</strong>
                                            <?php echo number_format($collecte, 0, ',', ' '); ?> FCFA</span>
                                        <span>•</span>
                                        <span><i class="fa-solid fa-users"></i> <?php echo (int) $c['nb_contributeurs']; ?>
                                            donateur(s)</span>
                                        <?php if (!empty($c['date_limite'])): ?>
                                            <span>•</span>
                                            <span><i class="fa-regular fa-clock"></i> Limite :
                                                <?php echo date('d/m/Y', strtotime($c['date_limite'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.65rem;">
                                <span
                                    style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 5px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid <?php echo $s_icon; ?>"></i> <?php echo $s_label; ?>
                                </span>

                                <?php if ($c['statut'] === 'en_attente' || $c['statut'] === 'refuse'): ?>
                                    <form method="POST"
                                        onsubmit="return confirm('Confirmez-vous l\'annulation de cette campagne de cotisation ?');"
                                        style="margin: 0;">
                                        <input type="hidden" name="action" value="annuler_demande_cotisation">
                                        <input type="hidden" name="campagne_id" value="<?php echo (int) $c['id']; ?>">
                                        <button type="submit" class="dash-btn-action"
                                            style="padding: 4px 8px; font-size: 0.78rem; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626;">
                                            <i class="fa-solid fa-trash-can"></i> Annuler
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Progression de la collecte -->
                        <div style="margin-top: 1rem;">
                            <div
                                style="display: flex; justify-content: space-between; font-size: 0.78rem; font-weight: 800; margin-bottom: 4px;">
                                <span style="color: var(--dash-primary);"><?php echo $pct; ?>% récolté</span>
                                <span style="color: var(--dash-muted);"><?php echo number_format($collecte, 0, ',', ' '); ?> /
                                    <?php echo number_format($objectif, 0, ',', ' '); ?> FCFA</span>
                            </div>
                            <div style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                <div
                                    style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, #ec4899 0%, #10b981 100%); border-radius: 999px;">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ==============================================================================
         ONGLET 4 : ENGAGEMENT PUBLIC (VOTES & LIKES)
         ============================================================================== -->
    <?php else: ?>
        <div class="dash-card" style="max-width: 1000px;">
            <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-chart-simple" style="color: var(--dash-primary);"></i>
                        Engagement & Plébiscites sur vos Programmes Actifs
                    </h3>
                    <div class="dash-card-subtitle">Volume de votes et de likes enregistrés sur chacun de vos programmes
                        publiés</div>
                </div>
            </div>

            <?php if (empty($mon_classement)): ?>
                <div style="text-align: center; color: var(--dash-muted); padding: 3rem 1rem;">
                    <i class="fa-solid fa-heart-crack"
                        style="font-size: 2.2rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                    Aucun événement actif répertorié pour le moment.
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($mon_classement as $ev): ?>
                        <div
                            style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 10px; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <div>
                                <strong style="color: var(--dash-text); font-size: 0.98rem; display: block; margin-bottom: 2px;">
                                    <?php echo htmlspecialchars($ev['nom']); ?>
                                </strong>
                                <small style="color: var(--dash-muted); font-size: 0.78rem;">
                                    Catégorie : <?php echo htmlspecialchars($ev['categorie']); ?>
                                    <?php if (!empty($ev['type_vote'])): ?>
                                        —
                                        [<?php echo $ev['type_vote'] === 'realisation_evenement' ? 'Vote de Réalisation' : 'Concours'; ?>]
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div style="display: flex; gap: 0.75rem; align-items: center;">
                                <span
                                    style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 8px; padding: 4px 12px; font-size: 0.82rem; font-weight: 800; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-vote-yea"></i> <?php echo (int) $ev['nb_votes']; ?>
                                    vote<?php echo (int) $ev['nb_votes'] > 1 ? 's' : ''; ?>
                                </span>
                                <span
                                    style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; padding: 4px 12px; font-size: 0.82rem; font-weight: 800; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-heart"></i> <?php echo (int) $ev['nb_likes']; ?>
                                    like<?php echo (int) $ev['nb_likes'] > 1 ? 's' : ''; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODALE DE CONSULTATION DÉTAILLÉE DE LA DEMANDE
     ============================================================================== -->
<div id="modalDetailsDemande"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem; backdrop-filter: blur(4px);">
    <div
        style="background: #ffffff; width: 100%; max-width: 650px; max-height: 90vh; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); display: flex; flex-direction: column; overflow: hidden;">
        <!-- Header modale -->
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="modalIcon"
                    style="background: var(--dash-primary); color: #ffffff; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;">
                    <i class="fa-solid fa-file-lines"></i>
                </span>
                <h3 id="modalTitle" style="margin: 0; font-size: 1.15rem; color: var(--dash-text); font-weight: 800;">
                    Détails de la Demande</h3>
            </div>
            <button type="button" onclick="closeDetailsModal()"
                style="background: #f1f5f9; border: 0; border-radius: 8px; width: 32px; height: 32px; cursor: pointer; color: var(--dash-muted);"
                title="Fermer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Contenu scrollable -->
        <div id="modalBody"
            style="padding: 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1.25rem; font-size: 0.88rem;">
        </div>

        <!-- Footer modale -->
        <div
            style="padding: 1rem 1.5rem; border-top: 1px solid var(--dash-border); display: flex; justify-content: flex-end; background: #f8fafc;">
            <button type="button" onclick="closeDetailsModal()" class="dash-btn-action"
                style="padding: 0.55rem 1.25rem; background: var(--dash-border); color: var(--dash-text);">
                Fermer
            </button>
        </div>
    </div>
</div>

<script>
    function openDetailsModal(data) {
        const modal = document.getElementById('modalDetailsDemande');
        const body = document.getElementById('modalBody');
        const titleEl = document.getElementById('modalTitle');
        if (!modal || !body) return;

        titleEl.textContent = data.nom || 'Détails de la demande';

        let badgeBg = '#fef3c7', badgeFg = '#92400e', badgeText = 'En attente';
        if (data.statut === 'approuve' || data.statut === 'active') { badgeBg = '#dcfce7'; badgeFg = '#166534'; badgeText = 'Approuvée & Active'; }
        else if (data.statut === 'refuse') { badgeBg = '#fee2e2'; badgeFg = '#b91c1c'; badgeText = 'Refusée'; }

        let html = `
            <div style="display: flex; gap: 1rem; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem;">
                <img src="${data.image ? '../uploads/events/' + data.image : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=400&q=80'}" 
                     onerror="this.src='https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=400&q=80'" 
                     style="width: 80px; height: 80px; border-radius: 10px; object-fit: cover; border: 1px solid var(--dash-border);">
                <div>
                    <span style="background: ${badgeBg}; color: ${badgeFg}; padding: 3px 10px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; display: inline-block; margin-bottom: 4px;">
                        ${badgeText}
                    </span>
                    <h4 style="margin: 0 0 4px; font-size: 1.1rem; color: var(--dash-text); font-weight: 800;">${data.nom}</h4>
                    <span style="color: var(--dash-muted); font-size: 0.8rem;">Catégorie : <strong>${data.categorie || 'Événement'}</strong></span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; background: #f8fafc; border-radius: 10px; padding: 1rem; border: 1px solid var(--dash-border);">
                <div>
                    <span style="color: var(--dash-muted); font-size: 0.75rem; display: block;">Date de l'événement</span>
                    <strong style="color: var(--dash-text);">${data.date_evenement || 'Non définie'} à ${data.heure ? data.heure.substring(0, 5) : '20:00'}</strong>
                </div>
                <div>
                    <span style="color: var(--dash-muted); font-size: 0.75rem; display: block;">Lieu</span>
                    <strong style="color: var(--dash-text);">${data.lieu || 'Non précisé'}</strong>
                </div>
                <div>
                    <span style="color: var(--dash-muted); font-size: 0.75rem; display: block;">Date de soumission</span>
                    <strong style="color: var(--dash-text);">${data.created_at || 'Récemment'}</strong>
                </div>
                <div>
                    <span style="color: var(--dash-muted); font-size: 0.75rem; display: block;">Tarif Vote</span>
                    <strong style="color: #f59e0b;">${data.prix_vote > 0 ? Number(data.prix_vote).toLocaleString('fr-FR') + ' FCFA' : 'Gratuit'}</strong>
                </div>
            </div>
        `;

        if (data.description) {
            html += `
                <div>
                    <span style="color: var(--dash-muted); font-size: 0.78rem; font-weight: 700; display: block; margin-bottom: 4px;">Description du projet :</span>
                    <p style="margin: 0; background: #ffffff; border: 1px solid var(--dash-border); padding: 0.75rem; border-radius: 8px; line-height: 1.45; color: var(--dash-text);">${data.description}</p>
                </div>
            `;
        }

        if (data.type_vote === 'realisation_evenement' && data.vote_question) {
            html += `
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 1rem;">
                    <strong style="color: #1e40af; font-size: 0.85rem; display: block; margin-bottom: 4px;"><i class="fa-solid fa-circle-question"></i> Question soumise au vote du public :</strong>
                    <div style="font-size: 0.95rem; font-weight: 800; color: #1e3a8a;">« ${data.vote_question} »</div>
                </div>
            `;
        }

        // Si des candidats sont enregistrés
        if (data.candidats_data) {
            try {
                const cands = JSON.parse(data.candidats_data);
                if (Array.isArray(cands) && cands.length > 0) {
                    html += `
                        <div>
                            <strong style="color: var(--dash-text); font-size: 0.88rem; display: block; margin-bottom: 8px;">
                                <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i> Participants & Candidats enregistrés (${cands.length}) :
                            </strong>
                            <div style="display: flex; flex-direction: column; gap: 6px;">
                    `;
                    cands.forEach((c, idx) => {
                        const photo = c.photo ? '../uploads/candidats/' + c.photo : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80';
                        html += `
                            <div style="display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 6px 10px;">
                                <img src="${photo}" style="width: 32px; height: 32px; border-radius: 6px; object-fit: cover;">
                                <div>
                                    <strong style="font-size: 0.85rem; color: var(--dash-text); display: block;">${c.nom}</strong>
                                    ${c.description ? `<small style="color: var(--dash-muted); font-size: 0.75rem;">${c.description}</small>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div></div>`;
                }
            } catch (e) { }
        }

        // Si des billets sont enregistrés
        if (data.ticket_types_data) {
            try {
                const tks = JSON.parse(data.ticket_types_data);
                if (Array.isArray(tks) && tks.length > 0) {
                    html += `
                        <div>
                            <strong style="color: var(--dash-text); font-size: 0.88rem; display: block; margin-bottom: 8px;">
                                <i class="fa-solid fa-ticket" style="color: var(--dash-primary);"></i> Tarifs & Billets proposés :
                            </strong>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px;">
                    `;
                    tks.forEach(tk => {
                        html += `
                            <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 8px 10px;">
                                <strong style="font-size: 0.82rem; color: var(--dash-text); display: block;">${tk.nom}</strong>
                                <span style="font-size: 0.95rem; font-weight: 800; color: var(--dash-primary);">${Number(tk.prix).toLocaleString('fr-FR')} F</span>
                                <small style="color: var(--dash-muted); display: block; font-size: 0.72rem;">${tk.quantite} places</small>
                            </div>
                        `;
                    });
                    html += `</div></div>`;
                }
            } catch (e) { }
        }

        if (data.commentaire_admin) {
            html += `
                <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 10px; padding: 1rem; color: #991b1b;">
                    <strong style="display: block; font-size: 0.82rem; margin-bottom: 3px;"><i class="fa-solid fa-comment-dots"></i> Remarque de l'administration :</strong>
                    <div style="font-size: 0.88rem;">${data.commentaire_admin}</div>
                </div>
            `;
        }

        body.innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeDetailsModal() {
        const modal = document.getElementById('modalDetailsDemande');
        if (modal) modal.style.display = 'none';
    }

    // Fermeture par touche Echap ou clic extérieur
    window.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDetailsModal();
    });
    document.getElementById('modalDetailsDemande')?.addEventListener('click', function (e) {
        if (e.target === this) closeDetailsModal();
    });
</script>

<?php include 'footer.php'; ?>