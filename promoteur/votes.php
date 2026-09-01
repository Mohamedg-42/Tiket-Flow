<?php
// ==============================================================================
// GESTION DES CONCOURS & VOTES (promoteur/votes.php)
// Design Dashboard Pro - Filtres avancés en haut, classement des candidats en direct & recettes
// ==============================================================================

$page_title = "Gestion des Concours & Votes - Espace Promoteur";
include 'header.php';

$user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Traitement : Ajout d'un candidat à un concours
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_candidat'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    // Vérifier que l'événement appartient bien au promoteur
    $stmt_chk = $pdo->prepare("SELECT id FROM events WHERE id = ? AND user_id = ?");
    $stmt_chk->execute([$event_id, $user_id]);
    $ev_ok = $stmt_chk->fetch();

    if (!$ev_ok) {
        $message = "Événement ou concours non valide ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom)) {
        $message = "Le nom du candidat ou participant est obligatoire.";
        $msg_type = "error";
    } else {
        // Upload photo
        $photo_name = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
            }
        }

        $stmt_ins = $pdo->prepare("
            INSERT INTO event_candidats (event_id, nom, description, photo, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt_ins->execute([$event_id, $nom, $desc ?: null, $photo_name]);

        $message = "Le candidat « " . htmlspecialchars($nom) . " » a été ajouté avec succès au concours !";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 2. Traitement : Modification d'un candidat
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_candidat'])) {
    $candidat_id = filter_input(INPUT_POST, 'candidat_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    // Vérifier autorisation
    $stmt_chk = $pdo->prepare("
        SELECT c.id, c.photo, c.event_id 
        FROM event_candidats c 
        JOIN events e ON c.event_id = e.id 
        WHERE c.id = ? AND e.user_id = ?
    ");
    $stmt_chk->execute([$candidat_id, $user_id]);
    $c_row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if (!$c_row) {
        $message = "Candidat introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom)) {
        $message = "Le nom du candidat ne peut pas être vide.";
        $msg_type = "error";
    } else {
        $photo_name = $c_row['photo'];
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
            }
        }

        $stmt_upd = $pdo->prepare("
            UPDATE event_candidats 
            SET nom = ?, description = ?, photo = ? 
            WHERE id = ?
        ");
        $stmt_upd->execute([$nom, $desc ?: null, $photo_name, $candidat_id]);

        $message = "Les informations de « " . htmlspecialchars($nom) . " » ont été mises à jour.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 3. Traitement : Suppression d'un candidat
// ------------------------------------------------------------------------------
if (isset($_GET['delete_candidat'])) {
    $del_id = filter_input(INPUT_GET, 'delete_candidat', FILTER_VALIDATE_INT);
    $stmt_chk = $pdo->prepare("
        SELECT c.id, c.nom 
        FROM event_candidats c 
        JOIN events e ON c.event_id = e.id 
        WHERE c.id = ? AND e.user_id = ?
    ");
    $stmt_chk->execute([$del_id, $user_id]);
    $c_del = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if ($c_del) {
        $pdo->prepare("DELETE FROM event_candidats WHERE id = ?")->execute([$del_id]);
        $message = "Le candidat « " . htmlspecialchars($c_del['nom']) . " » a été retiré de la compétition.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 4. Filtres Avancés (Typologie, Concours, Statut, Période, Recherche)
// ------------------------------------------------------------------------------
$type_filter = $_GET['type'] ?? 'tous';
if (!in_array($type_filter, ['tous', 'concours', 'realisation', 'payant', 'gratuit'], true)) {
    $type_filter = 'tous';
}

$statut_filter = $_GET['statut'] ?? 'tous';
if (!in_array($statut_filter, ['tous', 'actif', 'termine'], true)) {
    $statut_filter = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$search_q = trim($_GET['q'] ?? '');

// Liste complète de tous les concours/événements pour le sélecteur
$stmt_all_evs = $pdo->prepare("
    SELECT id, nom, type_vote 
    FROM events 
    WHERE user_id = ? 
      AND (type_vote IS NOT NULL OR categorie IN ('concours', 'vote') OR prix_vote > 0)
    ORDER BY nom ASC
");
$stmt_all_evs->execute([$user_id]);
$all_promoter_events = $stmt_all_evs->fetchAll(PDO::FETCH_ASSOC);

// Construction de la requête avec tous les filtres
$sql_events = "
    SELECT e.*,
           (SELECT COUNT(*) FROM event_votes ev WHERE ev.event_id = e.id) AS total_votes,
           (SELECT COUNT(*) FROM event_candidats ec WHERE ec.event_id = e.id) AS total_candidats,
           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') AS recettes_votes
    FROM events e
    WHERE e.user_id = ? 
      AND (e.type_vote IS NOT NULL OR e.categorie IN ('concours', 'vote') OR e.prix_vote > 0)
";
$params_events = [$user_id];

if ($filter_event) {
    $sql_events .= " AND e.id = ?";
    $params_events[] = $filter_event;
}

if ($type_filter === 'concours') {
    $sql_events .= " AND e.type_vote = 'concours'";
} elseif ($type_filter === 'realisation') {
    $sql_events .= " AND e.type_vote = 'realisation_evenement'";
} elseif ($type_filter === 'payant') {
    $sql_events .= " AND e.prix_vote > 0";
} elseif ($type_filter === 'gratuit') {
    $sql_events .= " AND (e.prix_vote = 0 OR e.prix_vote IS NULL)";
}

if ($statut_filter === 'actif') {
    $sql_events .= " AND e.statut IN ('actif', 'approuve')";
} elseif ($statut_filter === 'termine') {
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
    $sql_events .= " AND (e.nom LIKE ? OR e.vote_question LIKE ? OR EXISTS (SELECT 1 FROM event_candidats ec2 WHERE ec2.event_id = e.id AND (ec2.nom LIKE ? OR ec2.description LIKE ?)))";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
}

$sql_events .= " ORDER BY (e.statut = 'actif' OR e.statut = 'approuve') DESC, e.date_evenement DESC";

$stmt_evs = $pdo->prepare($sql_events);
$stmt_evs->execute($params_events);
$vote_events = $stmt_evs->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 5. Calculs KPI Globaux (selon filtrage courant)
// ------------------------------------------------------------------------------
$kpi_nb_concours = count($vote_events);
$kpi_total_votes = array_sum(array_column($vote_events, 'total_votes'));
$kpi_total_candidats = array_sum(array_column($vote_events, 'total_candidats'));
$kpi_recettes_votes = array_sum(array_column($vote_events, 'recettes_votes'));
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
    .candidat-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.85rem 1rem;
        background: #f8fafc;
        border: 1px solid var(--dash-border);
        border-radius: 12px;
        margin-bottom: 0.65rem;
        transition: all 0.2s ease;
    }

    .candidat-card:hover {
        background: #ffffff;
        border-color: var(--dash-primary);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .candidat-photo {
        width: 52px;
        height: 52px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid var(--dash-border);
        background: #e2e8f0;
        flex-shrink: 0;
    }

    .rank-badge {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.82rem;
        flex-shrink: 0;
    }

    .rank-1 {
        background: #fef3c7;
        color: #b45309;
        border: 1px solid #fde68a;
    }

    .rank-2 {
        background: #e2e8f0;
        color: #475569;
        border: 1px solid #cbd5e1;
    }

    .rank-3 {
        background: #ffedd5;
        color: #c2410c;
        border: 1px solid #fed7aa;
    }

    .rank-other {
        background: #f1f5f9;
        color: var(--dash-muted);
    }
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-trophy" style="color: #ca8a04; font-size: 1.55rem;"></i>
                Gestion des Concours & Votes
            </h1>
            <p>Supervisez vos compétitions, suivez le classement des candidats en direct et encaissez les recettes des
                votes en temps réel.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <button type="button" onclick="openAddCandidatModal()" class="dash-btn-action btn-primary"
                style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-user-plus"></i> Inscrire un Candidat
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES AVANCÉS SUR LA MÊME LIGNE (POSITIONNÉE TOUT EN HAUT)
         ============================================================================== -->
    <div
        style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : ONGLETS DE TYPOLOGIE -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?type=tous&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'tous' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-list"></i> Tous
            </a>

            <a href="?type=concours&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'concours' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-trophy" style="color: #ca8a04;"></i> Concours
            </a>

            <a href="?type=realisation&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'realisation' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-check-to-slot" style="color: #0284c7;"></i> Réalisation
            </a>

            <a href="?type=payant&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'payant' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-coins" style="color: #10b981;"></i> Payants
            </a>

            <a href="?type=gratuit&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'gratuit' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-gift" style="color: #8b5cf6;"></i> Gratuits
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS CONCOURS, STATUT, PÉRIODE & RECHERCHE -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">

            <!-- Sélecteur par Concours / Événement -->
            <select name="event_id" onchange="this.form.submit()"
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer; max-width: 170px;">
                <option value="">Tous mes concours</option>
                <?php foreach ($all_promoter_events as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>" <?php echo ($filter_event == $ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(mb_strimwidth($ev['nom'], 0, 24, '...')); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Sélecteur par Statut -->
            <select name="statut" onchange="this.form.submit()"
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="tous" <?php echo $statut_filter === 'tous' ? 'selected' : ''; ?>>Tous statuts</option>
                <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>Actifs / En cours
                </option>
                <option value="termine" <?php echo $statut_filter === 'termine' ? 'selected' : ''; ?>>Terminés</option>
            </select>

            <!-- Sélecteur PÉRIODE -->
            <div
                style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
                <i class="fa-regular fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="periode" onchange="this.form.submit()"
                    style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.3rem 0.2rem; outline: none;">
                    <option value="toutes" <?php echo $periode === 'toutes' ? 'selected' : ''; ?>>Toutes les dates
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

            <!-- Champ Recherche rapide -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass"
                    style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>"
                    placeholder="Candidat, concours..."
                    style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 145px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($type_filter !== 'tous' || $statut_filter !== 'tous' || $periode !== 'toutes' || $filter_event || $search_q !== ''): ?>
                <a href="votes.php"
                    style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. KPI CARDS : SYNTHÈSE CALCULÉE EN DIRECT (AU-DESSOUS DU FILTRE)
         ============================================================================== -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span
                    style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Concours
                    Filtrés</span>
                <span
                    style="background: #fef9c3; color: #ca8a04; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-trophy"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $kpi_nb_concours; ?>
            </div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Compétitions correspondantes</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Suffrages
                    Exprimés</span>
                <span
                    style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-check-to-slot"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;">
                <?php echo number_format($kpi_total_votes, 0, ',', ' '); ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Votes enregistrés au total</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #7c3aed; text-transform: uppercase;">Candidats
                    Inscrits</span>
                <span
                    style="background: #f3e8ff; color: #7c3aed; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #7c3aed;"><?php echo $kpi_total_candidats; ?></div>
            <small style="color: #7c3aed; font-size: 0.75rem;">Participants en lice</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #059669; text-transform: uppercase;">Recettes
                    des Votes</span>
                <span
                    style="background: #dcfce7; color: #059669; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #059669;">
                <?php echo number_format($kpi_recettes_votes, 0, ',', ' '); ?> F</div>
            <small style="color: #059669; font-size: 0.75rem;">Total encaissé par vote Mobile Money</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. CLASSEMENT & PARTICIPANTS PAR ÉVÉNEMENT
         ============================================================================== -->
    <?php if (count($vote_events) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1.75rem;">
            <?php foreach ($vote_events as $ev): ?>
                <?php
                // Récupérer les candidats de cet événement avec leur nombre de votes
                $stmt_cand = $pdo->prepare("
                    SELECT c.*,
                           (SELECT COUNT(*) FROM event_votes ev WHERE ev.candidat_id = c.id) AS nb_votes,
                           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.candidat_id = c.id AND vp.statut = 'paye') AS recettes_candidat
                    FROM event_candidats c
                    WHERE c.event_id = ?
                    ORDER BY nb_votes DESC, c.id ASC
                ");
                $stmt_cand->execute([$ev['id']]);
                $candidats = $stmt_cand->fetchAll(PDO::FETCH_ASSOC);

                $total_votes_event = (int) $ev['total_votes'];
                $prix_vote = (float) $ev['prix_vote'];
                $type_vote = $ev['type_vote'] ?? 'concours';
                ?>
                <div class="dash-card" style="padding: 1.5rem;">
                    <!-- En-tête de l'événement -->
                    <div
                        style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; border-bottom: 1px solid var(--dash-border); padding-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span
                                    style="background: <?php echo $type_vote === 'concours' ? '#fef9c3' : '#e0f2fe'; ?>; color: <?php echo $type_vote === 'concours' ? '#ca8a04' : '#0284c7'; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; text-transform: uppercase;">
                                    <?php echo $type_vote === 'concours' ? '🏆 Concours de Talents' : '🗳️ Vote Réalisation'; ?>
                                </span>
                                <span style="color: var(--dash-muted); font-size: 0.78rem;">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                </span>
                            </div>

                            <h2
                                style="margin: 0.35rem 0 0.25rem; color: var(--dash-text); font-size: 1.25rem; font-weight: 800;">
                                <?php echo htmlspecialchars($ev['nom']); ?>
                            </h2>

                            <?php if (!empty($ev['vote_question'])): ?>
                                <p style="margin: 0; color: var(--dash-muted); font-size: 0.84rem;">
                                    <i class="fa-solid fa-circle-question" style="color: var(--dash-primary);"></i>
                                    <strong>Question :</strong> « <?php echo htmlspecialchars($ev['vote_question']); ?> »
                                </p>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap;">
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; color: var(--dash-muted); display: block;">Prix du Vote</span>
                                <strong style="font-size: 1.05rem; color: var(--dash-text);">
                                    <?php echo $prix_vote > 0 ? number_format($prix_vote, 0, ',', ' ') . ' FCFA' : '<span style="color: #10b981;">Gratuit</span>'; ?>
                                </strong>
                            </div>

                            <div style="text-align: right; border-left: 1px solid var(--dash-border); padding-left: 1.25rem;">
                                <span style="font-size: 0.75rem; color: var(--dash-muted); display: block;">Total
                                    Recettes</span>
                                <strong style="font-size: 1.2rem; color: #059669; font-weight: 800;">
                                    <?php echo number_format((float) $ev['recettes_votes'], 0, ',', ' '); ?> F
                                </strong>
                            </div>

                            <button type="button" onclick="openAddCandidatModal(<?php echo $ev['id']; ?>)"
                                class="dash-btn-action"
                                style="padding: 0.45rem 0.85rem; font-size: 0.8rem; background: var(--dash-primary); color: #ffffff;">
                                <i class="fa-solid fa-plus"></i> Ajouter Candidat
                            </button>
                        </div>
                    </div>

                    <!-- Classement des Candidats -->
                    <h4
                        style="margin: 0 0 0.85rem; font-size: 0.92rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-ranking-star" style="color: #ca8a04;"></i>
                        Classement en direct (<?php echo count($candidats); ?>
                        candidat<?php echo count($candidats) > 1 ? 's' : ''; ?>)
                    </h4>

                    <?php if (count($candidats) > 0): ?>
                        <div style="display: flex; flex-direction: column;">
                            <?php
                            $rank = 1;
                            foreach ($candidats as $c):
                                $nb_v = (int) $c['nb_votes'];
                                $pct = $total_votes_event > 0 ? round(($nb_v / $total_votes_event) * 100, 1) : 0;
                                $rank_class = ($rank === 1) ? 'rank-1' : (($rank === 2) ? 'rank-2' : (($rank === 3) ? 'rank-3' : 'rank-other'));
                                $photo_url = !empty($c['photo']) ? '../uploads/candidats/' . htmlspecialchars($c['photo']) : '../images/default-avatar.png';
                                ?>
                                <div class="candidat-card">
                                    <div style="display: flex; align-items: center; gap: 0.85rem; flex: 1; min-width: 240px;">
                                        <!-- Rang -->
                                        <div class="rank-badge <?php echo $rank_class; ?>">
                                            <?php echo ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : '#' . $rank)); ?>
                                        </div>

                                        <!-- Photo -->
                                        <img src="<?php echo $photo_url; ?>" alt="Photo" class="candidat-photo"
                                            onerror="this.src='../images/default-avatar.png';">

                                        <!-- Info Candidat -->
                                        <div style="flex: 1;">
                                            <strong style="color: var(--dash-text); font-size: 0.95rem; display: block;">
                                                <?php echo htmlspecialchars($c['nom']); ?>
                                            </strong>
                                            <?php if (!empty($c['description'])): ?>
                                                <small
                                                    style="color: var(--dash-muted); font-size: 0.76rem; display: block; margin-top: 1px;">
                                                    <?php echo htmlspecialchars(mb_strimwidth($c['description'], 0, 70, '...')); ?>
                                                </small>
                                            <?php endif; ?>

                                            <!-- Jauge de vote -->
                                            <div
                                                style="background: #e2e8f0; height: 6px; border-radius: 999px; overflow: hidden; margin-top: 6px; max-width: 320px;">
                                                <div
                                                    style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, #ca8a04, #eab308); border-radius: 999px;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Score & Actions -->
                                    <div style="display: flex; align-items: center; gap: 1.25rem; flex-shrink: 0;">
                                        <div style="text-align: right;">
                                            <strong
                                                style="color: var(--dash-text); font-size: 1.1rem; font-weight: 800; display: block;">
                                                <?php echo number_format($nb_v, 0, ',', ' '); ?> <span
                                                    style="font-size: 0.8rem; font-weight: 600; color: var(--dash-muted);">votes</span>
                                            </strong>
                                            <small style="color: #ca8a04; font-weight: 800; font-size: 0.78rem;"><?php echo $pct; ?>%
                                                des voix</small>
                                        </div>

                                        <?php if ($prix_vote > 0): ?>
                                            <div style="text-align: right; border-left: 1px solid var(--dash-border); padding-left: 1rem;">
                                                <span style="font-size: 0.72rem; color: var(--dash-muted); display: block;">Recette</span>
                                                <strong style="color: #059669; font-size: 0.95rem; font-weight: 700;">
                                                    <?php echo number_format((float) $c['recettes_candidat'], 0, ',', ' '); ?> F
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Boutons Modifier / Supprimer -->
                                        <div style="display: flex; gap: 5px;">
                                            <button type="button" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.74rem;"
                                                onclick="openEditCandidatModal(<?php echo htmlspecialchars(json_encode($c)); ?>)"
                                                title="Modifier ce candidat">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="votes.php?delete_candidat=<?php echo $c['id']; ?>" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.74rem; color: #ef4444;"
                                                onclick="return confirm('Confirmez-vous le retrait de « <?php echo htmlspecialchars(addslashes($c['nom'])); ?> » du concours ?');"
                                                title="Supprimer">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $rank++;
                            endforeach;
                            ?>
                        </div>
                    <?php else: ?>
                        <div
                            style="text-align: center; padding: 2rem 1rem; color: var(--dash-muted); background: #f8fafc; border-radius: 10px; border: 1px dashed var(--dash-border);">
                            <i class="fa-solid fa-user-group"
                                style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                            Aucun candidat n'a encore été inscrit pour ce concours.<br>
                            <button type="button" onclick="openAddCandidatModal(<?php echo $ev['id']; ?>)"
                                class="dash-btn-action btn-primary"
                                style="margin-top: 0.75rem; padding: 0.4rem 0.85rem; font-size: 0.78rem;">
                                + Inscrire le premier candidat
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
            <i class="fa-solid fa-trophy"
                style="font-size: 2.75rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
            <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun
                événement de vote ou concours ne correspond à vos filtres</strong>
            <p style="font-size: 0.84rem; margin: 0 0 1.25rem;">Modifiez vos critères de recherche ou réinitialisez les
                filtres pour afficher l'ensemble de vos compétitions.</p>
            <a href="votes.php" class="dash-btn-action btn-primary" style="display: inline-flex; text-decoration: none;">
                <i class="fa-solid fa-rotate-left"></i> Réinitialiser les Filtres
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODAL : AJOUTER UN CANDIDAT
     ============================================================================== -->
<div id="modalAddCandidat"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div
        style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3
                style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-plus" style="color: var(--dash-primary);"></i> Inscrire un Candidat au
                Concours
            </h3>
            <button type="button" onclick="closeAddCandidatModal()"
                style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="votes.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <input type="hidden" name="action_add_candidat" value="1">

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Concours / Événement concerné *
                </label>
                <select name="event_id" id="add_candidat_event_id" required
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 700;">
                    <?php foreach ($all_promoter_events as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>">
                            <?php echo htmlspecialchars($ev['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom et Prénom du Candidat *
                </label>
                <input type="text" name="nom" required placeholder="Ex: Aïcha Koné, Numéro 04..."
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description / Bio / Numéro de dossard
                </label>
                <textarea name="description" rows="2"
                    placeholder="Ex: Candidate #04 représentant la région des Lagunes..."
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Photo officielle du candidat
                </label>
                <input type="file" name="photo" accept="image/*" style="width: 100%; font-size: 0.82rem;">
            </div>

            <div
                style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeAddCandidatModal()" class="dash-btn-action"
                    style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary"
                    style="padding: 0.55rem 1.25rem; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> Enregistrer le Candidat
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL : MODIFIER UN CANDIDAT
     ============================================================================== -->
<div id="modalEditCandidat"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div
        style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3
                style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i> Modifier le Candidat
            </h3>
            <button type="button" onclick="closeEditCandidatModal()"
                style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="votes.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <input type="hidden" name="action_edit_candidat" value="1">
            <input type="hidden" name="candidat_id" id="edit_candidat_id" value="">

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom et Prénom du Candidat *
                </label>
                <input type="text" name="nom" id="edit_candidat_nom" required
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description / Bio / Détails
                </label>
                <textarea name="description" id="edit_candidat_desc" rows="2"
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nouvelle photo (laisser vide pour conserver)
                </label>
                <input type="file" name="photo" accept="image/*" style="width: 100%; font-size: 0.82rem;">
            </div>

            <div
                style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeEditCandidatModal()" class="dash-btn-action"
                    style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary"
                    style="padding: 0.55rem 1.25rem; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> Enregistrer les Modifications
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddCandidatModal(eventId) {
        if (eventId) {
            document.getElementById('add_candidat_event_id').value = eventId;
        }
        document.getElementById('modalAddCandidat').style.display = 'flex';
    }
    function closeAddCandidatModal() {
        document.getElementById('modalAddCandidat').style.display = 'none';
    }

    function openEditCandidatModal(cand) {
        document.getElementById('edit_candidat_id').value = cand.id;
        document.getElementById('edit_candidat_nom').value = cand.nom;
        document.getElementById('edit_candidat_desc').value = cand.description || '';
        document.getElementById('modalEditCandidat').style.display = 'flex';
    }
    function closeEditCandidatModal() {
        document.getElementById('modalEditCandidat').style.display = 'none';
    }

    window.addEventListener('click', function (e) {
        const m1 = document.getElementById('modalAddCandidat');
        const m2 = document.getElementById('modalEditCandidat');
        if (e.target === m1) closeAddCandidatModal();
        if (e.target === m2) closeEditCandidatModal();
    });
</script>

<?php include 'footer.php'; ?>