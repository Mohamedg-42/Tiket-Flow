<?php
// ==============================================================================
// GESTION DES TÂCHES — VUE ADMINISTRATEUR (admin/taches.php)
// Attribution, suivi, priorités et échéances pour les collaborateurs Eventia
// ==============================================================================

$admin_page_title = "Gestion des Tâches - Administration";
include 'header.php';

requirePermission('tasks.manage');

$message = "";
$msg_type = "";

// 1. Liste des utilisateurs pour assignation
$assignable_users = $pdo->query("
    SELECT id, nom, prenom, role, email 
    FROM users 
    WHERE statut = 'actif' 
    ORDER BY role ASC, nom ASC
")->fetchAll();

// ==============================================================================
// ACTIONS POST
// ==============================================================================

// Action A : Création d'une tâche
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $user_id = (int) $_POST['user_id'];
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priorite = $_POST['priorite'] ?? 'normale';
    $date_debut = !empty($_POST['date_debut']) ? $_POST['date_debut'] : date('Y-m-d');
    $date_limite = !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;

    if (empty($titre) || empty($user_id)) {
        $message = "Veuillez renseigner le titre et sélectionner un utilisateur.";
        $msg_type = "error";
    } else {
        $stmt_task = $pdo->prepare("
            INSERT INTO tasks (user_id, created_by, titre, description, priorite, statut, date_debut, date_limite) 
            VALUES (?, ?, ?, ?, ?, 'a_faire', ?, ?)
        ");
        $stmt_task->execute([
            $user_id,
            (int) $_SESSION['user_id'],
            $titre,
            $description,
            $priorite,
            $date_debut,
            $date_limite
        ]);
        $task_id = (int) $pdo->lastInsertId();

        // Récupérer le nom de l'assigné pour le log
        $stmt_u = $pdo->prepare("SELECT nom, prenom FROM users WHERE id = ?");
        $stmt_u->execute([$user_id]);
        $dest = $stmt_u->fetch();
        $dest_name = trim(($dest['prenom'] ?? '') . ' ' . ($dest['nom'] ?? ''));

        logActivity('task.create', 'task', $task_id, "Tâche « $titre » assignée à $dest_name (Priorité: $priorite, Échéance: " . ($date_limite ?: 'aucune') . ")");
        $message = "La tâche a été créée et attribuée avec succès !";
        $msg_type = "success";
    }
}

// Action B : Modification d'une tâche
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])) {
    $task_id = (int) $_POST['task_id'];
    $user_id = (int) $_POST['user_id'];
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priorite = $_POST['priorite'] ?? 'normale';
    $statut = $_POST['statut'] ?? 'a_faire';
    $date_debut = !empty($_POST['date_debut']) ? $_POST['date_debut'] : null;
    $date_limite = !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;

    if (empty($titre) || empty($user_id)) {
        $message = "Le titre et l'utilisateur sont obligatoires.";
        $msg_type = "error";
    } else {
        $stmt_up = $pdo->prepare("
            UPDATE tasks 
            SET user_id = ?, titre = ?, description = ?, priorite = ?, statut = ?, date_debut = ?, date_limite = ? 
            WHERE id = ?
        ");
        $stmt_up->execute([$user_id, $titre, $description, $priorite, $statut, $date_debut, $date_limite, $task_id]);

        logActivity('task.update', 'task', $task_id, "Mise à jour de la tâche « $titre » (Statut: $statut, Priorité: $priorite)");
        $message = "La tâche #$task_id a été mise à jour avec succès.";
        $msg_type = "success";
    }
}

// Action C : Changement rapide de statut
if (isset($_GET['set_statut']) && isset($_GET['task_id'])) {
    $t_id = (int) $_GET['task_id'];
    $n_stat = $_GET['set_statut'];

    if (in_array($n_stat, ['a_faire', 'en_cours', 'termine', 'annule'], true)) {
        $pdo->prepare("UPDATE tasks SET statut = ? WHERE id = ?")->execute([$n_stat, $t_id]);
        logActivity('task.status_update', 'task', $t_id, "Statut de la tâche #$t_id changé à « $n_stat »");
        $message = "Le statut de la tâche a été mis à jour.";
        $msg_type = "success";
    }
}

// Action D : Suppression d'une tâche
if (isset($_GET['delete_task'])) {
    $del_id = (int) $_GET['delete_task'];
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$del_id]);
    logActivity('task.delete', 'task', $del_id, "Suppression de la tâche #$del_id");
    $message = "Tâche supprimée avec succès.";
    $msg_type = "success";
}

// ==============================================================================
// FILTRES ET CONSULTATION
// ==============================================================================
$filtre_user = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: 0;
$filtre_statut = trim($_GET['statut'] ?? '');
$filtre_priorite = trim($_GET['priorite'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT t.*,
           u.nom AS user_nom, u.prenom AS user_prenom, u.role AS user_role,
           c.nom AS creator_nom, c.prenom AS creator_prenom
    FROM tasks t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users c ON t.created_by = c.id
    WHERE 1=1
";
$params = [];

if ($filtre_user > 0) {
    $sql .= " AND t.user_id = ?";
    $params[] = $filtre_user;
}

if (!empty($filtre_statut) && in_array($filtre_statut, ['a_faire', 'en_cours', 'termine', 'annule'], true)) {
    $sql .= " AND t.statut = ?";
    $params[] = $filtre_statut;
}

if (!empty($filtre_priorite) && in_array($filtre_priorite, ['faible', 'normale', 'haute', 'urgente'], true)) {
    $sql .= " AND t.priorite = ?";
    $params[] = $filtre_priorite;
}

if (!empty($search)) {
    $sql .= " AND (t.titre LIKE ? OR t.description LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY (CASE t.priorite WHEN 'urgente' THEN 1 WHEN 'haute' THEN 2 WHEN 'normale' THEN 3 WHEN 'faible' THEN 4 ELSE 5 END), t.date_limite ASC, t.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// KPIs globaux des tâches
$tot_tasks = (int) $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$tot_afaire = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE statut = 'a_faire'")->fetchColumn();
$tot_encours = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE statut = 'en_cours'")->fetchColumn();
$tot_termine = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE statut = 'termine'")->fetchColumn();
$tot_retard = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE statut IN ('a_faire', 'en_cours') AND date_limite < CURRENT_DATE")->fetchColumn();
?>

<div class="dash-container">
    <!-- En-tête -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-list-check" style="color: var(--primary); font-size: 1.55rem;"></i>
                Gestion des Tâches Collaboratives
            </h1>
            <p>Supervisez toutes les tâches de l'équipe, attribuez de nouvelles missions et suivez les échéances en
                temps réel.</p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
            <a href="export.php?type=taches" class="dash-btn-action" style="text-decoration: none;" title="Exporter toutes les tâches sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="mes-taches.php" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-thumbtack" style="color: #FF4A0D;"></i> Mes Propres Tâches
            </a>
            <button type="button" onclick="openCreateTaskModal()" class="dash-btn-action btn-primary"
                style="display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-plus"></i> Nouvelle Tâche
            </button>
        </div>
    </div>

    <!-- Alertes -->
    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 0.85rem 1.15rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.88rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- KPIs Tâches -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase;">Total
                Tâches</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--navy); margin-top: 2px;">
                <?php echo $tot_tasks; ?></div>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">À
                Faire</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #FF4A0D; margin-top: 2px;">
                <?php echo $tot_afaire; ?></div>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">En
                Cours</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #FF4A0D; margin-top: 2px;">
                <?php echo $tot_encours; ?></div>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span
                style="font-size: 0.75rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Terminées</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #FF4A0D; margin-top: 2px;">
                <?php echo $tot_termine; ?></div>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <span style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase;">En
                Retard</span>
            <div style="font-size: 1.6rem; font-weight: 800; color: #000000; margin-top: 2px;">
                <?php echo $tot_retard; ?></div>
        </div>
    </div>

    <!-- Filtres & Recherche -->
    <div
        style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Onglets statut -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=&priorite=<?php echo urlencode($filtre_priorite); ?>&user_id=<?php echo $filtre_user; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.8rem; font-weight: 700; <?php echo $filtre_statut === '' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Toutes
            </a>
            <a href="?statut=a_faire&priorite=<?php echo urlencode($filtre_priorite); ?>&user_id=<?php echo $filtre_user; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.8rem; font-weight: 700; <?php echo $filtre_statut === 'a_faire' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                À faire (<?php echo $tot_afaire; ?>)
            </a>
            <a href="?statut=en_cours&priorite=<?php echo urlencode($filtre_priorite); ?>&user_id=<?php echo $filtre_user; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.8rem; font-weight: 700; <?php echo $filtre_statut === 'en_cours' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                En cours (<?php echo $tot_encours; ?>)
            </a>
            <a href="?statut=termine&priorite=<?php echo urlencode($filtre_priorite); ?>&user_id=<?php echo $filtre_user; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.8rem; font-weight: 700; <?php echo $filtre_statut === 'termine' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Terminées (<?php echo $tot_termine; ?>)
            </a>
        </div>

        <!-- Formulaire filtres déroulants -->
        <form method="GET" action="taches.php"
            style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filtre_statut); ?>">

            <select name="user_id" onchange="this.form.submit()"
                style="padding: 0.4rem 0.6rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem; background: #ffffff; max-width: 170px;">
                <option value="0">Tous les membres</option>
                <?php foreach ($assignable_users as $au): ?>
                    <option value="<?php echo $au['id']; ?>" <?php echo $filtre_user === (int) $au['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(trim(($au['prenom'] ?? '') . ' ' . $au['nom'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="priorite" onchange="this.form.submit()"
                style="padding: 0.4rem 0.6rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem; background: #ffffff;">
                <option value="">Toutes priorités</option>
                <option value="urgente" <?php echo $filtre_priorite === 'urgente' ? 'selected' : ''; ?>>Urgente</option>
                <option value="haute" <?php echo $filtre_priorite === 'haute' ? 'selected' : ''; ?>>Haute</option>
                <option value="normale" <?php echo $filtre_priorite === 'normale' ? 'selected' : ''; ?>>Normale</option>
                <option value="faible" <?php echo $filtre_priorite === 'faible' ? 'selected' : ''; ?>>Faible</option>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Titre, description..."
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem; width: 140px; background: #ffffff;">

            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.75rem; font-size: 0.8rem; background: var(--primary); color: #ffffff;">Filtrer</button>
            <?php if ($filtre_user > 0 || $filtre_statut !== '' || $filtre_priorite !== '' || $search !== ''): ?>
                <a href="taches.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tableau des tâches -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list" style="color: var(--primary);"></i> Liste des Tâches
                (<?php echo count($tasks); ?>)
            </h3>
        </div>

        <?php if (empty($tasks)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-circle-check"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucune tâche ne correspond aux critères sélectionnés.
            </div>
        <?php else: ?>
            <div class="dash-table-wrapper">
                <table class="dash-pro-table">
                    <thead>
                        <tr>
                            <th>Tâche & Description</th>
                            <th>Assignée à</th>
                            <th>Priorité</th>
                            <th>Échéance</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $t): ?>
                            <?php
                            $prio_badges = [
                                'urgente' => ['Urgente', 'background: #F5F5F5; color: #000000;', 'fa-triangle-exclamation'],
                                'haute' => ['Haute', 'background: #FFF2ED; color: #FF4A0D;', 'fa-angles-up'],
                                'normale' => ['Normale', 'background: #FFF2ED; color: #FF4A0D;', 'fa-minus'],
                                'faible' => ['Faible', 'background: #F5F5F5; color: #737373;', 'fa-arrow-down']
                            ];
                            [$p_txt, $p_css, $p_icon] = $prio_badges[$t['priorite']] ?? ['Normale', 'background: #FFF2ED; color: #FF4A0D;', 'fa-minus'];

                            $statut_badges = [
                                'a_faire' => ['À faire', 'background: #FFF2ED; color: #FF4A0D;'],
                                'en_cours' => ['En cours', 'background: #FFF2ED; color: #FF4A0D;'],
                                'termine' => ['Terminé', 'background: #FFF2ED; color: #000000;'],
                                'annule' => ['Annulé', 'background: #F5F5F5; color: #737373;']
                            ];
                            [$s_txt, $s_css] = $statut_badges[$t['statut']] ?? ['À faire', 'background: #FFF2ED; color: #FF4A0D;'];

                            $is_late = ($t['statut'] !== 'termine' && $t['statut'] !== 'annule' && !empty($t['date_limite']) && $t['date_limite'] < date('Y-m-d'));
                            $assigned_name = trim(($t['user_prenom'] ?? '') . ' ' . ($t['user_nom'] ?? 'Inconnu'));
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--navy); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($t['titre']); ?>
                                    </strong>
                                    <?php if (!empty($t['description'])): ?>
                                        <small
                                            style="color: var(--muted); font-size: 0.74rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 380px;">
                                            <?php echo htmlspecialchars($t['description']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span style="font-size: 0.82rem; font-weight: 700; color: var(--navy); display: block;">
                                        <i class="fa-regular fa-user" style="color: var(--muted); width: 14px;"></i>
                                        <?php echo htmlspecialchars($assigned_name); ?>
                                    </span>
                                    <small style="color: var(--muted); font-size: 0.7rem; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($t['user_role'] ?? ''); ?>
                                    </small>
                                </td>

                                <td>
                                    <span
                                        style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $p_css; ?>">
                                        <i class="fa-solid <?php echo $p_icon; ?>" style="font-size: 0.65rem;"></i>
                                        <?php echo $p_txt; ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (!empty($t['date_limite'])): ?>
                                        <span
                                            style="font-size: 0.8rem; font-weight: 600; <?php echo $is_late ? 'color: #000000;' : 'color: var(--muted);'; ?>">
                                            <?php if ($is_late): ?><i class="fa-solid fa-triangle-exclamation"></i><?php endif; ?>
                                            <?php echo date('d/m/Y', strtotime($t['date_limite'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #E5E5E5; font-size: 0.78rem;">Sans échéance</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <!-- Sélecteur rapide de statut -->
                                    <select
                                        onchange="window.location.href='taches.php?task_id=<?php echo $t['id']; ?>&set_statut=' + this.value"
                                        style="padding: 2px 6px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(0,0,0,0.06); cursor: pointer; <?php echo $s_css; ?>">
                                        <option value="a_faire" <?php echo $t['statut'] === 'a_faire' ? 'selected' : ''; ?>>À
                                            faire</option>
                                        <option value="en_cours" <?php echo $t['statut'] === 'en_cours' ? 'selected' : ''; ?>>En
                                            cours</option>
                                        <option value="termine" <?php echo $t['statut'] === 'termine' ? 'selected' : ''; ?>>
                                            Terminé</option>
                                        <option value="annule" <?php echo $t['statut'] === 'annule' ? 'selected' : ''; ?>>Annulé
                                        </option>
                                    </select>
                                </td>

                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 4px;">
                                        <button type="button" class="dash-btn-action"
                                            style="padding: 0.35rem 0.6rem; font-size: 0.74rem;"
                                            onclick='openEditTaskModal(<?php echo json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <a href="taches.php?delete_task=<?php echo $t['id']; ?>" class="dash-btn-action"
                                            style="padding: 0.35rem 0.6rem; font-size: 0.74rem; color: #000000;"
                                            onclick="return confirm('Supprimer cette tâche ?');">
                                            <i class="fa-solid fa-trash"></i>
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

<!-- ==============================================================================
     MODALE 1 : CRÉATION D'UNE TÂCHE
     ============================================================================== -->
<div id="createTaskModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeCreateTaskModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 520px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-plus-circle" style="color: var(--primary);"></i> Attribuer une Nouvelle Tâche</h3>
            <button type="button" class="dash-modal-close" onclick="closeCreateTaskModal()">&times;</button>
        </div>
        <form method="POST" action="taches.php">
            <input type="hidden" name="create_task" value="1">
            <div class="dash-modal-body" style="display: grid; gap: 0.85rem;">
                <div class="form-group">
                    <label>Membre assigné *</label>
                    <select name="user_id" required>
                        <option value="">-- Sélectionner le collaborateur --</option>
                        <?php foreach ($assignable_users as $au): ?>
                            <option value="<?php echo $au['id']; ?>">
                                <?php echo htmlspecialchars(trim(($au['prenom'] ?? '') . ' ' . $au['nom']) . " (" . strtoupper($au['role']) . ")"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Titre de la tâche *</label>
                    <input type="text" name="titre" required placeholder="Ex: Vérifier les événements en attente">
                </div>

                <div class="form-group">
                    <label>Description détaillée</label>
                    <textarea name="description" rows="3"
                        placeholder="Instructions précises pour l'exécution..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.6rem;">
                    <div class="form-group">
                        <label>Priorité *</label>
                        <select name="priorite" required>
                            <option value="faible">Faible</option>
                            <option value="normale" selected>Normale</option>
                            <option value="haute">Haute</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date de début</label>
                        <input type="date" name="date_debut" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Échéance (Date limite)</label>
                        <input type="date" name="date_limite"
                            value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeCreateTaskModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Créer la Tâche</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE 2 : MODIFICATION D'UNE TÂCHE
     ============================================================================== -->
<div id="editTaskModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeEditTaskModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 520px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i> Modifier la Tâche</h3>
            <button type="button" class="dash-modal-close" onclick="closeEditTaskModal()">&times;</button>
        </div>
        <form method="POST" action="taches.php">
            <input type="hidden" name="update_task" value="1">
            <input type="hidden" name="task_id" id="edit_task_id">
            <div class="dash-modal-body" style="display: grid; gap: 0.85rem;">
                <div class="form-group">
                    <label>Membre assigné *</label>
                    <select name="user_id" id="edit_task_user_id" required>
                        <?php foreach ($assignable_users as $au): ?>
                            <option value="<?php echo $au['id']; ?>">
                                <?php echo htmlspecialchars(trim(($au['prenom'] ?? '') . ' ' . $au['nom']) . " (" . strtoupper($au['role']) . ")"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Titre de la tâche *</label>
                    <input type="text" name="titre" id="edit_task_titre" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_task_desc" rows="3"></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Priorité *</label>
                        <select name="priorite" id="edit_task_prio" required>
                            <option value="faible">Faible</option>
                            <option value="normale">Normale</option>
                            <option value="haute">Haute</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Statut *</label>
                        <select name="statut" id="edit_task_statut" required>
                            <option value="a_faire">À faire</option>
                            <option value="en_cours">En cours</option>
                            <option value="termine">Terminé</option>
                            <option value="annule">Annulé</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Date de début</label>
                        <input type="date" name="date_debut" id="edit_task_debut">
                    </div>

                    <div class="form-group">
                        <label>Date limite</label>
                        <input type="date" name="date_limite" id="edit_task_limite">
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeEditTaskModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer les Modifications</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateTaskModal() {
        document.getElementById('createTaskModal').style.display = 'flex';
    }
    function closeCreateTaskModal() {
        document.getElementById('createTaskModal').style.display = 'none';
    }

    function openEditTaskModal(t) {
        document.getElementById('edit_task_id').value = t.id;
        document.getElementById('edit_task_user_id').value = t.user_id;
        document.getElementById('edit_task_titre').value = t.titre || '';
        document.getElementById('edit_task_desc').value = t.description || '';
        document.getElementById('edit_task_prio').value = t.priorite || 'normale';
        document.getElementById('edit_task_statut').value = t.statut || 'a_faire';
        document.getElementById('edit_task_debut').value = t.date_debut || '';
        document.getElementById('edit_task_limite').value = t.date_limite || '';
        document.getElementById('editTaskModal').style.display = 'flex';
    }
    function closeEditTaskModal() {
        document.getElementById('editTaskModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>