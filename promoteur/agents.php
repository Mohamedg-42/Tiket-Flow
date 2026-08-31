<?php
// ==============================================================================
// GESTION DES AGENTS DU PROMOTEUR (promoteur/agents.php)
// Liaison stricte entre l'Agent, le Promoteur et l'Événement à contrôler
// ==============================================================================

$page_title = "Mes Agents de Contrôle - Espace Promoteur";
include 'header.php';

$promoter_user_id = (int)$_SESSION['user_id'];

$message = "";
$msg_type = "";

// 1. Récupération des événements du promoteur pour la liste déroulante
$stmt_ev = $pdo->prepare("SELECT id, nom, date_evenement, lieu, statut FROM events WHERE user_id = ? ORDER BY date_evenement DESC");
$stmt_ev->execute([$promoter_user_id]);
$my_events = $stmt_ev->fetchAll();

// 2. Traitement de la création d'un agent assigné à un événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_agent'])) {
    $nom       = trim($_POST['nom'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $event_id  = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

    if (empty($nom) || empty($email) || empty($password) || !$event_id) {
        $message = "Veuillez renseigner tous les champs obligatoires et sélectionner l'événement à contrôler.";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "L'adresse email de l'agent est invalide.";
        $msg_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Le mot de passe de l'agent doit comporter au moins 6 caractères.";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Création ou récupération du compte utilisateur Agent
            $stmt_chk = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $stmt_chk->execute([$email]);
            $existing_user = $stmt_chk->fetch();

            if ($existing_user) {
                if ($existing_user['role'] !== 'agent') {
                    throw new Exception("Cette adresse email est déjà associée à un compte non-agent.");
                }
                $agent_id = (int)$existing_user['id'];
            } else {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO users (nom, email, telephone, password, role, est_verifie) 
                    VALUES (?, ?, ?, ?, 'agent', 1)
                ");
                $stmt_ins->execute([$nom, $email, $telephone, $password_hash]);
                $agent_id = (int)$pdo->lastInsertId();
            }

            // Liaison de l'agent au promoteur et à l'événement spécifique
            $stmt_assign = $pdo->prepare("
                INSERT INTO agent_assignments (agent_id, promoter_user_id, event_id) 
                VALUES (?, ?, ?)
            ");
            $stmt_assign->execute([$agent_id, $promoter_user_id, $event_id]);

            $pdo->commit();

            $message = "L'agent « " . htmlspecialchars($nom) . " » a été assigné avec succès au contrôle de l'événement !";
            $msg_type = "success";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $message = "Cet agent est déjà assigné à cet événement.";
            } else {
                $message = "Erreur : " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}

// 3. Suppression d'une affectation d'agent
if (isset($_GET['delete_assign'])) {
    $del_assign_id = (int)$_GET['delete_assign'];
    $stmt_del = $pdo->prepare("DELETE FROM agent_assignments WHERE id = ? AND promoter_user_id = ?");
    $stmt_del->execute([$del_assign_id, $promoter_user_id]);
    $message = "L'affectation de l'agent a été retirée.";
    $msg_type = "success";
}

// 4. Récupération des agents liés à ce promoteur avec l'événement assigné
$sql_agents = "
    SELECT aa.id AS assign_id, aa.created_at AS assign_date,
           u.id AS agent_id, u.nom AS agent_nom, u.email AS agent_email, u.telephone AS agent_tel,
           e.id AS event_id, e.nom AS event_nom, e.date_evenement, e.lieu
    FROM agent_assignments aa
    JOIN users u ON aa.agent_id = u.id
    JOIN events e ON aa.event_id = e.id
    WHERE aa.promoter_user_id = ?
    ORDER BY aa.id DESC
";
$stmt_assigned = $pdo->prepare($sql_agents);
$stmt_assigned->execute([$promoter_user_id]);
$assigned_agents = $stmt_assigned->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker"><i class="fa-solid fa-shield-halved"></i> Contrôle d'Accès aux Portes</span>
        <h1>Mes Agents de Contrôle & Événements Assignés</h1>
        <p>Chaque agent est rattaché à votre compte promoteur et à un événement précis pour lequel il valide les QR codes.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: minmax(320px, 1fr) minmax(380px, 1.4fr); gap: 1.5rem; align-items: start;">
    
    <!-- 1. Formulaire d'ajout d'agent avec choix de l'événement -->
    <div class="content-section">
        <div class="section-title">
            <i class="fa-solid fa-user-plus"></i> Assigner un Agent à un Événement
        </div>

        <?php if (count($my_events) > 0): ?>
            <form method="POST" action="agents.php">
                <input type="hidden" name="creer_agent" value="1">

                <div class="form-group">
                    <label for="event_id"><i class="fa-solid fa-calendar-check" style="color: var(--primary);"></i> Événement à contrôler *</label>
                    <select name="event_id" id="event_id" required style="font-weight: 700;">
                        <option value="">-- Sélectionner l'événement --</option>
                        <?php foreach ($my_events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>">
                                <?php echo htmlspecialchars($ev['nom']); ?> (<?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--muted); display: block; margin-top: 3px; font-size: 0.78rem;">
                        L'agent ne pourra scanner que les billets achetés pour cet événement.
                    </small>
                </div>

                <div class="form-group">
                    <label for="nom"><i class="fa-solid fa-user"></i> Nom & Prénom de l'agent *</label>
                    <input type="text" id="nom" name="nom" required placeholder="Ex: Agent Touré Bakary" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email"><i class="fa-solid fa-envelope"></i> Email de connexion de l'agent *</label>
                    <input type="email" id="email" name="email" required placeholder="agent.toure@ticketflow.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="telephone"><i class="fa-solid fa-phone"></i> Numéro de Téléphone</label>
                    <input type="tel" id="telephone" name="telephone" placeholder="Ex: 07 00 00 00 00" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password"><i class="fa-solid fa-lock"></i> Mot de passe de l'agent *</label>
                    <input type="password" id="password" name="password" required placeholder="Minimum 6 caractères">
                </div>

                <button type="submit" class="btn-submit" style="margin-top: 0.5rem;">
                    <i class="fa-solid fa-shield-check"></i> Créer & Assigner l'Agent
                </button>
            </form>
        <?php else: ?>
            <div style="text-align: center; padding: 2rem 1rem; color: var(--muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.75rem; display: block;"></i>
                <strong style="color: var(--navy); display: block; margin-bottom: 0.35rem;">Aucun événement créé</strong>
                Vous devez d'abord créer ou avoir un événement approuvé avant de pouvoir y affecter des agents de contrôle.<br>
                <a href="demande-evenement.php" class="btn-submit" style="display: inline-block; width: auto; margin-top: 1rem; text-decoration: none; padding: 0.6rem 1.25rem;">
                    <i class="fa-solid fa-plus"></i> Proposer un Événement
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. Liste des agents avec leur événement assigné -->
    <div class="content-section">
        <div class="section-title">
            <i class="fa-solid fa-clipboard-user"></i> Vos Agents Actifs & Postes de Scan (<?php echo count($assigned_agents); ?>)
        </div>

        <div class="table-wrapper">
            <table class="events-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Événement Assigné</th>
                        <th>Date & Lieu</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($assigned_agents) > 0): ?>
                        <?php foreach ($assigned_agents as $ag): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--navy); display: block;"><?php echo htmlspecialchars($ag['agent_nom']); ?></strong>
                                    <small style="color: var(--muted); font-size: 0.8rem; display: block;">
                                        <i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($ag['agent_email']); ?>
                                    </small>
                                    <small style="color: var(--muted); font-size: 0.8rem;">
                                        <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($ag['agent_tel'] ?: '-'); ?>
                                    </small>
                                </td>

                                <td>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 0.84rem; display: inline-block;">
                                        <i class="fa-solid fa-calendar-check"></i> <?php echo htmlspecialchars($ag['event_nom']); ?>
                                    </span>
                                </td>

                                <td>
                                    <small style="color: var(--muted); display: block;">
                                        <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ag['date_evenement'])); ?>
                                    </small>
                                    <small style="color: var(--muted); display: block;">
                                        <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ag['lieu']); ?>
                                    </small>
                                </td>

                                <td style="text-align: right;">
                                    <a href="?delete_assign=<?php echo $ag['assign_id']; ?>" onclick="return confirm('Retirer cet agent du contrôle de cet événement ?')" style="color: #ef4444; font-size: 0.85rem; text-decoration: none; font-weight: 600;">
                                        <i class="fa-solid fa-user-xmark"></i> Retirer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                                <i class="fa-solid fa-users" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.75rem; display: block;"></i>
                                Aucun agent n'est actuellement assigné à vos événements.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
