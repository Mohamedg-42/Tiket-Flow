<?php
// ==============================================================================
// GESTION DES COMPTES, RÔLES & PERMISSIONS (admin/utilisateurs.php)
// Administration Tikéli — Supervision complète, profils métiers, suspensions et historique
// ==============================================================================

$admin_page_title = "Gestion des Comptes - Administration";
include 'header.php';

requirePermission('users.view');
require_once __DIR__ . '/../includes/mailer.php';

$message = "";
$msg_type = "";

// 1. Récupération des profils disponibles pour les menus déroulants
$profiles = $pdo->query("SELECT id, nom, description FROM profiles ORDER BY nom ASC")->fetchAll();

// ==============================================================================
// GESTION DES ACTIONS POST
// ==============================================================================

// Action A : Création directe d'un compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    requirePermission('users.create');

    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'client';
    $profile_id = !empty($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

    if (empty($nom) || empty($email) || empty($password)) {
        $message = "Veuillez renseigner les champs obligatoires (Nom, Email, Mot de passe).";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "L'adresse email fournie est invalide.";
        $msg_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Le mot de passe doit comporter au moins 6 caractères.";
        $msg_type = "error";
    } else {
        // Vérification préalable d'unicité de l'email
        $stmt_check_email = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check_email->execute([$email]);
        if ($stmt_check_email->fetch()) {
            $message = "Cette adresse email est déjà associée à un compte utilisateur.";
            $msg_type = "error";
        } else {
            try {
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt_add = $pdo->prepare("
                    INSERT INTO users (nom, prenom, email, telephone, password, role, profile_id, statut, est_verifie) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'actif', 1)
                ");
                $stmt_add->execute([$nom, $prenom, $email, $telephone, $pass_hash, $role, $profile_id]);
                $new_user_id = (int) $pdo->lastInsertId();

                $full_name = trim("$prenom $nom");

                // Si le compte créé est un promoteur, initialisation du profil promoteur
                if ($role === 'promoteur') {
                    $type_entite = $_POST['type_entite_promo'] ?? 'physique';
                    $nom_commercial = !empty($_POST['nom_commercial_promo']) ? trim($_POST['nom_commercial_promo']) : $full_name;
                    $numero_registre = !empty($_POST['numero_registre_promo']) ? trim($_POST['numero_registre_promo']) : null;
                    $rep_legal = !empty($_POST['representant_legal_promo']) ? trim($_POST['representant_legal_promo']) : $full_name;
                    $promo_comm = isset($_POST['commission_rate_promo']) ? (float)str_replace(',', '.', trim($_POST['commission_rate_promo'])) : 5.00;
                    if ($promo_comm < 0 || $promo_comm > 50) $promo_comm = 5.00;

                    $st_chk_p = $pdo->prepare("SELECT id FROM promoters WHERE user_id = ?");
                    $st_chk_p->execute([$new_user_id]);
                    if ($st_chk_p->fetch()) {
                        $st_promo = $pdo->prepare("
                            UPDATE promoters 
                            SET type_entite = ?, nom_commercial = ?, numero_registre = ?, representant_legal = ?, telephone_contact = ?, email_contact = ?, commission_rate = ?, statut = 'approuve'
                            WHERE user_id = ?
                        ");
                        $st_promo->execute([$type_entite, $nom_commercial, $numero_registre, $rep_legal, $telephone, $email, $promo_comm, $new_user_id]);
                    } else {
                        $st_promo = $pdo->prepare("
                            INSERT INTO promoters (user_id, type_entite, nom_commercial, numero_registre, representant_legal, telephone_contact, email_contact, statut, solde, commission_rate, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approuve', 0.00, ?, NOW())
                        ");
                        $st_promo->execute([$new_user_id, $type_entite, $nom_commercial, $numero_registre, $rep_legal, $telephone, $email, $promo_comm]);
                    }
                }

                // Récupération du libellé du profil si assigné
                $profile_nom = '';
                if ($profile_id) {
                    $st_p = $pdo->prepare("SELECT nom FROM profiles WHERE id = ?");
                    $st_p->execute([$profile_id]);
                    $profile_nom = (string) $st_p->fetchColumn();
                }

                // Envoi immédiat des identifiants par e-mail
                $email_sent = sendAdminCreatedAccountEmail($email, $full_name, $role, $password, $profile_nom);

                logActivity('user.create', 'user', $new_user_id, "Création du compte « $full_name » (Rôle: $role, Profil: " . ($profile_nom ?: 'aucun') . ") — Email identifiants: " . ($email_sent ? 'envoyé' : 'échec'));

                $message = "Le compte « " . htmlspecialchars($full_name) . " » a été créé avec succès !" . ($email_sent ? " Un e-mail contenant ses identifiants a été envoyé à $email." : " (Note: l'e-mail automatique n'a pas pu être délivré).");
                $msg_type = "success";
            } catch (PDOException $e) {
                if ($e->getCode() == '23000' || $e->getCode() == '23505' || str_contains($e->getMessage(), 'uq_users_email') || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'unique constraint')) {
                    $message = "Cette adresse email est déjà associée à un compte.";
                } else {
                    $message = "Erreur lors de la création : " . $e->getMessage();
                }
                $msg_type = "error";
            }
        }
    }
}

// Action B : Modification complète d'un compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    requirePermission('users.edit');

    $user_id = (int) $_POST['user_id'];
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $role = $_POST['role'] ?? 'client';
    $profile_id = !empty($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;
    $new_pass = trim($_POST['new_password'] ?? '');

    // Récupération de l'ancien état pour tracer le diff dans l'historique
    $stmt_old = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt_old->execute([$user_id]);
    $old_user = $stmt_old->fetch();

    if (!$old_user) {
        $message = "Utilisateur introuvable.";
        $msg_type = "error";
    } elseif (empty($nom) || empty($email)) {
        $message = "Le nom et l'adresse email ne peuvent pas être vides.";
        $msg_type = "error";
    } else {
        try {
            $changes = [];
            if ($old_user['nom'] !== $nom)
                $changes[] = "Nom: '{$old_user['nom']}' → '$nom'";
            if ($old_user['prenom'] !== $prenom)
                $changes[] = "Prénom: '{$old_user['prenom']}' → '$prenom'";
            if ($old_user['email'] !== $email)
                $changes[] = "Email: '{$old_user['email']}' → '$email'";
            if ($old_user['telephone'] !== $telephone)
                $changes[] = "Tél: '{$old_user['telephone']}' → '$telephone'";
            if ($old_user['role'] !== $role)
                $changes[] = "Rôle: '{$old_user['role']}' → '$role'";
            if ((int) $old_user['profile_id'] !== (int) $profile_id)
                $changes[] = "Profil ID: '{$old_user['profile_id']}' → '$profile_id'";

            $sql_up = "UPDATE users SET nom = ?, prenom = ?, email = ?, telephone = ?, role = ?, profile_id = ?";
            $params = [$nom, $prenom, $email, $telephone, $role, $profile_id];

            if (!empty($new_pass)) {
                $sql_up .= ", password = ?";
                $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
                $changes[] = "Mot de passe réinitialisé";
            }

            $sql_up .= " WHERE id = ?";
            $params[] = $user_id;

            $stmt_up = $pdo->prepare($sql_up);
            $stmt_up->execute($params);

            // Si le compte est ou devient un promoteur, mise à jour ou initialisation du profil et du taux de commission
            if ($role === 'promoteur' && isset($_POST['commission_rate_promo_edit'])) {
                $promo_comm = (float)str_replace(',', '.', trim($_POST['commission_rate_promo_edit']));
                if ($promo_comm < 0 || $promo_comm > 50) $promo_comm = 5.00;

                $st_chk_p = $pdo->prepare("SELECT id, commission_rate FROM promoters WHERE user_id = ?");
                $st_chk_p->execute([$user_id]);
                $existing_p = $st_chk_p->fetch();

                if ($existing_p) {
                    $st_up_p = $pdo->prepare("UPDATE promoters SET commission_rate = ? WHERE user_id = ?");
                    $st_up_p->execute([$promo_comm, $user_id]);
                    if (abs((float)$existing_p['commission_rate'] - $promo_comm) > 0.001) {
                        $changes[] = "Taux commission : " . number_format((float)$existing_p['commission_rate'], 2) . "% → " . number_format($promo_comm, 2) . "%";
                    }
                } else {
                    $full_name = trim("$prenom $nom");
                    $st_ins_p = $pdo->prepare("
                        INSERT INTO promoters (user_id, type_entite, nom_commercial, representant_legal, telephone_contact, email_contact, statut, solde, commission_rate, created_at)
                        VALUES (?, 'physique', ?, ?, ?, ?, 'approuve', 0.00, ?, NOW())
                    ");
                    $st_ins_p->execute([$user_id, $full_name, $full_name, $telephone, $email, $promo_comm]);
                    $changes[] = "Profil promoteur initialisé (taux : " . number_format($promo_comm, 2) . "%)";
                }

                // Synchronisation optionnelle des événements actifs
                if (!empty($_POST['update_active_events_edit'])) {
                    $st_ev = $pdo->prepare("UPDATE events SET commission_rate = ? WHERE user_id = ? AND statut = 'actif'");
                    $st_ev->execute([$promo_comm, $user_id]);
                    $ev_count = $st_ev->rowCount();
                    if ($ev_count > 0) {
                        $changes[] = "$ev_count événement(s) actif(s) mis à jour au taux de " . number_format($promo_comm, 2) . "%";
                    }
                }
            }

            $diff_str = !empty($changes) ? implode(', ', $changes) : "Mise à jour sans changement majeur";
            $admin_label = $_SESSION['user_nom'] ?? 'Un administrateur';
            logActivity('user.update', 'user', $user_id, "$admin_label a modifié le compte #$user_id : $diff_str");

            $message = "Les informations du compte ont été mises à jour avec succès.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur de mise à jour : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// Action C : Suspension (temporaire ou définitive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suspend_user'])) {
    requirePermission('users.status');

    $user_id = (int) $_POST['user_id'];
    $susp_type = $_POST['suspension_type'] ?? 'temporaire'; // 'temporaire' ou 'permanente'
    $date_debut = !empty($_POST['suspended_from']) ? $_POST['suspended_from'] : date('Y-m-d');
    $date_fin = !empty($_POST['suspended_until']) ? $_POST['suspended_until'] : null;
    $motif = trim($_POST['suspension_reason'] ?? '');

    if ($user_id === (int) $_SESSION['user_id']) {
        $message = "Vous ne pouvez pas suspendre votre propre compte administrateur.";
        $msg_type = "error";
    } elseif ($susp_type === 'temporaire' && empty($date_fin)) {
        $message = "Veuillez renseigner une date de fin pour la suspension temporaire.";
        $msg_type = "error";
    } else {
        $new_statut = ($susp_type === 'permanente') ? 'suspendu_def' : 'suspendu_temp';
        $stmt_susp = $pdo->prepare("
            UPDATE users 
            SET statut = ?, suspended_from = ?, suspended_until = ?, suspension_reason = ? 
            WHERE id = ?
        ");
        $stmt_susp->execute([
            $new_statut,
            $date_debut,
            ($susp_type === 'permanente' ? null : $date_fin),
            $motif,
            $user_id
        ]);

        $detail = ($susp_type === 'permanente')
            ? "Suspension définitive. Motif : $motif"
            : "Suspension temporaire du $date_debut au $date_fin. Motif : $motif";

        logActivity('user.suspend', 'user', $user_id, $detail);

        // Envoi d'une notification par email à l'utilisateur suspendu
        $st_u = $pdo->prepare("SELECT nom, prenom, email FROM users WHERE id = ?");
        $st_u->execute([$user_id]);
        $u_info = $st_u->fetch();
        if ($u_info && !empty($u_info['email'])) {
            sendAccountStatusNotificationEmail($u_info['email'], trim($u_info['prenom'] . ' ' . $u_info['nom']), $susp_type, $motif, $date_fin);
        }

        $message = "Le compte a été suspendu avec succès ($susp_type). Un email de notification a été expédié.";
        $msg_type = "success";
    }
}

// Action D : Réactivation directe d'un compte
if (isset($_GET['reactivate'])) {
    requirePermission('users.status');
    $react_id = (int) $_GET['reactivate'];

    $stmt_react = $pdo->prepare("
        UPDATE users 
        SET statut = 'actif', suspended_from = NULL, suspended_until = NULL, suspension_reason = NULL 
        WHERE id = ?
    ");
    $stmt_react->execute([$react_id]);

    logActivity('user.reactivate', 'user', $react_id, "Réactivation manuelle du compte par l'administrateur.");

    // Envoi d'une notification par email à l'utilisateur réactivé
    $st_u = $pdo->prepare("SELECT nom, prenom, email FROM users WHERE id = ?");
    $st_u->execute([$react_id]);
    $u_info = $st_u->fetch();
    if ($u_info && !empty($u_info['email'])) {
        sendAccountStatusNotificationEmail($u_info['email'], trim($u_info['prenom'] . ' ' . $u_info['nom']), 'reactivation');
    }

    $message = "Le compte a été réactivé avec succès. Un e-mail de notification a été expédié.";
    $msg_type = "success";
}

// Action E : Suppression d'un utilisateur
if (isset($_GET['delete'])) {
    requirePermission('users.delete');
    $del_id = (int) $_GET['delete'];
    if ($del_id === (int) $_SESSION['user_id']) {
        $message = "Vous ne pouvez pas supprimer votre propre compte administrateur.";
        $msg_type = "error";
    } else {
        $stmt_del = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_del->execute([$del_id]);

        logActivity('user.delete', 'user', $del_id, "Suppression définitive du compte #$del_id");
        $message = "Utilisateur supprimé avec succès.";
        $msg_type = "success";
    }
}

// ==============================================================================
// FILTRES ET RECHERCHE
// ==============================================================================
$search = trim($_GET['q'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$statut_filt = trim($_GET['statut'] ?? '');
$prof_filter = filter_input(INPUT_GET, 'profile_id', FILTER_VALIDATE_INT) ?: 0;

$sql = "
    SELECT u.*, p.nom AS profile_nom, pr.commission_rate AS promoter_commission_rate
    FROM users u
    LEFT JOIN profiles p ON u.profile_id = p.id
    LEFT JOIN promoters pr ON pr.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.telephone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role_filter) && in_array($role_filter, ['client', 'promoteur', 'agent', 'admin'], true)) {
    $sql .= " AND u.role = ?";
    $params[] = $role_filter;
}

if (!empty($statut_filt) && in_array($statut_filt, ['actif', 'inactif', 'suspendu_temp', 'suspendu_def'], true)) {
    $sql .= " AND u.statut = ?";
    $params[] = $statut_filt;
}

if ($prof_filter > 0) {
    $sql .= " AND u.profile_id = ?";
    $params[] = $prof_filter;
}

$sql .= " ORDER BY u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// KPIs globaux
$tot_users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$tot_actifs = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE statut = 'actif'")->fetchColumn();
$tot_suspendus = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE statut IN ('suspendu_temp', 'suspendu_def')")->fetchColumn();
$tot_admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$tot_agents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'agent'")->fetchColumn();
?>

<style>
/* ==============================================================================
   STYLES RESPONSIVE & SYSTÈME SUISSE - UTILISATEURS & RÔLES
   ============================================================================== */
.users-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.users-header-actions {
    display: flex;
    gap: 0.65rem;
    align-items: center;
    flex-wrap: wrap;
}
.users-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    background: #ffffff;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    flex-wrap: wrap;
}
.users-pills-row {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    max-width: 100%;
}
.users-pills-row::-webkit-scrollbar {
    display: none;
}
.users-pill-link {
    text-decoration: none;
    border-radius: 8px;
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.15s ease;
}

.users-kpis-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.users-desktop-table {
    display: block;
}
.users-mobile-list {
    display: none;
}

/* Breakpoints Responsives */
@media (max-width: 960px) {
    .users-kpis-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 860px) {
    .dash-container {
        padding: 0.75rem 0.5rem !important;
    }
    .dash-card {
        padding: 0.75rem 0.5rem !important;
        border-radius: 12px !important;
        overflow: visible !important;
    }
    .dash-card-head {
        margin-bottom: 0.75rem !important;
        padding: 0 0.25rem !important;
    }
    .dash-card-title {
        font-size: 0.95rem !important;
    }
    .users-header-section {
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
    }
    .users-header-actions {
        width: 100%;
        flex-direction: column;
    }
    .users-header-actions a,
    .users-header-actions button {
        width: 100%;
        justify-content: center;
        box-sizing: border-box;
        text-align: center;
    }

    .users-filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
    }
    .users-pills-row {
        width: 100%;
    }
    .users-search-form {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    .users-search-form select,
    .users-search-form input,
    .users-search-form button {
        width: 100% !important;
        box-sizing: border-box;
    }

    /* Masquer le tableau large */
    .users-desktop-table {
        display: none !important;
    }

    /* Activer les cartes suisses mobiles */
    .users-mobile-list {
        display: flex !important;
        flex-direction: column;
        gap: 0.85rem;
    }

    .user-mobile-card {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        box-sizing: border-box;
    }
    .umc-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
    }
    .umc-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: var(--dash-text, #000000);
        line-height: 1.3;
    }
    .umc-id {
        font-family: 'Space Mono', monospace;
        font-size: 0.72rem;
        color: var(--dash-muted, #737373);
    }
    .umc-contact-info {
        background: #F5F5F5;
        border: 1px solid #F5F5F5;
        border-radius: 8px;
        padding: 0.6rem 0.75rem;
        font-size: 0.8rem;
        color: var(--dash-text, #000000);
        display: flex;
        flex-direction: column;
        gap: 4px;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .umc-meta-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        font-size: 0.78rem;
    }
    .umc-meta-item {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 8px;
        padding: 0.45rem 0.65rem;
    }
    .umc-meta-label {
        font-size: 0.68rem;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--dash-muted, #737373);
        display: block;
        margin-bottom: 2px;
    }
    .umc-actions {
        display: flex;
        gap: 6px;
        align-items: center;
        border-top: 1px solid var(--dash-border, #E5E5E5);
        padding-top: 0.65rem;
    }
    .umc-btn {
        flex: 1;
        padding: 0.5rem 0.5rem;
        font-size: 0.78rem;
        font-weight: 700;
        border-radius: 6px;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        text-decoration: none;
        cursor: pointer;
        box-sizing: border-box;
    }
    .umc-btn-view {
        background: #F5F5F5;
        color: #000000;
        border-color: #E5E5E5;
    }
    .umc-btn-edit {
        background: #FFF2ED;
        color: #FF4A0D;
        border-color: #FFF2ED;
    }
    .umc-btn-suspend {
        background: #FFF2ED;
        color: #FF4A0D;
        border-color: #E5E5E5;
    }
    .umc-btn-reactivate {
        background: #FFF2ED;
        color: #000000;
        border-color: #FFF2ED;
    }
    .umc-btn-more {
        flex: 0 0 36px;
        background: #ffffff;
        color: #737373;
        border-color: #E5E5E5;
    }
}

@media (max-width: 480px) {
    .users-kpis-grid {
        grid-template-columns: 1fr !important;
        gap: 0.65rem !important;
    }
    .umc-meta-grid {
        grid-template-columns: 1fr;
    }
    .dash-modal-dialog {
        margin: 0.5rem;
    }
    .dash-modal-body div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div class="dash-container">
    <!-- 1. En-tête -->
    <div class="users-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-users" style="color: var(--primary); font-size: 1.55rem;"></i>
                Gestion des Comptes & Rôles
            </h1>
            <p>Supervisez tous les utilisateurs, attribuez des profils métiers précis, gérez les suspensions et
                consultez l'activité.</p>
        </div>

        <div class="users-header-actions">
            <a href="export.php?type=utilisateurs" class="dash-btn-action" style="text-decoration: none;" title="Exporter tous les utilisateurs sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="profils.php" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-user-shield" style="color: var(--primary);"></i> Profils & Permissions
            </a>
            <?php if (hasPermission('users.create')): ?>
                <button type="button" onclick="openCreateUserModal()" class="dash-btn-action btn-primary"
                    style="display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-user-plus"></i> Nouveau Compte
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Message de notification -->
    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 0.85rem 1.15rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.88rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- 2. KPIs synthétiques -->
    <div class="users-kpis-grid">
        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span
                    style="font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase;">Total
                    Comptes</span>
                <span
                    style="background: var(--primary-soft); color: var(--primary); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--navy);"><?php echo $tot_users; ?></div>
            <small style="color: var(--muted); font-size: 0.74rem;">Inscrits sur Tikéli</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span style="font-size: 0.75rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Comptes
                    Actifs</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-user-check"></i></span>
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #FF4A0D;"><?php echo $tot_actifs; ?></div>
            <small style="color: #FF4A0D; font-size: 0.74rem;">Accès autorisé</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase;">Comptes
                    Suspendus</span>
                <span
                    style="background: #F5F5F5; color: #000000; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-user-lock"></i></span>
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #000000;"><?php echo $tot_suspendus; ?></div>
            <small style="color: #000000; font-size: 0.74rem;">Accès temporairement ou définitivement bloqué</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1rem 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span
                    style="font-size: 0.75rem; font-weight: 700; color: var(--accent); text-transform: uppercase;">Agents
                    & Équipe</span>
                <span
                    style="background: var(--accent-soft); color: var(--accent); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-shield-halved"></i></span>
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: var(--accent);">
                <?php echo ($tot_admins + $tot_agents); ?></div>
            <small style="color: var(--muted); font-size: 0.74rem;"><?php echo $tot_admins; ?> Admins ·
                <?php echo $tot_agents; ?> Agents</small>
        </div>
    </div>

    <!-- 3. Barre de filtres et recherche -->
    <div class="users-filter-bar">
        <!-- Filtres par Rôle -->
        <div class="users-pills-row">
            <a href="?role=&statut=<?php echo urlencode($statut_filt); ?>&q=<?php echo urlencode($search); ?>" class="users-pill-link"
                style="<?php echo $role_filter === '' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Tous (<?php echo $tot_users; ?>)
            </a>
            <a href="?role=client&statut=<?php echo urlencode($statut_filt); ?>&q=<?php echo urlencode($search); ?>" class="users-pill-link"
                style="<?php echo $role_filter === 'client' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Clients
            </a>
            <a href="?role=promoteur&statut=<?php echo urlencode($statut_filt); ?>&q=<?php echo urlencode($search); ?>" class="users-pill-link"
                style="<?php echo $role_filter === 'promoteur' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Promoteurs
            </a>
            <a href="?role=agent&statut=<?php echo urlencode($statut_filt); ?>&q=<?php echo urlencode($search); ?>" class="users-pill-link"
                style="<?php echo $role_filter === 'agent' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Agents
            </a>
            <a href="?role=admin&statut=<?php echo urlencode($statut_filt); ?>&q=<?php echo urlencode($search); ?>" class="users-pill-link"
                style="<?php echo $role_filter === 'admin' ? 'background: var(--primary); color: #ffffff;' : 'background: #F5F5F5; color: var(--muted); border: 1px solid #E5E5E5;'; ?>">
                Admins
            </a>
        </div>

        <!-- Recherche et statut -->
        <form method="GET" action="utilisateurs.php" class="users-search-form"
            style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0;">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">

            <select name="statut" onchange="this.form.submit()"
                style="padding: 0.4rem 0.6rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem; background: #ffffff;">
                <option value="">Tous les statuts</option>
                <option value="actif" <?php echo $statut_filt === 'actif' ? 'selected' : ''; ?>>Actif</option>
                <option value="suspendu_temp" <?php echo $statut_filt === 'suspendu_temp' ? 'selected' : ''; ?>>Suspendu temporairement</option>
                <option value="suspendu_def" <?php echo $statut_filt === 'suspendu_def' ? 'selected' : ''; ?>>Suspendu définitivement</option>
                <option value="inactif" <?php echo $statut_filt === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Nom, email, tel..."
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem; width: 170px; background: #ffffff;">

            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.85rem; font-size: 0.8rem; background: var(--primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($role_filter !== '' || $statut_filt !== '' || $search !== ''): ?>
                <a href="utilisateurs.php"
                    style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 4px;">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- 4. Tableau des Utilisateurs -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list" style="color: var(--primary);"></i> Liste des Comptes
                (<?php echo count($users); ?>)
            </h3>
        </div>

        <?php if (empty($users)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-user-xmark"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun compte utilisateur ne correspond à vos critères.
            </div>
        <?php else: ?>
            <!-- Vue Table Desktop (> 860px) -->
            <div class="dash-table-wrapper users-desktop-table" style="overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch;">
                <table class="dash-pro-table" style="min-width: 900px; width: 100%;">
                    <thead>
                        <tr>
                            <th>Utilisateur</th>
                            <th>Email & Téléphone</th>
                            <th>Type & Profil</th>
                            <th>Statut</th>
                            <th>Créé le</th>
                            <th>Dernière connexion</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            // Badges rôles
                            $role_badges = [
                                'admin' => ['Admin', 'background: #F5F5F5; color: #000000;'],
                                'promoteur' => ['Promoteur', 'background: #FFF2ED; color: #000000;'],
                                'agent' => ['Agent', 'background: #FFF2ED; color: #FF4A0D;'],
                                'client' => ['Client', 'background: #FFF2ED; color: #FF4A0D;']
                            ];
                            [$r_text, $r_style] = $role_badges[$u['role']] ?? ['Inconnu', 'background: #F5F5F5; color: #737373;'];

                            // Badges statut
                            $statut_badges = [
                                'actif' => ['Actif', 'background: #FFF2ED; color: #000000;', 'fa-circle-check'],
                                'inactif' => ['Inactif', 'background: #F5F5F5; color: #737373;', 'fa-circle-pause'],
                                'suspendu_temp' => ['Suspendu temp.', 'background: #FFF2ED; color: #FF4A0D;', 'fa-clock'],
                                'suspendu_def' => ['Désactivé', 'background: #F5F5F5; color: #000000;', 'fa-ban']
                            ];
                            [$s_text, $s_style, $s_ico] = $statut_badges[$u['statut'] ?? 'actif'] ?? ['Actif', 'background: #FFF2ED; color: #000000;', 'fa-circle-check'];

                            $full_name = trim(($u['prenom'] ?? '') . ' ' . $u['nom']);
                            ?>
                            <tr>
                                <!-- Nom & Prénom -->
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($full_name); ?>
                                    </strong>
                                    <small
                                        style="color: var(--dash-muted); font-size: 0.72rem; font-family: 'Space Mono', monospace;">
                                        ID #<?php echo $u['id']; ?>
                                    </small>
                                </td>

                                <!-- Contact -->
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-text); display: block;">
                                        <i class="fa-regular fa-envelope" style="color: var(--dash-muted); width: 14px;"></i>
                                        <?php echo htmlspecialchars($u['email']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <i class="fa-solid fa-phone" style="width: 14px;"></i>
                                        <?php echo htmlspecialchars($u['telephone'] ?: 'Non renseigné'); ?>
                                    </small>
                                </td>

                                <!-- Type de compte & Profil métier -->
                                <td>
                                    <div style="display: flex; gap: 4px; align-items: center; flex-wrap: wrap; margin-bottom: 2px;">
                                        <span
                                            style="display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $r_style; ?>;">
                                            <?php echo $r_text; ?>
                                        </span>
                                        <?php if ($u['role'] === 'promoteur' && isset($u['promoter_commission_rate'])): ?>
                                            <span style="display: inline-block; font-size: 0.7rem; font-family: monospace; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px;" title="Taux de commission Tikéli">
                                                <?php echo number_format((float)$u['promoter_commission_rate'], 2); ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($u['profile_nom'])): ?>
                                        <small style="display: block; color: var(--primary); font-size: 0.74rem; font-weight: 600;">
                                            <i class="fa-solid fa-id-badge"></i> <?php echo htmlspecialchars($u['profile_nom']); ?>
                                        </small>
                                    <?php else: ?>
                                        <small style="display: block; color: var(--muted); font-size: 0.72rem;">Profil
                                            standard</small>
                                    <?php endif; ?>
                                </td>

                                <!-- Statut -->
                                <td>
                                    <span
                                        style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; <?php echo $s_style; ?>"
                                        title="<?php echo htmlspecialchars($u['suspension_reason'] ?? ''); ?>">
                                        <i class="fa-solid <?php echo $s_ico; ?>" style="font-size: 0.65rem;"></i>
                                        <?php echo $s_text; ?>
                                    </span>
                                    <?php if ($u['statut'] === 'suspendu_temp' && !empty($u['suspended_until'])): ?>
                                        <small style="display: block; font-size: 0.68rem; color: #FF4A0D; margin-top: 2px;">
                                            Jusqu'au <?php echo date('d/m/Y', strtotime($u['suspended_until'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <!-- Date de création -->
                                <td>
                                    <span style="font-size: 0.8rem; color: var(--dash-muted);">
                                        <?php echo !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '—'; ?>
                                    </span>
                                </td>

                                <!-- Dernière connexion -->
                                <td>
                                    <span style="font-size: 0.8rem; color: var(--dash-muted);">
                                        <?php if (!empty($u['derniere_connexion'])): ?>
                                            <i class="fa-regular fa-clock" style="font-size: 0.75rem;"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($u['derniere_connexion'])); ?>
                                        <?php else: ?>
                                            <span style="color: #E5E5E5;">Jamais</span>
                                        <?php endif; ?>
                                    </span>
                                </td>

                                <!-- Menu d'Actions Contextuel ⋮ -->
                                <td style="text-align: right; position: relative; white-space: nowrap;">
                                    <div class="user-action-dropdown" style="display: inline-block;">
                                        <button type="button" class="dash-btn-action"
                                            style="padding: 0.35rem 0.65rem; font-size: 0.82rem;"
                                            onclick="toggleActionMenu(event, 'menu-<?php echo $u['id']; ?>')">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>

                                        <div id="menu-<?php echo $u['id']; ?>" class="action-popover"
                                            style="display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 190px; z-index: 50; padding: 4px 0; text-align: left;">
                                            <!-- Voir la fiche -->
                                            <button type="button" class="action-item"
                                                onclick='openViewUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                <i class="fa-regular fa-eye" style="color: var(--primary);"></i> Voir la fiche
                                            </button>

                                            <!-- Modifier le compte -->
                                            <?php if (hasPermission('users.edit')): ?>
                                                <button type="button" class="action-item"
                                                    onclick='openEditUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                    <i class="fa-solid fa-pen-to-square" style="color: #FF4A0D;"></i> Modifier
                                                </button>
                                            <?php endif; ?>

                                            <!-- Activer / Désactiver -->
                                            <?php if (hasPermission('users.status') && $u['id'] !== (int) $_SESSION['user_id']): ?>
                                                <?php if (in_array($u['statut'] ?? '', ['suspendu_temp', 'suspendu_def', 'inactif'], true)): ?>
                                                    <a href="utilisateurs.php?reactivate=<?php echo $u['id']; ?>" class="action-item"
                                                        onclick="return confirm('Voulez-vous réactiver immédiatement ce compte ?');"
                                                        style="color: #000000;">
                                                        <i class="fa-solid fa-circle-check"></i> Réactiver le compte
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="action-item" style="color: #FF4A0D;"
                                                        onclick='openSuspendUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                        <i class="fa-solid fa-user-slash"></i> Suspendre / Désactiver
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <div style="height: 1px; background: var(--dash-border-light); margin: 4px 0;">
                                            </div>

                                            <!-- Voir les tâches -->
                                            <a href="taches.php?user_id=<?php echo $u['id']; ?>" class="action-item"
                                                style="color: var(--dash-text); text-decoration: none;">
                                                <i class="fa-solid fa-list-check" style="color: var(--accent);"></i> Voir les
                                                tâches
                                            </a>

                                            <!-- Voir l'activité -->
                                            <a href="activite.php?user_id=<?php echo $u['id']; ?>" class="action-item"
                                                style="color: var(--dash-text); text-decoration: none;">
                                                <i class="fa-solid fa-clock-rotate-left" style="color: #FF4A0D;"></i> Voir
                                                l'activité
                                            </a>

                                            <?php if (hasPermission('users.delete') && $u['id'] !== (int) $_SESSION['user_id']): ?>
                                                <div style="height: 1px; background: var(--dash-border-light); margin: 4px 0;">
                                                </div>
                                                <a href="utilisateurs.php?delete=<?php echo $u['id']; ?>" class="action-item"
                                                    style="color: #000000;"
                                                    onclick="return confirm('Confirmez-vous la suppression irréversible de cet utilisateur ?');">
                                                    <i class="fa-solid fa-trash"></i> Supprimer
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vue Cartes Mobile (<= 860px) -->
            <div class="users-mobile-list">
                <?php foreach ($users as $u): ?>
                    <?php
                    $role_badges = [
                        'admin' => ['Admin', 'background: #F5F5F5; color: #000000;'],
                        'promoteur' => ['Promoteur', 'background: #FFF2ED; color: #000000;'],
                        'agent' => ['Agent', 'background: #FFF2ED; color: #FF4A0D;'],
                        'client' => ['Client', 'background: #FFF2ED; color: #FF4A0D;']
                    ];
                    [$r_text, $r_style] = $role_badges[$u['role']] ?? ['Inconnu', 'background: #F5F5F5; color: #737373;'];

                    $statut_badges = [
                        'actif' => ['Actif', 'background: #FFF2ED; color: #000000;', 'fa-circle-check'],
                        'inactif' => ['Inactif', 'background: #F5F5F5; color: #737373;', 'fa-circle-pause'],
                        'suspendu_temp' => ['Suspendu temp.', 'background: #FFF2ED; color: #FF4A0D;', 'fa-clock'],
                        'suspendu_def' => ['Désactivé', 'background: #F5F5F5; color: #000000;', 'fa-ban']
                    ];
                    [$s_text, $s_style, $s_ico] = $statut_badges[$u['statut'] ?? 'actif'] ?? ['Actif', 'background: #FFF2ED; color: #000000;', 'fa-circle-check'];

                    $full_name = trim(($u['prenom'] ?? '') . ' ' . $u['nom']);
                    ?>
                    <div class="user-mobile-card">
                        <div class="umc-header">
                            <div>
                                <h4 class="umc-name"><?php echo htmlspecialchars($full_name); ?></h4>
                                <span class="umc-id">ID #<?php echo $u['id']; ?></span>
                            </div>
                            <div style="display: flex; gap: 4px; align-items: center; flex-wrap: wrap;">
                                <span style="display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $r_style; ?>">
                                    <?php echo $r_text; ?>
                                </span>
                                <?php if ($u['role'] === 'promoteur' && isset($u['promoter_commission_rate'])): ?>
                                    <span style="display: inline-block; font-size: 0.7rem; font-family: monospace; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px;">
                                        <?php echo number_format((float)$u['promoter_commission_rate'], 2); ?>%
                                    </span>
                                <?php endif; ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; <?php echo $s_style; ?>" title="<?php echo htmlspecialchars($u['suspension_reason'] ?? ''); ?>">
                                    <i class="fa-solid <?php echo $s_ico; ?>" style="font-size: 0.65rem;"></i>
                                    <?php echo $s_text; ?>
                                </span>
                            </div>
                        </div>

                        <div class="umc-contact-info">
                            <div>
                                <i class="fa-regular fa-envelope" style="color: var(--dash-muted); width: 14px;"></i>
                                <a href="mailto:<?php echo htmlspecialchars($u['email']); ?>" style="color: inherit; text-decoration: none; font-weight: 600;"><?php echo htmlspecialchars($u['email']); ?></a>
                            </div>
                            <?php if (!empty($u['telephone'])): ?>
                                <div>
                                    <i class="fa-solid fa-phone" style="color: var(--dash-muted); width: 14px;"></i>
                                    <a href="tel:<?php echo htmlspecialchars($u['telephone']); ?>" style="color: inherit; text-decoration: none;"><?php echo htmlspecialchars($u['telephone']); ?></a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="umc-meta-grid">
                            <div class="umc-meta-item">
                                <span class="umc-meta-label">Profil Métier</span>
                                <span style="color: var(--primary); font-weight: 600;">
                                    <?php echo !empty($u['profile_nom']) ? htmlspecialchars($u['profile_nom']) : 'Standard'; ?>
                                </span>
                            </div>
                            <div class="umc-meta-item">
                                <span class="umc-meta-label">Dernière Connexion</span>
                                <span style="color: var(--dash-text);">
                                    <?php echo !empty($u['derniere_connexion']) ? date('d/m/Y H:i', strtotime($u['derniere_connexion'])) : 'Jamais'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Actions Tactiles Directes -->
                        <div class="umc-actions">
                            <button type="button" class="umc-btn umc-btn-view" onclick='openViewUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                <i class="fa-regular fa-eye"></i> Fiche
                            </button>

                            <?php if (hasPermission('users.edit')): ?>
                                <button type="button" class="umc-btn umc-btn-edit" onclick='openEditUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                <i class="fa-solid fa-pen-to-square"></i> Modifier
                                </button>
                            <?php endif; ?>

                            <?php if (hasPermission('users.status') && $u['id'] !== (int) $_SESSION['user_id']): ?>
                                <?php if (in_array($u['statut'] ?? '', ['suspendu_temp', 'suspendu_def', 'inactif'], true)): ?>
                                    <a href="utilisateurs.php?reactivate=<?php echo $u['id']; ?>" class="umc-btn umc-btn-reactivate" onclick="return confirm('Voulez-vous réactiver immédiatement ce compte ?');">
                                        <i class="fa-solid fa-circle-check"></i> Réactiver
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="umc-btn umc-btn-suspend" onclick='openSuspendUserModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <i class="fa-solid fa-user-slash"></i> Suspendre
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- Actions secondaires -->
                            <div class="user-action-dropdown" style="position: relative;">
                                <button type="button" class="umc-btn umc-btn-more" onclick="toggleActionMenu(event, 'menu-m-<?php echo $u['id']; ?>')">
                                    <i class="fa-solid fa-ellipsis"></i>
                                </button>
                                <div id="menu-m-<?php echo $u['id']; ?>" class="action-popover" style="display: none; position: absolute; right: 0; bottom: 100%; margin-bottom: 4px; background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); width: 180px; z-index: 50; padding: 4px 0; text-align: left;">
                                    <a href="taches.php?user_id=<?php echo $u['id']; ?>" class="action-item" style="color: var(--dash-text); text-decoration: none;">
                                        <i class="fa-solid fa-list-check" style="color: var(--accent);"></i> Tâches
                                    </a>
                                    <a href="activite.php?user_id=<?php echo $u['id']; ?>" class="action-item" style="color: var(--dash-text); text-decoration: none;">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #FF4A0D;"></i> Activité
                                    </a>
                                    <?php if (hasPermission('users.delete') && $u['id'] !== (int) $_SESSION['user_id']): ?>
                                        <div style="height: 1px; background: var(--dash-border-light); margin: 4px 0;"></div>
                                        <a href="utilisateurs.php?delete=<?php echo $u['id']; ?>" class="action-item" style="color: #000000;" onclick="return confirm('Confirmez-vous la suppression irréversible de cet utilisateur ?');">
                                            <i class="fa-solid fa-trash"></i> Supprimer
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==============================================================================
     MODALE 1 : CRÉATION D'UN COMPTE
     ============================================================================== -->
<div id="createUserModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeCreateUserModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 540px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-user-plus" style="color: var(--primary);"></i> Créer un Nouveau Compte</h3>
            <button type="button" class="dash-modal-close" onclick="closeCreateUserModal()">&times;</button>
        </div>
        <form method="POST" action="utilisateurs.php">
            <input type="hidden" name="create_user" value="1">
            <div class="dash-modal-body" style="display: grid; gap: 0.9rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Nom *</label>
                        <input type="text" name="nom" required placeholder="Ex: Koffi">
                    </div>
                    <div class="form-group">
                        <label>Prénom</label>
                        <input type="text" name="prenom" placeholder="Ex: Jean">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required placeholder="koffi.jean@mail.com">
                </div>

                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="telephone" placeholder="Ex: +225 07 00 00 00 00">
                </div>

                <div class="form-group">
                    <label>Mot de passe initial *</label>
                    <div style="position: relative;">
                        <input type="password" id="create_user_pass" name="password" required minlength="6" placeholder="Au moins 6 caractères" style="width: 100%; box-sizing: border-box; padding-right: 2.25rem;">
                        <i class="fa-regular fa-eye" onclick="togglePassVisibility('create_user_pass', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--dash-muted, #737373); font-size: 0.85rem;"></i>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Rôle Système *</label>
                        <select name="role" id="create_role_select" onchange="toggleAdminPromoterFields(this.value)"
                            required>
                            <option value="client">Client</option>
                            <option value="promoteur">Promoteur</option>
                            <option value="agent" selected>Agent Contrôle</option>
                            <option value="admin">Administrateur</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Profil Métier</label>
                        <select name="profile_id">
                            <option value="">-- Aucun profil spécialisé --</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Bloc Optionnel pour le rôle Promoteur -->
                <div id="admin_promo_fields"
                    style="display: none; background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 8px; padding: 0.85rem; margin-top: 0.35rem;">
                    <div
                        style="font-weight: 800; color: #000000; font-size: 0.82rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-user-tie"></i> Détails & Statut Juridique du Promoteur
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.78rem;">Forme Juridique *</label>
                            <select name="type_entite_promo" id="select_type_entite_promo"
                                onchange="togglePromoterLegalFields(this.value)"
                                style="padding: 0.4rem; font-size: 0.82rem;">
                                <option value="physique">Personne Physique (Indépendant / Artiste)</option>
                                <option value="morale">Personne Morale (Entreprise / Société)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;" id="grp_nom_commercial">
                            <label style="font-size: 0.78rem;" id="lbl_nom_commercial">Nom de Scène / Commercial
                                (Optionnel)</label>
                            <input type="text" name="nom_commercial_promo" id="input_nom_commercial_promo"
                                placeholder="Ex: DJ Magic, Prod..." style="padding: 0.4rem; font-size: 0.82rem;">
                        </div>
                    </div>
                    <!-- Champs Personne Morale (Affichés uniquement si Personne Morale) -->
                    <div id="promo_morale_fields" style="display: none; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.78rem;">N° Registre / RCCM *</label>
                            <input type="text" name="numero_registre_promo" id="input_numero_registre_promo"
                                placeholder="Ex: CI-ABJ-2026-B-XXXX" style="padding: 0.4rem; font-size: 0.82rem;">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.78rem;">Représentant Légal *</label>
                            <input type="text" name="representant_legal_promo" id="input_representant_legal_promo"
                                placeholder="Nom du signataire" style="padding: 0.4rem; font-size: 0.82rem;">
                        </div>
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeCreateUserModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Créer le Compte</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE 2 : MODIFICATION D'UN COMPTE
     ============================================================================== -->
<div id="editUserModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeEditUserModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 540px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i> Modifier le Compte</h3>
            <button type="button" class="dash-modal-close" onclick="closeEditUserModal()">&times;</button>
        </div>
        <form method="POST" action="utilisateurs.php"
            onsubmit="return confirm('Confirmez-vous l\'enregistrement de ces modifications ?');">
            <input type="hidden" name="update_user" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="dash-modal-body" style="display: grid; gap: 0.9rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Nom *</label>
                        <input type="text" name="nom" id="edit_nom" required>
                    </div>
                    <div class="form-group">
                        <label>Prénom</label>
                        <input type="text" name="prenom" id="edit_prenom">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>

                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="telephone" id="edit_telephone">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Rôle Système *</label>
                        <select name="role" id="edit_role" required>
                            <option value="client">Client</option>
                            <option value="promoteur">Promoteur</option>
                            <option value="agent">Agent Contrôle</option>
                            <option value="admin">Administrateur</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Profil Métier</label>
                        <select name="profile_id" id="edit_profile_id">
                            <option value="">-- Aucun profil spécialisé --</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="edit_promoter_section" style="display: none; background: #fff7ed; border: 1px solid #fdba74; border-radius: 8px; padding: 0.85rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                        <label style="font-weight: 700; color: #9a3412; font-size: 0.82rem; margin: 0;">
                            <i class="fa-solid fa-percent"></i> Taux de Commission Négocié (%)
                        </label>
                        <span style="font-family: monospace; font-size: 0.72rem; color: #c2410c; background: #ffedd5; padding: 2px 6px; border-radius: 4px;">Défaut : 5.00%</span>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" step="0.1" min="0" max="50" name="commission_rate_promo_edit" id="edit_commission_rate" value="5.00" style="width: 110px; font-weight: 700; padding: 0.4rem 0.6rem; border: 1px solid #fdba74; border-radius: 6px;">
                        <span style="font-size: 0.82rem; color: #78350f;">% prélevé par Tikéli sur chaque billet vendu</span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.45rem; margin-top: 0.5rem; font-size: 0.78rem; color: #9a3412; cursor: pointer; user-select: none;">
                        <input type="checkbox" name="update_active_events_edit" value="1">
                        <span>Appliquer aussi ce taux à tous ses événements actuellement actifs</span>
                    </label>
                </div>

                <div class="form-group" style="border-top: 1px solid var(--line); padding-top: 0.75rem;">
                    <label>Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                    <div style="position: relative;">
                        <input type="password" id="edit_user_pass" name="new_password" placeholder="Nouveau mot de passe (optionnel)" style="width: 100%; box-sizing: border-box; padding-right: 2.25rem;">
                        <i class="fa-regular fa-eye" onclick="togglePassVisibility('edit_user_pass', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--dash-muted, #737373); font-size: 0.85rem;"></i>
                    </div>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeEditUserModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer les Modifications</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE 3 : SUSPENSION / DÉSACTIVATION DU COMPTE
     ============================================================================== -->
<div id="suspendUserModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeSuspendUserModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 480px;">
        <div class="dash-modal-header">
            <h3 style="color: #FF4A0D;"><i class="fa-solid fa-user-slash"></i> Suspendre le Compte</h3>
            <button type="button" class="dash-modal-close" onclick="closeSuspendUserModal()">&times;</button>
        </div>
        <form method="POST" action="utilisateurs.php"
            onsubmit="return confirm('Confirmez-vous la suspension de cet utilisateur ?');">
            <input type="hidden" name="suspend_user" value="1">
            <input type="hidden" name="user_id" id="suspend_user_id">

            <div class="dash-modal-body" style="display: grid; gap: 0.9rem;">
                <div
                    style="background: #FFF2ED; border: 1px solid #E5E5E5; border-radius: 8px; padding: 0.75rem; font-size: 0.82rem; color: #000000;">
                    Pendant la suspension, l'utilisateur ne pourra plus se connecter. Ses données et historiques restent
                    intégralement conservés.
                </div>

                <div class="form-group">
                    <label>Type de désactivation *</label>
                    <select name="suspension_type" id="susp_type_select" onchange="toggleSuspensionDates()" required>
                        <option value="temporaire">Désactivation temporaire (avec date de fin)</option>
                        <option value="permanente">Désactivation définitive (indéterminée)</option>
                    </select>
                </div>

                <div id="susp_dates_box" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label>Date de début *</label>
                        <input type="date" name="suspended_from" id="susp_from" value="<?php echo date('Y-m-d'); ?>"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Date de fin *</label>
                        <input type="date" name="suspended_until" id="susp_until"
                            value="<?php echo date('Y-m-d', strtotime('+10 days')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Motif de désactivation *</label>
                    <textarea name="suspension_reason" required rows="3"
                        placeholder="Ex: Non-respect des conditions d'utilisation, litige en cours..."
                        style="width: 100%; padding: 0.6rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem;"></textarea>
                </div>
            </div>
            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeSuspendUserModal()">Annuler</button>
                <button type="submit" class="dash-btn-action" style="background: #000000; color: #ffffff;">Confirmer la
                    Suspension</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE 4 : FICHE DÉTAILLÉE DU COMPTE
     ============================================================================== -->
<div id="viewUserModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeViewUserModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 520px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-id-card" style="color: var(--primary);"></i> Fiche Utilisateur</h3>
            <button type="button" class="dash-modal-close" onclick="closeViewUserModal()">&times;</button>
        </div>
        <div class="dash-modal-body" id="viewUserContent" style="font-size: 0.88rem;">
            <!-- Rempli en JavaScript -->
        </div>
        <div class="dash-modal-footer">
            <button type="button" class="dash-btn-action" onclick="closeViewUserModal()">Fermer</button>
        </div>
    </div>
</div>

<style>
    /* Modals & Dropdown Styles */
    .dash-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .dash-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
    }

    .dash-modal-dialog {
        position: relative;
        background: #ffffff;
        border-radius: 14px;
        width: 100%;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        overflow: hidden;
        animation: modalIn 0.2s ease-out;
    }

    @keyframes modalIn {
        from {
            opacity: 0;
            transform: translateY(-15px) scale(0.98);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .dash-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.1rem 1.4rem;
        border-bottom: 1px solid var(--dash-border);
    }

    .dash-modal-header h3 {
        margin: 0;
        font-size: 1.1rem;
        color: var(--dash-text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .dash-modal-close {
        background: transparent;
        border: none;
        font-size: 1.4rem;
        line-height: 1;
        color: var(--dash-muted);
        cursor: pointer;
    }

    .dash-modal-body {
        padding: 1.4rem;
        max-height: calc(85vh - 120px);
        overflow-y: auto;
    }

    .dash-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        padding: 1rem 1.4rem;
        background: #F5F5F5;
        border-top: 1px solid var(--dash-border);
    }

    .action-item {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 0.5rem 0.85rem;
        background: transparent;
        border: none;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--dash-text);
        text-align: left;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .action-item:hover {
        background: #F5F5F5;
    }
</style>

<script>
    // Gestion des Popovers d'actions ⋮
    function toggleActionMenu(event, menuId) {
        event.stopPropagation();
        document.querySelectorAll('.action-popover').forEach(p => {
            if (p.id !== menuId) p.style.display = 'none';
        });
        const menu = document.getElementById(menuId);
        if (menu) {
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
        }
    }
    document.addEventListener('click', function () {
        document.querySelectorAll('.action-popover').forEach(p => p.style.display = 'none');
    });

    // Modale Création
    function openCreateUserModal() {
        document.getElementById('createUserModal').style.display = 'flex';
        const role = document.getElementById('create_role_select').value;
        toggleAdminPromoterFields(role);
    }
    function closeCreateUserModal() {
        document.getElementById('createUserModal').style.display = 'none';
    }
    function toggleAdminPromoterFields(role) {
        const box = document.getElementById('admin_promo_fields');
        if (box) {
            const isPromo = (role === 'promoteur');
            box.style.display = isPromo ? 'block' : 'none';
            if (isPromo) {
                const selectType = document.getElementById('select_type_entite_promo');
                togglePromoterLegalFields(selectType ? selectType.value : 'physique');
            }
        }
    }

    function togglePromoterLegalFields(type) {
        const isMorale = (type === 'morale');
        const boxMorale = document.getElementById('promo_morale_fields');
        const lblCommercial = document.getElementById('lbl_nom_commercial');
        const inputCommercial = document.getElementById('input_nom_commercial_promo');
        const inputRccm = document.getElementById('input_numero_registre_promo');
        const inputRep = document.getElementById('input_representant_legal_promo');

        if (boxMorale) {
            boxMorale.style.display = isMorale ? 'grid' : 'none';
        }
        if (lblCommercial) {
            lblCommercial.innerHTML = isMorale ? 'Raison Sociale / Nom Entreprise *' : 'Nom de Scène / Commercial (Optionnel)';
        }
        if (inputCommercial) {
            inputCommercial.placeholder = isMorale ? 'Ex: Live Event SARL' : 'Ex: DJ Magic, Prod...';
        }
        if (inputRccm) inputRccm.required = isMorale;
        if (inputRep) inputRep.required = isMorale;
    }

    // Modale Édition
    function openEditUserModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_nom').value = user.nom || '';
        document.getElementById('edit_prenom').value = user.prenom || '';
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_telephone').value = user.telephone || '';
        const role = user.role || 'client';
        document.getElementById('edit_role').value = role;
        document.getElementById('edit_profile_id').value = user.profile_id || '';

        const promoSection = document.getElementById('edit_promoter_section');
        const commInput = document.getElementById('edit_commission_rate');
        if (promoSection && commInput) {
            if (role === 'promoteur') {
                promoSection.style.display = 'block';
                commInput.value = (user.promoter_commission_rate !== undefined && user.promoter_commission_rate !== null)
                    ? parseFloat(user.promoter_commission_rate).toFixed(2)
                    : '5.00';
            } else {
                promoSection.style.display = 'none';
                commInput.value = '5.00';
            }
        }

        document.getElementById('editUserModal').style.display = 'flex';
    }
    function closeEditUserModal() {
        document.getElementById('editUserModal').style.display = 'none';
    }

    // Bascule dynamique du champ commission lors d'un changement de rôle dans l'édition
    const editRoleEl = document.getElementById('edit_role');
    if (editRoleEl) {
        editRoleEl.addEventListener('change', function() {
            const promoSection = document.getElementById('edit_promoter_section');
            if (promoSection) {
                promoSection.style.display = (this.value === 'promoteur') ? 'block' : 'none';
            }
        });
    }

    // Modale Suspension
    function openSuspendUserModal(user) {
        document.getElementById('suspend_user_id').value = user.id;
        document.getElementById('suspendUserModal').style.display = 'flex';
    }
    function closeSuspendUserModal() {
        document.getElementById('suspendUserModal').style.display = 'none';
    }
    function toggleSuspensionDates() {
        const type = document.getElementById('susp_type_select').value;
        const box = document.getElementById('susp_dates_box');
        const untilInput = document.getElementById('susp_until');
        if (type === 'permanente') {
            box.style.display = 'none';
            if (untilInput) untilInput.required = false;
        } else {
            box.style.display = 'grid';
            if (untilInput) untilInput.required = true;
        }
    }

    // Modale Fiche Détail
    function openViewUserModal(u) {
        const full = ((u.prenom || '') + ' ' + (u.nom || '')).trim();
        const html = `
        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.25rem;">
            <div style="width: 52px; height: 52px; border-radius: 12px; background: var(--primary-soft); color: var(--primary); font-size: 1.4rem; font-weight: 800; display: grid; place-items: center;">
                ${(u.nom || 'U').charAt(0).toUpperCase()}
            </div>
            <div>
                <strong style="font-size: 1.15rem; color: var(--navy); display: block;">${full}</strong>
                <span style="font-size: 0.8rem; color: var(--muted); font-family: 'Space Mono', monospace;">ID #${u.id} · ${u.role ? u.role.toUpperCase() : 'USER'}</span>
            </div>
        </div>

        <div style="display: grid; gap: 0.65rem; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 10px; padding: 1rem;">
            <div><span style="color: var(--muted); font-size: 0.78rem;">Email :</span> <strong style="color: var(--navy);">${u.email || '—'}</strong></div>
            <div><span style="color: var(--muted); font-size: 0.78rem;">Téléphone :</span> <strong style="color: var(--navy);">${u.telephone || 'Non renseigné'}</strong></div>
            ${u.role === 'promoteur' ? `<div><span style="color: var(--muted); font-size: 0.78rem;">Taux Commission :</span> <strong style="color: #b45309; font-family: monospace;">${u.promoter_commission_rate ? parseFloat(u.promoter_commission_rate).toFixed(2) : '5.00'}%</strong></div>` : ''}
            <div><span style="color: var(--muted); font-size: 0.78rem;">Profil Métier :</span> <strong style="color: var(--primary);">${u.profile_nom || 'Aucun (Standard)'}</strong></div>
            <div><span style="color: var(--muted); font-size: 0.78rem;">Statut Actuel :</span> <strong style="text-transform: capitalize;">${u.statut || 'Actif'}</strong></div>
            <div><span style="color: var(--muted); font-size: 0.78rem;">Dernière connexion :</span> <strong>${u.derniere_connexion || 'Jamais connecté'}</strong></div>
            <div><span style="color: var(--muted); font-size: 0.78rem;">Date d'inscription :</span> <strong>${u.created_at || '—'}</strong></div>
            ${u.suspension_reason ? `<div style="color: #000000; font-size: 0.8rem; border-top: 1px solid #F5F5F5; padding-top: 6px; margin-top: 4px;"><strong>Motif suspension :</strong> ${u.suspension_reason}</div>` : ''}
        </div>
    `;
        document.getElementById('viewUserContent').innerHTML = html;
        document.getElementById('viewUserModal').style.display = 'flex';
    }
    function closeViewUserModal() {
        document.getElementById('viewUserModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>