<?php
// ==============================================================================
// GESTION DU SUPPORT & RÉCLAMATIONS (promoteur/reclamations.php)
// Design Dashboard Pro - Dépôt de tickets, suivi des réponses & SLA prioritaire
// ==============================================================================

$page_title = "Support & Réclamations - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Traitement : Dépôt d'un nouveau ticket de réclamation
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_claim'])) {
    $sujet     = trim($_POST['sujet'] ?? '');
    $categorie = trim($_POST['categorie'] ?? 'general');
    $msg       = trim($_POST['message'] ?? '');

    if (!empty($categorie) && $categorie !== 'general') {
        $prefix = '[' . strtoupper($categorie) . '] ';
        if (!str_starts_with($sujet, $prefix)) {
            $sujet = $prefix . $sujet;
        }
    }

    if (empty($sujet) || empty($msg)) {
        $message = "Veuillez renseigner le sujet et le message détaillé de votre réclamation.";
        $msg_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("
            INSERT INTO claims (user_id, sujet, message, statut, created_at) 
            VALUES (?, ?, ?, 'en_attente', NOW())
        ");
        $stmt_ins->execute([$user_id, $sujet, $msg]);

        $message = "Votre ticket de réclamation a été transmis avec succès au support prioritaire Eventia.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 2. Traitement : Clôturer un ticket résolu par le promoteur
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_fermer_ticket'])) {
    $claim_id = filter_input(INPUT_POST, 'claim_id', FILTER_VALIDATE_INT);
    if ($claim_id) {
        $stmt_cls = $pdo->prepare("UPDATE claims SET statut = 'fermee' WHERE id = ? AND user_id = ?");
        $stmt_cls->execute([$claim_id, $user_id]);
        $message = "Le ticket a été marqué comme fermé.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 3. Filtres & Recherche
// ------------------------------------------------------------------------------
$filter_statut = $_GET['statut'] ?? 'tous';
if (!in_array($filter_statut, ['tous', 'en_attente', 'en_cours', 'resolue', 'fermee'], true)) {
    $filter_statut = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$search_q = trim($_GET['q'] ?? '');

$sql_claims = "SELECT * FROM claims WHERE user_id = ?";
$params_claims = [$user_id];

if ($filter_statut !== 'tous') {
    $sql_claims .= " AND statut = ?";
    $params_claims[] = $filter_statut;
}

if ($periode === 'ce_mois') {
    $sql_claims .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_claims .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_claims .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_claims .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_claims .= " AND (sujet LIKE ? OR message LIKE ? OR reponse_admin LIKE ?)";
    $params_claims[] = "%$search_q%";
    $params_claims[] = "%$search_q%";
    $params_claims[] = "%$search_q%";
}

$sql_claims .= " ORDER BY (statut = 'en_attente') DESC, created_at DESC";

$stmt_claims = $pdo->prepare($sql_claims);
$stmt_claims->execute($params_claims);
$claims = $stmt_claims->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 4. Statistiques globales KPI
// ------------------------------------------------------------------------------
$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(statut = 'en_attente') AS en_attente,
        SUM(statut = 'en_cours') AS en_cours,
        SUM(statut = 'resolue') AS resolues
    FROM claims 
    WHERE user_id = ?
");
$stmt_stats->execute([$user_id]);
$stats_cl = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$total_claims = (int)($stats_cl['total'] ?? 0);
$nb_attente   = (int)($stats_cl['en_attente'] ?? 0);
$nb_cours     = (int)($stats_cl['en_cours'] ?? 0);
$nb_resolues  = (int)($stats_cl['resolues'] ?? 0);

function get_claim_badge($statut) {
    switch ($statut) {
        case 'en_attente':
            return ['En attente', '#fef3c7', '#b45309', 'fa-solid fa-hourglass-half'];
        case 'en_cours':
            return ['En traitement', '#e0f2fe', '#0369a1', 'fa-solid fa-spinner fa-spin'];
        case 'resolue':
            return ['Résolue', '#dcfce7', '#166534', 'fa-solid fa-circle-check'];
        case 'fermee':
            return ['Fermée', '#f1f5f9', '#475569', 'fa-solid fa-lock'];
    }
    return [ucfirst($statut), '#f1f5f9', '#475569', 'fa-solid fa-circle-info'];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.claim-card {
    background: #ffffff;
    border: 1px solid var(--dash-border);
    border-radius: 14px;
    padding: 1.25rem 1.35rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    transition: all 0.2s ease;
}
.claim-card:hover {
    border-color: var(--dash-primary);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-headset" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Support & Réclamations Promoteur
            </h1>
            <p>Assistance dédiée 24/7 : contactez l'équipe d'administration pour vos virements, événements, agents ou questions techniques.</p>
        </div>

        <div>
            <button type="button" onclick="openNewTicketModal()" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-plus"></i> Nouveau Ticket de Support
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
         2. KPI CARDS : SYNTHÈSE DES TICKETS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <a href="?statut=tous&periode=<?php echo $periode; ?>" style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'tous' ? 'var(--dash-primary)' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Demandes</span>
                    <span style="background: #f1f5f9; color: var(--dash-text); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-comments"></i></span>
                </div>
                <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $total_claims; ?></div>
                <small style="color: var(--dash-muted); font-size: 0.75rem;">Historique complet de support</small>
            </div>
        </a>

        <a href="?statut=en_attente&periode=<?php echo $periode; ?>" style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'en_attente' ? '#f59e0b' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">En Attente</span>
                    <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hourglass-half"></i></span>
                </div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo $nb_attente; ?></div>
                <small style="color: #b45309; font-size: 0.75rem;">En attente de prise en charge</small>
            </div>
        </a>

        <a href="?statut=en_cours&periode=<?php echo $periode; ?>" style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'en_cours' ? '#0284c7' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #0369a1; text-transform: uppercase;">En Cours</span>
                    <span style="background: #e0f2fe; color: #0369a1; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-arrows-rotate"></i></span>
                </div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #0369a1;"><?php echo $nb_cours; ?></div>
                <small style="color: #0369a1; font-size: 0.75rem;">Investigation en cours par l'admin</small>
            </div>
        </a>

        <a href="?statut=resolue&periode=<?php echo $periode; ?>" style="text-decoration: none; color: inherit;">
            <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid <?php echo $filter_statut === 'resolue' ? '#10b981' : 'var(--dash-border)'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #166534; text-transform: uppercase;">Résolues</span>
                    <span style="background: #dcfce7; color: #166534; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
                </div>
                <div style="font-size: 1.65rem; font-weight: 800; color: #166534;"><?php echo $nb_resolues; ?></div>
                <small style="color: #166534; font-size: 0.75rem;">Réponses satisfaites</small>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES : STATUTS, PÉRIODE & RECHERCHE (SUR LA MÊME LIGNE)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Onglets Statuts -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'tous' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-list"></i> Tous (<?php echo $total_claims; ?>)
            </a>

            <a href="?statut=en_attente&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'en_attente' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-hourglass-half" style="color: #f59e0b;"></i> En attente (<?php echo $nb_attente; ?>)
            </a>

            <a href="?statut=en_cours&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'en_cours' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrows-rotate" style="color: #0284c7;"></i> En cours (<?php echo $nb_cours; ?>)
            </a>

            <a href="?statut=resolue&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'resolue' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Résolues (<?php echo $nb_resolues; ?>)
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

            <!-- Champ Recherche -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Rechercher..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 160px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $search_q !== '' || $filter_statut !== 'tous'): ?>
                <a href="reclamations.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. LISTE DES RÉCLAMATIONS & TICKETS (DESIGN DASHBOARD PRO)
         ============================================================================== -->
    <?php if (count($claims) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <?php foreach ($claims as $cl): ?>
                <?php 
                    [$badge_label, $badge_bg, $badge_color, $badge_icon] = get_claim_badge($cl['statut']);
                ?>
                <div class="claim-card">
                    <!-- En-tête de la réclamation -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; padding: 4px 10px; border-radius: 6px; font-weight: 800; font-size: 0.74rem; display: inline-flex; align-items: center; gap: 5px;">
                                <i class="<?php echo $badge_icon; ?>"></i> <?php echo $badge_label; ?>
                            </span>
                            <span style="color: var(--dash-muted); font-size: 0.78rem;">Ticket #<?php echo $cl['id']; ?></span>
                        </div>

                        <div style="color: var(--dash-muted); font-size: 0.78rem; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-regular fa-clock"></i>
                            Déposé le <?php echo date('d/m/Y à H:i', strtotime($cl['created_at'])); ?>
                        </div>
                    </div>

                    <!-- Titre du sujet -->
                    <h3 style="margin: 0 0 0.65rem; color: var(--dash-text); font-size: 1.05rem; font-weight: 800;">
                        <?php echo htmlspecialchars($cl['sujet']); ?>
                    </h3>

                    <!-- Message déposé par le promoteur -->
                    <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem; font-size: 0.85rem; color: var(--dash-text); line-height: 1.5; margin-bottom: 0.85rem;">
                        <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                    </div>

                    <!-- Réponse de l'administration si présente -->
                    <?php if (!empty($cl['reponse_admin'])): ?>
                        <div style="background: linear-gradient(135deg, #f0fdfa, #f8fafc); border-left: 4px solid var(--dash-primary); border-radius: 0 10px 10px 0; padding: 1rem; margin-bottom: 0.75rem; border-top: 1px solid var(--dash-border); border-right: 1px solid var(--dash-border); border-bottom: 1px solid var(--dash-border);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                                <strong style="color: var(--dash-primary); font-size: 0.84rem; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-reply"></i> Réponse officielle du Support Eventia
                                </strong>
                                <span style="font-size: 0.72rem; color: var(--dash-muted);">
                                    <?php echo !empty($cl['updated_at']) ? 'Mise à jour le ' . date('d/m/Y à H:i', strtotime($cl['updated_at'])) : ''; ?>
                                </span>
                            </div>
                            <p style="margin: 0; font-size: 0.86rem; color: var(--dash-text); line-height: 1.5;">
                                <?php echo nl2br(htmlspecialchars($cl['reponse_admin'])); ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div style="padding: 0.5rem 0; display: flex; align-items: center; gap: 6px; color: var(--dash-muted); font-size: 0.78rem; font-style: italic;">
                            <i class="fa-solid fa-hourglass-half" style="color: #f59e0b;"></i> En attente de traitement par un agent du support technique...
                        </div>
                    <?php endif; ?>

                    <!-- Action Clôturer si résolue ou en cours -->
                    <?php if ($cl['statut'] !== 'fermee'): ?>
                        <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem;">
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Voulez-vous marquer ce ticket comme fermé ?');">
                                <input type="hidden" name="action_fermer_ticket" value="1">
                                <input type="hidden" name="claim_id" value="<?php echo $cl['id']; ?>">
                                <button type="submit" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.74rem;">
                                    <i class="fa-solid fa-lock"></i> Clôturer ce ticket
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
            <i class="fa-solid fa-headset" style="font-size: 2.75rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
            <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun ticket de réclamation trouvé</strong>
            <p style="font-size: 0.84rem; margin: 0 0 1.25rem;">Vous n'avez aucun ticket correspondant aux filtres ou vous n'avez pas encore posé de réclamation.</p>
            <button type="button" onclick="openNewTicketModal()" class="dash-btn-action btn-primary" style="display: inline-flex;">
                <i class="fa-solid fa-plus"></i> Ouvrir un Ticket
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODAL : OUVRIR UN NOUVEAU TICKET DE SUPPORT
     ============================================================================== -->
<div id="modalNewTicket" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 520px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-paper-plane" style="color: var(--dash-primary);"></i> Ouvrir un Ticket de Support
            </h3>
            <button type="button" onclick="closeNewTicketModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="reclamations.php" style="padding: 1.5rem;">
            <input type="hidden" name="send_claim" value="1">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Catégorie de la réclamation *
                </label>
                <select name="categorie" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 700;">
                    <option value="virement">💰 Trésorerie, Virement & Solde</option>
                    <option value="evenement">🎟️ Événement, Billetterie & Quotas</option>
                    <option value="agents">🛡️ Agents de contrôle & Application Scan</option>
                    <option value="technique">⚙️ Problème technique / Bug d'affichage</option>
                    <option value="autre">📝 Autre question / Demande générale</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Objet du ticket *
                </label>
                <input type="text" name="sujet" required placeholder="Ex: Délai virement Wave #1234, Problème quotas..." style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Message détaillé *
                </label>
                <textarea name="message" rows="5" required placeholder="Décrivez votre situation avec le plus de précisions possible pour accélérer le traitement..." style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; line-height: 1.4;"></textarea>
                <small style="color: var(--dash-muted); font-size: 0.74rem; display: block; margin-top: 3px;">
                    <i class="fa-solid fa-circle-info"></i> Les tickets des promoteurs certifiés sont traités en priorité sous 24h.
                </small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeNewTicketModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 800;">
                    <i class="fa-solid fa-paper-plane"></i> Transmettre au Support
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewTicketModal() {
    document.getElementById('modalNewTicket').style.display = 'flex';
}
function closeNewTicketModal() {
    document.getElementById('modalNewTicket').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalNewTicket');
    if (e.target === m) closeNewTicketModal();
});
</script>

<?php include 'footer.php'; ?>
