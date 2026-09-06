<?php
// ==============================================================================
// GESTION DES PROFILS & PERMISSIONS (admin/profils.php)
// Système de profils sur-mesure avec attributions de permissions modulaires
// ==============================================================================

$admin_page_title = "Profils & Permissions - Administration";
include 'header.php';

requirePermission('profiles.view');

$message = "";
$msg_type = "";

// 1. Récupération de toutes les permissions regroupées par catégorie
$raw_perms = $pdo->query("SELECT * FROM permissions ORDER BY categorie ASC, id ASC")->fetchAll();
$permissions_by_cat = [];
foreach ($raw_perms as $p) {
    $permissions_by_cat[$p['categorie']][] = $p;
}

// ==============================================================================
// GESTION DES ACTIONS POST
// ==============================================================================

// Action A : Création d'un nouveau profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_profile'])) {
    requirePermission('profiles.manage');

    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $perms_ids = $_POST['permissions'] ?? [];

    if (empty($nom)) {
        $message = "Le nom du profil est obligatoire.";
        $msg_type = "error";
    } else {
        try {
            $stmt_ins = $pdo->prepare("INSERT INTO profiles (nom, description, is_system) VALUES (?, ?, 0)");
            $stmt_ins->execute([$nom, $description]);
            $new_prof_id = (int) $pdo->lastInsertId();

            if (!empty($perms_ids) && is_array($perms_ids)) {
                $stmt_link = $pdo->prepare("INSERT INTO profile_permissions (profile_id, permission_id) VALUES (?, ?)");
                foreach ($perms_ids as $pid) {
                    $stmt_link->execute([$new_prof_id, (int) $pid]);
                }
            }

            logActivity('profile.create', 'profile', $new_prof_id, "Création du profil « $nom » avec " . count($perms_ids) . " permission(s)");
            $message = "Le profil « " . htmlspecialchars($nom) . " » a été créé avec succès !";
            $msg_type = "success";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Un profil portant ce nom existe déjà.";
            } else {
                $message = "Erreur lors de la création : " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}

// Action B : Modification d'un profil existant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    requirePermission('profiles.manage');

    $prof_id = (int) $_POST['profile_id'];
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $perms_ids = $_POST['permissions'] ?? [];

    $stmt_curr = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt_curr->execute([$prof_id]);
    $curr_prof = $stmt_curr->fetch();

    if (!$curr_prof) {
        $message = "Profil introuvable.";
        $msg_type = "error";
    } elseif (empty($nom)) {
        $message = "Le nom du profil est obligatoire.";
        $msg_type = "error";
    } else {
        try {
            $stmt_up = $pdo->prepare("UPDATE profiles SET nom = ?, description = ? WHERE id = ?");
            $stmt_up->execute([$nom, $description, $prof_id]);

            // Réassignation des permissions
            $pdo->prepare("DELETE FROM profile_permissions WHERE profile_id = ?")->execute([$prof_id]);

            if (!empty($perms_ids) && is_array($perms_ids)) {
                $stmt_link = $pdo->prepare("INSERT INTO profile_permissions (profile_id, permission_id) VALUES (?, ?)");
                foreach ($perms_ids as $pid) {
                    $stmt_link->execute([$prof_id, (int) $pid]);
                }
            }

            logActivity('profile.update', 'profile', $prof_id, "Mise à jour du profil « $nom » (" . count($perms_ids) . " permissions associées)");
            $message = "Le profil « " . htmlspecialchars($nom) . " » a été mis à jour avec succès.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// Action C : Suppression d'un profil
if (isset($_GET['delete_profile'])) {
    requirePermission('profiles.manage');
    $del_id = (int) $_GET['delete_profile'];

    $stmt_p = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt_p->execute([$del_id]);
    $prof_to_del = $stmt_p->fetch();

    if (!$prof_to_del) {
        $message = "Profil introuvable.";
        $msg_type = "error";
    } elseif (!empty($prof_to_del['is_system'])) {
        $message = "Ce profil système par défaut est protégé et ne peut pas être supprimé.";
        $msg_type = "error";
    } else {
        // Vérification si des utilisateurs ont ce profil
        $nb_users_with = (int) $pdo->prepare("SELECT COUNT(*) FROM users WHERE profile_id = ?")->execute([$del_id]) ? $pdo->prepare("SELECT COUNT(*) FROM users WHERE profile_id = ?")->fetchColumn() : 0;

        $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE profile_id = ?");
        $stmt_cnt->execute([$del_id]);
        $nb_users_with = (int) $stmt_cnt->fetchColumn();

        if ($nb_users_with > 0) {
            $message = "Impossible de supprimer ce profil car $nb_users_with utilisateur(s) y sont rattachés. Réassignez-les d'abord.";
            $msg_type = "error";
        } else {
            $pdo->prepare("DELETE FROM profile_permissions WHERE profile_id = ?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM profiles WHERE id = ?")->execute([$del_id]);

            logActivity('profile.delete', 'profile', $del_id, "Suppression du profil « {$prof_to_del['nom']} »");
            $message = "Le profil a été supprimé avec succès.";
            $msg_type = "success";
        }
    }
}

// ==============================================================================
// 2. RÉCUPÉRATION DE TOUS LES PROFILS AVEC LEURS PERMISSIONS ET EFFECTIFS
// ==============================================================================
$profiles_list = $pdo->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM users u WHERE u.profile_id = p.id) AS nb_utilisateurs
    FROM profiles p
    ORDER BY p.is_system DESC, p.nom ASC
")->fetchAll();

// Récupérer les IDs de permissions pour chaque profil
$prof_perms_map = [];
$res_pp = $pdo->query("SELECT profile_id, permission_id FROM profile_permissions")->fetchAll();
foreach ($res_pp as $row) {
    $prof_perms_map[(int) $row['profile_id']][] = (int) $row['permission_id'];
}
?>

<div class="dash-container">
    <!-- En-tête -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-user-shield" style="color: var(--primary); font-size: 1.55rem;"></i>
                Profils Métiers & Permissions
            </h1>
            <p>Définissez des profils spécialisés (Gestionnaire d'événements, Vérificateur, Comptable...) avec des
                permissions granulaires.</p>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <a href="utilisateurs.php" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-users"></i> Gestion des Comptes
            </a>
            <?php if (hasPermission('profiles.manage')): ?>
                <button type="button" onclick="openCreateProfileModal()" class="dash-btn-action btn-primary"
                    style="display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plus"></i> Nouveau Profil
                </button>
            <?php endif; ?>
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

    <!-- Grille des Profils Existants -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 420px), 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
        <?php foreach ($profiles_list as $prof): ?>
            <?php
            $p_perms = $prof_perms_map[(int) $prof['id']] ?? [];
            $nb_perms = count($p_perms);
            ?>
            <div class="dash-card"
                style="padding: 1.35rem; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div
                        style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div>
                            <strong
                                style="color: var(--navy); font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-id-badge" style="color: var(--primary);"></i>
                                <?php echo htmlspecialchars($prof['nom']); ?>
                            </strong>
                            <small style="color: var(--muted); font-size: 0.82rem; display: block; margin-top: 3px;">
                                <?php echo htmlspecialchars($prof['description'] ?: 'Aucune description spécifique.'); ?>
                            </small>
                        </div>
                        <div style="text-align: right; flex-shrink: 0;">
                            <?php if (!empty($prof['is_system'])): ?>
                                <span
                                    style="background: #F5F5F5; color: var(--navy); padding: 2px 7px; border-radius: 6px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase;">
                                    Système
                                </span>
                            <?php endif; ?>
                            <span
                                style="display: block; color: var(--muted); font-size: 0.75rem; margin-top: 4px; font-weight: 600;">
                                <i class="fa-solid fa-users"></i> <?php echo (int) $prof['nb_utilisateurs']; ?>
                                utilisateur(s)
                            </span>
                        </div>
                    </div>

                    <!-- Résumé des permissions -->
                    <div style="margin: 0.85rem 0; padding-top: 0.75rem; border-top: 1px solid var(--line);">
                        <span
                            style="font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase; display: block; margin-bottom: 6px;">
                            Droits accordés (<?php echo $nb_perms; ?>) :
                        </span>
                        <div
                            style="display: flex; flex-wrap: wrap; gap: 4px; max-height: 96px; overflow-y: auto; padding-right: 2px;">
                            <?php if ($nb_perms === count($raw_perms)): ?>
                                <span
                                    style="background: #F5F5F5; color: var(--primary); padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700;">
                                    <i class="fa-solid fa-crown" style="color: #FF4A0D;"></i> Accès complet (Toutes les
                                    permissions)
                                </span>
                            <?php else: ?>
                                <?php foreach ($raw_perms as $rp): ?>
                                    <?php if (in_array((int) $rp['id'], $p_perms, true)): ?>
                                        <span
                                            style="background: #F5F5F5; color: var(--navy); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600;"
                                            title="<?php echo htmlspecialchars($rp['description']); ?>">
                                            <?php echo htmlspecialchars($rp['nom']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Actions de bas de carte -->
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--line);">
                    <a href="utilisateurs.php?profile_id=<?php echo $prof['id']; ?>"
                        style="font-size: 0.78rem; color: var(--primary); text-decoration: none; font-weight: 700;">
                        Voir les comptes associés →
                    </a>

                    <div style="display: flex; gap: 6px;">
                        <?php if (hasPermission('profiles.manage')): ?>
                            <button type="button" class="dash-btn-action" style="padding: 0.35rem 0.65rem; font-size: 0.76rem;"
                                onclick='openEditProfileModal(<?php echo json_encode($prof, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode($p_perms); ?>)'>
                                <i class="fa-solid fa-pen-to-square"></i> Modifier
                            </button>

                            <?php if (empty($prof['is_system']) && (int) $prof['nb_utilisateurs'] === 0): ?>
                                <a href="profils.php?delete_profile=<?php echo $prof['id']; ?>" class="dash-btn-action"
                                    style="padding: 0.35rem 0.65rem; font-size: 0.76rem; color: #000000;"
                                    onclick="return confirm('Confirmez-vous la suppression de ce profil ?');">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ==============================================================================
     MODALE : CRÉATION D'UN PROFIL
     ============================================================================== -->
<div id="createProfileModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeCreateProfileModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 680px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Créer un Nouveau Profil Métier
            </h3>
            <button type="button" class="dash-modal-close" onclick="closeCreateProfileModal()">&times;</button>
        </div>
        <form method="POST" action="profils.php">
            <input type="hidden" name="create_profile" value="1">
            <div class="dash-modal-body" style="display: grid; gap: 1rem;">
                <div class="form-group">
                    <label>Nom du profil *</label>
                    <input type="text" name="nom" required
                        placeholder="Ex: Responsable Billetterie, Coordinateur VIP...">
                </div>

                <div class="form-group">
                    <label>Description du rôle</label>
                    <textarea name="description" rows="2"
                        placeholder="Décrivez les responsabilités de ce profil..."></textarea>
                </div>

                <div>
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                        <label style="font-weight: 700; color: var(--navy); margin: 0;">Permissions associées *</label>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="dash-btn-action"
                                style="padding: 0.2rem 0.55rem; font-size: 0.72rem;"
                                onclick="toggleAllCheckboxes('createProfileModal', true)">Tout cocher</button>
                            <button type="button" class="dash-btn-action"
                                style="padding: 0.2rem 0.55rem; font-size: 0.72rem;"
                                onclick="toggleAllCheckboxes('createProfileModal', false)">Tout décocher</button>
                        </div>
                    </div>

                    <div
                        style="display: grid; gap: 0.85rem; max-height: 280px; overflow-y: auto; padding: 0.5rem; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px;">
                        <?php foreach ($permissions_by_cat as $cat => $p_list): ?>
                            <div
                                style="background: #ffffff; border: 1px solid var(--line); border-radius: 6px; padding: 0.6rem 0.75rem;">
                                <strong
                                    style="font-size: 0.75rem; color: var(--primary); text-transform: uppercase; display: block; margin-bottom: 6px; letter-spacing: 0.5px;">
                                    <?php echo htmlspecialchars($cat); ?>
                                </strong>
                                <div
                                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 6px;">
                                    <?php foreach ($p_list as $p_item): ?>
                                        <label
                                            style="display: flex; align-items: flex-start; gap: 6px; font-size: 0.8rem; cursor: pointer;">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $p_item['id']; ?>"
                                                style="accent-color: var(--accent); margin-top: 2px;">
                                            <span>
                                                <strong
                                                    style="color: var(--dash-text);"><?php echo htmlspecialchars($p_item['nom']); ?></strong>
                                                <small
                                                    style="display: block; color: var(--muted); font-size: 0.7rem;"><?php echo htmlspecialchars($p_item['description']); ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeCreateProfileModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer le Profil</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE : MODIFICATION D'UN PROFIL
     ============================================================================== -->
<div id="editProfileModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeEditProfileModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 680px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i> Modifier le Profil</h3>
            <button type="button" class="dash-modal-close" onclick="closeEditProfileModal()">&times;</button>
        </div>
        <form method="POST" action="profils.php">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="profile_id" id="edit_prof_id">
            <div class="dash-modal-body" style="display: grid; gap: 1rem;">
                <div class="form-group">
                    <label>Nom du profil *</label>
                    <input type="text" name="nom" id="edit_prof_nom" required>
                </div>

                <div class="form-group">
                    <label>Description du rôle</label>
                    <textarea name="description" id="edit_prof_desc" rows="2"></textarea>
                </div>

                <div>
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                        <label style="font-weight: 700; color: var(--navy); margin: 0;">Permissions associées *</label>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="dash-btn-action"
                                style="padding: 0.2rem 0.55rem; font-size: 0.72rem;"
                                onclick="toggleAllCheckboxes('editProfileModal', true)">Tout cocher</button>
                            <button type="button" class="dash-btn-action"
                                style="padding: 0.2rem 0.55rem; font-size: 0.72rem;"
                                onclick="toggleAllCheckboxes('editProfileModal', false)">Tout décocher</button>
                        </div>
                    </div>

                    <div
                        style="display: grid; gap: 0.85rem; max-height: 280px; overflow-y: auto; padding: 0.5rem; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px;">
                        <?php foreach ($permissions_by_cat as $cat => $p_list): ?>
                            <div
                                style="background: #ffffff; border: 1px solid var(--line); border-radius: 6px; padding: 0.6rem 0.75rem;">
                                <strong
                                    style="font-size: 0.75rem; color: var(--primary); text-transform: uppercase; display: block; margin-bottom: 6px; letter-spacing: 0.5px;">
                                    <?php echo htmlspecialchars($cat); ?>
                                </strong>
                                <div
                                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 6px;">
                                    <?php foreach ($p_list as $p_item): ?>
                                        <label
                                            style="display: flex; align-items: flex-start; gap: 6px; font-size: 0.8rem; cursor: pointer;">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $p_item['id']; ?>"
                                                class="edit-perm-check" id="edit_perm_<?php echo $p_item['id']; ?>"
                                                style="accent-color: var(--accent); margin-top: 2px;">
                                            <span>
                                                <strong
                                                    style="color: var(--dash-text);"><?php echo htmlspecialchars($p_item['nom']); ?></strong>
                                                <small
                                                    style="display: block; color: var(--muted); font-size: 0.7rem;"><?php echo htmlspecialchars($p_item['description']); ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeEditProfileModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Mettre à jour le Profil</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateProfileModal() {
        document.getElementById('createProfileModal').style.display = 'flex';
    }
    function closeCreateProfileModal() {
        document.getElementById('createProfileModal').style.display = 'none';
    }

    function openEditProfileModal(prof, assignedPermIds) {
        document.getElementById('edit_prof_id').value = prof.id;
        document.getElementById('edit_prof_nom').value = prof.nom || '';
        document.getElementById('edit_prof_desc').value = prof.description || '';

        // Décocher tout d'abord
        document.querySelectorAll('.edit-perm-check').forEach(cb => cb.checked = false);

        // Cocher les permissions associées
        if (Array.isArray(assignedPermIds)) {
            assignedPermIds.forEach(pid => {
                const cb = document.getElementById('edit_perm_' + pid);
                if (cb) cb.checked = true;
            });
        }

        document.getElementById('editProfileModal').style.display = 'flex';
    }
    function closeEditProfileModal() {
        document.getElementById('editProfileModal').style.display = 'none';
    }

    function toggleAllCheckboxes(modalId, checked) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        modal.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = checked);
    }
</script>

<?php include 'footer.php'; ?>