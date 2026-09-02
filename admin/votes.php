<?php
// ==============================================================================
// GESTION & SUPERVISION DES VOTES ET CONCOURS (admin/votes.php)
// Design Dashboard Pro - Contrôle centralisé de tous les votes et concours de la plateforme
// ==============================================================================

$admin_page_title = "Gestion des Votes & Concours - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Traitement des actions Administrateur (Modération du vote, activation/clôture)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_vote_admin'])) {
    $ev_id = (int)($_POST['event_id'] ?? 0);
    $action_vote = $_POST['action_vote_admin'];

    if ($action_vote === 'cloturer') {
        $stmt = $pdo->prepare("UPDATE events SET type_vote = 'ferme' WHERE id = ?");
        $stmt->execute([$ev_id]);
        $message = "Le vote pour cet événement a été clôturé avec succès.";
        $msg_type = "success";
    } elseif ($action_vote === 'reactiver') {
        $nv_type = $_POST['nouveau_type_vote'] ?? 'concours';
        $stmt = $pdo->prepare("UPDATE events SET type_vote = ? WHERE id = ?");
        $stmt->execute([$nv_type, $ev_id]);
        $message = "Le vote pour cet événement a été réactivé.";
        $msg_type = "success";
    }
}

// 2. Filtres avancés
$type_filter   = $_GET['type'] ?? 'tous';
$statut_filter = $_GET['statut'] ?? 'tous';
$periode       = $_GET['periode'] ?? 'tous';
$search        = trim($_GET['q'] ?? '');

$sql = "
    SELECT e.id, e.nom, e.categorie, e.image, e.date_evenement, e.lieu, e.prix_vote, e.type_vote, e.statut as event_statut,
           u.nom as promoteur_nom, u.email as promoteur_email, p.nom_commercial,
           (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) as total_votes,
           (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) as total_likes,
           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') as total_recette_votes,
           (SELECT COUNT(*) FROM event_candidats c WHERE c.event_id = e.id) as nb_candidats
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN promoters p ON u.id = p.user_id
    WHERE (e.type_vote IS NOT NULL AND e.type_vote != 'aucun' AND e.type_vote != '')
";
$params = [];

if ($type_filter === 'concours') {
    $sql .= " AND e.type_vote = 'concours'";
} elseif ($type_filter === 'realisation') {
    $sql .= " AND e.type_vote = 'realisation'";
} elseif ($type_filter === 'payant') {
    $sql .= " AND e.prix_vote > 0";
} elseif ($type_filter === 'gratuit') {
    $sql .= " AND (e.prix_vote = 0 OR e.prix_vote IS NULL)";
}

if ($statut_filter === 'actif') {
    $sql .= " AND e.type_vote != 'ferme' AND e.statut = 'actif'";
} elseif ($statut_filter === 'termine') {
    $sql .= " AND (e.type_vote = 'ferme' OR e.statut = 'termine')";
}

if ($periode === '7j') {
    $sql .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($periode === '30j') {
    $sql .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === 'futur') {
    $sql .= " AND e.date_evenement >= CURDATE()";
}

if (!empty($search)) {
    $sql .= " AND (e.nom LIKE ? OR u.nom LIKE ? OR p.nom_commercial LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY total_votes DESC, total_likes DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$votes_events = $stmt->fetchAll();

// KPIs globaux
$global_votes = (int)$pdo->query("SELECT COUNT(*) FROM event_votes")->fetchColumn();
$global_likes = (int)$pdo->query("SELECT COUNT(*) FROM event_likes")->fetchColumn();
$global_ca_votes = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM vote_paiements WHERE statut = 'paye'")->fetchColumn();
$nb_concours_actifs = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE type_vote IN ('concours', 'realisation') AND statut = 'actif'")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-ranking-star" style="color: #ca8a04; font-size: 1.55rem;"></i>
                Supervision des Votes & Concours
            </h1>
            <p>Contrôle global des suffrages, des compétitions de candidats et des recettes de votes payants.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <button type="button" class="dash-btn-action" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimer Rapport
            </button>
            <a href="evenements.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-calendar-days"></i> Voir les Événements
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT (AU-DESSUS DES KPIS)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : ONGLETS TYPOLOGIE -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?type=tous&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.42rem 0.85rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $type_filter === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-layer-group" style="<?php echo $type_filter === 'tous' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous
            </a>

            <a href="?type=concours&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.42rem 0.85rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $type_filter === 'concours' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-trophy" style="color: #ca8a04;"></i> Concours
            </a>

            <a href="?type=realisation&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.42rem 0.85rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $type_filter === 'realisation' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-star" style="color: #0284c7;"></i> Réalisations
            </a>

            <a href="?type=payant&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.42rem 0.85rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $type_filter === 'payant' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-coins" style="color: #10b981;"></i> Votes Payants
            </a>

            <a href="?type=gratuit&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.42rem 0.85rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $type_filter === 'gratuit' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-gift" style="color: #0d9488;"></i> Gratuits
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS & RECHERCHE -->
        <form method="GET" action="votes.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">

            <select name="statut" onchange="this.form.submit()" style="padding: 0.4rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="tous" <?php echo $statut_filter === 'tous' ? 'selected' : ''; ?>>Tous statuts</option>
                <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>🟢 En cours</option>
                <option value="termine" <?php echo $statut_filter === 'termine' ? 'selected' : ''; ?>>🏁 Clôturés</option>
            </select>

            <select name="periode" onchange="this.form.submit()" style="padding: 0.4rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="tous" <?php echo $periode === 'tous' ? 'selected' : ''; ?>>Toute période</option>
                <option value="7j" <?php echo $periode === '7j' ? 'selected' : ''; ?>>7 derniers jours</option>
                <option value="30j" <?php echo $periode === '30j' ? 'selected' : ''; ?>>30 derniers jours</option>
                <option value="futur" <?php echo $periode === 'futur' ? 'selected' : ''; ?>>À venir</option>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Titre ou promoteur..." style="padding: 0.4rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 150px; background: #ffffff;">

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.75rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($type_filter !== 'tous' || $statut_filter !== 'tous' || $periode !== 'tous' || $search !== ''): ?>
                <a href="votes.php" style="color: #ef4444; font-size: 0.76rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- BANDEAU ZONE ACTIVE EXPLICITE -->
    <div style="background: linear-gradient(90deg, rgba(15, 23, 42, 0.04), rgba(15, 23, 42, 0.01)); border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 10px; padding: 0.6rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; font-size: 0.82rem; color: #475569;">
        <div>
            <i class="fa-solid fa-crosshairs" style="color: var(--dash-primary); margin-right: 6px;"></i>
            <strong>Zone Active :</strong>
            <span>Typologie : <strong style="color: var(--dash-text);"><?php echo ucfirst($type_filter); ?></strong></span> ·
            <span>Statut : <strong style="color: var(--dash-text);"><?php echo ucfirst($statut_filter); ?></strong></span> ·
            <span>Période : <strong style="color: var(--dash-text);"><?php echo ucfirst($periode); ?></strong></span>
        </div>
        <span style="background: #e2e8f0; color: #1e293b; padding: 2px 8px; border-radius: 999px; font-weight: 800; font-size: 0.75rem;">
            <?php echo count($votes_events); ?> événement(s) listé(s)
        </span>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE SUPERVISION (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: clamp(0.75rem, 2vw, 1rem); margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Suffrages Exprimés</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-check-to-slot"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($global_votes, 0, ',', ' '); ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Total des votes enregistrés sur la plateforme</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Recettes Votes Payants</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($global_ca_votes, 0, ',', ' '); ?> F</div>
            <small style="color: #16a34a; font-size: 0.75rem;">Chiffre d'affaires encaissé via Mobile Money</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ca8a04; text-transform: uppercase;">Compétitions Actives</span>
                <span style="background: #fef9c3; color: #ca8a04; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-trophy"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ca8a04;"><?php echo $nb_concours_actifs; ?></div>
            <small style="color: #ca8a04; font-size: 0.75rem;">Événements ouverts aux votes en ce moment</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ef4444; text-transform: uppercase;">Coups de Cœur (Likes)</span>
                <span style="background: #fee2e2; color: #ef4444; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-heart"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ef4444;"><?php echo number_format($global_likes, 0, ',', ' '); ?></div>
            <small style="color: #ef4444; font-size: 0.75rem;">Engagement direct des visiteurs</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DE CONTRÔLE ET D'ARBITRAGE DES VOTES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Événements avec Système de Vote (<?php echo count($votes_events); ?>)
            </h3>
        </div>

        <?php if (empty($votes_events)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-trophy" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun concours ou vote ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Typologie</th>
                            <th>Tarification</th>
                            <th>Candidats</th>
                            <th>Suffrages & Likes</th>
                            <th>Recettes</th>
                            <th>Statut du Vote</th>
                            <th style="text-align: right;">Arbitrage Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($votes_events as $ve): ?>
                            <?php
                            $is_closed = ($ve['type_vote'] === 'ferme' || $ve['event_statut'] === 'termine');
                            $type_badge = ($ve['type_vote'] === 'concours') ? ['🏆 Concours', '#fef9c3', '#b45309'] : ['🗳️ Réalisation', '#e0f2fe', '#0369a1'];
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($ve['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ve['date_evenement'])); ?> · <?php echo htmlspecialchars($ve['lieu']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem; display: block;">
                                        <?php echo htmlspecialchars($ve['nom_commercial'] ?: $ve['promoteur_nom']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($ve['promoteur_email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="background: <?php echo $type_badge[1]; ?>; color: <?php echo $type_badge[2]; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $type_badge[0]; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($ve['prix_vote'] > 0): ?>
                                        <span style="font-weight: 800; color: #16a34a; font-size: 0.82rem;">
                                            <i class="fa-solid fa-coins"></i> <?php echo number_format((float)$ve['prix_vote'], 0, ',', ' '); ?> F/vote
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 0.78rem; font-weight: 700;">
                                            <i class="fa-solid fa-gift"></i> Gratuit
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 700; font-size: 0.85rem; color: var(--dash-text);">
                                        <?php echo (int)$ve['nb_candidats']; ?> inscrit(s)
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 800; color: #0284c7; font-size: 0.92rem;">
                                        <?php echo number_format((int)$ve['total_votes'], 0, ',', ' '); ?> votes
                                    </div>
                                    <small style="color: #ef4444; font-size: 0.76rem; font-weight: 700;">
                                        <i class="fa-solid fa-heart"></i> <?php echo (int)$ve['total_likes']; ?> likes
                                    </small>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.92rem;">
                                        <?php echo number_format((float)$ve['total_recette_votes'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($is_closed): ?>
                                        <span style="background: #f1f5f9; color: #64748b; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                            🏁 Clos
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                            🟢 En cours
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 5px;">
                                        <?php if (!$is_closed): ?>
                                            <form method="POST" onsubmit="return confirm('Voulez-vous clôturer immédiatement ce vote ? Les utilisateurs ne pourront plus voter.');" style="margin: 0;">
                                                <input type="hidden" name="event_id" value="<?php echo $ve['id']; ?>">
                                                <input type="hidden" name="action_vote_admin" value="cloturer">
                                                <button type="submit" class="dash-btn-action" style="padding: 0.35rem 0.65rem; font-size: 0.74rem; background: #fee2e2; color: #ef4444;" title="Clôturer le vote">
                                                    <i class="fa-solid fa-lock"></i> Clôturer
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="event_id" value="<?php echo $ve['id']; ?>">
                                                <input type="hidden" name="action_vote_admin" value="reactiver">
                                                <input type="hidden" name="nouveau_type_vote" value="concours">
                                                <button type="submit" class="dash-btn-action" style="padding: 0.35rem 0.65rem; font-size: 0.74rem; background: #dcfce7; color: #166534;" title="Réactiver le vote">
                                                    <i class="fa-solid fa-unlock"></i> Réactiver
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="../client/accueil.php" target="_blank" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.74rem;" title="Voir côté public">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
