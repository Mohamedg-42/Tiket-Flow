<?php
// ==============================================================================
// GESTION DES COTISATIONS & CAMPAGNES DE CONTRIBUTION (admin/cotisations.php)
// Design Dashboard Pro - Contrôle, arbitrage et validation des fonds de solidarité
// ==============================================================================

$admin_page_title = "Cotisations & Cagnottes - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'creer_campagne') {
            $titre            = trim($_POST['titre'] ?? '');
            $description      = trim($_POST['description'] ?? '');
            $montant_objectif = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
            $date_limite      = trim($_POST['date_limite'] ?? '');

            if ($titre === '' || !$montant_objectif || $montant_objectif < 1000) {
                $message = "Veuillez renseigner un titre et un montant objectif valide (minimum 1 000 FCFA).";
                $msg_type = "error";
            } else {
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

                $stmt = $pdo->prepare("
                    INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut)
                    VALUES (NULL, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$titre, $description ?: null, $image_name, $montant_objectif, $date_limite ?: null]);
                $message = "La campagne « " . htmlspecialchars($titre) . " » a été créée et mise en ligne avec succès.";
                $msg_type = "success";
            }

        } elseif ($action === 'changer_statut') {
            $campagne_id = (int)($_POST['campagne_id'] ?? 0);
            $statut = $_POST['statut'] ?? '';
            if (in_array($statut, ['active', 'terminee', 'annulee'], true) && $campagne_id > 0) {
                $stmt = $pdo->prepare("UPDATE cotisation_campagnes SET statut = ? WHERE id = ?");
                $stmt->execute([$statut, $campagne_id]);
                $message = "Le statut de la campagne a été mis à jour.";
                $msg_type = "success";
            }

        } elseif ($action === 'statut_cotisation') {
            $cotisation_id = (int)($_POST['cotisation_id'] ?? 0);
            $statut = $_POST['statut'] ?? '';
            if (in_array($statut, ['payee', 'annule', 'en_attente'], true) && $cotisation_id > 0) {
                $stmt = $pdo->prepare("UPDATE cotisations SET statut = ? WHERE id = ?");
                $stmt->execute([$statut, $cotisation_id]);
                $message = "Le statut de la contribution a été mis à jour.";
                $msg_type = "success";
            }
        }
    } catch (PDOException $e) {
        $message = "Erreur base de données : " . $e->getMessage();
        $msg_type = "error";
    }
}

// Filtres
$statut_f = $_GET['statut'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

// Données : campagnes + stats + contributions
$campagnes = [];
$toutes_contributions = [];
try {
    $sql_c = "
        SELECT c.*, u.nom AS promoteur_nom, u.email as promoteur_email,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE 1=1
    ";
    $params_c = [];

    if ($statut_f === 'active') {
        $sql_c .= " AND c.statut = 'active'";
    } elseif ($statut_f === 'terminee') {
        $sql_c .= " AND c.statut = 'terminee'";
    } elseif ($statut_f === 'annulee') {
        $sql_c .= " AND c.statut = 'annulee'";
    } elseif ($statut_f === 'en_attente') {
        $sql_c .= " AND c.statut = 'en_attente'";
    }

    if (!empty($search)) {
        $sql_c .= " AND (c.titre LIKE ? OR u.nom LIKE ?)";
        $params_c[] = "%$search%";
        $params_c[] = "%$search%";
    }

    $sql_c .= " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC";
    $stmt_c = $pdo->prepare($sql_c);
    $stmt_c->execute($params_c);
    $campagnes = $stmt_c->fetchAll();

    $toutes_contributions = $pdo->query("
        SELECT ct.*, c.titre AS campagne_titre
        FROM cotisations ct
        LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
        ORDER BY ct.created_at DESC
        LIMIT 40
    ")->fetchAll();

    $tot_campagnes = (int)$pdo->query("SELECT COUNT(*) FROM cotisation_campagnes")->fetchColumn();
    $tot_objectif  = (float)$pdo->query("SELECT COALESCE(SUM(montant_objectif), 0) FROM cotisation_campagnes")->fetchColumn();
    $tot_collecte   = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM cotisations WHERE statut IN ('en_attente', 'payee')")->fetchColumn();
    $tot_donateurs = (int)$pdo->query("SELECT COUNT(DISTINCT email) FROM cotisations WHERE statut = 'payee'")->fetchColumn();

} catch (PDOException $e) {
    $campagnes = [];
    $toutes_contributions = [];
    $tot_campagnes = $tot_objectif = $tot_collecte = $tot_donateurs = 0;
}
?>

<style>
/* ==============================================================================
   STYLES RESPONSIVE & SYSTÈME SUISSE - COTISATIONS & CAGNOTTES
   ============================================================================== */
.cotis-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.cotis-header-actions {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
    align-items: center;
}
.cotis-tabs-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    background: #ffffff;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--eventia-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    flex-wrap: wrap;
}
.cotis-tabs-pills {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    max-width: 100%;
}
.cotis-tabs-pills::-webkit-scrollbar {
    display: none;
}
.cotis-tab-link {
    text-decoration: none;
    border-radius: 9px;
    padding: 0.45rem 0.95rem;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.15s ease;
}

.cotis-kpis-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
}

.cotis-desktop-table {
    display: block;
}
.cotis-mobile-list {
    display: none;
}

.cotis-contrib-desktop-table {
    display: block;
}
.cotis-contrib-mobile-list {
    display: none;
}

/* Breakpoints Responsives */
@media (max-width: 960px) {
    .cotis-kpis-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 860px) {
    .cotis-header-section {
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
    }
    .cotis-header-actions {
        width: 100%;
        flex-direction: column;
    }
    .cotis-header-actions a,
    .cotis-header-actions button {
        width: 100%;
        justify-content: center;
        box-sizing: border-box;
        text-align: center;
    }
    .cotis-tabs-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
    }
    .cotis-tabs-pills {
        width: 100%;
    }

    /* Masquer les tableaux débordants */
    .cotis-desktop-table,
    .cotis-contrib-desktop-table {
        display: none !important;
    }

    /* Activer les cartes suisses mobiles */
    .cotis-mobile-list,
    .cotis-contrib-mobile-list {
        display: flex !important;
        flex-direction: column;
        gap: 0.85rem;
    }

    /* Carte Campagne Mobile */
    .cotis-mobile-card,
    .contrib-mobile-card {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        box-sizing: border-box;
    }
    .cmc-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
    }
    .cmc-title-box {
        flex: 1;
        min-width: 0;
    }
    .cmc-title {
        margin: 0 0 4px 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--dash-text, #000000);
        line-height: 1.3;
        word-break: break-word;
    }
    .cmc-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.78rem;
        color: var(--dash-muted, #737373);
        flex-wrap: wrap;
    }
    .cmc-initiator {
        font-weight: 700;
        color: var(--dash-text, #000000);
    }
    .cmc-badge {
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 800;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .cmc-progress-box {
        background: #F5F5F5;
        border: 1px solid #F5F5F5;
        border-radius: 10px;
        padding: 0.75rem;
    }
    .cmc-amounts-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 6px;
    }
    .cmc-label {
        display: block;
        font-size: 0.7rem;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--dash-muted, #737373);
    }
    .cmc-val-col {
        color: #FF4A0D;
        font-size: 1rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    .cmc-val-obj {
        color: var(--dash-muted, #737373);
        font-size: 0.85rem;
        font-weight: 600;
    }
    .cmc-bar-wrapper {
        background: #E5E5E5;
        height: 6px;
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 6px;
    }
    .cmc-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #FF4A0D, #FF4A0D);
        border-radius: 999px;
    }
    .cmc-progress-sub {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.76rem;
        color: var(--dash-muted, #737373);
    }
    .cmc-actions-row {
        display: flex;
        gap: 6px;
        padding-top: 0.5rem;
        border-top: 1px solid var(--dash-border, #E5E5E5);
    }
    .cmc-btn {
        flex: 1;
        padding: 0.55rem 0.5rem;
        font-size: 0.8rem;
        font-weight: 700;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        box-sizing: border-box;
        transition: opacity 0.15s ease;
    }
    .cmc-btn:hover {
        opacity: 0.85;
    }
    .cmc-btn-active {
        background: #FFF2ED;
        color: #000000;
    }
    .cmc-btn-finish {
        background: #F5F5F5;
        color: #737373;
    }
    .cmc-btn-cancel {
        background: #F5F5F5;
        color: #000000;
    }
}

@media (max-width: 480px) {
    .cotis-kpis-grid {
        grid-template-columns: 1fr !important;
        gap: 0.65rem !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="cotis-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Gestion des Cotisations & Cagnottes
            </h1>
            <p>Supervisez les collectes de fonds solidaires, validez les campagnes et suivez les dons reçus.</p>
        </div>

        <div class="cotis-header-actions">
            <a href="export.php?type=cotisations&statut=<?php echo urlencode($statut_f); ?>&q=<?php echo urlencode($search); ?>" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter les cotisations et donateurs sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="creer-evenement.php?onglet=cotisation" class="eventia-btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-plus"></i> Nouvelle Campagne Admin
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="eventia-alert eventia-alert-<?php echo ($msg_type === 'success') ? 'success' : 'error'; ?>" style="margin-bottom: 1.25rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES BIEN VISIBLES)
         ============================================================================== -->
    <div class="cotis-tabs-bar">
        <!-- À GAUCHE : PILULES STATUT -->
        <div class="cotis-tabs-pills">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>" class="cotis-tab-link" style="<?php echo $statut_f === 'tous' ? 'background: var(--tikeli-black, #000000); color: #ffffff; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: var(--tikeli-orange, #FF4A0D);' : ''; ?>"></i> Toutes (<?php echo $tot_campagnes; ?>)
            </a>

            <a href="?statut=active&q=<?php echo urlencode($search); ?>" class="cotis-tab-link" style="<?php echo $statut_f === 'active' ? 'background: var(--tikeli-black, #000000); color: #ffffff; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-bolt" style="color: var(--tikeli-orange, #FF4A0D);"></i> En cours (Actives)
            </a>

            <a href="?statut=terminee&q=<?php echo urlencode($search); ?>" class="cotis-tab-link" style="<?php echo $statut_f === 'terminee' ? 'background: var(--tikeli-black, #000000); color: #ffffff; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-flag-checkered" style="color: #737373;"></i> Clôturées
            </a>

            <a href="?statut=en_attente&q=<?php echo urlencode($search); ?>" class="cotis-tab-link" style="<?php echo $statut_f === 'en_attente' ? 'background: var(--tikeli-black, #000000); color: #ffffff; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-clock" style="color: var(--tikeli-orange, #FF4A0D);"></i> En attente
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="cotisations.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Titre ou promoteur..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.82rem; width: 170px; background: #ffffff;">
            <button type="submit" class="eventia-btn-secondary" style="padding: 0.4rem 0.85rem; font-size: 0.82rem;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="cotisations.php" style="color: var(--eventia-danger, #000000); font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE COTISATION (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div class="cotis-kpis-grid">
        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--tikeli-orange, #FF4A0D); text-transform: uppercase;">Total Collecté</span>
                <span style="background: rgba(255, 74, 13, 0.12); color: var(--tikeli-orange, #FF4A0D); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--tikeli-orange, #FF4A0D);"><?php echo number_format($tot_collecte, 0, ',', ' '); ?> F</div>
            <small style="color: var(--tikeli-orange, #FF4A0D); font-size: 0.75rem; font-weight: 600;">Dons reçus via Mobile Money</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Objectifs Cumulés</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bullseye"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo number_format($tot_objectif, 0, ',', ' '); ?> F</div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Budget total recherché</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Campagnes Publiées</span>
                <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hand-holding-heart"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo $tot_campagnes; ?></div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Incluses dans l'espace Cotisations</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase;">Donateurs Solidaires</span>
                <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000);"><?php echo $tot_donateurs; ?></div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem;">Contributeurs distincts enregistrés</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES CAMPAGNES DE COTISATION
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Campagnes (<?php echo count($campagnes); ?>)
            </h3>
        </div>

        <?php if (empty($campagnes)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucune campagne de cotisation dans cette catégorie.
            </div>
        <?php else: ?>
            <!-- Vue Table Desktop (> 860px) -->
            <div class="dash-table-wrapper cotis-desktop-table" style="overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch;">
                <table class="dash-table" style="min-width: 900px; width: 100%;">
                    <thead>
                        <tr>
                            <th>Campagne</th>
                            <th>Initiateur</th>
                            <th>Collecté / Objectif</th>
                            <th>Avancement</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Actions Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campagnes as $camp): ?>
                            <?php
                            $col = (float)$camp['montant_collecte'];
                            $obj = (float)$camp['montant_objectif'];
                            $pct = ($obj > 0) ? min(100, round(($col / $obj) * 100)) : 0;
                            ?>
                            <tr>
                                <td data-label="Campagne">
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($camp['titre']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo $camp['date_limite'] ? '<i class="fa-regular fa-calendar"></i> Jusqu\'au ' . date('d/m/Y', strtotime($camp['date_limite'])) : 'Durée illimitée'; ?>
                                    </small>
                                </td>
                                <td data-label="Initiateur">
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem;">
                                        <?php echo $camp['promoteur_nom'] ? htmlspecialchars($camp['promoteur_nom']) : '<span style="color: var(--eventia-turquoise-dark, #FF4A0D); font-weight: 700;">Plateforme (Admin)</span>'; ?>
                                    </span>
                                </td>
                                <td data-label="Collecté" style="white-space: nowrap;">
                                    <strong class="cell-amount" style="color: #FF4A0D; font-size: 0.92rem; font-weight: 800; white-space: nowrap;">
                                        <?php echo str_replace(' ', '&nbsp;', number_format($col, 0, ',', ' ')); ?>&nbsp;F
                                    </strong>
                                    <small style="color: var(--dash-muted); display: block; font-size: 0.74rem; white-space: nowrap;">
                                        sur <?php echo str_replace(' ', '&nbsp;', number_format($obj, 0, ',', ' ')); ?>&nbsp;F
                                    </small>
                                </td>
                                <td data-label="Avancement" style="white-space: nowrap;">
                                    <div style="font-size: 0.8rem; font-weight: 700; color: var(--dash-text); white-space: nowrap;">
                                        <?php echo $pct; ?>% <small style="color: var(--dash-muted); font-weight: normal;">(<?php echo (int)$camp['nb_contributeurs']; ?> donateurs)</small>
                                    </div>
                                    <div style="background: #E5E5E5; height: 6px; border-radius: 999px; overflow: hidden; margin-top: 4px; width: 110px;">
                                        <div style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, #FF4A0D, #FF4A0D); border-radius: 999px;"></div>
                                    </div>
                                </td>
                                <td data-label="Statut" style="white-space: nowrap;">
                                    <?php if ($camp['statut'] === 'active'): ?>
                                        <span class="cell-status-badge" style="background: #FFF2ED; color: #000000; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Active</span>
                                    <?php elseif ($camp['statut'] === 'terminee'): ?>
                                        <span class="cell-status-badge" style="background: #F5F5F5; color: #737373; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Terminée</span>
                                    <?php elseif ($camp['statut'] === 'en_attente'): ?>
                                        <span class="cell-status-badge" style="background: #FFF2ED; color: #FF4A0D; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">En attente</span>
                                    <?php else: ?>
                                        <span class="cell-status-badge" style="background: #F5F5F5; color: #000000; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Annulée</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions" style="text-align: right; white-space: nowrap;">
                                    <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 4px; margin: 0;">
                                        <input type="hidden" name="action" value="changer_statut">
                                        <input type="hidden" name="campagne_id" value="<?php echo (int)$camp['id']; ?>">
                                        <?php if ($camp['statut'] !== 'active'): ?>
                                            <button type="submit" name="statut" value="active" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #FFF2ED; color: #000000; white-space: nowrap;" title="Activer / Publier">
                                                <i class="fa-solid fa-play"></i> Activer
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($camp['statut'] !== 'terminee'): ?>
                                            <button type="submit" name="statut" value="terminee" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #F5F5F5; color: #737373; white-space: nowrap;" title="Marquer comme Terminée">
                                                <i class="fa-solid fa-flag-checkered"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($camp['statut'] !== 'annulee'): ?>
                                            <button type="submit" name="statut" value="annulee" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #F5F5F5; color: #000000; white-space: nowrap;" title="Annuler">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vue Cartes Mobile (<= 860px) -->
            <div class="cotis-mobile-list">
                <?php foreach ($campagnes as $camp): ?>
                    <?php
                    $col = (float)$camp['montant_collecte'];
                    $obj = (float)$camp['montant_objectif'];
                    $pct = ($obj > 0) ? min(100, round(($col / $obj) * 100)) : 0;
                    
                    $badge_status = [
                        'active' => ['Active', '#FFF2ED', '#000000'],
                        'terminee' => ['Terminée', '#F5F5F5', '#737373'],
                        'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D'],
                        'annulee' => ['Annulée', '#F5F5F5', '#000000']
                    ];
                    [$st_label, $st_bg, $st_fg] = $badge_status[$camp['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                    ?>
                    <div class="cotis-mobile-card">
                        <div class="cmc-head">
                            <div class="cmc-title-box">
                                <h4 class="cmc-title"><?php echo htmlspecialchars($camp['titre']); ?></h4>
                                <div class="cmc-meta">
                                    <span class="cmc-initiator">
                                        <i class="fa-solid fa-user-tie" style="font-size: 0.72rem;"></i>
                                        <?php echo $camp['promoteur_nom'] ? htmlspecialchars($camp['promoteur_nom']) : 'Plateforme (Admin)'; ?>
                                    </span>
                                    <span>·</span>
                                    <span class="cmc-date">
                                        <?php echo $camp['date_limite'] ? '<i class="fa-regular fa-calendar"></i> ' . date('d/m/Y', strtotime($camp['date_limite'])) : 'Durée illimitée'; ?>
                                    </span>
                                </div>
                            </div>
                            <span class="cmc-badge" style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>;">
                                <?php echo $st_label; ?>
                            </span>
                        </div>

                        <!-- Jauge financière & progression -->
                        <div class="cmc-progress-box">
                            <div class="cmc-amounts-row">
                                <div>
                                    <span class="cmc-label">Collecté</span>
                                    <strong class="cmc-val-col"><?php echo number_format($col, 0, ',', ' '); ?> F</strong>
                                </div>
                                <div style="text-align: right;">
                                    <span class="cmc-label">Objectif</span>
                                    <span class="cmc-val-obj"><?php echo number_format($obj, 0, ',', ' '); ?> F</span>
                                </div>
                            </div>

                            <div class="cmc-bar-wrapper">
                                <div class="cmc-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>

                            <div class="cmc-progress-sub">
                                <span><strong><?php echo $pct; ?>%</strong> atteint</span>
                                <span><i class="fa-solid fa-users" style="font-size: 0.72rem;"></i> <?php echo (int)$camp['nb_contributeurs']; ?> donateur(s)</span>
                            </div>
                        </div>

                        <!-- Actions Admin -->
                        <div class="cmc-actions-row">
                            <form method="POST" action="cotisations.php" style="display: flex; gap: 6px; width: 100%; margin: 0;">
                                <input type="hidden" name="action" value="changer_statut">
                                <input type="hidden" name="campagne_id" value="<?php echo (int)$camp['id']; ?>">
                                
                                <?php if ($camp['statut'] !== 'active'): ?>
                                    <button type="submit" name="statut" value="active" class="cmc-btn cmc-btn-active">
                                        <i class="fa-solid fa-play"></i> Activer
                                    </button>
                                <?php endif; ?>
                                <?php if ($camp['statut'] !== 'terminee'): ?>
                                    <button type="submit" name="statut" value="terminee" class="cmc-btn cmc-btn-finish">
                                        <i class="fa-solid fa-flag-checkered"></i> Clôturer
                                    </button>
                                <?php endif; ?>
                                <?php if ($camp['statut'] !== 'annulee'): ?>
                                    <button type="submit" name="statut" value="annulee" class="cmc-btn cmc-btn-cancel">
                                        <i class="fa-solid fa-ban"></i> Annuler
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==============================================================================
         5. FORMULAIRE DE CRÉATION DE NOUVELLE CAMPAGNE (ESPACE ADMIN)
         ============================================================================== -->
    <div class="dash-card" id="formNewCampagne" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-circle-plus" style="color: var(--dash-primary);"></i> Créer une Campagne Institutionnelle (Plateforme)
            </h3>
        </div>

        <form method="POST" action="cotisations.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="creer_campagne">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Titre de la campagne *</label>
                    <input type="text" name="titre" required placeholder="Ex: Soutien aux Artistes et Festivals Nationaux 2026" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Description et Objectifs du Projet</label>
                    <textarea name="description" rows="3" placeholder="Présentez le projet d'intérêt général financé..." style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box;"></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Montant Objectif (FCFA) *</label>
                    <input type="number" name="montant_objectif" required min="1000" step="5000" placeholder="Ex: 5000000" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Date d'échéance (Optionnelle)</label>
                    <input type="date" name="date_limite" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Bannière / Visuel (Optionnel)</label>
                    <input type="file" name="image" accept="image/png, image/jpeg, image/webp" style="width: 100%; padding: 0.45rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                </div>
            </div>

            <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.65rem 1.4rem;">
                <i class="fa-solid fa-paper-plane"></i> Mettre en Ligne la Campagne
            </button>
        </form>
    </div>

    <!-- ==============================================================================
         6. DERNIÈRES CONTRIBUTIONS REÇUES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i> Contributions & Dons Reçus (Récents)
            </h3>
        </div>

        <?php if (empty($toutes_contributions)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--dash-muted);">
                Aucune contribution reçue pour l'instant.
            </div>
        <?php else: ?>
            <!-- Vue Table Desktop (> 860px) -->
            <div class="cotis-contrib-desktop-table" style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Contributeur</th>
                            <th>Campagne</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toutes_contributions as $ct): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($ct['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($ct['telephone'] ?? $ct['email'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; color: var(--dash-text);">
                                        <?php echo $ct['campagne_titre'] ? htmlspecialchars($ct['campagne_titre']) : '<em>Contribution Générale</em>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #FF4A0D; font-size: 0.95rem;">
                                        <?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($ct['statut'] === 'payee'): ?>
                                        <span style="background: #FFF2ED; color: #000000; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">Payée</span>
                                    <?php elseif ($ct['statut'] === 'annule'): ?>
                                        <span style="background: #F5F5F5; color: #000000; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">Annulée</span>
                                    <?php else: ?>
                                        <span style="background: #FFF2ED; color: #FF4A0D; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">En attente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo date('d/m/Y à H:i', strtotime($ct['created_at'])); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 4px; margin: 0;">
                                        <input type="hidden" name="action" value="statut_cotisation">
                                        <input type="hidden" name="cotisation_id" value="<?php echo (int)$ct['id']; ?>">
                                        <?php if ($ct['statut'] !== 'payee'): ?>
                                            <button type="submit" name="statut" value="payee" class="dash-btn-action" style="padding: 0.3rem 0.6rem; font-size: 0.74rem; background: #FFF2ED; color: #000000;" title="Valider comme Payée">
                                                <i class="fa-solid fa-check"></i> Valider
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($ct['statut'] !== 'annule'): ?>
                                            <button type="submit" name="statut" value="annule" class="dash-btn-action" style="padding: 0.3rem 0.6rem; font-size: 0.74rem; background: #F5F5F5; color: #000000;" title="Annuler">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vue Cartes Mobile Dons (<= 860px) -->
            <div class="cotis-contrib-mobile-list">
                <?php foreach ($toutes_contributions as $ct): ?>
                    <?php
                    $st_badge = [
                        'payee' => ['Payée', '#FFF2ED', '#000000'],
                        'annule' => ['Annulée', '#F5F5F5', '#000000'],
                        'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D']
                    ];
                    [$cl_l, $cl_b, $cl_f] = $st_badge[$ct['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                    ?>
                    <div class="contrib-mobile-card">
                        <div class="cmc-head">
                            <div>
                                <strong style="color: var(--dash-text, #000000); font-size: 0.92rem; display: block;">
                                    <?php echo htmlspecialchars($ct['nom']); ?>
                                </strong>
                                <small style="color: var(--dash-muted, #737373); font-size: 0.76rem;">
                                    <?php echo htmlspecialchars($ct['telephone'] ?? $ct['email'] ?? '—'); ?>
                                </small>
                            </div>
                            <span style="background: <?php echo $cl_b; ?>; color: <?php echo $cl_f; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; text-transform: uppercase;">
                                <?php echo $cl_l; ?>
                            </span>
                        </div>

                        <div style="font-size: 0.8rem; color: var(--dash-muted, #737373); margin: 2px 0;">
                            Campagne : <strong style="color: var(--dash-text, #000000);"><?php echo $ct['campagne_titre'] ? htmlspecialchars($ct['campagne_titre']) : 'Contribution Générale'; ?></strong>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--dash-border, #E5E5E5); padding-top: 0.5rem; margin-top: 0.25rem;">
                            <div>
                                <strong style="color: #FF4A0D; font-size: 1.05rem; font-weight: 800; font-variant-numeric: tabular-nums;">
                                    <?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F
                                </strong>
                                <small style="color: var(--dash-muted, #737373); display: block; font-size: 0.72rem;">
                                    <?php echo date('d/m/Y à H:i', strtotime($ct['created_at'])); ?>
                                </small>
                            </div>

                            <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 4px; margin: 0;">
                                <input type="hidden" name="action" value="statut_cotisation">
                                <input type="hidden" name="cotisation_id" value="<?php echo (int)$ct['id']; ?>">
                                <?php if ($ct['statut'] !== 'payee'): ?>
                                    <button type="submit" name="statut" value="payee" class="dash-btn-action" style="padding: 0.4rem 0.75rem; font-size: 0.75rem; background: #FFF2ED; color: #000000; font-weight: 700; border-radius: 6px;">
                                        <i class="fa-solid fa-check"></i> Valider
                                    </button>
                                <?php endif; ?>
                                <?php if ($ct['statut'] !== 'annule'): ?>
                                    <button type="submit" name="statut" value="annule" class="dash-btn-action" style="padding: 0.4rem 0.75rem; font-size: 0.75rem; background: #F5F5F5; color: #000000; font-weight: 700; border-radius: 6px;">
                                        <i class="fa-solid fa-xmark"></i> Annuler
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>