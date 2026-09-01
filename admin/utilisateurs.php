<?php
// ==============================================================================
// GESTION DES UTILISATEURS & ACCÈS (admin/utilisateurs.php)
// Design Dashboard Pro - Supervision des comptes, rôles et création d'agents
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

// KPIs globaux
$tot_clients    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();
$tot_promoteurs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'promoteur'")->fetchColumn();
$tot_agents     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'agent'")->fetchColumn();
$tot_admins     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$tot_users      = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-users" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion des Comptes & Droits d'Accès
            </h1>
            <p>Supervisez tous les profils de la plateforme, affectez les rôles et créez des comptes d'agents de contrôle.</p>
        </div>

        <div>
            <button type="button" onclick="document.getElementById('createUserForm').scrollIntoView({behavior: 'smooth'})" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-user-plus"></i> Créer un Compte Agent / User
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES BIEN VISIBLES)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES RÔLES -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?role=&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $role_filter === '' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-users" style="<?php echo $role_filter === '' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous (<?php echo $tot_users; ?>)
            </a>

            <a href="?role=client&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $role_filter === 'client' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-user" style="color: #0284c7;"></i> Clients (<?php echo $tot_clients; ?>)
            </a>

            <a href="?role=promoteur&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $role_filter === 'promoteur' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-user-tie" style="color: #10b981;"></i> Promoteurs (<?php echo $tot_promoteurs; ?>)
            </a>

            <a href="?role=agent&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $role_filter === 'agent' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-qrcode" style="color: #8b5cf6;"></i> Agents Contrôle (<?php echo $tot_agents; ?>)
            </a>

            <a href="?role=admin&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $role_filter === 'admin' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-shield-halved" style="color: #f59e0b;"></i> Admins (<?php echo $tot_admins; ?>)
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="utilisateurs.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nom, email, tel..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($role_filter !== '' || $search !== ''): ?>
                <a href="utilisateurs.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Clients Enregistrés</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-user"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo $tot_clients; ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Acheteurs de billets et votants</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #10b981; text-transform: uppercase;">Organisateurs / Promoteurs</span>
                <span style="background: #dcfce7; color: #10b981; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-user-tie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #10b981;"><?php echo $tot_promoteurs; ?></div>
            <small style="color: #10b981; font-size: 0.75rem;">Créateurs d'événements & votes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #8b5cf6; text-transform: uppercase;">Agents de Contrôle</span>
                <span style="background: #f5f3ff; color: #8b5cf6; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-qrcode"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #8b5cf6;"><?php echo $tot_agents; ?></div>
            <small style="color: #8b5cf6; font-size: 0.75rem;">Scanners habilités aux entrées</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #f59e0b; text-transform: uppercase;">Administrateurs</span>
                <span style="background: #fef3c7; color: #f59e0b; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-shield-halved"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #f59e0b;"><?php echo $tot_admins; ?></div>
            <small style="color: #f59e0b; font-size: 0.75rem;">Accès de supervision complet</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES UTILISATEURS
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Utilisateurs (<?php echo count($users); ?>)
            </h3>
        </div>

        <?php if (empty($users)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-user-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun utilisateur ne correspond à vos critères de recherche.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Nom & Prénom</th>
                            <th>Contact</th>
                            <th>Rôle Actuel</th>
                            <th>Date d'Inscription</th>
                            <th>Statut Vérification</th>
                            <th style="text-align: right;">Modifier Rôle / Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $role_badge = [
                                'admin'     => ['Admin', '#fee2e2', '#991b1b'],
                                'promoteur' => ['Promoteur', '#dcfce7', '#166534'],
                                'agent'     => ['Agent', '#f5f3ff', '#6d28d9'],
                                'client'    => ['Client', '#e0f2fe', '#0369a1']
                            ];
                            [$r_lbl, $r_bg, $r_fg] = $role_badge[$u['role']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($u['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        ID #<?php echo $u['id']; ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; color: var(--dash-text); display: block;">
                                        <i class="fa-regular fa-envelope" style="color: var(--dash-muted);"></i> <?php echo htmlspecialchars($u['email']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.75rem;">
                                        <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($u['telephone'] ?: 'Non renseigné'); ?>
                                    </small>
                                </td>
                                <td>
                                    <form method="POST" action="utilisateurs.php" style="margin: 0;">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="update_role" value="1">
                                        <select name="new_role" onchange="this.form.submit()" style="background: <?php echo $r_bg; ?>; color: <?php echo $r_fg; ?>; border: 1px solid rgba(0,0,0,0.06); padding: 3px 8px; border-radius: 6px; font-size: 0.76rem; font-weight: 800; cursor: pointer; outline: none;">
                                            <option value="client" <?php echo $u['role'] === 'client' ? 'selected' : ''; ?>>Client</option>
                                            <option value="promoteur" <?php echo $u['role'] === 'promoteur' ? 'selected' : ''; ?>>Promoteur</option>
                                            <option value="agent" <?php echo $u['role'] === 'agent' ? 'selected' : ''; ?>>Agent Contrôle</option>
                                            <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Administrateur</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '—'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($u['est_verifie'])): ?>
                                        <span style="color: #16a34a; font-weight: 700; font-size: 0.78rem;"><i class="fa-solid fa-circle-check"></i> Vérifié</span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 0.78rem;"><i class="fa-solid fa-clock"></i> Non vérifié</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                        <a href="utilisateurs.php?delete=<?php echo $u['id']; ?>" onclick="return confirm('Confirmez-vous la suppression de ce compte utilisateur ?');" class="dash-btn-action" style="padding: 0.35rem 0.65rem; font-size: 0.74rem; color: #ef4444;" title="Supprimer le compte">
                                            <i class="fa-solid fa-trash"></i> Supprimer
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.74rem; font-weight: 700;">(Votre Compte)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==============================================================================
         5. FORMULAIRE DE CRÉATION RAPIDE D'UN COMPTE (AGENT / ADMIN)
         ============================================================================== -->
    <div class="dash-card" id="createUserForm">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-user-plus" style="color: var(--dash-primary);"></i> Création Directe de Compte Utilisateur / Agent
            </h3>
        </div>

        <form method="POST" action="utilisateurs.php">
            <input type="hidden" name="create_user" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Nom & Prénom *</label>
                    <input type="text" name="nom" required placeholder="Ex: Kouassi Jean" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Adresse Email *</label>
                    <input type="email" name="email" required placeholder="agent@ticketflow.com" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Téléphone</label>
                    <input type="text" name="telephone" placeholder="+225 07..." style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Mot de passe initial *</label>
                    <input type="password" name="password" required placeholder="6 caractères minimum" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Rôle attribué *</label>
                    <select name="role" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="agent" selected>Agent de Contrôle (Scannage QR)</option>
                        <option value="promoteur">Promoteur (Organisateur)</option>
                        <option value="client">Client</option>
                        <option value="admin">Administrateur</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.65rem 1.4rem;">
                <i class="fa-solid fa-check"></i> Créer le Compte
            </button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
