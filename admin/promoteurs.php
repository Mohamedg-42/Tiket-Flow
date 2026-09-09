<?php
// ==============================================================================
// GESTION DES PROMOTEURS (admin/promoteurs.php)
// Design Dashboard Pro - Supervision des partenaires, soldes financiers et statuts
// ==============================================================================

$admin_page_title = "Gestion des Promoteurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 0. Action : Création manuelle d'un promoteur par l'administrateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_promoter'])) {
    $type_entite = $_POST['type_entite'] ?? 'physique';
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Champs pour personne morale
    $raison_sociale = trim($_POST['raison_sociale'] ?? '');
    $numero_rccm = trim($_POST['numero_rccm'] ?? '');
    $rep_legal = trim($_POST['representant_legal'] ?? '');

    if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
        $message = "Veuillez renseigner le nom, le prénom, l'adresse e-mail et le mot de passe.";
        $msg_type = "error";
    } elseif ($type_entite === 'morale' && empty($raison_sociale)) {
        $message = "Veuillez renseigner la raison sociale de la personne morale.";
        $msg_type = "error";
    } else {
        $st_chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $st_chk->execute([$email]);
        if ($st_chk->fetch()) {
            $message = "Cette adresse e-mail est déjà associée à un compte utilisateur.";
            $msg_type = "error";
        } else {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $st_u = $pdo->prepare("
                INSERT INTO users (nom, prenom, email, telephone, password, role, statut, est_verifie, created_at)
                VALUES (?, ?, ?, ?, ?, 'promoteur', 'actif', 1, NOW())
            ");
            $st_u->execute([$nom, $prenom, $email, $telephone, $pass_hash]);
            $new_user_id = (int) $pdo->lastInsertId();

            $full_name = trim("$prenom $nom");
            $nom_commercial = ($type_entite === 'morale') ? $raison_sociale : (!empty($_POST['nom_commercial']) ? trim($_POST['nom_commercial']) : $full_name);
            $rccm_val = ($type_entite === 'morale') ? $numero_rccm : null;
            $rep_val = ($type_entite === 'morale') ? (!empty($rep_legal) ? $rep_legal : $full_name) : $full_name;
            $commission_rate = isset($_POST['commission_rate']) ? (float)str_replace(',', '.', trim($_POST['commission_rate'])) : 5.00;
            if ($commission_rate < 0 || $commission_rate > 50) $commission_rate = 5.00;

            $st_chk = $pdo->prepare("SELECT id FROM promoters WHERE user_id = ?");
            $st_chk->execute([$new_user_id]);
            if ($st_chk->fetch()) {
                $st_prom = $pdo->prepare("
                    UPDATE promoters 
                    SET type_entite = ?, nom_commercial = ?, numero_registre = ?, representant_legal = ?, telephone_contact = ?, email_contact = ?, ville = ?, commission_rate = ?, statut = 'approuve'
                    WHERE user_id = ?
                ");
                $st_prom->execute([$type_entite, $nom_commercial, $rccm_val, $rep_val, $telephone, $email, $ville, $commission_rate, $new_user_id]);
            } else {
                $st_prom = $pdo->prepare("
                    INSERT INTO promoters (user_id, type_entite, nom_commercial, numero_registre, representant_legal, telephone_contact, email_contact, ville, statut, solde, commission_rate, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approuve', 0.00, ?, NOW())
                ");
                $st_prom->execute([$new_user_id, $type_entite, $nom_commercial, $rccm_val, $rep_val, $telephone, $email, $ville, $commission_rate]);
            }

            // Envoi des identifiants par e-mail
            require_once __DIR__ . '/../includes/mailer.php';
            $mail_ok = sendAdminCreatedAccountEmail($email, $full_name, 'promoteur', $password, 'Promoteur Événements');

            logActivity('user.create', 'user', $new_user_id, "Création manuelle du promoteur « $nom_commercial » ($type_entite, commission: $commission_rate%)");

            $message = "Le promoteur « " . htmlspecialchars($nom_commercial) . " » a été créé et activé avec succès (taux: $commission_rate%) !" . ($mail_ok ? " Ses identifiants ont été envoyés à $email." : "");
            $msg_type = "success";
        }
    }
}

// 1. Actions d'activation / suspension de promoteur
if (isset($_GET['id']) && isset($_GET['action'])) {
    $promoter_id = (int) $_GET['id'];
    $action = $_GET['action'];

    if ($action === 'suspend') {
        $stmt = $pdo->prepare("UPDATE promoters SET statut = 'suspendu' WHERE id = ?");
        $stmt->execute([$promoter_id]);
        $message = "Le compte du promoteur a été suspendu.";
        $msg_type = "error";
    } elseif ($action === 'activate') {
        $stmt = $pdo->prepare("UPDATE promoters SET statut = 'approuve' WHERE id = ?");
        $stmt->execute([$promoter_id]);
        $message = "Le promoteur a été réactivé avec succès.";
        $msg_type = "success";
    }
}

// 1.5 Action : Modification du taux de commission personnalisé d'un promoteur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_commission') {
    $promoter_id = (int)($_POST['promoter_id'] ?? 0);
    $new_rate_raw = trim($_POST['commission_rate'] ?? '');
    $new_rate = (float)str_replace(',', '.', $new_rate_raw);
    $apply_active_events = !empty($_POST['apply_active_events']);

    if ($promoter_id <= 0) {
        $message = "Promoteur introuvable.";
        $msg_type = "error";
    } elseif ($new_rate < 0 || $new_rate > 50) {
        $message = "Le taux de commission doit être compris entre 0% et 50%.";
        $msg_type = "error";
    } else {
        $st_p = $pdo->prepare("SELECT p.*, u.nom AS user_nom, u.email AS user_email FROM promoters p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $st_p->execute([$promoter_id]);
        $prom_info = $st_p->fetch(PDO::FETCH_ASSOC);

        if (!$prom_info) {
            $message = "Promoteur introuvable.";
            $msg_type = "error";
        } else {
            $old_rate = (float)($prom_info['commission_rate'] ?? 5.00);

            // Mise à jour du taux dans promoters
            $stmt_upd = $pdo->prepare("UPDATE promoters SET commission_rate = ?, updated_at = NOW() WHERE id = ?");
            $stmt_upd->execute([$new_rate, $promoter_id]);

            $events_count = 0;
            if ($apply_active_events) {
                // Mettre également à jour les événements actifs et en attente de ce promoteur
                $stmt_ev = $pdo->prepare("UPDATE events SET commission_rate = ? WHERE user_id = ? AND statut = 'actif'");
                $stmt_ev->execute([$new_rate, $prom_info['user_id']]);
                $events_count = $stmt_ev->rowCount();
            }

            $nom_promo = $prom_info['nom_commercial'] ?: $prom_info['user_nom'];
            logActivity('promoter.update_commission', 'user', (int)$prom_info['user_id'], "Modification du taux de commission de « $nom_promo » : $old_rate% -> $new_rate%" . ($apply_active_events ? " (appliqué à $events_count événements actifs)" : ""));

            $message = "Le taux de commission du promoteur « " . htmlspecialchars($nom_promo) . " » a été modifié avec succès à " . number_format($new_rate, 2) . "% !" . ($apply_active_events ? " ($events_count événement(s) actif(s) actualisé(s))" : "");
            $msg_type = "success";
        }
    }
}

// Filtre statut et recherche
$statut_f = $_GET['statut'] ?? 'tous';
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, u.nom AS user_nom, u.email AS user_email, u.telephone AS user_tel,
           (SELECT COUNT(*) FROM events e WHERE e.user_id = p.user_id) AS total_events,
           (SELECT COUNT(*) FROM information_requests ir WHERE ir.promoter_id = p.user_id) AS total_info_reqs
    FROM promoters p
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($statut_f === 'approuve') {
    $sql .= " AND p.statut = 'approuve'";
} elseif ($statut_f === 'suspendu') {
    $sql .= " AND p.statut = 'suspendu'";
} elseif ($statut_f === 'en_attente') {
    $sql .= " AND p.statut = 'en_attente'";
}

if (!empty($search)) {
    $sql .= " AND (p.nom_commercial LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR p.telephone_contact LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$promoters = $stmt->fetchAll();

// KPIs
$tot_promoters = (int) $pdo->query("SELECT COUNT(*) FROM promoters")->fetchColumn();
$tot_actifs = (int) $pdo->query("SELECT COUNT(*) FROM promoters WHERE statut = 'approuve'")->fetchColumn();
$tot_suspendus = (int) $pdo->query("SELECT COUNT(*) FROM promoters WHERE statut = 'suspendu'")->fetchColumn();
$tot_soldes = (float) $pdo->query("SELECT COALESCE(SUM(solde), 0) FROM promoters")->fetchColumn();
?>

<style>
/* Responsive Styles for Promoteurs */
.dash-kpi-grid-promoters {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
}
.promoters-desktop-table {
    display: block;
}
.promoters-mobile-list {
    display: none;
}
@media (max-width: 960px) {
    .dash-kpi-grid-promoters {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 860px) {
    .promoters-desktop-table {
        display: none !important;
    }
    .promoters-mobile-list {
        display: flex !important;
        flex-direction: column;
        gap: 0.85rem;
    }
    .promoter-mobile-card {
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
    .pmc-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
    }
    .pmc-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--dash-text, #000000);
    }
    .pmc-contact-info {
        font-size: 0.82rem;
        color: var(--dash-muted, #737373);
        display: flex;
        flex-direction: column;
        gap: 4px;
        background: #F5F5F5;
        padding: 0.65rem 0.85rem;
        border-radius: 8px;
        border: 1px solid #F5F5F5;
        word-break: break-word;
    }
    .pmc-stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        font-size: 0.82rem;
    }
    .pmc-stat-box {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 8px;
        padding: 0.55rem 0.75rem;
    }
    .pmc-stat-label {
        font-size: 0.7rem;
        color: var(--dash-muted, #737373);
        text-transform: uppercase;
        font-weight: 700;
        display: block;
        margin-bottom: 2px;
    }
    .pmc-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        padding-top: 0.65rem;
        border-top: 1px solid var(--dash-border, #E5E5E5);
    }
    .pmc-actions a {
        width: 100%;
        text-align: center;
        justify-content: center;
        padding: 0.65rem 1rem;
        box-sizing: border-box;
    }
}
@media (max-width: 480px) {
    .dash-kpi-grid-promoters {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
    .pmc-stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-user-tie" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Gestion des Promoteurs Partenaires
            </h1>
            <p>Supervisez les organisateurs agréés, consultez leurs soldes de trésorerie et gérez leurs statuts.</p>
        </div>

        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="export.php?type=utilisateurs&role=promoteur&statut=<?php echo urlencode($statut_f); ?>&q=<?php echo urlencode($search); ?>" class="dash-btn-action"
                style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Exporter les promoteurs sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <button type="button" onclick="openCreatePromoterModal()" class="dash-btn-action btn-primary"
                style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <i class="fa-solid fa-user-plus"></i> Créer un Promoteur
            </button>
            <a href="demandes-promoteurs.php" class="dash-btn-action"
                style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-id-card"></i> Dossiers d'Éligibilité
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES)
         ============================================================================== -->
    <div
        style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; overflow-x: auto;">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>"
                style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #FF4A0D;' : ''; ?>"></i>
                Tous (<?php echo $tot_promoters; ?>)
            </a>

            <a href="?statut=approuve&q=<?php echo urlencode($search); ?>"
                style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'approuve' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i> Actifs (<?php echo $tot_actifs; ?>)
            </a>

            <a href="?statut=suspendu&q=<?php echo urlencode($search); ?>"
                style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'suspendu' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-ban" style="color: #000000;"></i> Suspendus (<?php echo $tot_suspendus; ?>)
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="promoteurs.php"
            style="display: inline-flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Structure, nom, email..."
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 180px; background: #ffffff;">
            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="promoteurs.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div class="dash-kpi-grid-promoters">
        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Promoteurs
                    Actifs</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-user-tie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;"><?php echo $tot_actifs; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Organisateurs autorisés</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Soldes
                    Cumulés</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-wallet"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;">
                <?php echo number_format($tot_soldes, 0, ',', ' '); ?> F</div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Total des portefeuilles</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #000000; text-transform: uppercase;">Comptes
                    Suspendus</span>
                <span
                    style="background: #F5F5F5; color: #000000; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-ban"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #000000;"><?php echo $tot_suspendus; ?></div>
            <small style="color: #000000; font-size: 0.75rem;">Accès temporairement bloqués</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span
                    style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total
                    Partenaires</span>
                <span
                    style="background: #F5F5F5; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-handshake"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $tot_promoters; ?>
            </div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Tous statuts confondus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES PROMOTEURS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Promoteurs
                (<?php echo count($promoters); ?>)
            </h3>
        </div>

        <?php if (empty($promoters)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-user-tie"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun promoteur ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <!-- Vue Table Desktop -->
            <div class="dash-table-wrapper promoters-desktop-table" style="overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch;">
                <table class="dash-table" style="min-width: 880px; width: 100%;">
                    <thead>
                        <tr>
                            <th>Structure / Promoteur</th>
                            <th>Contact Direct</th>
                            <th>Événements</th>
                            <th>Demandes Infos</th>
                            <th>Solde Retirable</th>
                            <th>Taux Commission</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Action Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promoters as $p): ?>
                            <?php
                            $is_susp = ($p['statut'] === 'suspendu');
                            $statut_badge = [
                                'approuve' => ['Actif', '#FFF2ED', '#000000'],
                                'suspendu' => ['Suspendu', '#F5F5F5', '#000000'],
                                'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D']
                            ];
                            [$st_l, $st_b, $st_f] = $statut_badge[$p['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                            $comm_rate = (float)($p['commission_rate'] ?? 5.00);
                            $is_custom_rate = (abs($comm_rate - 5.00) > 0.001);
                            $p_nom_clean = htmlspecialchars($p['nom_commercial'] ?: $p['user_nom']);
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo $p_nom_clean; ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        Responsable : <?php echo htmlspecialchars($p['user_nom']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-text); display: block;">
                                        <i class="fa-regular fa-envelope" style="color: var(--dash-muted);"></i>
                                        <?php echo htmlspecialchars($p['email_contact'] ?: $p['user_email']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <i class="fa-solid fa-phone"></i>
                                        <?php echo htmlspecialchars($p['telephone_contact'] ?: $p['user_tel']); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong style="font-size: 0.88rem; color: var(--dash-text);">
                                        <?php echo (int) $p['total_events']; ?>
                                    </strong> événement(s)
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem; color: var(--dash-text);">
                                        <?php echo (int) $p['total_info_reqs']; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #FF4A0D; font-size: 0.98rem; font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums;">
                                        <?php echo str_replace(' ', '&nbsp;', number_format((float) $p['solde'], 0, ',', ' ')); ?>&nbsp;F
                                    </strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                        <strong style="font-size: 0.92rem; color: <?php echo $is_custom_rate ? '#FF4A0D' : 'var(--dash-text)'; ?>;">
                                            <?php echo number_format($comm_rate, 2); ?>%
                                        </strong>
                                        <?php if ($is_custom_rate): ?>
                                            <span style="font-size: 0.65rem; font-weight: 800; background: #FFF2ED; color: #FF4A0D; padding: 1px 6px; border-radius: 4px; text-transform: uppercase;" title="Taux contractuel personnalisé négocié">
                                                Spécial
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size: 0.65rem; color: var(--dash-muted);">Standard</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span
                                        style="background: <?php echo $st_b; ?>; color: <?php echo $st_f; ?>; padding: 3px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_l; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="dash-btn-action"
                                            onclick="openCommissionModal(<?php echo (int)$p['id']; ?>, '<?php echo addslashes($p['nom_commercial'] ?: $p['user_nom']); ?>', <?php echo $comm_rate; ?>)"
                                            style="padding: 0.35rem 0.65rem; font-size: 0.74rem; background: #ffffff; border-color: #E2E8F0; color: #0F172A;"
                                            title="Modifier le taux de commission de ce promoteur">
                                            <i class="fa-solid fa-percent" style="color: #FF4A0D;"></i> Taux
                                        </button>
                                        <?php if ($is_susp): ?>
                                            <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=activate"
                                                onclick="return confirm('Réactiver ce promoteur ?');" class="dash-btn-action"
                                                style="padding: 0.35rem 0.75rem; font-size: 0.74rem; background: #FFF2ED; color: #000000;">
                                                <i class="fa-solid fa-unlock"></i> Réactiver
                                            </a>
                                        <?php else: ?>
                                            <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=suspend"
                                                onclick="return confirm('Voulez-vous suspendre temporairement ce promoteur ?');"
                                                class="dash-btn-action"
                                                style="padding: 0.35rem 0.75rem; font-size: 0.74rem; background: #F5F5F5; color: #000000;">
                                                <i class="fa-solid fa-ban"></i> Suspendre
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vue Cartes Mobile (<= 860px) -->
            <div class="promoters-mobile-list">
                <?php foreach ($promoters as $p): ?>
                    <?php
                    $is_susp = ($p['statut'] === 'suspendu');
                    $statut_badge = [
                        'approuve' => ['Actif', '#FFF2ED', '#000000'],
                        'suspendu' => ['Suspendu', '#F5F5F5', '#000000'],
                        'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D']
                    ];
                    [$st_l, $st_b, $st_f] = $statut_badge[$p['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                    $comm_rate = (float)($p['commission_rate'] ?? 5.00);
                    $is_custom_rate = (abs($comm_rate - 5.00) > 0.001);
                    ?>
                    <div class="promoter-mobile-card">
                        <div class="pmc-header">
                            <div>
                                <h4 class="pmc-title"><?php echo htmlspecialchars($p['nom_commercial'] ?: $p['user_nom']); ?></h4>
                                <small style="color: var(--dash-muted); font-size: 0.75rem;">Resp. : <?php echo htmlspecialchars($p['user_nom']); ?></small>
                            </div>
                            <span style="background: <?php echo $st_b; ?>; color: <?php echo $st_f; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; text-transform: uppercase;">
                                <?php echo $st_l; ?>
                            </span>
                        </div>

                        <div class="pmc-contact-info">
                            <div><i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($p['email_contact'] ?: $p['user_email']); ?></div>
                            <div><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($p['telephone_contact'] ?: $p['user_tel']); ?></div>
                        </div>

                        <div class="pmc-stats-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                            <div class="pmc-stat-box">
                                <span class="pmc-stat-label">Événements</span>
                                <strong><?php echo (int) $p['total_events']; ?></strong>
                            </div>
                            <div class="pmc-stat-box">
                                <span class="pmc-stat-label">Commission</span>
                                <strong style="color: <?php echo $is_custom_rate ? '#FF4A0D' : 'var(--dash-text)'; ?>;">
                                    <?php echo number_format($comm_rate, 2); ?>%
                                </strong>
                            </div>
                            <div class="pmc-stat-box">
                                <span class="pmc-stat-label">Solde</span>
                                <strong style="color: #FF4A0D; font-variant-numeric: tabular-nums;">
                                    <?php echo number_format((float) $p['solde'], 0, ',', ' '); ?> F
                                </strong>
                            </div>
                        </div>

                        <div class="pmc-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="dash-btn-action"
                                onclick="openCommissionModal(<?php echo (int)$p['id']; ?>, '<?php echo addslashes($p['nom_commercial'] ?: $p['user_nom']); ?>', <?php echo $comm_rate; ?>)"
                                style="background: #ffffff; border-color: #CBD5E1; color: #0F172A; flex: 1; justify-content: center; font-weight: 700;">
                                <i class="fa-solid fa-percent" style="color: #FF4A0D;"></i> Modifier le Taux
                            </button>
                            <?php if ($is_susp): ?>
                                <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=activate"
                                    onclick="return confirm('Réactiver ce promoteur ?');" class="dash-btn-action"
                                    style="background: #FFF2ED; color: #000000; font-weight: 800; flex: 1; justify-content: center;">
                                    <i class="fa-solid fa-unlock"></i> Réactiver
                                </a>
                            <?php else: ?>
                                <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=suspend"
                                    onclick="return confirm('Voulez-vous suspendre temporairement ce promoteur ?');"
                                    class="dash-btn-action"
                                    style="background: #F5F5F5; color: #000000; font-weight: 800; flex: 1; justify-content: center;">
                                    <i class="fa-solid fa-ban"></i> Suspendre
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==============================================================================
     MODALE DE CRÉATION DE PROMOTEUR (ADMIN)
     ============================================================================== -->
<div id="createPromoterModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeCreatePromoterModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 620px;">
        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-user-tie" style="color: #FF4A0D;"></i> Créer un Nouveau Promoteur</h3>
            <button type="button" class="dash-modal-close" onclick="closeCreatePromoterModal()">&times;</button>
        </div>
        <form method="POST" action="promoteurs.php" style="padding: 1.5rem;">
            <input type="hidden" name="create_promoter" value="1">

            <!-- 1. Choix Personne Physique vs Personne Morale -->
            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--dash-text);">
                    Statut Juridique de l'Organisateur *
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <label id="card_type_physique" onclick="switchPromoterType('physique')"
                        style="display: flex; align-items: center; gap: 10px; padding: 0.75rem 1rem; border: 2px solid #FF4A0D; background: #FFF2ED; border-radius: 10px; cursor: pointer; transition: all 0.2s;">
                        <input type="radio" name="type_entite" value="physique" checked style="accent-color: #FF4A0D;">
                        <div>
                            <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Personne
                                Physique</strong>
                            <small style="color: var(--dash-muted); font-size: 0.74rem;">Indépendant / Artiste /
                                Particulier</small>
                        </div>
                    </label>
                    <label id="card_type_morale" onclick="switchPromoterType('morale')"
                        style="display: flex; align-items: center; gap: 10px; padding: 0.75rem 1rem; border: 2px solid #E5E5E5; background: #ffffff; border-radius: 10px; cursor: pointer; transition: all 0.2s;">
                        <input type="radio" name="type_entite" value="morale" style="accent-color: #FF4A0D;">
                        <div>
                            <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Personne
                                Morale</strong>
                            <small style="color: var(--dash-muted); font-size: 0.74rem;">Entreprise / Association /
                                Société</small>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Informations Entreprise (Affichées UNIQUEMENT si Personne Morale) -->
            <div id="box_personne_morale"
                style="display: none; background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 10px; padding: 1rem 1.15rem; margin-bottom: 1.25rem;">
                <div
                    style="font-weight: 800; color: #000000; font-size: 0.84rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-building" style="color: #FF4A0D;"></i> Identifiants de la Personne Morale
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <label
                        style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Raison
                        Sociale / Nom Commercial de l'Entreprise *</label>
                    <input type="text" name="raison_sociale" id="input_pm_raison"
                        placeholder="Ex: Live Event SARL, Pulse Agency..."
                        style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">N°
                            Registre RCCM / SIRET</label>
                        <input type="text" name="numero_rccm" placeholder="Ex: CI-ABJ-2026-B-9988"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Représentant
                            Légal / Signataire *</label>
                        <input type="text" name="representant_legal" id="input_pm_rep"
                            placeholder="Nom et prénom du gérant"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>
            </div>

            <!-- Nom de scène / Pseudo optionnel si Personne Physique -->
            <div id="box_personne_physique" style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Nom
                    d'artiste / Nom de Scène (Optionnel)</label>
                <input type="text" name="nom_commercial" placeholder="Ex: DJ Magic, Production Artistique..."
                    style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
            </div>

            <!-- 3. Identité du Contact / Déclarant (Nom et Prénom bien séparés) -->
            <div style="border-top: 1px solid #F5F5F5; padding-top: 1rem; margin-bottom: 1rem;">
                <div
                    style="font-weight: 800; color: #000000; font-size: 0.84rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-id-card" style="color: #FF4A0D;"></i> Informations Personnelles du Promoteur
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Nom
                            de famille *</label>
                        <input type="text" name="nom" required placeholder="Ex: Kouamé"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Prénom(s)
                            *</label>
                        <input type="text" name="prenom" required placeholder="Ex: Yao Franck"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Adresse
                            Email (Identifiant de connexion) *</label>
                        <input type="email" name="email" required placeholder="promoteur@domaine.ci"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Numéro
                            de Téléphone *</label>
                        <input type="text" name="telephone" required placeholder="0701020304"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Ville
                            / Commune</label>
                        <input type="text" name="ville" placeholder="Ex: Abidjan, Cocody"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Taux
                            Commission (%) *</label>
                        <input type="number" step="0.1" min="0" max="50" name="commission_rate" value="5.0" required placeholder="5.0"
                            style="width: 100%; padding: 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box; font-weight: 700; color: #FF4A0D;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 3px; color: var(--dash-text);">Mot
                            de passe initial *</label>
                        <div style="position: relative;">
                            <input type="password" id="create_promo_pass" name="password" required placeholder="Minimum 6 caractères"
                                style="width: 100%; padding: 0.55rem 2.25rem 0.55rem 0.55rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                            <i class="fa-regular fa-eye" onclick="togglePassVisibility('create_promo_pass', this)"
                                style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--dash-muted, #737373); font-size: 0.85rem;"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid #F5F5F5; padding-top: 1.15rem; margin-top: 0.5rem;">
                <button type="button" class="dash-btn-action" onclick="closeCreatePromoterModal()">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="font-weight: 800;">
                    <i class="fa-solid fa-user-plus"></i> Créer et Activer le Compte
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODALE DE MODIFICATION DU TAUX DE COMMISSION DU PROMOTEUR
     ============================================================================== -->
<div id="commissionModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.65); z-index: 99999; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);">
    <div style="background: #ffffff; width: 100%; max-width: 490px; border-radius: 14px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); overflow: hidden; animation: commModalIn 0.2s ease;">
        <div style="background: #0F172A; color: #ffffff; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-percent" style="color: #FF4A0D;"></i> Modifier le Taux de Commission
            </h3>
            <button type="button" onclick="closeCommissionModal()" style="background: none; border: none; color: #94A3B8; font-size: 1.3rem; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>

        <form method="POST" action="promoteurs.php" style="padding: 1.35rem;">
            <input type="hidden" name="action" value="update_commission">
            <input type="hidden" name="promoter_id" id="modal_comm_promoter_id">

            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px 14px; margin-bottom: 1.25rem;">
                <span style="font-size: 0.72rem; color: #64748B; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;">Promoteur Partenaire</span>
                <strong id="modal_comm_promoter_name" style="font-size: 1rem; color: #0F172A; display: block; margin-top: 2px;">—</strong>
                <div style="font-size: 0.8rem; color: #475569; margin-top: 4px;">
                    Taux actuellement en vigueur : <strong id="modal_comm_current_rate" style="color: #0F172A;">5.00%</strong>
                </div>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-weight: 800; font-size: 0.84rem; color: #0F172A; margin-bottom: 6px;">
                    Nouveau taux de commission plateforme (%) <span style="color: #EF4444;">*</span>
                </label>
                <div style="position: relative;">
                    <input type="number" step="0.1" min="0" max="50" name="commission_rate" id="modal_comm_input_rate" required
                        placeholder="Ex: 3.5"
                        style="width: 100%; padding: 0.65rem 2.5rem 0.65rem 0.85rem; border: 1.5px solid #CBD5E1; border-radius: 8px; font-size: 1.05rem; font-weight: 800; color: #0F172A; box-sizing: border-box; outline: none;">
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-weight: 800; color: #64748B; font-size: 1rem;">%</span>
                </div>
                <small style="color: #64748B; font-size: 0.75rem; margin-top: 5px; display: block; line-height: 1.4;">
                    Le promoteur percevra <strong>(100 - Taux)%</strong> du montant brut de chaque billet vendu.
                </small>
            </div>

            <!-- Raccourcis de barème rapides -->
            <div style="margin-bottom: 1.25rem;">
                <label style="font-size: 0.74rem; color: #64748B; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 6px;">
                    Raccourcis barèmes standards :
                </label>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" class="dash-btn-action" style="padding: 3px 8px; font-size: 0.73rem; background: #ffffff; border: 1px solid #CBD5E1;" onclick="setQuickRate(2.5)">2.5% (VIP)</button>
                    <button type="button" class="dash-btn-action" style="padding: 3px 8px; font-size: 0.73rem; background: #ffffff; border: 1px solid #CBD5E1;" onclick="setQuickRate(3.0)">3.0% (Stade / Festival)</button>
                    <button type="button" class="dash-btn-action" style="padding: 3px 8px; font-size: 0.73rem; background: #ffffff; border: 1px solid #CBD5E1;" onclick="setQuickRate(4.0)">4.0% (Grand Evt)</button>
                    <button type="button" class="dash-btn-action" style="padding: 3px 8px; font-size: 0.73rem; background: #ffffff; border: 1px solid #CBD5E1;" onclick="setQuickRate(5.0)">5.0% (Standard)</button>
                    <button type="button" class="dash-btn-action" style="padding: 3px 8px; font-size: 0.73rem; background: #ffffff; border: 1px solid #CBD5E1;" onclick="setQuickRate(7.0)">7.0% (Intimiste)</button>
                </div>
            </div>

            <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; padding: 12px; margin-bottom: 1.35rem;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; margin: 0;">
                    <input type="checkbox" name="apply_active_events" value="1" style="margin-top: 3px; accent-color: #FF4A0D; width: 16px; height: 16px;">
                    <span style="font-size: 0.8rem; color: #92400E; line-height: 1.45;">
                        <strong>Appliquer aussi aux événements actifs :</strong><br>
                        Actualiser le taux de commission de tous les événements actuellement en cours / publiés de ce promoteur.
                    </span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid #F1F5F9; padding-top: 1rem;">
                <button type="button" class="dash-btn-action" onclick="closeCommissionModal()" style="background: #F1F5F9; color: #334155; border-color: #E2E8F0;">
                    Annuler
                </button>
                <button type="submit" class="dash-btn-action" style="background: #000000; color: #ffffff; font-weight: 800; border-color: #000000;">
                    <i class="fa-solid fa-check"></i> Enregistrer le Taux
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCommissionModal(promoterId, promoterName, currentRate) {
        document.getElementById('modal_comm_promoter_id').value = promoterId;
        document.getElementById('modal_comm_promoter_name').textContent = promoterName;
        document.getElementById('modal_comm_current_rate').textContent = parseFloat(currentRate).toFixed(2) + '%';
        document.getElementById('modal_comm_input_rate').value = parseFloat(currentRate).toFixed(1);
        document.getElementById('commissionModal').style.display = 'flex';
        setTimeout(() => {
            document.getElementById('modal_comm_input_rate').focus();
            document.getElementById('modal_comm_input_rate').select();
        }, 80);
    }

    function closeCommissionModal() {
        document.getElementById('commissionModal').style.display = 'none';
    }

    function setQuickRate(rate) {
        const input = document.getElementById('modal_comm_input_rate');
        if (input) {
            input.value = parseFloat(rate).toFixed(1);
            input.focus();
        }
    }

    function openCreatePromoterModal() {
        document.getElementById('createPromoterModal').style.display = 'flex';
    }
    function closeCreatePromoterModal() {
        document.getElementById('createPromoterModal').style.display = 'none';
    }
    function switchPromoterType(type) {
        const isMorale = (type === 'morale');
        const radPhysique = document.querySelector('input[name="type_entite"][value="physique"]');
        const radMorale = document.querySelector('input[name="type_entite"][value="morale"]');
        if (radPhysique) radPhysique.checked = !isMorale;
        if (radMorale) radMorale.checked = isMorale;

        const cardP = document.getElementById('card_type_physique');
        const cardM = document.getElementById('card_type_morale');
        const boxMorale = document.getElementById('box_personne_morale');
        const boxPhysique = document.getElementById('box_personne_physique');
        const inputRaison = document.getElementById('input_pm_raison');
        const inputRep = document.getElementById('input_pm_rep');

        if (isMorale) {
            cardM.style.borderColor = '#FF4A0D';
            cardM.style.background = '#FFF2ED';
            cardP.style.borderColor = '#E5E5E5';
            cardP.style.background = '#ffffff';
            boxMorale.style.display = 'block';
            boxPhysique.style.display = 'none';
            if (inputRaison) inputRaison.required = true;
            if (inputRep) inputRep.required = true;
        } else {
            cardP.style.borderColor = '#FF4A0D';
            cardP.style.background = '#FFF2ED';
            cardM.style.borderColor = '#E5E5E5';
            cardM.style.background = '#ffffff';
            boxMorale.style.display = 'none';
            boxPhysique.style.display = 'block';
            if (inputRaison) inputRaison.required = false;
            if (inputRep) inputRep.required = false;
        }
    }
</script>

<?php include 'footer.php'; ?>