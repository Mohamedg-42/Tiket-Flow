<?php
// ==============================================================================
// GESTION DES ÉVÉNEMENTS DU PROMOTEUR (promoteur/mes-evenements.php)
// Design Dashboard Pro - Pilotage des événements, billetteries, quotas et statuts
// ==============================================================================

$page_title = "Gestion de mes Événements - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Actions de gestion (Clôturer, Réactiver, Modifier)
// ------------------------------------------------------------------------------

// Clôturer / Marquer comme Terminé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cloturer'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $stmt_close = $pdo->prepare("UPDATE events SET statut = 'termine' WHERE id = ? AND user_id = ?");
    $stmt_close->execute([$event_id, $user_id]);
    $message = "L'événement a été marqué comme terminé et la billetterie est désormais clôturée.";
    $msg_type = "success";
}

// Réactiver l'événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reactiver'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $stmt_act = $pdo->prepare("UPDATE events SET statut = 'actif' WHERE id = ? AND user_id = ?");
    $stmt_act->execute([$event_id, $user_id]);
    $message = "L'événement a été réactivé avec succès.";
    $msg_type = "success";
}

// Modification des informations de base de l'événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_modifier'])) {
    $event_id    = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $nom         = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $lieu        = trim($_POST['lieu'] ?? '');
    $date_ev     = trim($_POST['date_evenement'] ?? '');
    $heure       = trim($_POST['heure'] ?? '');

    if (empty($nom) || empty($lieu) || empty($date_ev) || empty($heure)) {
        $message = "Veuillez remplir tous les champs obligatoires (Titre, Date, Heure, Lieu).";
        $msg_type = "error";
    } else {
        // Traitement de l'upload de nouvelle image si présente
        $new_image_name = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $new_image_name = 'event_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = __DIR__ . '/../uploads/' . $new_image_name;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                    $new_image_name = null;
                }
            }
        }

        if ($new_image_name) {
            $stmt_upd = $pdo->prepare("
                UPDATE events 
                SET nom = ?, description = ?, lieu = ?, date_evenement = ?, heure = ?, image = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt_upd->execute([$nom, $description, $lieu, $date_ev, $heure, $new_image_name, $event_id, $user_id]);
        } else {
            $stmt_upd = $pdo->prepare("
                UPDATE events 
                SET nom = ?, description = ?, lieu = ?, date_evenement = ?, heure = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt_upd->execute([$nom, $description, $lieu, $date_ev, $heure, $event_id, $user_id]);
        }
        $message = "Les modifications ont été enregistrées avec succès.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 2. Filtres & Recherche
// ------------------------------------------------------------------------------
$filter_statut = $_GET['statut'] ?? 'tous';
if (!in_array($filter_statut, ['tous', 'actif', 'termine', 'complet'], true)) {
    $filter_statut = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$search_q = trim($_GET['q'] ?? '');

// ------------------------------------------------------------------------------
// 3. Récupération des événements avec statistiques de ventes et scans
// ------------------------------------------------------------------------------
$sql_events = "
    SELECT e.*, 
           COALESCE((SELECT SUM(tt.quantite_vendue) FROM ticket_types tt WHERE tt.event_id = e.id), 0) AS tickets_vendus,
           COALESCE((SELECT SUM(tt.quantite) FROM ticket_types tt WHERE tt.event_id = e.id), 0) AS total_places,
           COALESCE((SELECT SUM(t.prix) FROM tickets t WHERE t.event_id = e.id AND t.statut IN ('vendu', 'utilise')), 0) AS total_recette,
           COALESCE((SELECT COUNT(*) FROM tickets t_sc WHERE t_sc.event_id = e.id AND t_sc.statut = 'utilise'), 0) AS total_scans,
           COALESCE((SELECT COUNT(*) FROM agent_assignments aa WHERE aa.event_id = e.id), 0) AS nb_agents,
           COALESCE((SELECT COUNT(*) FROM event_votes ev WHERE ev.event_id = e.id), 0) AS nb_votes
    FROM events e 
    WHERE e.user_id = ?
";
$params_events = [$user_id];

if ($filter_statut === 'actif') {
    $sql_events .= " AND (e.statut = 'actif' OR e.statut = 'approuve')";
} elseif ($filter_statut === 'termine') {
    $sql_events .= " AND e.statut = 'termine'";
}

if ($periode === 'ce_mois') {
    $sql_events .= " AND e.date_evenement >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_events .= " AND e.date_evenement >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_events .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_events .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_events .= " AND (e.nom LIKE ? OR e.lieu LIKE ? OR e.categorie LIKE ?)";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
}

$sql_events .= " ORDER BY (e.statut = 'actif' OR e.statut = 'approuve') DESC, e.date_evenement DESC";

$stmt_events = $pdo->prepare($sql_events);
$stmt_events->execute($params_events);
$all_events = $stmt_events->fetchAll(PDO::FETCH_ASSOC);

// Filtrage si 'complet'
if ($filter_statut === 'complet') {
    $all_events = array_filter($all_events, function($ev) {
        return (int)$ev['total_places'] > 0 && (int)$ev['tickets_vendus'] >= (int)$ev['total_places'];
    });
}

// ------------------------------------------------------------------------------
// 4. Calcul des KPI Cards Globales
// ------------------------------------------------------------------------------
$total_events_count = count($all_events);
$total_billets_vendus_sum = array_sum(array_column($all_events, 'tickets_vendus'));
$total_places_sum = array_sum(array_column($all_events, 'total_places'));
$total_recettes_sum = array_sum(array_column($all_events, 'total_recette'));
$taux_remplissage_global = $total_places_sum > 0 ? round(($total_billets_vendus_sum / $total_places_sum) * 100, 1) : 0;
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.event-thumb {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    object-fit: cover;
    background: #f1f5f9;
    border: 1px solid var(--dash-border);
    flex-shrink: 0;
}
.event-action-btn {
    padding: 0.35rem 0.65rem;
    border-radius: 8px;
    border: 1px solid var(--dash-border);
    background: #ffffff;
    color: var(--dash-text);
    font-size: 0.78rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.event-action-btn:hover {
    background: #f8fafc;
    border-color: var(--dash-primary);
    color: var(--dash-primary);
}
.event-action-btn.btn-danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #ef4444;
}
.event-progress-bar {
    background: #e2e8f0;
    border-radius: 999px;
    height: 7px;
    overflow: hidden;
    margin-top: 5px;
}
.event-progress-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion de mes Événements
            </h1>
            <p>Pilotez vos billetteries, suivez le remplissage des salles, gérez vos tarifs et encadrez vos équipes de contrôle.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <a href="demande-evenement.php" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; text-decoration: none;">
                <i class="fa-solid fa-plus"></i> Proposer un Événement
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE DE GESTION DES ÉVÉNEMENTS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Événements</span>
                <span style="background: #f1f5f9; color: var(--dash-text); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $total_events_count; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">En ligne & enregistrés</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Billets Écoulés</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($total_billets_vendus_sum, 0, ',', ' '); ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">sur <?php echo number_format($total_places_sum, 0, ',', ' '); ?> places disponibles</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #059669; text-transform: uppercase;">Recettes Cumulées</span>
                <span style="background: #dcfce7; color: #059669; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #059669;"><?php echo number_format($total_recettes_sum, 0, ',', ' '); ?> F</div>
            <small style="color: #059669; font-size: 0.75rem;">Chiffre d'affaires brut généré</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">Remplissage Moyen</span>
                <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chart-pie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo $taux_remplissage_global; ?>%</div>
            <small style="color: #b45309; font-size: 0.75rem;">Capacité totale occupée</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES : STATUTS, PÉRIODE & RECHERCHE (SUR UNE SEULE LIGNE)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Onglets Statuts -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'tous' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-list"></i> Tous (<?php echo $total_events_count; ?>)
            </a>

            <a href="?statut=actif&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'actif' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-circle-dot" style="color: #10b981;"></i> En cours / Actifs
            </a>

            <a href="?statut=complet&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'complet' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Complets / Sold Out
            </a>

            <a href="?statut=termine&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'termine' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-flag-checkered" style="color: #64748b;"></i> Terminés
            </a>
        </div>

        <!-- Filtre Période & Recherche sur la même ligne -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filter_statut); ?>">

            <!-- Sélecteur PÉRIODE -->
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
                <i class="fa-regular fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="periode" onchange="this.form.submit()" style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.3rem 0.2rem; outline: none;">
                    <option value="toutes" <?php echo $periode === 'toutes' ? 'selected' : ''; ?>>Toutes les dates</option>
                    <option value="7_jours" <?php echo $periode === '7_jours' ? 'selected' : ''; ?>>7 derniers jours</option>
                    <option value="30_jours" <?php echo $periode === '30_jours' ? 'selected' : ''; ?>>30 derniers jours</option>
                    <option value="ce_mois" <?php echo $periode === 'ce_mois' ? 'selected' : ''; ?>>Ce mois-ci</option>
                    <option value="cette_annee" <?php echo $periode === 'cette_annee' ? 'selected' : ''; ?>>Cette année</option>
                </select>
            </div>

            <!-- Champ Recherche rapide -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Nom, lieu..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $search_q !== '' || $filter_statut !== 'tous'): ?>
                <a href="mes-evenements.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. LISTE DES ÉVÉNEMENTS (TABLEAU & FICHES DASHBOARD PRO)
         ============================================================================== -->
    <div class="dash-card" style="padding: 0; overflow: hidden;">
        <div style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 700;">
                    <i class="fa-solid fa-bullhorn" style="color: var(--dash-primary); margin-right: 6px;"></i>
                    Événements Publiés sur Eventia (<?php echo count($all_events); ?>)
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Suivi en direct des ventes, entrées scannées et pilotage de la billetterie.</small>
            </div>
            <a href="demandes.php" style="font-size: 0.82rem; color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-folder-open"></i> Voir mes propositions en attente
            </a>
        </div>

        <div style="overflow-x: auto;">
            <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--dash-border); font-size: 0.75rem; text-transform: uppercase; color: var(--dash-muted);">
                        <th style="padding: 0.85rem 1.25rem;">Événement</th>
                        <th style="padding: 0.85rem 1rem;">Date & Lieu</th>
                        <th style="padding: 0.85rem 1rem;">Billetterie & Remplissage</th>
                        <th style="padding: 0.85rem 1rem;">Recettes Brutes</th>
                        <th style="padding: 0.85rem 1rem;">Statut</th>
                        <th style="padding: 0.85rem 1.25rem; text-align: right;">Actions de Gestion</th>
                    </tr>
                </thead>
                <tbody style="font-size: 0.85rem;">
                    <?php if (count($all_events) > 0): ?>
                        <?php foreach ($all_events as $ev): ?>
                            <?php 
                                $vendus   = (int)$ev['tickets_vendus'];
                                $total    = (int)$ev['total_places'];
                                $restants = max(0, $total - $vendus);
                                $pct      = ($total > 0) ? round(($vendus / $total) * 100) : 0;
                                $is_actif = in_array($ev['statut'], ['actif', 'approuve'], true);
                                $is_soldout = ($total > 0 && $restants === 0);

                                $img_src = !empty($ev['image']) ? '../uploads/' . htmlspecialchars($ev['image']) : '../images/default-event.jpg';
                                $type_label = '';
                                if ($ev['type_vote'] === 'concours') $type_label = '🏆 Concours';
                                elseif ($ev['type_vote'] === 'realisation_evenement') $type_label = '🗳️ Vote Réalisation';
                                else $type_label = '🎟️ Billetterie';
                            ?>
                            <tr style="border-bottom: 1px solid var(--dash-border); transition: background 0.15s ease;">
                                <!-- Événement & Affiche -->
                                <td style="padding: 1rem 1.25rem;">
                                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                                        <img src="<?php echo $img_src; ?>" alt="Affiche" class="event-thumb" onerror="this.src='../images/default-event.jpg';">
                                        <div>
                                            <strong style="color: var(--dash-text); font-weight: 700; display: block; font-size: 0.92rem;">
                                                <?php echo htmlspecialchars($ev['nom']); ?>
                                            </strong>
                                            <div style="color: var(--dash-muted); font-size: 0.78rem; display: flex; align-items: center; gap: 6px; margin-top: 3px; flex-wrap: wrap;">
                                                <span style="background: #f1f5f9; color: var(--dash-text); padding: 1px 6px; border-radius: 4px; font-weight: 600; font-size: 0.72rem;">
                                                    <?php echo htmlspecialchars($ev['categorie']); ?>
                                                </span>
                                                <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; font-weight: 700; font-size: 0.72rem;">
                                                    <?php echo $type_label; ?>
                                                </span>
                                                <?php if ($ev['nb_agents'] > 0): ?>
                                                    <span style="color: #059669; font-size: 0.74rem;">
                                                        <i class="fa-solid fa-shield-halved"></i> <?php echo $ev['nb_agents']; ?> agent(s)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Date & Lieu -->
                                <td style="padding: 1rem;">
                                    <div style="color: var(--dash-text); font-weight: 600; font-size: 0.84rem;">
                                        <i class="fa-regular fa-calendar" style="color: var(--dash-primary); margin-right: 4px;"></i>
                                        <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                    </div>
                                    <small style="color: var(--dash-muted); font-size: 0.78rem; display: block; margin-top: 2px;">
                                        <i class="fa-regular fa-clock"></i> <?php echo substr($ev['heure'], 0, 5); ?>
                                        • <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ev['lieu']); ?>
                                    </small>
                                </td>

                                <!-- Billetterie & Remplissage -->
                                <td style="padding: 1rem; min-width: 190px;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.82rem;">
                                        <strong style="color: #0284c7; font-size: 0.9rem;">
                                            <?php echo $vendus; ?> vendu(s)
                                        </strong>
                                        <span style="color: var(--dash-muted); font-size: 0.75rem;">
                                            <?php echo $restants; ?> restant(s) / <?php echo $total; ?>
                                        </span>
                                    </div>
                                    <div class="event-progress-bar">
                                        <div class="event-progress-fill" style="width: <?php echo min(100, $pct); ?>%; background: <?php echo $is_soldout ? '#ef4444' : ($pct > 80 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 3px;">
                                        <small style="color: var(--dash-muted); font-size: 0.72rem;">Rempli à <?php echo $pct; ?>%</small>
                                        <?php if ($ev['total_scans'] > 0): ?>
                                            <small style="color: #059669; font-size: 0.72rem; font-weight: 700;">
                                                <i class="fa-solid fa-qrcode"></i> <?php echo $ev['total_scans']; ?> scanné(s)
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Recettes -->
                                <td style="padding: 1rem;">
                                    <strong style="color: #059669; font-size: 0.95rem; display: block;">
                                        <?php echo number_format($ev['total_recette'], 0, ',', ' '); ?> F
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.72rem;">Com. plateforme: <?php echo (float)$ev['commission_rate']; ?>%</small>
                                </td>

                                <!-- Statut -->
                                <td style="padding: 1rem;">
                                    <?php if ($ev['statut'] === 'termine'): ?>
                                        <span style="background: #e2e8f0; color: #475569; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-flag-checkered"></i> Terminé
                                        </span>
                                    <?php elseif ($is_soldout): ?>
                                        <span style="background: #fee2e2; color: #b91c1c; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-ban"></i> Complet
                                        </span>
                                    <?php elseif ($is_actif): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-circle-dot" style="color: #10b981;"></i> En vente
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: var(--dash-muted); padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars(ucfirst($ev['statut'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions de Gestion -->
                                <td style="padding: 1rem 1.25rem; text-align: right;">
                                    <div style="display: inline-flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end;">
                                        <!-- Modifier -->
                                        <button type="button" class="event-action-btn" title="Modifier l'événement" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ev)); ?>)">
                                            <i class="fa-solid fa-pen-to-square"></i> Modifier
                                        </button>

                                        <!-- Agents -->
                                        <a href="agents.php?event_id=<?php echo $ev['id']; ?>" class="event-action-btn" title="Gérer les agents de scan pour cet événement">
                                            <i class="fa-solid fa-shield-halved"></i> Agents
                                        </a>

                                        <!-- Ventes -->
                                        <a href="mes-ventes.php?event_id=<?php echo $ev['id']; ?>" class="event-action-btn" title="Consulter la liste des acheteurs et ventes">
                                            <i class="fa-solid fa-receipt"></i> Ventes
                                        </a>

                                        <!-- Clôturer / Réactiver -->
                                        <?php if ($is_actif): ?>
                                            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Voulez-vous clôturer la billetterie de « <?php echo htmlspecialchars(addslashes($ev['nom'])); ?> » ?');">
                                                <input type="hidden" name="action_cloturer" value="1">
                                                <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                <button type="submit" class="event-action-btn btn-danger" title="Clôturer la billetterie">
                                                    <i class="fa-solid fa-stop"></i> Clôturer
                                                </button>
                                            </form>
                                        <?php elseif ($ev['statut'] === 'termine'): ?>
                                            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Réactiver cet événement et rouvrir sa billetterie ?');">
                                                <input type="hidden" name="action_reactiver" value="1">
                                                <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                <button type="submit" class="event-action-btn" style="color: #10b981;" title="Réactiver la billetterie">
                                                    <i class="fa-solid fa-play"></i> Réactiver
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
                                <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                                <strong style="display: block; font-size: 1rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun événement trouvé</strong>
                                <p style="font-size: 0.82rem; margin: 0 0 1rem;">Vous n'avez aucun événement correspondant aux filtres sélectionnés.</p>
                                <a href="demande-evenement.php" class="dash-btn-action btn-primary" style="display: inline-flex; text-decoration: none;">
                                    <i class="fa-solid fa-plus"></i> Proposer un Événement
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==============================================================================
     MODAL DE MODIFICATION D'UN ÉVÉNEMENT
     ============================================================================== -->
<div id="modalEditEvent" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 540px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i> Modifier l'Événement
            </h3>
            <button type="button" onclick="closeEditModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="mes-evenements.php" enctype="multipart/form-data" style="padding: 1.5rem; overflow-y: auto;">
            <input type="hidden" name="action_modifier" value="1">
            <input type="hidden" name="event_id" id="edit_event_id" value="">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom / Titre de l'événement *
                </label>
                <input type="text" name="nom" id="edit_nom" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Date de l'événement *
                    </label>
                    <input type="date" name="date_evenement" id="edit_date" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Heure de début *
                    </label>
                    <input type="time" name="heure" id="edit_heure" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Lieu / Salle *
                </label>
                <input type="text" name="lieu" id="edit_lieu" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description du programme
                </label>
                <textarea name="description" id="edit_desc" rows="4" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Changer l'affiche (Optionnel)
                </label>
                <input type="file" name="image" accept="image/*" style="width: 100%; font-size: 0.82rem;">
                <small style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;">Format JPG, PNG ou WEBP conseillé.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--dash-border);">
                <button type="button" onclick="closeEditModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem;">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(eventData) {
    document.getElementById('edit_event_id').value = eventData.id;
    document.getElementById('edit_nom').value = eventData.nom;
    document.getElementById('edit_date').value = eventData.date_evenement;
    document.getElementById('edit_heure').value = (eventData.heure || '').substring(0, 5);
    document.getElementById('edit_lieu').value = eventData.lieu;
    document.getElementById('edit_desc').value = eventData.description || '';
    document.getElementById('modalEditEvent').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('modalEditEvent').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalEditEvent');
    if (e.target === m) closeEditModal();
});
</script>

<?php include 'footer.php'; ?>
