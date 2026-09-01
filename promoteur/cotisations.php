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

        try {
            $stmt = $pdo->prepare("
                INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut)
                VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
            ");
            $stmt->execute([
                $user_id,
                $titre,
                $description ?: null,
                $image_name,
                $montant_objectif,
                $date_limite ?: null
            ]);
            $message = "Votre campagne « " . htmlspecialchars($titre) . " » a été soumise avec succès à l'administration.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
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
                      WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
    FROM cotisation_campagnes c
    WHERE c.user_id = ?
";
$params_camp = [$user_id];

if ($filter_statut !== 'tous') {
    $sql_camp .= " AND c.statut = ?";
    $params_camp[] = $filter_statut;
}

if ($periode === 'ce_mois') {
    $sql_camp .= " AND c.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_camp .= " AND c.created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_camp .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_camp .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_camp .= " AND (c.titre LIKE ? OR c.description LIKE ?)";
    $params_camp[] = "%$search_q%";
    $params_camp[] = "%$search_q%";
}

$sql_camp .= " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC";

$mes_campagnes = [];
$contributions_par_campagne = [];
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
    foreach ($mes_campagnes as $camp) {
        $stmt_ct->execute([$camp['id']]);
        $contributions_par_campagne[$camp['id']] = $stmt_ct->fetchAll(PDO::FETCH_ASSOC);
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
            return ['En attente admin', '#fef3c7', '#b45309', 'fa-solid fa-hourglass-half'];
        case 'active':
        case 'approuve':
            return ['Active & En ligne', '#dcfce7', '#166534', 'fa-solid fa-circle-check'];
        case 'terminee':
            return ['Terminée', '#e2e8f0', '#475569', 'fa-solid fa-flag-checkered'];
        case 'annulee':
        case 'refuse':
            return ['Refusée', '#fee2e2', '#b91c1c', 'fa-solid fa-ban'];
    }
    return [ucfirst($statut), '#f1f5f9', '#475569', 'fa-solid fa-info'];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.camp-thumb {
    width: 65px;
    height: 65px;
    border-radius: 12px;
    object-fit: cover;
    background: #f1f5f9;
    border: 1px solid var(--dash-border);
    flex-shrink: 0;
}
.camp-progress-bar {
    background: #e2e8f0;
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
                <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899; font-size: 1.55rem;"></i>
                Mes Campagnes de Cotisation & Financement
            </h1>
            <p>Mobilisez votre communauté pour co-financer vos productions, concerts ou causes. Suivez les contributions en temps réel.</p>
        </div>

        <div>
            <button type="button" onclick="openNewCampagneModal()" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-plus"></i> Créer une Campagne
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE DE COLLECTE
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Campagnes</span>
                <span style="background: #f1f5f9; color: var(--dash-text); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-layer-group"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $total_campagnes; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Collectes lancées</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ec4899; text-transform: uppercase;">Total Collecté</span>
                <span style="background: #fdf2f8; color: #ec4899; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ec4899;"><?php echo number_format($total_collecte_cumul, 0, ',', ' '); ?> F</div>
            <small style="color: #ec4899; font-size: 0.75rem;">Fonds déjà réunis</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #059669; text-transform: uppercase;">Objectif Cumulé</span>
                <span style="background: #dcfce7; color: #059669; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bullseye"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #059669;"><?php echo number_format($total_objectif_cumul, 0, ',', ' '); ?> F</div>
            <small style="color: #059669; font-size: 0.75rem;">Total des cibles financières</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Donateurs</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo $total_donateurs; ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Contributions individuelles</small>
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
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> En cours
            </a>

            <a href="?statut=en_attente&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'en_attente' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-hourglass-half" style="color: #f59e0b;"></i> En attente
            </a>

            <a href="?statut=terminee&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'terminee' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-flag-checkered" style="color: #64748b;"></i> Terminées
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
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Titre..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 150px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $search_q !== '' || $filter_statut !== 'tous'): ?>
                <a href="cotisations.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
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
                                    <div style="margin-top: 4px; color: #b91c1c; font-size: 0.75rem; background: #fee2e2; padding: 2px 8px; border-radius: 4px; display: inline-block;">
                                        <i class="fa-solid fa-comment-dots"></i> Motif admin : <?php echo htmlspecialchars($camp['commentaire_admin']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Montants collectés -->
                        <div style="text-align: right;">
                            <strong style="color: #ec4899; font-size: 1.35rem; font-weight: 800; display: block;">
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
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 0.45rem 0; font-weight: 600; color: var(--dash-text);"><?php echo htmlspecialchars($ct['nom']); ?></td>
                                                <td style="padding: 0.45rem; color: var(--dash-muted);"><?php echo htmlspecialchars($ct['telephone'] ?? '—'); ?></td>
                                                <td style="padding: 0.45rem; font-weight: 700; color: #ec4899;">+ <?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F</td>
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
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
            <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.75rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
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
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899;"></i> Nouvelle Campagne de Cotisation
            </h3>
            <button type="button" onclick="closeNewCampagneModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="cotisations.php" enctype="multipart/form-data" style="padding: 1.5rem; overflow-y: auto;">
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

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeNewCampagneModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 800; background: linear-gradient(135deg, #ec4899, #f43f5e); border: none;">
                    <i class="fa-solid fa-paper-plane"></i> Soumettre la Campagne
                </button>
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

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalNewCampagne');
    if (e.target === m) closeNewCampagneModal();
});
</script>

<?php include 'footer.php'; ?>