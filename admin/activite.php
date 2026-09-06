<?php
// ==============================================================================
// JOURNAL D'ACTIVITÉ & AUDIT TRAIL (admin/activite.php)
// Traçabilité complète des actions : Qui a fait quoi ? Quand ? Sur quel élément ?
// ==============================================================================

$admin_page_title = "Journal d'Activité - Administration";
include 'header.php';

requirePermission('activity.view');

// 1. Utilisateurs pour le filtre déroulant
$authors = $pdo->query("
    SELECT id, nom, prenom, role 
    FROM users 
    ORDER BY nom ASC
")->fetchAll();

// 2. Filtres
$filtre_user = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: 0;
$filtre_action = trim($_GET['action'] ?? '');
$search = trim($_GET['q'] ?? '');
$date_debut = trim($_GET['date_debut'] ?? '');
$date_fin = trim($_GET['date_fin'] ?? '');

$sql = "
    SELECT a.*,
           u.nom AS user_nom, u.prenom AS user_prenom, u.role AS user_role, u.email AS user_email
    FROM activity_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($filtre_user > 0) {
    $sql .= " AND a.user_id = ?";
    $params[] = $filtre_user;
}

if (!empty($filtre_action)) {
    $sql .= " AND a.action LIKE ?";
    $params[] = "%$filtre_action%";
}

if (!empty($search)) {
    $sql .= " AND (a.details LIKE ? OR a.action LIKE ? OR a.ip_address LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($date_debut)) {
    $sql .= " AND DATE(a.created_at) >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $sql .= " AND DATE(a.created_at) <= ?";
    $params[] = $date_fin;
}

$sql .= " ORDER BY a.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Statistiques rapides
$tot_logs_today = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$tot_logins = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'connexion'")->fetchColumn();
$tot_updates = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE '%.update'")->fetchColumn();
$tot_suspensions = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'user.suspend'")->fetchColumn();
?>
<style>
/* ==============================================================================
   RESPONSIVE DESIGN SUISSE : JOURNAL D'ACTIVITÉ (admin/activite.php)
   ============================================================================== */
.activite-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.activite-header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.activite-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

/* Filtres */
.activite-filter-card {
    background: #ffffff;
    padding: 0.85rem 1.15rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    margin-bottom: 1.25rem;
}
.activite-filter-form {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
    flex-wrap: wrap;
    margin: 0;
}
.activite-filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    flex: 1 1 180px;
    min-width: 140px;
}
.activite-filter-label {
    font-size: 0.70rem;
    font-weight: 800;
    text-transform: uppercase;
    color: var(--muted, #737373);
    letter-spacing: 0.04em;
}
.activite-filter-select, .activite-filter-input {
    width: 100%;
    height: 38px;
    padding: 0.4rem 0.65rem;
    border-radius: 8px;
    border: 1px solid var(--dash-border, #E5E5E5);
    font-size: 0.82rem;
    background: #ffffff;
    color: var(--dash-text, #000000);
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.15s ease;
}
.activite-filter-select:focus, .activite-filter-input:focus {
    border-color: var(--primary, #FF4A0D);
}
.date-interval-inputs {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
}
.date-interval-inputs .date-input {
    flex: 1 1 0;
    min-width: 0;
}
.date-sep {
    color: var(--muted, #737373);
    font-size: 0.78rem;
    flex-shrink: 0;
}
.activite-filter-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 0 0 auto;
}
.activite-reset-link {
    color: #000000;
    font-size: 0.78rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    padding: 0.4rem 0.5rem;
}
.activite-reset-link:hover {
    text-decoration: underline;
}

/* Tableau desktop */
.activite-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.dash-table.activite-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.85rem;
}
.dash-table.activite-table th {
    font-size: 0.70rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #737373;
    letter-spacing: 0.05em;
    padding: 0.75rem 0.85rem;
    border-bottom: 1px solid var(--dash-border, #E5E5E5);
    text-align: left;
    background: #ffffff;
    white-space: nowrap !important;
}
.dash-table.activite-table td {
    padding: 0.75rem 0.85rem;
    border-bottom: 1px solid #F5F5F5;
    vertical-align: middle;
}
.card-badge-mobile {
    display: none;
}
.activite-role-pill {
    font-size: 0.68rem;
    text-transform: uppercase;
    font-weight: 700;
    color: #737373;
    background: #F5F5F5;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
    margin-top: 2px;
}
.activite-detail-text {
    font-size: 0.80rem;
    color: var(--dash-text, #000000);
    line-height: 1.4;
    display: block;
    max-width: 480px;
    word-break: break-word;
}

/* ==============================================================================
   TRANSFORMATION EN CARTES MOBILES (≤ 860px)
   ============================================================================== */
@media (max-width: 860px) {
    .dash-container {
        padding: 0.75rem 0.5rem !important;
    }
    .activite-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .activite-header-actions {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 0.45rem !important;
    }
    .activite-header-actions a,
    .activite-header-actions button {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }
    
    .activite-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.50rem !important;
    }
    .activite-kpis .dash-kpi-card {
        padding: 0.75rem !important;
    }

    .activite-filter-card {
        padding: 0.75rem !important;
    }
    .activite-filter-form {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.65rem !important;
    }
    .activite-filter-group {
        width: 100% !important;
        flex: 1 1 100% !important;
    }
    .activite-filter-actions {
        width: 100% !important;
        flex-direction: column !important;
    }

    /* Transformation du tableau en cartes */
    .activite-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    .dash-table.activite-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.activite-table thead {
        display: none !important;
    }
    .dash-table.activite-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.activite-table tr {
        display: block !important;
        background: #ffffff !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
        border-radius: 12px !important;
        padding: 0.85rem !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04) !important;
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .dash-table.activite-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.activite-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0.5rem !important;
        padding: 0.48rem 0 !important;
        border-bottom: 1px dashed #E5E5E5 !important;
        width: 100% !important;
        max-width: 100% !important;
        text-align: right !important;
        font-size: 0.82rem !important;
        box-sizing: border-box !important;
    }
    .dash-table.activite-table td::before {
        content: attr(data-label);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }
    .dash-table.activite-table td.card-top {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 0 !important;
        padding-bottom: 0.60rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
        text-align: left !important;
    }
    .dash-table.activite-table td.card-top::before {
        display: none !important;
    }
    .card-badge-mobile {
        display: inline-flex !important;
    }
    .dash-table.activite-table td.hide-on-mobile-card {
        display: none !important;
    }
    .dash-table.activite-table td.card-details {
        display: block !important;
        text-align: left !important;
        padding: 0.55rem 0 !important;
    }
    .dash-table.activite-table td.card-details::before {
        display: block !important;
        margin-bottom: 4px !important;
    }
    .dash-table.activite-table td:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }
}

@media (max-width: 420px) {
    .activite-kpis {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div class="dash-container">
    <!-- En-tête -->
    <div class="activite-header">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-clock-rotate-left" style="color: var(--primary); font-size: 1.55rem;"></i>
                Journal d'Activité & Traçabilité (Audit Trail)
            </h1>
            <p>Historique immuable de toutes les actions sensibles effectuées sur la plateforme Eventia.</p>
        </div>

        <div class="activite-header-actions">
            <a href="export.php?type=activite&action=<?php echo urlencode($filtre_action); ?>&search=<?php echo urlencode($search); ?>&date_debut=<?php echo urlencode($date_debut); ?>&date_fin=<?php echo urlencode($date_fin); ?>" class="dash-btn-action" style="text-decoration: none;" title="Exporter les logs d'activité sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="utilisateurs.php" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-users"></i> Gestion des Comptes
            </a>
            <button type="button" class="dash-btn-action" onclick="window.print()" title="Imprimer le rapport d'audit">
                <i class="fa-solid fa-print"></i> Imprimer
            </button>
        </div>
    </div>

    <!-- KPIs Synthétiques -->
    <div class="activite-kpis">
        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase;">Actions
                Aujourd'hui</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--navy); margin-top: 2px;">
                <?php echo $tot_logs_today; ?></div>
            <small style="color: var(--muted); font-size: 0.72rem;">Événements enregistrés ce jour</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Connexions
                Totales</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #16a34a; margin-top: 2px;">
                <?php echo $tot_logins; ?></div>
            <small style="color: #16a34a; font-size: 0.72rem;">Sessions authentifiées</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span
                style="font-size: 0.75rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Modifications</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #0284c7; margin-top: 2px;">
                <?php echo $tot_updates; ?></div>
            <small style="color: #0284c7; font-size: 0.72rem;">Mises à jour de comptes/tâches</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span
                style="font-size: 0.75rem; font-weight: 700; color: #dc2626; text-transform: uppercase;">Suspensions</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #dc2626; margin-top: 2px;">
                <?php echo $tot_suspensions; ?></div>
            <small style="color: #dc2626; font-size: 0.72rem;">Mesures disciplinaires</small>
        </div>
    </div>

    <!-- Barre de Filtres et Recherche Responsive -->
    <div class="activite-filter-card">
        <form method="GET" action="activite.php" class="activite-filter-form">
            <!-- Auteur -->
            <div class="activite-filter-group">
                <label class="activite-filter-label">Utilisateur</label>
                <select name="user_id" class="activite-filter-select">
                    <option value="0">Tous les utilisateurs</option>
                    <?php foreach ($authors as $auth): ?>
                        <option value="<?php echo $auth['id']; ?>" <?php echo $filtre_user === (int) $auth['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim(($auth['prenom'] ?? '') . ' ' . $auth['nom']) . " (" . strtoupper($auth['role']) . ")"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type d'action -->
            <div class="activite-filter-group">
                <label class="activite-filter-label">Action</label>
                <select name="action" class="activite-filter-select">
                    <option value="">Toutes les actions</option>
                    <option value="connexion" <?php echo $filtre_action === 'connexion' ? 'selected' : ''; ?>>Connexion</option>
                    <option value="deconnexion" <?php echo $filtre_action === 'deconnexion' ? 'selected' : ''; ?>>Déconnexion</option>
                    <option value="user.create" <?php echo $filtre_action === 'user.create' ? 'selected' : ''; ?>>Création de compte</option>
                    <option value="user.update" <?php echo $filtre_action === 'user.update' ? 'selected' : ''; ?>>Modification de compte</option>
                    <option value="user.suspend" <?php echo $filtre_action === 'user.suspend' ? 'selected' : ''; ?>>Suspension de compte</option>
                    <option value="user.reactivate" <?php echo $filtre_action === 'user.reactivate' ? 'selected' : ''; ?>>Réactivation de compte</option>
                    <option value="task." <?php echo $filtre_action === 'task.' ? 'selected' : ''; ?>>Actions sur tâches</option>
                    <option value="profile." <?php echo $filtre_action === 'profile.' ? 'selected' : ''; ?>>Actions sur profils</option>
                </select>
            </div>

            <!-- Intervalle de dates -->
            <div class="activite-filter-group date-interval-group">
                <label class="activite-filter-label">Période (Du / Au)</label>
                <div class="date-interval-inputs">
                    <input type="date" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>" class="activite-filter-input date-input" title="Date de début">
                    <span class="date-sep">à</span>
                    <input type="date" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>" class="activite-filter-input date-input" title="Date de fin">
                </div>
            </div>

            <!-- Recherche libre -->
            <div class="activite-filter-group search-group">
                <label class="activite-filter-label">Recherche</label>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Détail, mot-clé, IP..." class="activite-filter-input">
            </div>

            <!-- Bouton Filtrer et réinitialiser -->
            <div class="activite-filter-actions">
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.5rem 1rem; font-size: 0.82rem; height: 38px; display: inline-flex; align-items: center; gap: 6px; justify-content: center; width: 100%;">
                    <i class="fa-solid fa-filter"></i> Filtrer
                </button>
                <?php if ($filtre_user > 0 || !empty($filtre_action) || !empty($search) || !empty($date_debut) || !empty($date_fin)): ?>
                    <a href="activite.php" class="activite-reset-link" title="Réinitialiser tous les filtres">
                        <i class="fa-solid fa-xmark"></i> Effacer
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tableau Chronologique d'Audit -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-timeline" style="color: var(--primary);"></i> Flux d'Activité Récent
                (<?php echo count($logs); ?> événements)
            </h3>
        </div>

        <?php if (empty($logs)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-clock"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucune activité enregistrée correspondant à ces critères.
            </div>
        <?php else: ?>
            <div class="dash-table-wrapper activite-table-wrapper">
                <table class="dash-table activite-table">
                    <thead>
                        <tr>
                            <th>Date & Heure</th>
                            <th>Utilisateur</th>
                            <th>Action</th>
                            <th>Élément</th>
                            <th>Détail de l'Action</th>
                            <th>Adresse IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            // Badges stylisés par action
                            $action_map = [
                                'connexion' => ['Connexion', 'background: #dcfce7; color: #166534;', 'fa-right-to-bracket'],
                                'deconnexion' => ['Déconnexion', 'background: #f1f5f9; color: #64748b;', 'fa-right-from-bracket'],
                                'connexion.echec' => ['Échec Connexion', 'background: #fee2e2; color: #991b1b;', 'fa-triangle-exclamation'],
                                'connexion.refusee_suspension' => ['Accès refusé (Suspendu)', 'background: #fef2f2; color: #dc2626;', 'fa-ban'],
                                'user.create' => ['Création Compte', 'background: #e0f2fe; color: #0369a1;', 'fa-user-plus'],
                                'user.update' => ['Modification Compte', 'background: #fef3c7; color: #b45309;', 'fa-pen'],
                                'user.suspend' => ['Suspension Compte', 'background: #fee2e2; color: #dc2626;', 'fa-user-slash'],
                                'user.reactivate' => ['Réactivation Compte', 'background: #dcfce7; color: #166534;', 'fa-user-check'],
                                'user.auto_reactivated' => ['Réactivation Auto', 'background: #dcfce7; color: #166534;', 'fa-clock-rotate-left'],
                                'user.delete' => ['Suppression Compte', 'background: #fee2e2; color: #991b1b;', 'fa-trash'],
                                'profile.create' => ['Création Profil', 'background: #e0f2fe; color: #0369a1;', 'fa-shield-halved'],
                                'profile.update' => ['Modification Profil', 'background: #fef3c7; color: #b45309;', 'fa-shield'],
                                'profile.delete' => ['Suppression Profil', 'background: #fee2e2; color: #991b1b;', 'fa-trash'],
                                'task.create' => ['Création Tâche', 'background: #e0f2fe; color: #0369a1;', 'fa-plus'],
                                'task.update' => ['Modification Tâche', 'background: #fef3c7; color: #b45309;', 'fa-pen-to-square'],
                                'task.status_update' => ['Statut Tâche', 'background: #FFF2ED; color: #FF4A0D;', 'fa-arrows-rotate'],
                                'task.my_status_update' => ['Avancement Tâche', 'background: #FFF2ED; color: #000000;', 'fa-check'],
                                'task.delete' => ['Suppression Tâche', 'background: #F5F5F5; color: #000000;', 'fa-trash']
                            ];
                            [$a_label, $a_style, $a_ico] = $action_map[$log['action']] ?? [
                                htmlspecialchars($log['action']),
                                'background: #F5F5F5; color: #737373;',
                                'fa-circle-info'
                            ];

                            $author_str = !empty($log['user_nom'])
                                ? trim(($log['user_prenom'] ?? '') . ' ' . $log['user_nom'])
                                : ($log['user_id'] ? ("Utilisateur #" . $log['user_id']) : "Système");
                            ?>
                            <tr class="activite-row">
                                <!-- Date & Heure (Sur mobile, fusionné dans card-top avec l'action) -->
                                <td class="card-top" data-label="Date & Heure">
                                    <div class="card-date-box">
                                        <strong style="color: var(--navy); font-size: 0.84rem; display: block;">
                                            <?php echo date('d/m/Y', strtotime($log['created_at'])); ?>
                                        </strong>
                                        <small
                                            style="color: var(--muted); font-size: 0.72rem; font-family: 'Space Mono', monospace;">
                                            <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                        </small>
                                    </div>
                                    <div class="card-badge-mobile">
                                        <span
                                            style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $a_style; ?>">
                                            <i class="fa-solid <?php echo $a_ico; ?>" style="font-size: 0.65rem;"></i>
                                            <?php echo $a_label; ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Utilisateur auteur -->
                                <td data-label="Utilisateur">
                                    <div style="text-align: right;">
                                        <strong style="color: var(--navy); font-size: 0.84rem; display: block;">
                                            <?php echo htmlspecialchars($author_str); ?>
                                        </strong>
                                        <?php if (!empty($log['user_role'])): ?>
                                            <span class="activite-role-pill">
                                                <?php echo htmlspecialchars($log['user_role']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Action (affiché sur desktop, caché sur mobile car déjà dans card-top) -->
                                <td data-label="Action" class="hide-on-mobile-card">
                                    <span
                                        style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $a_style; ?>">
                                        <i class="fa-solid <?php echo $a_ico; ?>" style="font-size: 0.65rem;"></i>
                                        <?php echo $a_label; ?>
                                    </span>
                                </td>

                                <!-- Cible -->
                                <td data-label="Élément">
                                    <?php if (!empty($log['target_type'])): ?>
                                        <span
                                            style="font-size: 0.75rem; color: var(--navy); font-weight: 600; text-transform: capitalize;">
                                            <?php echo htmlspecialchars($log['target_type']); ?>
                                            <?php if ($log['target_id']): ?>
                                                <small
                                                    style="font-family: 'Space Mono', monospace; color: var(--muted);">#<?php echo $log['target_id']; ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #E5E5E5; font-size: 0.75rem;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Détail -->
                                <td data-label="Détail" class="card-details">
                                    <span class="activite-detail-text">
                                        <?php echo htmlspecialchars($log['details'] ?: 'Aucun détail supplémentaire'); ?>
                                    </span>
                                </td>

                                <!-- IP -->
                                <td data-label="Adresse IP" style="white-space: nowrap;">
                                    <small
                                        style="font-family: 'Space Mono', monospace; color: var(--muted); font-size: 0.72rem;">
                                        <?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?>
                                    </small>
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