<?php
// ==============================================================================
// GESTION DES COTISATIONS / CAMPAGNES DE CONTRIBUTION (promoteur/cotisations.php)
// Design Dashboard Pro - Création, jauge de collecte & suivi des contributions
// ==============================================================================

$page_title = "Mes Cotisations & Financement - Espace Promoteur";
include 'header.php';

$message = "";
$msg_type = "";
$user_id = (int)$_SESSION['user_id'];

// ------------------------------------------------------------------------------
// 1. Traitement : Création d'une campagne de cotisation
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_campagne') {
    $titre            = trim($_POST['titre'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $montant_objectif = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
    $date_limite      = trim($_POST['date_limite'] ?? '');

    if ($titre === '' || !$montant_objectif || $montant_objectif < 1000) {
        $message = "Veuillez renseigner un titre et un montant objectif valide (minimum 1 000 FCFA).";
        $msg_type = "error";
    } else {
        // Upload de l'image
        $image_name = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_events = '../uploads/events/';
                if (!is_dir($upload_events)) {
                    mkdir($upload_events, 0777, true);
                }
                $image_name = 'campagne_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image_name);
            }
        }

        // Visibilité de la campagne
        $vis_p = ($_POST['visibilite_camp'] ?? 'public') === 'prive' ? 'prive' : 'public';
        $token_p = $vis_p === 'prive' ? bin2hex(random_bytes(16)) : null;

        try {
            // Vérifier si colonne visibilite existe
            $has_vis_p = false;
            try {
                $chk_p = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='cotisation_campagnes' AND column_name='visibilite'");
                $has_vis_p = (bool)$chk_p->fetchColumn();
            } catch(Exception $e) { $has_vis_p = false; }

            if ($has_vis_p) {
                $stmt = $pdo->prepare("
                    INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut, visibilite, access_token)
                    VALUES (?, ?, ?, ?, ?, ?, 'en_attente', ?, ?)
                ");
                $stmt->execute([$user_id, $titre, $description ?: null, $image_name, $montant_objectif, $date_limite ?: null, $vis_p, $token_p]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut)
                    VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
                ");
                $stmt->execute([$user_id, $titre, $description ?: null, $image_name, $montant_objectif, $date_limite ?: null]);
            }
            $message = "Votre campagne « " . htmlspecialchars($titre) . " » a été soumise avec succès à l'administration.";
            if ($vis_p === 'prive') {
                $message .= " Elle est marquée <strong>privée</strong> — le lien sera disponible après approbation.";
            }
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = friendly_db_error($e, 'campagne_cotisation', "Impossible de créer la campagne de cotisation. Veuillez vérifier vos informations et réessayer.");
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 1.1 Ajout d'un participant à la liste d'invités d'une campagne privée (Whitelist)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_participant_whitelist') {
    require_once __DIR__ . '/../includes/whitelist.php';
    $camp_id = filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $tel = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $stmt_check = $pdo->prepare("SELECT id, titre FROM cotisation_campagnes WHERE id = ? AND user_id = ?");
    $stmt_check->execute([$camp_id, $user_id]);
    $camp_own = $stmt_check->fetch();

    if (!$camp_own) {
        $message = "Campagne introuvable ou non autorisée.";
        $msg_type = "error";
    } elseif (empty($nom) || empty($tel)) {
        $message = "Le nom et le numéro de téléphone sont obligatoires.";
        $msg_type = "error";
    } else {
        $tel_norm = normalizePhone($tel);
        try {
            $stmt_ins_w = $pdo->prepare("
                INSERT INTO cotisation_whitelist (campagne_id, nom, prenom, telephone, email, ajoute_par)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (campagne_id, telephone) DO UPDATE SET
                    nom = EXCLUDED.nom, prenom = EXCLUDED.prenom, email = EXCLUDED.email
            ");
            $stmt_ins_w->execute([$camp_id, $nom, $prenom ?: null, $tel_norm, $email ?: null, $user_id]);
            $message = "Participant « " . htmlspecialchars($nom) . " » enregistré avec succès sur la liste autorisée.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = friendly_db_error($e, 'ajout_invite_campagne', "Impossible d'enregistrer ce participant. Veuillez vérifier son numéro de téléphone ou son email.");
            $msg_type = "error";
        }
    }
}

// 1.2 Suppression d'un participant de la whitelist
if (isset($_GET['supprimer_participant'])) {
    $part_id = filter_input(INPUT_GET, 'supprimer_participant', FILTER_VALIDATE_INT);
    if ($part_id) {
        $stmt_del = $pdo->prepare("
            UPDATE cotisation_whitelist 
            SET deleted_at = NOW()
            WHERE id = ? AND campagne_id IN (SELECT id FROM cotisation_campagnes WHERE user_id = ?)
        ");
        $stmt_del->execute([$part_id, $user_id]);
        $message = "Participant retiré de la liste avec succès.";
        $msg_type = "success";
    }
}

// 1.3 Modification d'un participant de la whitelist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier_participant_whitelist') {
    require_once __DIR__ . '/../includes/whitelist.php';
    $part_id = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
    $camp_id = filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $tel = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $stmt_check = $pdo->prepare("SELECT id FROM cotisation_campagnes WHERE id = ? AND user_id = ?");
    $stmt_check->execute([$camp_id, $user_id]);
    $camp_own = $stmt_check->fetch();

    if (!$camp_own) {
        $message = "Campagne introuvable ou non autorisée.";
        $msg_type = "error";
    } elseif (empty($nom) || empty($tel)) {
        $message = "Le nom et le numéro de téléphone sont obligatoires.";
        $msg_type = "error";
    } else {
        $tel_norm = normalizePhone($tel);
        try {
            $stmt_upd_w = $pdo->prepare("
                UPDATE cotisation_whitelist
                SET nom = ?, prenom = ?, telephone = ?, email = ?
                WHERE id = ? AND campagne_id = ?
            ");
            $stmt_upd_w->execute([$nom, $prenom ?: null, $tel_norm, $email ?: null, $part_id, $camp_id]);
            $message = "Informations de l'invité « " . htmlspecialchars($nom) . " » mises à jour avec succès.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = friendly_db_error($e, 'modif_invite_campagne', "Impossible de modifier les informations du participant. Veuillez vérifier les données saisies.");
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 2. Filtres (Statut, Période & Recherche)
// ------------------------------------------------------------------------------
$filter_statut = $_GET['statut'] ?? 'tous';
if (!in_array($filter_statut, ['tous', 'en_attente', 'active', 'terminee', 'annulee'], true)) {
    $filter_statut = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$search_q = trim($_GET['q'] ?? '');

$sql_camp = "
    SELECT c.*,
           COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                      WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
           COALESCE((SELECT COUNT(*) FROM cotisations ct
                      WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs,
            COALESCE((SELECT COUNT(*) FROM cotisation_whitelist cw
                       WHERE cw.campagne_id = c.id AND cw.deleted_at IS NULL), 0) AS nb_participants_whitelist
    FROM cotisation_campagnes c
    WHERE c.user_id = ? AND c.deleted_at IS NULL AND c.statut != 'supprime'
";
$params_camp = [$user_id];

if ($filter_statut !== 'tous') {
    $sql_camp .= " AND c.statut = ?";
    $params_camp[] = $filter_statut;
}

if ($periode === 'ce_mois') {
    $sql_camp .= " AND c.created_at >= date_trunc('month', CURRENT_TIMESTAMP)";
} elseif ($periode === 'cette_annee') {
    $sql_camp .= " AND c.created_at >= date_trunc('year', CURRENT_TIMESTAMP)";
} elseif ($periode === '30_jours') {
    $sql_camp .= " AND c.created_at >= (CURRENT_TIMESTAMP - INTERVAL '30 days')";
} elseif ($periode === '7_jours') {
    $sql_camp .= " AND c.created_at >= (CURRENT_TIMESTAMP - INTERVAL '7 days')";
}

if ($search_q !== '') {
    $sql_camp .= " AND (c.titre LIKE ? OR c.description LIKE ?)";
    $params_camp[] = "%$search_q%";
    $params_camp[] = "%$search_q%";
}

$sql_camp .= " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC";

$mes_campagnes = [];
$contributions_par_campagne = [];
$participants_par_campagne = [];
try {
    $stmt_c = $pdo->prepare($sql_camp);
    $stmt_c->execute($params_camp);
    $mes_campagnes = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    // Contributions récentes par campagne
    $stmt_ct = $pdo->prepare("
        SELECT campagne_id, nom, telephone, montant, statut, created_at
        FROM cotisations
        WHERE campagne_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");

    // Participants autorisés sur la whitelist par campagne
    $stmt_wl_p = $pdo->prepare("
        SELECT id, nom, prenom, telephone, email, created_at
        FROM cotisation_whitelist
        WHERE campagne_id = ? AND deleted_at IS NULL
        ORDER BY id DESC
    ");

    foreach ($mes_campagnes as $camp) {
        $stmt_ct->execute([$camp['id']]);
        $contributions_par_campagne[$camp['id']] = $stmt_ct->fetchAll(PDO::FETCH_ASSOC);

        if (($camp['visibilite'] ?? 'public') === 'prive') {
            $stmt_wl_p->execute([$camp['id']]);
            $participants_par_campagne[$camp['id']] = $stmt_wl_p->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $mes_campagnes = [];
}

// ------------------------------------------------------------------------------
// 3. Calculs KPI
// ------------------------------------------------------------------------------
$total_campagnes = count($mes_campagnes);
$total_collecte_cumul = array_sum(array_column($mes_campagnes, 'montant_collecte'));
$total_objectif_cumul = array_sum(array_column($mes_campagnes, 'montant_objectif'));
$total_donateurs = array_sum(array_column($mes_campagnes, 'nb_contributeurs'));

function get_cotisation_badge($statut) {
    switch ($statut) {
        case 'en_attente':
            return ['En attente admin', '#FFF2ED', '#FF4A0D', 'fa-solid fa-hourglass-half'];
        case 'active':
        case 'approuve':
            return ['Active & En ligne', '#FFF2ED', '#000000', 'fa-solid fa-circle-check'];
        case 'terminee':
            return ['Terminée', '#E5E5E5', '#737373', 'fa-solid fa-flag-checkered'];
        case 'annulee':
        case 'refuse':
            return ['Refusée', '#F5F5F5', '#000000', 'fa-solid fa-ban'];
    }
    return [ucfirst($statut), '#F5F5F5', '#737373', 'fa-solid fa-info'];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.camp-thumb {
    width: 65px;
    height: 65px;
    border-radius: 12px;
    object-fit: cover;
    background: #F5F5F5;
    border: 1px solid var(--dash-border);
    flex-shrink: 0;
}
.camp-progress-bar {
    background: #E5E5E5;
    border-radius: 999px;
    height: 8px;
    overflow: hidden;
    margin-top: 6px;
}
.camp-progress-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-hand-holding-heart" style="color: var(--tikeli-orange, #FF4A0D); font-size: 1.55rem;"></i>
                Mes Campagnes de Cotisation & Financement
            </h1>
            <p>Mobilisez votre communauté pour co-financer vos productions, concerts ou causes. Suivez les contributions en temps réel.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; align-items: center; flex-wrap: wrap;">
            <a href="export?type=cotisations" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter les campagnes et contributions sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: var(--tikeli-black, #000000);"></i> Exporter Excel
            </a>
            <button type="button" onclick="openNewCampagneModal()" class="eventia-btn-primary" style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; cursor: pointer;">
                <i class="fa-solid fa-plus"></i> Créer une Campagne
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="eventia-alert eventia-alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="margin-bottom: 1.5rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE DE COLLECTE
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Campagnes</span>
                <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-layer-group"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo $total_campagnes; ?></div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Collectes lancées</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--tikeli-orange, #FF4A0D); text-transform: uppercase;">Total Collecté</span>
                <span style="background: rgba(255, 74, 13, 0.12); color: var(--tikeli-orange, #FF4A0D); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--tikeli-orange, #FF4A0D);"><?php echo number_format($total_collecte_cumul, 0, ',', ' '); ?> F</div>
            <small style="color: var(--tikeli-orange, #FF4A0D); font-size: 0.75rem; font-weight: 600;">Fonds déjà réunis</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Objectif Cumulé</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bullseye"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo number_format($total_objectif_cumul, 0, ',', ' '); ?> F</div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Total des cibles financières</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Donateurs</span>
                <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo $total_donateurs; ?></div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Contributions individuelles</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES : STATUTS, PÉRIODE & RECHERCHE (SUR LA MÊME LIGNE)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Onglets Statuts -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'tous' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-list"></i> Toutes (<?php echo $total_campagnes; ?>)
            </a>

            <a href="?statut=active&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'active' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i> En cours
            </a>

            <a href="?statut=en_attente&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'en_attente' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-hourglass-half" style="color: #FF4A0D;"></i> En attente
            </a>

            <a href="?statut=terminee&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'terminee' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-flag-checkered" style="color: #737373;"></i> Terminées
            </a>
        </div>

        <!-- Filtre Période & Recherche sur la même ligne -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filter_statut); ?>">

            <!-- Sélecteur PÉRIODE -->
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
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
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Titre..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 150px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $search_q !== '' || $filter_statut !== 'tous'): ?>
                <a href="cotisations" style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. LISTE DES CAMPAGNES (FICHES DASHBOARD PRO)
         ============================================================================== -->
    <?php if (!empty($mes_campagnes)): ?>
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <?php foreach ($mes_campagnes as $camp): ?>
                <?php
                $collecte         = (float)$camp['montant_collecte'];
                $objectif         = (float)$camp['montant_objectif'];
                $pct_collecte     = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100)) : 0;
                $objectif_atteint = ($objectif > 0 && $collecte >= $objectif);
                [$badge_label, $badge_bg, $badge_color, $badge_icon] = get_cotisation_badge($camp['statut']);

                $img_src = !empty($camp['image']) ? '../uploads/events/' . htmlspecialchars($camp['image']) : '../images/default-event.jpg';
                ?>
                <div class="dash-card" style="padding: 1.35rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1.25rem; flex-wrap: wrap;">
                        <div style="display: flex; gap: 1rem; align-items: center; flex: 1; min-width: 280px;">
                            <img src="<?php echo $img_src; ?>" alt="Image Campagne" class="camp-thumb" onerror="this.src='../images/default-event.jpg';">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <h3 style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800;">
                                        <?php echo htmlspecialchars($camp['titre']); ?>
                                    </h3>
                                    <span style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="<?php echo $badge_icon; ?>"></i> <?php echo $badge_label; ?>
                                    </span>
                                    <?php $vis_pc = $camp['visibilite'] ?? 'public'; ?>
                                    <span style="font-size: 0.68rem; font-weight: 800; padding: 2px 8px; border-radius: 5px; background: <?php echo $vis_pc === 'prive' ? '#FFF2ED' : '#F0FDF4'; ?>; color: <?php echo $vis_pc === 'prive' ? '#FF4A0D' : '#166534'; ?>; display: inline-flex; align-items: center; gap: 3px;">
                                        <i class="fa-solid <?php echo $vis_pc === 'prive' ? 'fa-lock' : 'fa-globe'; ?>"></i>
                                        <?php echo $vis_pc === 'prive' ? 'Privée' : 'Publique'; ?>
                                    </span>
                                    <?php if ($vis_pc === 'prive' && !empty($camp['access_token']) && $camp['statut'] === 'active'): ?>
                                        <a href="../client/cotisation?id=<?php echo (int)$camp['id']; ?>&token=<?php echo htmlspecialchars($camp['access_token']); ?>" target="_blank" style="font-size: 0.68rem; color: #FF4A0D; text-decoration: underline; display: inline-flex; align-items: center; gap: 3px;" title="Lien privé à partager">
                                            <i class="fa-solid fa-link"></i> Lien privé
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <p style="margin: 4px 0; color: var(--dash-muted); font-size: 0.82rem; max-width: 550px;">
                                    <?php echo htmlspecialchars($camp['description'] ?: 'Aucune description spécifiée.'); ?>
                                </p>

                                <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo $camp['date_limite'] ? 'Date limite : ' . date('d/m/Y', strtotime($camp['date_limite'])) : 'Sans date limite'; ?>
                                    • Lancée le <?php echo date('d/m/Y', strtotime($camp['created_at'])); ?>
                                </small>

                                <?php if (!empty($camp['commentaire_admin'])): ?>
                                    <div style="margin-top: 4px; color: #000000; font-size: 0.75rem; background: #F5F5F5; padding: 2px 8px; border-radius: 4px; display: inline-block;">
                                        <i class="fa-solid fa-comment-dots"></i> Motif admin : <?php echo htmlspecialchars($camp['commentaire_admin']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Montants collectés -->
                        <div style="text-align: right;">
                            <strong style="color: #FF4A0D; font-size: 1.35rem; font-weight: 800; display: block;">
                                <?php echo number_format($collecte, 0, ',', ' '); ?> FCFA
                            </strong>
                            <small style="color: var(--dash-muted); font-size: 0.8rem;">
                                Objectif : <strong><?php echo number_format($objectif, 0, ',', ' '); ?> FCFA</strong>
                            </small>
                        </div>
                    </div>

                    <!-- Barre de progression -->
                    <div class="camp-progress-bar" style="margin-top: 1rem;">
                        <div class="camp-progress-fill" style="width: <?php echo $pct_collecte; ?>%; background: <?php echo $objectif_atteint ? '#10b981' : 'linear-gradient(90deg, #ec4899, #f43f5e)'; ?>;"></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: 0.78rem;">
                        <span style="color: var(--dash-muted); font-weight: 700;">
                            <?php echo $pct_collecte; ?>% atteint • <strong style="color: var(--dash-text);"><?php echo (int)$camp['nb_contributeurs']; ?></strong> donateur(s)
                            <?php if ($objectif_atteint): ?>
                                <span style="color: #10b981; margin-left: 6px;"><i class="fa-solid fa-circle-check"></i> Objectif accompli !</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Détail des 10 dernières contributions -->
                    <?php if (!empty($contributions_par_campagne[$camp['id']])): ?>
                        <details style="margin-top: 1rem; border-top: 1px dashed var(--dash-border); padding-top: 0.75rem;">
                            <summary style="cursor: pointer; font-weight: 700; color: var(--dash-primary); font-size: 0.82rem; user-select: none;">
                                <i class="fa-solid fa-chevron-down" style="font-size: 0.75rem; margin-right: 4px;"></i>
                                Voir les récentes contributions reçues (<?php echo count($contributions_par_campagne[$camp['id']]); ?>)
                            </summary>

                            <div style="overflow-x: auto; margin-top: 0.5rem;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                                    <thead>
                                        <tr style="color: var(--dash-muted); border-bottom: 1px solid var(--dash-border);">
                                            <th style="padding: 0.4rem 0;">Contributeur</th>
                                            <th style="padding: 0.4rem;">Téléphone</th>
                                            <th style="padding: 0.4rem;">Montant</th>
                                            <th style="padding: 0.4rem;">Statut</th>
                                            <th style="padding: 0.4rem; text-align: right;">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($contributions_par_campagne[$camp['id']] as $ct): ?>
                                            <tr style="border-bottom: 1px solid #F5F5F5;">
                                                <td style="padding: 0.45rem 0; font-weight: 600; color: var(--dash-text);"><?php echo htmlspecialchars($ct['nom']); ?></td>
                                                <td style="padding: 0.45rem; color: var(--dash-muted);"><?php echo htmlspecialchars($ct['telephone'] ?? '—'); ?></td>
                                                <td style="padding: 0.45rem; font-weight: 700; color: #10b981;">+ <?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F</td>
                                                <td style="padding: 0.45rem;">
                                                    <?php if ($ct['statut'] === 'payee'): ?>
                                                        <span style="color: #10b981; font-weight: 700;">Payée</span>
                                                    <?php else: ?>
                                                        <span style="color: #f59e0b; font-weight: 700;">En attente</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 0.45rem; text-align: right; color: var(--dash-muted);"><?php echo date('d/m/Y H:i', strtotime($ct['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>

                    <!-- Gestion Whitelist pour Campagne Privée -->
                    <?php if ($vis_pc === 'prive'): ?>
                        <?php 
                            $camp_participants = $participants_par_campagne[$camp['id']] ?? []; 
                            $link_prive = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\') . '/client/cotisation.php?id=' . (int)$camp['id'] . '&token=' . urlencode($camp['access_token'] ?? '');
                        ?>
                        <div style="margin-top: 1rem; border-top: 1px solid var(--dash-border); padding-top: 0.85rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 0.6rem;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 6px; background: #FFF2ED; color: #FF4A0D; font-size: 0.8rem;">
                                        <i class="fa-solid fa-user-shield"></i>
                                    </span>
                                    <div>
                                        <strong style="font-size: 0.86rem; color: var(--dash-text); display: block;">
                                            Liste des invités autorisés (<?php echo count($camp_participants); ?>)
                                        </strong>
                                        <small style="color: #737373; font-size: 0.72rem;">Seules les personnes enregistrées accédant avec le lien peuvent cotiser.</small>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <?php if (!empty($camp['access_token'])): ?>
                                        <button type="button" onclick="copyPrivateLink('<?php echo htmlspecialchars($link_prive, ENT_QUOTES); ?>', this)" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; border: 1px solid #FF4A0D; background: #FFF2ED; color: #FF4A0D; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-weight: 700;">
                                            <i class="fa-solid fa-link"></i> Copier le lien privé
                                        </button>
                                    <?php endif; ?>
                                    <a href="export?type=invites_cotisation&campagne_id=<?php echo (int)$camp['id']; ?>" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; border: 1px solid var(--dash-border); background: #ffffff; color: var(--dash-text); border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-weight: 700;" title="Télécharger la liste des invités autorisés en CSV (Excel)">
                                        <i class="fa-solid fa-file-export" style="color: #10B981;"></i> Exporter la liste
                                    </a>
                                </div>
                            </div>

                            <details style="background: #FAFAFA; border: 1px solid var(--dash-border); border-radius: 8px; padding: 0.75rem;">
                                <summary style="cursor: pointer; font-weight: 700; color: var(--dash-text); font-size: 0.82rem; user-select: none;">
                                    <i class="fa-solid fa-user-plus" style="color: #FF4A0D; margin-right: 4px;"></i> Inscrire un nouvel invité / Voir la liste complète
                                </summary>

                                <!-- Formulaire d'ajout rapide -->
                                <form method="POST" action="cotisations" style="margin-top: 0.75rem; background: #ffffff; border: 1px solid var(--dash-border); border-radius: 8px; padding: 0.85rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)) auto; gap: 8px; align-items: end;">
                                    <input type="hidden" name="action" value="ajouter_participant_whitelist">
                                    <input type="hidden" name="campagne_id" value="<?php echo (int)$camp['id']; ?>">

                                    <div>
                                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Nom *</label>
                                        <input type="text" name="nom" required placeholder="Ex: Kouassi" style="width: 100%; padding: 0.4rem 0.6rem; font-size: 0.8rem; border: 1px solid var(--dash-border); border-radius: 6px;">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Prénom</label>
                                        <input type="text" name="prenom" placeholder="Ex: Jean" style="width: 100%; padding: 0.4rem 0.6rem; font-size: 0.8rem; border: 1px solid var(--dash-border); border-radius: 6px;">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Téléphone *</label>
                                        <input type="tel" name="telephone" required placeholder="Ex: 0701020304" style="width: 100%; padding: 0.4rem 0.6rem; font-size: 0.8rem; border: 1px solid var(--dash-border); border-radius: 6px;">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Email (optionnel)</label>
                                        <input type="email" name="email" placeholder="jean@example.com" style="width: 100%; padding: 0.4rem 0.6rem; font-size: 0.8rem; border: 1px solid var(--dash-border); border-radius: 6px;">
                                    </div>
                                    <div>
                                        <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.45rem 0.9rem; font-size: 0.8rem; border-radius: 6px; white-space: nowrap; height: 33px;">
                                            <i class="fa-solid fa-plus"></i> Inscrire
                                        </button>
                                    </div>
                                </form>

                                <!-- Table des invités autorisés -->
                                <?php if (!empty($camp_participants)): ?>
                                    <div style="overflow-x: auto; margin-top: 0.75rem;">
                                        <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem; text-align: left;">
                                            <thead>
                                                <tr style="color: var(--dash-muted); border-bottom: 1px solid var(--dash-border); background: #f8fafc;">
                                                    <th style="padding: 0.4rem 0.6rem;">Invité</th>
                                                    <th style="padding: 0.4rem 0.6rem;">Téléphone autorisé</th>
                                                    <th style="padding: 0.4rem 0.6rem;">Email</th>
                                                    <th style="padding: 0.4rem 0.6rem;">Ajouté le</th>
                                                    <th style="padding: 0.4rem 0.6rem; text-align: right;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($camp_participants as $part): ?>
                                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                                        <td style="padding: 0.4rem 0.6rem; font-weight: 700; color: var(--dash-text);">
                                                            <?php echo htmlspecialchars($part['nom'] . ' ' . ($part['prenom'] ?? '')); ?>
                                                        </td>
                                                        <td style="padding: 0.4rem 0.6rem; font-family: monospace; font-weight: 700; color: #FF4A0D;">
                                                            <?php echo htmlspecialchars($part['telephone']); ?>
                                                        </td>
                                                        <td style="padding: 0.4rem 0.6rem; color: var(--dash-muted);">
                                                            <?php echo htmlspecialchars($part['email'] ?? '—'); ?>
                                                        </td>
                                                        <td style="padding: 0.4rem 0.6rem; color: var(--dash-muted);">
                                                            <?php echo date('d/m/Y H:i', strtotime($part['created_at'])); ?>
                                                        </td>
                                                        <td style="padding: 0.4rem 0.6rem; text-align: right; white-space: nowrap;">
                                                            <button type="button" 
                                                                onclick='openEditCotisationGuestModal(<?php echo json_encode([
                                                                    "id" => (int)$part["id"],
                                                                    "campagne_id" => (int)$camp["id"],
                                                                    "nom" => $part["nom"],
                                                                    "prenom" => $part["prenom"] ?? "",
                                                                    "telephone" => $part["telephone"],
                                                                    "email" => $part["email"] ?? ""
                                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' 
                                                                style="background: transparent; border: 0; color: #FF4A0D; cursor: pointer; font-size: 0.8rem; margin-right: 6px;" 
                                                                title="Modifier l'invité">
                                                                <i class="fa-solid fa-pen-to-square"></i>
                                                            </button>
                                                            <a href="cotisations?supprimer_participant=<?php echo (int)$part['id']; ?>" onclick="return confirm('Retirer cet invité de la liste autorisée ?');" style="color: #ef4444; text-decoration: none; font-size: 0.75rem;" title="Retirer l'invité">
                                                                <i class="fa-solid fa-trash-can"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top: 0.5rem; padding: 0.6rem; background: #fff; border-radius: 6px; font-size: 0.76rem; color: var(--dash-muted); text-align: center;">
                                        Aucun invité inscrit pour l'instant. Utilisez le formulaire ci-dessus pour autoriser des personnes.
                                    </div>
                                <?php endif; ?>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
            <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.75rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
            <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucune campagne de cotisation</strong>
            <p style="font-size: 0.84rem; margin: 0 0 1.25rem;">Lancez une campagne pour inviter votre public à soutenir vos projets artistiques ou événements.</p>
            <button type="button" onclick="openNewCampagneModal()" class="dash-btn-action btn-primary" style="display: inline-flex;">
                <i class="fa-solid fa-plus"></i> Créer ma première campagne
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODAL : CRÉER UNE CAMPAGNE DE COTISATION
     ============================================================================== -->
<div id="modalNewCampagne" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 540px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #F5F5F5;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D;"></i> Nouvelle Campagne de Cotisation
            </h3>
            <button type="button" onclick="closeNewCampagneModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="cotisations" enctype="multipart/form-data" style="padding: 1.5rem; overflow-y: auto;">
            <input type="hidden" name="action" value="creer_campagne">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Titre du projet / de la campagne *
                </label>
                <input type="text" name="titre" required placeholder="Ex: Financement Festival Nuits d'Abidjan 2026" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description & Objectifs
                </label>
                <textarea name="description" rows="3" placeholder="Expliquez à vos festivaliers pourquoi vous levez ces fonds..." style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; line-height: 1.4;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Montant Objectif (FCFA) *
                    </label>
                    <input type="number" name="montant_objectif" required min="1000" step="1000" placeholder="Ex: 2 000 000" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 700;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Date limite (Optionnelle)
                    </label>
                    <input type="date" name="date_limite" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Affiche / Visuel de la campagne
                </label>
                <input type="file" name="image" accept="image/*" style="width: 100%; font-size: 0.82rem;">
            </div>

            <!-- Visibilité -->
            <div class="dash-visibility-group" style="margin-bottom: 1.25rem;">
                <div class="dash-visibility-label">
                    <i class="fa-solid fa-eye" style="color: #FF4A0D;"></i> Visibilité de la campagne *
                </div>
                <div class="dash-visibility-options">
                    <label class="dash-visibility-card">
                        <input type="radio" name="visibilite_camp" value="public" checked onchange="syncVisibilityCards(this)">
                        <span class="option-title">Publique</span>
                        <span class="option-desc">(listée sur la plateforme)</span>
                    </label>
                    <label class="dash-visibility-card">
                        <input type="radio" name="visibilite_camp" value="prive" onchange="syncVisibilityCards(this)">
                        <span class="option-title">Privée</span>
                        <span class="option-desc">(lien direct uniquement)</span>
                    </label>
                </div>
                <small class="dash-visibility-hint">Une campagne <strong>privée</strong> n'est pas listée publiquement &mdash; lien unique généré après validation.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeNewCampagneModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 800; background: #FF4A0D; border: none;">
                    <i class="fa-solid fa-paper-plane"></i> Soumettre la Campagne
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modification Invité Cotisation -->
<div id="modalEditCotisationGuest" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-user-pen" style="color: #FF4A0D;"></i> Modifier l'invité
            </h3>
            <button type="button" onclick="closeEditCotisationGuestModal()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="cotisations" style="padding: 1.5rem;">
            <input type="hidden" name="action" value="modifier_participant_whitelist">
            <input type="hidden" name="campagne_id" id="edit_cot_campagne_id" value="">
            <input type="hidden" name="participant_id" id="edit_cot_participant_id" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom *</label>
                    <input type="text" name="nom" id="edit_cot_nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prénom</label>
                    <input type="text" name="prenom" id="edit_cot_prenom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Numéro de téléphone *</label>
                <input type="tel" name="telephone" id="edit_cot_telephone" required placeholder="Ex: 07 00 00 00 00" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Email (optionnel)</label>
                <input type="email" name="email" id="edit_cot_email" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="closeEditCotisationGuestModal()" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="background: #FF4A0D; border: none; font-weight: 700;">Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewCampagneModal() {
    document.getElementById('modalNewCampagne').style.display = 'flex';
}
function closeNewCampagneModal() {
    document.getElementById('modalNewCampagne').style.display = 'none';
}

function openEditCotisationGuestModal(part) {
    document.getElementById('edit_cot_campagne_id').value = part.campagne_id;
    document.getElementById('edit_cot_participant_id').value = part.id;
    document.getElementById('edit_cot_nom').value = part.nom || '';
    document.getElementById('edit_cot_prenom').value = part.prenom || '';
    document.getElementById('edit_cot_telephone').value = part.telephone || '';
    document.getElementById('edit_cot_email').value = part.email || '';
    document.getElementById('modalEditCotisationGuest').style.display = 'grid';
}

function closeEditCotisationGuestModal() {
    document.getElementById('modalEditCotisationGuest').style.display = 'none';
}

function copyPrivateLink(url, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            var orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
            btn.style.background = '#10B981';
            btn.style.color = '#fff';
            btn.style.borderColor = '#10B981';
            setTimeout(function() {
                btn.innerHTML = orig;
                btn.style.background = '#FFF2ED';
                btn.style.color = '#FF4A0D';
                btn.style.borderColor = '#FF4A0D';
            }, 2500);
        });
    } else {
        prompt('Copiez ce lien privé d\'accès :', url);
    }
}

function syncVisibilityCards(radio) {
    if (!radio) return;
    const group = radio.closest('.dash-visibility-options') || radio.closest('.dash-radio-cards');
    if (!group) return;
    group.querySelectorAll('.dash-visibility-card, .dash-radio-card').forEach(card => {
        const input = card.querySelector('input[type="radio"]');
        if (input && input.checked) {
            card.classList.add('is-active');
        } else {
            card.classList.remove('is-active');
        }
    });
}

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalNewCampagne');
    const mCotEdit = document.getElementById('modalEditCotisationGuest');
    if (e.target === m) closeNewCampagneModal();
    if (e.target === mCotEdit) closeEditCotisationGuestModal();
});
</script>

<?php include 'footer.php'; ?>