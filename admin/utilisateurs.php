<?php
// ==============================================================================
// GESTION DES UTILISATEURS & CRÉATION D'AGENTS (admin/utilisateurs.php)
// Affichage, recherche, filtrage et création d'agents / utilisateurs
// ==============================================================================

$admin_page_title = "Gestion des Utilisateurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// Action : Création directe d'un agent ou utilisateur par l'admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $nom       = trim($_POST['nom'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = $_POST['role'] ?? 'agent';

    if (empty($nom) || empty($email) || empty($password)) {
        $message = "Veuillez remplir tous les champs obligatoires.";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "L'adresse email est invalide.";
        $msg_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Le mot de passe doit comporter au moins 6 caractères.";
        $msg_type = "error";
    } else {
        try {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt_add = $pdo->prepare("INSERT INTO users (nom, email, telephone, password, role, est_verifie) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt_add->execute([$nom, $email, $telephone, $pass_hash, $role]);

            $message = "Le compte « " . htmlspecialchars($nom) . " » (rôle: " . strtoupper($role) . ") a été créé avec succès !";
            $msg_type = "success";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                $message = "Cette adresse email est déjà utilisée.";
            } else {
                $message = "Erreur : " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}

// Action : Modification de rôle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $user_id  = (int)$_POST['user_id'];
    $new_role = $_POST['new_role'] ?? 'client';

    if (in_array($new_role, ['client', 'promoteur', 'agent', 'admin'], true)) {
        $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$new_role, $user_id]);
        $message = "Le rôle de l'utilisateur a été mis à jour avec succès.";
        $msg_type = "success";
    }
}

// Action : Suppression d'un utilisateur
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id === (int)$_SESSION['user_id']) {
        $message = "Vous ne pouvez pas supprimer votre propre compte administrateur.";
        $msg_type = "error";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$del_id]);
        $message = "Utilisateur supprimé avec succès.";
        $msg_type = "success";
    }
}

// Filtres et recherche
$search = trim($_GET['q'] ?? '');
$role_filter = trim($_GET['role'] ?? '');

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (nom LIKE ? OR email LIKE ? OR telephone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role_filter) && in_array($role_filter, ['client', 'promoteur', 'agent', 'admin'], true)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Administration</span>
        <h1>Gestion des Utilisateurs & Agents</h1>
        <p>Consultez, recherchez et gérez les comptes ou créez de nouveaux agents de contrôle.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Formulaire de création rapide d'Agent / Utilisateur -->
<div class="content-section" style="margin-bottom: 1.5rem;">
    <div class="section-title">
        <i class="fa-solid fa-user-plus"></i> Créer un nouvel Agent ou Utilisateur
    </div>

    <form method="POST" action="utilisateurs.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 1rem; align-items: end;">
        <input type="hidden" name="create_user" value="1">

        <div class="form-group" style="margin: 0;">
            <label for="nom" style="font-size: 0.82rem;">Nom complet *</label>
            <input type="text" id="nom" name="nom" required placeholder="Ex: Agent Touré">
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="email" style="font-size: 0.82rem;">Email de connexion *</label>
            <input type="email" id="email" name="email" required placeholder="agent.toure@ticketflow.com">
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="telephone" style="font-size: 0.82rem;">Téléphone</label>
            <input type="tel" id="telephone" name="telephone" placeholder="07 00 00 00 00">
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="role_select" style="font-size: 0.82rem;">Rôle assigné *</label>
            <select name="role" id="role_select" required>
                <option value="agent" selected>Agent de contrôle (Scan)</option>
                <option value="client">Client</option>
                <option value="promoteur">Promoteur</option>
                <option value="admin">Administrateur</option>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="password" style="font-size: 0.82rem;">Mot de passe *</label>
            <input type="password" id="password" name="password" required placeholder="Min 6 caractères">
        </div>

        <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.4rem;">
            <i class="fa-solid fa-plus"></i> Créer
        </button>
    </form>
</div>

<!-- Barre de recherche et filtres -->
<div class="content-section" style="margin-bottom: 1.5rem;">
    <form method="GET" action="utilisateurs.php" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
        <div style="flex: 1; min-width: 250px;">
            <input type="text" name="q" placeholder="Rechercher par nom, email ou téléphone..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div style="min-width: 180px;">
            <select name="role" style="width: 100%; padding: 0.75rem; border: 1px solid var(--line); border-radius: 6px;">
                <option value="">Tous les rôles</option>
                <option value="agent" <?php echo ($role_filter === 'agent') ? 'selected' : ''; ?>>Agents de contrôle</option>
                <option value="client" <?php echo ($role_filter === 'client') ? 'selected' : ''; ?>>Clients</option>
                <option value="promoteur" <?php echo ($role_filter === 'promoteur') ? 'selected' : ''; ?>>Promoteurs</option>
                <option value="admin" <?php echo ($role_filter === 'admin') ? 'selected' : ''; ?>>Administrateurs</option>
            </select>
        </div>

        <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.5rem;">
            <i class="fa-solid fa-magnifying-glass"></i> Filtrer
        </button>

        <?php if (!empty($search) || !empty($role_filter)): ?>
            <a href="utilisateurs.php" style="color: var(--muted); text-decoration: underline; font-size: 0.9rem; align-self: center;">
                Réinitialiser les filtres
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Tableau des utilisateurs -->
<div class="content-section">
    <div class="section-title">
        <i class="fa-solid fa-users"></i> Liste des Utilisateurs & Agents (<?php echo count($users); ?>)
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom Complet</th>
                    <th>Email</th>
                    <th>Téléphone</th>
                    <th>Rôle</th>
                    <th>Inscription</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>#<?php echo $u['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($u['nom']); ?></strong>
                                <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                    <span style="font-size: 0.75rem; background: #e0f2fe; color: #0284c7; padding: 2px 6px; border-radius: 4px; margin-left: 5px;">Vous</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo htmlspecialchars($u['telephone'] ?: '-'); ?></td>
                            <td>
                                <form method="POST" style="display: inline-block;">
                                    <input type="hidden" name="update_role" value="1">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <select name="new_role" onchange="this.form.submit()" style="padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; border: 1px solid var(--line);">
                                        <option value="agent" <?php echo ($u['role'] === 'agent') ? 'selected' : ''; ?>>Agent</option>
                                        <option value="client" <?php echo ($u['role'] === 'client') ? 'selected' : ''; ?>>Client</option>
                                        <option value="promoteur" <?php echo ($u['role'] === 'promoteur') ? 'selected' : ''; ?>>Promoteur</option>
                                        <option value="admin" <?php echo ($u['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></td>
                            <td style="text-align: right;">
                                <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <a href="?delete=<?php echo $u['id']; ?>" class="delete-action" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')" style="color: #ef4444; text-decoration: none;">
                                        <i class="fa-solid fa-trash"></i> Supprimer
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucun utilisateur ne correspond à votre recherche.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
