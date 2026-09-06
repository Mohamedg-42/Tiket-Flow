<?php
// ==============================================================================
// MES TÂCHES — ESPACE COLLABORATEUR (admin/mes-taches.php)
// Interface personnelle pour suivre et mettre à jour l'avancement de ses tâches
// ==============================================================================

$admin_page_title = "Mes Tâches - Eventia";
include 'header.php';

requireLogin('../connexion.php');

$current_user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// Action : Mise à jour du statut par l'utilisateur assigné
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_my_status'])) {
    $task_id    = (int)$_POST['task_id'];
    $new_status = $_POST['new_status'] ?? '';

    if (in_array($new_status, ['a_faire', 'en_cours', 'termine'], true)) {
        // Vérification stricte : la tâche doit appartenir à l'utilisateur connecté (ou admin)
        $stmt_chk = $pdo->prepare("SELECT id, titre, statut FROM tasks WHERE id = ? AND (user_id = ? OR ? = 'admin')");
        $stmt_chk->execute([$task_id, $current_user_id, $_SESSION['user_role'] ?? '']);
        $my_task = $stmt_chk->fetch();

        if ($my_task) {
            $stmt_up = $pdo->prepare("UPDATE tasks SET statut = ? WHERE id = ?");
            $stmt_up->execute([$new_status, $task_id]);

            logActivity('task.my_status_update', 'task', $task_id, "Statut de ma tâche « {$my_task['titre']} » passé de '{$my_task['statut']}' à '$new_status'");
            $message = "Le statut de votre tâche a été mis à jour avec succès !";
            $msg_type = "success";
        } else {
            $message = "Vous n'êtes pas autorisé à modifier cette tâche.";
            $msg_type = "error";
        }
    }
}

// Récupération de mes tâches
$stmt_my = $pdo->prepare("
    SELECT t.*, c.nom AS creator_nom, c.prenom AS creator_prenom
    FROM tasks t
    LEFT JOIN users c ON t.created_by = c.id
    WHERE t.user_id = ?
    ORDER BY (CASE t.priorite WHEN 'urgente' THEN 1 WHEN 'haute' THEN 2 WHEN 'normale' THEN 3 WHEN 'faible' THEN 4 ELSE 5 END), t.date_limite ASC, t.id DESC
");
$stmt_my->execute([$current_user_id]);
$all_my_tasks = $stmt_my->fetchAll();

// Répartition par colonne kanban
$tasks_afaire  = array_filter($all_my_tasks, fn($t) => $t['statut'] === 'a_faire');
$tasks_encours = array_filter($all_my_tasks, fn($t) => $t['statut'] === 'en_cours');
$tasks_termine = array_filter($all_my_tasks, fn($t) => $t['statut'] === 'termine');
?>

<div class="dash-container">
    <!-- En-tête -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-thumbtack" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Mes Tâches Personnelles
            </h1>
            <p>Retrouvez vos missions en cours, mettez à jour votre avancement et respectez les échéances.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; align-items: center; flex-wrap: wrap;">
            <a href="export.php?type=taches" class="dash-btn-action" style="text-decoration: none;" title="Exporter les tâches sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <?php if (hasPermission('tasks.manage')): ?>
                <a href="taches.php" class="dash-btn-action" style="text-decoration: none;">
                    <i class="fa-solid fa-list-check"></i> Voir toutes les tâches de l'équipe
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alertes -->
    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 0.85rem 1.15rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.88rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Vue Tableau de Bord Kanban (3 Colonnes Réactives) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 1.25rem; align-items: start;">
        
        <!-- 1. Colonne : À FAIRE -->
        <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <span style="font-size: 0.88rem; font-weight: 800; color: #FF4A0D; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-dot" style="font-size: 0.75rem;"></i> À FAIRE
                </span>
                <span style="background: #FFF2ED; color: #FF4A0D; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 999px;">
                    <?php echo count($tasks_afaire); ?>
                </span>
            </div>

            <div style="display: grid; gap: 0.85rem;">
                <?php if (empty($tasks_afaire)): ?>
                    <div style="background: #ffffff; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 2rem 1rem; text-align: center; color: var(--dash-muted); font-size: 0.82rem;">
                        Aucune tâche en attente.
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks_afaire as $task): ?>
                        <?php renderTaskCard($task); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. Colonne : EN COURS -->
        <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <span style="font-size: 0.88rem; font-weight: 800; color: #FF4A0D; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-spinner" style="font-size: 0.75rem;"></i> EN COURS
                </span>
                <span style="background: #FFF2ED; color: #FF4A0D; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 999px;">
                    <?php echo count($tasks_encours); ?>
                </span>
            </div>

            <div style="display: grid; gap: 0.85rem;">
                <?php if (empty($tasks_encours)): ?>
                    <div style="background: #ffffff; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 2rem 1rem; text-align: center; color: var(--dash-muted); font-size: 0.82rem;">
                        Aucune tâche en cours d'exécution.
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks_encours as $task): ?>
                        <?php renderTaskCard($task); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. Colonne : TERMINÉES -->
        <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <span style="font-size: 0.88rem; font-weight: 800; color: #FF4A0D; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 0.75rem;"></i> TERMINÉES
                </span>
                <span style="background: #FFF2ED; color: #000000; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 999px;">
                    <?php echo count($tasks_termine); ?>
                </span>
            </div>

            <div style="display: grid; gap: 0.85rem;">
                <?php if (empty($tasks_termine)): ?>
                    <div style="background: #ffffff; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 2rem 1rem; text-align: center; color: var(--dash-muted); font-size: 0.82rem;">
                        Aucune tâche terminée récemment.
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks_termine as $task): ?>
                        <?php renderTaskCard($task); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php
/**
 * Rendu d'une carte de tâche individuelle
 */
function renderTaskCard($t) {
    $prio_info = [
        'urgente' => ['Urgente', 'background: #F5F5F5; color: #000000;', 'fa-triangle-exclamation'],
        'haute'   => ['Haute',   'background: #FFF2ED; color: #FF4A0D;', 'fa-angles-up'],
        'normale' => ['Normale', 'background: #FFF2ED; color: #FF4A0D;', 'fa-minus'],
        'faible'  => ['Faible',  'background: #F5F5F5; color: #737373;', 'fa-arrow-down']
    ];
    [$p_txt, $p_css, $p_ico] = $prio_info[$t['priorite']] ?? ['Normale', 'background: #FFF2ED; color: #FF4A0D;', 'fa-minus'];

    $is_late = ($t['statut'] !== 'termine' && !empty($t['date_limite']) && $t['date_limite'] < date('Y-m-d'));
    ?>
    <div class="dash-card" style="padding: 1.1rem; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.5rem;">
            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; border-radius: 4px; font-size: 0.68rem; font-weight: 700; <?php echo $p_css; ?>">
                <i class="fa-solid <?php echo $p_ico; ?>" style="font-size: 0.6rem;"></i> <?php echo $p_txt; ?>
            </span>

            <?php if (!empty($t['date_limite'])): ?>
                <span style="font-size: 0.72rem; font-weight: 700; <?php echo $is_late ? 'color: #000000;' : 'color: var(--muted);'; ?>">
                    <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($t['date_limite'])); ?>
                </span>
            <?php endif; ?>
        </div>

        <strong style="color: var(--navy); font-size: 0.92rem; display: block; line-height: 1.35; margin-bottom: 0.4rem;">
            <?php echo htmlspecialchars($t['titre']); ?>
        </strong>

        <?php if (!empty($t['description'])): ?>
            <p style="color: var(--muted); font-size: 0.78rem; margin: 0 0 0.85rem; line-height: 1.45;">
                <?php echo nl2br(htmlspecialchars($t['description'])); ?>
            </p>
        <?php endif; ?>

        <!-- Changement de statut personnel -->
        <form method="POST" action="mes-taches.php" style="margin: 0; padding-top: 0.65rem; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
            <input type="hidden" name="update_my_status" value="1">
            <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
            
            <small style="color: var(--muted); font-size: 0.7rem;">Statut :</small>
            <select name="new_status" onchange="this.form.submit()" style="padding: 3px 8px; border-radius: 6px; font-size: 0.74rem; font-weight: 700; border: 1px solid var(--dash-border); background: #ffffff; cursor: pointer;">
                <option value="a_faire" <?php echo $t['statut'] === 'a_faire' ? 'selected' : ''; ?>>À faire</option>
                <option value="en_cours" <?php echo $t['statut'] === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                <option value="termine" <?php echo $t['statut'] === 'termine' ? 'selected' : ''; ?>>Terminé</option>
            </select>
        </form>
    </div>
    <?php
}
?>

<?php include 'footer.php'; ?>
