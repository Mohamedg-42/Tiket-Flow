<?php
// ==============================================================================
// GESTION DES AGENTS DU PROMOTEUR (promoteur/agents.php)
// Design Dashboard Pro - Statistiques de scans en direct & Affectations strictes
// ==============================================================================

$page_title = "Mes Agents de Contrôle - Espace Promoteur";
include 'header.php';

$promoter_user_id = (int)$_SESSION['user_id'];

$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Traitement de la création / assignation d'un agent
// ------------------------------------------------------------------------------
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

            // Vérification de la propriété de l'événement par ce promoteur
            $stmt_ev_chk = $pdo->prepare("SELECT id, nom FROM events WHERE id = ? AND user_id = ?");
            $stmt_ev_chk->execute([$event_id, $promoter_user_id]);
            $ev_valid = $stmt_ev_chk->fetch();
            if (!$ev_valid) {
                throw new Exception("L'événement sélectionné n'est pas valide ou ne vous appartient pas.");
            }

            // Création ou récupération du compte utilisateur Agent
            $stmt_chk = $pdo->prepare("SELECT id, role, nom FROM users WHERE email = ?");
            $stmt_chk->execute([$email]);
            $existing_user = $stmt_chk->fetch();

            if ($existing_user) {
                if ($existing_user['role'] !== 'agent') {
                    throw new Exception("Cette adresse email est déjà associée à un compte utilisateur non-agent.");
                }
                $agent_id = (int)$existing_user['id'];
                // Mettre à jour le mot de passe si réaffecté
                $stmt_upd = $pdo->prepare("UPDATE users SET password = ?, telephone = COALESCE(NULLIF(?, ''), telephone) WHERE id = ?");
                $stmt_upd->execute([$password_hash, $telephone, $agent_id]);
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

            $message = "L'agent « " . htmlspecialchars($nom) . " » a été assigné avec succès à « " . htmlspecialchars($ev_valid['nom']) . " » !";
            $msg_type = "success";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $message = "Cet agent est déjà assigné au contrôle de cet événement.";
            } else {
                $message = "Erreur : " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 2. Traitement de la modification du mot de passe d'un agent
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password_agent'])) {
    $target_agent_id = filter_input(INPUT_POST, 'agent_id', FILTER_VALIDATE_INT);
    $new_pass        = $_POST['new_password'] ?? '';

    if ($target_agent_id && strlen($new_pass) >= 6) {
        // Vérifier que cet agent est bien assigné à un événement de ce promoteur
        $stmt_verif_ag = $pdo->prepare("
            SELECT COUNT(*) FROM agent_assignments WHERE agent_id = ? AND promoter_user_id = ?
        ");
        $stmt_verif_ag->execute([$target_agent_id, $promoter_user_id]);
        if ($stmt_verif_ag->fetchColumn() > 0) {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt_pwd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'agent'");
            $stmt_pwd->execute([$new_hash, $target_agent_id]);
            $message = "Le mot de passe de l'agent a été mis à jour avec succès.";
            $msg_type = "success";
        } else {
            $message = "Vous n'avez pas l'autorisation de modifier ce compte agent.";
            $msg_type = "error";
        }
    } else {
        $message = "Le nouveau mot de passe doit comporter au moins 6 caractères.";
        $msg_type = "error";
    }
}

// ------------------------------------------------------------------------------
// 3. Traitement de la suppression d'une affectation
// ------------------------------------------------------------------------------
if (isset($_GET['delete_assign'])) {
    $del_assign_id = (int)$_GET['delete_assign'];
    $stmt_del = $pdo->prepare("DELETE FROM agent_assignments WHERE id = ? AND promoter_user_id = ?");
    $stmt_del->execute([$del_assign_id, $promoter_user_id]);
    $message = "L'affectation de l'agent a été retirée.";
    $msg_type = "success";
}

// ------------------------------------------------------------------------------
// 4. Récupération des événements du promoteur (pour le formulaire & les filtres)
// ------------------------------------------------------------------------------
$stmt_ev = $pdo->prepare("
    SELECT id, nom, date_evenement, lieu, statut 
    FROM events 
    WHERE user_id = ? 
    ORDER BY date_evenement DESC
");
$stmt_ev->execute([$promoter_user_id]);
$my_events = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 5. Filtres (Recherche textuelle & Filtrage par Événement)
// ------------------------------------------------------------------------------
$filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$search_q     = trim($_GET['q'] ?? '');

$sql_agents = "
    SELECT aa.id AS assign_id, aa.created_at AS assign_date,
           u.id AS agent_id, u.nom AS agent_nom, u.email AS agent_email, u.telephone AS agent_tel,
           e.id AS event_id, e.nom AS event_nom, e.date_evenement, e.lieu, e.statut AS event_statut,
           (SELECT COUNT(*) FROM tickets t 
             WHERE t.validated_by = u.id AND t.event_id = e.id AND t.statut = 'utilise') AS nb_scans,
           (SELECT MAX(t.date_utilisation) FROM tickets t 
             WHERE t.validated_by = u.id AND t.event_id = e.id AND t.statut = 'utilise') AS dernier_scan,
           (SELECT COUNT(*) FROM tickets t2 
             WHERE t2.event_id = e.id AND t2.statut IN ('vendu', 'utilise')) AS total_billets_evenement,
           (SELECT COUNT(*) FROM tickets t3 
             WHERE t3.event_id = e.id AND t3.statut = 'utilise') AS total_scans_evenement
    FROM agent_assignments aa
    JOIN users u ON aa.agent_id = u.id
    JOIN events e ON aa.event_id = e.id
    WHERE aa.promoter_user_id = ?
";
$params_ag = [$promoter_user_id];

if ($filter_event) {
    $sql_agents .= " AND aa.event_id = ?";
    $params_ag[] = $filter_event;
}
if ($search_q !== '') {
    $sql_agents .= " AND (u.nom LIKE ? OR u.email LIKE ? OR e.nom LIKE ?)";
    $params_ag[] = "%$search_q%";
    $params_ag[] = "%$search_q%";
    $params_ag[] = "%$search_q%";
}

$sql_agents .= " ORDER BY aa.id DESC";
$stmt_assigned = $pdo->prepare($sql_agents);
$stmt_assigned->execute($params_ag);
$assigned_agents = $stmt_assigned->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 6. Calcul des statistiques KPI globales
// ------------------------------------------------------------------------------
$total_agents_uniques = count(array_unique(array_column($assigned_agents, 'agent_id')));
$total_postes_assignes = count($assigned_agents);
$total_billets_scannes = array_sum(array_column($assigned_agents, 'nb_scans'));

// Taux de scan global sur les événements avec agents
$sum_billets_evenements = array_sum(array_unique(array_column($assigned_agents, 'total_billets_evenement')));
$taux_presence = $sum_billets_evenements > 0 ? round(($total_billets_scannes / $sum_billets_evenements) * 100, 1) : 0;
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.agent-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--dash-primary), #0284c7);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.05rem;
    box-shadow: 0 2px 6px rgba(13, 148, 136, 0.25);
    flex-shrink: 0;
}
.scan-progress-bar {
    background: #e2e8f0;
    border-radius: 999px;
    height: 7px;
    overflow: hidden;
    margin-top: 5px;
}
.scan-progress-fill {
    background: linear-gradient(90deg, #10b981, #059669);
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}
.agent-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--dash-border);
    background: #ffffff;
    color: var(--dash-text);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.85rem;
}
.agent-action-btn:hover {
    background: #f8fafc;
    border-color: var(--dash-primary);
    color: var(--dash-primary);
}
.agent-action-btn.btn-danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #ef4444;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-users-gear" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Mes Agents de Contrôle & Postes de Scan
            </h1>
            <p>Pilotez vos équipes de sécurité et vérificateurs d'accès aux portes. Suivez en direct les scans de billets validés.</p>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" onclick="openCreateAgentModal()" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem;">
                <i class="fa-solid fa-user-plus"></i> Assigner un Nouvel Agent
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : STATISTIQUES DE CONTRÔLE D'ACCÈS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Agents Déployés</span>
                <span style="background: #f1f5f9; color: var(--dash-text); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-clipboard-user"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $total_agents_uniques; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;"><?php echo $total_postes_assignes; ?> affectation(s) d'événements</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Événements Couverts</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo count(array_unique(array_column($assigned_agents, 'event_id'))); ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Sous surveillance active</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #059669; text-transform: uppercase;">Billets Scannés</span>
                <span style="background: #dcfce7; color: #059669; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-qrcode"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #059669;"><?php echo number_format($total_billets_scannes, 0, ',', ' '); ?></div>
            <small style="color: #059669; font-size: 0.75rem;">Entrées validées aux portes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">Taux de Contrôle</span>
                <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chart-pie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo $taux_presence; ?>%</div>
            <small style="color: #b45309; font-size: 0.75rem;">Ratio entrées / billets émis</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. BARRE DE RECHERCHE & FILTRES
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 0; width: 100%;">
            <!-- Filtre Événement -->
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 2px 8px;">
                <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="event_id" onchange="this.form.submit()" style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.35rem 0.25rem;">
                    <option value="">Tous les événements</option>
                    <?php foreach ($my_events as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>" <?php echo $filter_event === (int)$ev['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ev['nom']); ?> (<?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Recherche texte -->
            <div style="position: relative; flex-grow: 1; max-width: 320px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Rechercher par nom, email d'agent..." style="padding: 0.45rem 0.75rem 0.45rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 100%; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.45rem 0.9rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                <i class="fa-solid fa-filter"></i> Filtrer
            </button>

            <?php if ($filter_event || $search_q !== ''): ?>
                <a href="agents.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. LISTE DES AGENTS DE CONTRÔLE (TABLEAU DASHBOARD PRO)
         ============================================================================== -->
    <div class="dash-card" style="padding: 0; overflow: hidden;">
        <div style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 700;">
                    <i class="fa-solid fa-clipboard-user" style="color: var(--dash-primary); margin-right: 6px;"></i>
                    Équipes de Contrôle Actives (<?php echo count($assigned_agents); ?>)
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Chaque agent dispose d'une connexion mobile dédiée pour valider les billets aux portes.</small>
            </div>
            <a href="../agent/verification.php" target="_blank" style="font-size: 0.82rem; color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Tester l'espace Scanner
            </a>
        </div>

        <div style="overflow-x: auto;">
            <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--dash-border); font-size: 0.75rem; text-transform: uppercase; color: var(--dash-muted);">
                        <th style="padding: 0.85rem 1.25rem;">Agent</th>
                        <th style="padding: 0.85rem 1rem;">Événement Assigné</th>
                        <th style="padding: 0.85rem 1rem;">Scans Validés</th>
                        <th style="padding: 0.85rem 1rem;">Dernière Activité</th>
                        <th style="padding: 0.85rem 1.25rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody style="font-size: 0.85rem;">
                    <?php if (count($assigned_agents) > 0): ?>
                        <?php foreach ($assigned_agents as $ag): ?>
                            <?php 
                                $words = explode(' ', trim($ag['agent_nom']));
                                $initials = strtoupper(substr($words[0] ?? 'A', 0, 1) . substr($words[1] ?? '', 0, 1));
                                $pct_event = $ag['total_billets_evenement'] > 0 ? round(($ag['nb_scans'] / $ag['total_billets_evenement']) * 100, 1) : 0;
                            ?>
                            <tr style="border-bottom: 1px solid var(--dash-border); transition: background 0.15s ease;">
                                <!-- Identité Agent -->
                                <td style="padding: 1rem 1.25rem;">
                                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                                        <div class="agent-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                        <div>
                                            <strong style="color: var(--dash-text); font-weight: 700; display: block; font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($ag['agent_nom']); ?>
                                            </strong>
                                            <div style="color: var(--dash-muted); font-size: 0.78rem; display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                                <span><i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($ag['agent_email']); ?></span>
                                                <?php if (!empty($ag['agent_tel'])): ?>
                                                    <span>•</span>
                                                    <span><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($ag['agent_tel']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Événement Assigné -->
                                <td style="padding: 1rem;">
                                    <div style="display: inline-block;">
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 9px; border-radius: 6px; font-weight: 700; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-calendar-check" style="font-size: 0.75rem;"></i>
                                            <?php echo htmlspecialchars($ag['event_nom']); ?>
                                        </span>
                                        <div style="color: var(--dash-muted); font-size: 0.75rem; margin-top: 4px;">
                                            <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ag['date_evenement'])); ?>
                                            • <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ag['lieu']); ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Scans Validés -->
                                <td style="padding: 1rem; min-width: 180px;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.82rem;">
                                        <strong style="color: #059669; font-size: 0.95rem;">
                                            <i class="fa-solid fa-qrcode" style="margin-right: 3px;"></i>
                                            <?php echo (int)$ag['nb_scans']; ?>
                                        </strong>
                                        <span style="color: var(--dash-muted); font-size: 0.75rem;">
                                            sur <?php echo (int)$ag['total_billets_evenement']; ?> billets (<?php echo $pct_event; ?>%)
                                        </span>
                                    </div>
                                    <div class="scan-progress-bar">
                                        <div class="scan-progress-fill" style="width: <?php echo min(100, $pct_event); ?>%;"></div>
                                    </div>
                                </td>

                                <!-- Dernière Activité -->
                                <td style="padding: 1rem;">
                                    <?php if (!empty($ag['dernier_scan'])): ?>
                                        <span style="color: var(--dash-text); font-weight: 600; font-size: 0.82rem; display: block;">
                                            <?php echo date('d/m/Y à H:i', strtotime($ag['dernier_scan'])); ?>
                                        </span>
                                        <small style="color: #10b981; font-size: 0.72rem; font-weight: 700;">
                                            <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Scan récent
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.78rem; font-style: italic;">
                                            En attente de premier scan
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td style="padding: 1rem 1.25rem; text-align: right;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <!-- Modifier Mot de passe -->
                                        <button type="button" class="agent-action-btn" title="Modifier le mot de passe" onclick="openResetPasswordModal(<?php echo $ag['agent_id']; ?>, '<?php echo htmlspecialchars(addslashes($ag['agent_nom'])); ?>')">
                                            <i class="fa-solid fa-key"></i>
                                        </button>

                                        <!-- Retirer Affectation -->
                                        <a href="?delete_assign=<?php echo $ag['assign_id']; ?>" class="agent-action-btn btn-danger" title="Retirer l'affectation à cet événement" onclick="return confirm('Êtes-vous sûr de vouloir retirer cet agent du contrôle de « <?php echo htmlspecialchars(addslashes($ag['event_nom'])); ?> » ?');">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
                                <i class="fa-solid fa-users-slash" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                                <strong style="display: block; font-size: 1rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun agent assigné</strong>
                                <p style="font-size: 0.82rem; margin: 0 0 1rem;">Ajoutez vos agents de contrôle et assignez-les à vos événements pour débuter les vérifications aux portes.</p>
                                <button type="button" onclick="openCreateAgentModal()" class="dash-btn-action btn-primary" style="display: inline-flex;">
                                    <i class="fa-solid fa-user-plus"></i> Assigner mon premier agent
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==============================================================================
     MODAL 1 : CRÉER / ASSIGNER UN NOUVEL AGENT
     ============================================================================== -->
<div id="modalCreateAgent" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 520px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-shield" style="color: var(--dash-primary);"></i> Assigner un Agent de Contrôle
            </h3>
            <button type="button" onclick="closeCreateAgentModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <?php if (count($my_events) > 0): ?>
            <form method="POST" action="agents.php" style="padding: 1.5rem;">
                <input type="hidden" name="creer_agent" value="1">

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        <i class="fa-solid fa-calendar-check" style="color: var(--dash-primary);"></i> Événement à contrôler *
                    </label>
                    <select name="event_id" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 700;">
                        <option value="">-- Sélectionner l'événement --</option>
                        <?php foreach ($my_events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>" <?php echo $filter_event === (int)$ev['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ev['nom']); ?> (<?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--dash-muted); font-size: 0.75rem; display: block; margin-top: 3px;">
                        L'agent ne pourra valider que les billets achetés pour cet événement précis.
                    </small>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        <i class="fa-solid fa-user"></i> Nom complet de l'agent *
                    </label>
                    <input type="text" name="nom" required placeholder="Ex: Bakary Koné" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-solid fa-envelope"></i> Email (Identifiant) *
                        </label>
                        <input type="email" name="email" required placeholder="agent@exemple.com" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-solid fa-phone"></i> Téléphone
                        </label>
                        <input type="tel" name="telephone" placeholder="07 00 00 00 00" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        <i class="fa-solid fa-lock"></i> Mot de passe de connexion *
                    </label>
                    <div style="position: relative;">
                        <input type="password" id="agent_pass_input" name="password" required placeholder="Minimum 6 caractères" style="width: 100%; padding: 0.55rem 2.25rem 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                        <i class="fa-regular fa-eye" id="toggle_agent_pass" onclick="togglePassVisibility('agent_pass_input', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--dash-muted); font-size: 0.85rem;"></i>
                    </div>
                    <small style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;">
                        L'agent utilisera cet email et ce mot de passe pour se connecter à l'application de scan sur son smartphone.
                    </small>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                    <button type="button" onclick="closeCreateAgentModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                    <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem;">
                        <i class="fa-solid fa-shield-check"></i> Créer & Assigner
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div style="padding: 2.5rem 1.5rem; text-align: center; color: var(--dash-muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun événement créé</strong>
                Vous devez d'abord créer ou avoir un événement approuvé pour y affecter des agents.<br><br>
                <a href="demande-evenement.php" class="dash-btn-action btn-primary" style="display: inline-flex;">
                    <i class="fa-solid fa-plus"></i> Proposer un Événement
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==============================================================================
     MODAL 2 : MODIFICATION DU MOT DE PASSE AGENT
     ============================================================================== -->
<div id="modalResetPassword" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 420px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-key" style="color: var(--dash-primary);"></i> Nouveau mot de passe
            </h3>
            <button type="button" onclick="closeResetPasswordModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="agents.php" style="padding: 1.5rem;">
            <input type="hidden" name="reset_password_agent" value="1">
            <input type="hidden" name="agent_id" id="reset_agent_id" value="">

            <p style="font-size: 0.85rem; color: var(--dash-muted); margin: 0 0 1rem;">
                Définissez un nouveau mot de passe pour l'agent <strong id="reset_agent_nom" style="color: var(--dash-text);"></strong> :
            </p>

            <div style="margin-bottom: 1.25rem;">
                <div style="position: relative;">
                    <input type="password" id="new_pass_input" name="new_password" required placeholder="Minimum 6 caractères" style="width: 100%; padding: 0.55rem 2.25rem 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    <i class="fa-regular fa-eye" onclick="togglePassVisibility('new_pass_input', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--dash-muted); font-size: 0.85rem;"></i>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" onclick="closeResetPasswordModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem;">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateAgentModal() {
    const m = document.getElementById('modalCreateAgent');
    m.style.display = 'flex';
}
function closeCreateAgentModal() {
    const m = document.getElementById('modalCreateAgent');
    m.style.display = 'none';
}

function openResetPasswordModal(agentId, agentNom) {
    document.getElementById('reset_agent_id').value = agentId;
    document.getElementById('reset_agent_nom').innerText = agentNom;
    document.getElementById('modalResetPassword').style.display = 'flex';
}
function closeResetPasswordModal() {
    document.getElementById('modalResetPassword').style.display = 'none';
}

function togglePassVisibility(inputId, iconElem) {
    const inp = document.getElementById(inputId);
    if (inp.type === 'password') {
        inp.type = 'text';
        iconElem.classList.remove('fa-eye');
        iconElem.classList.add('fa-eye-slash');
    } else {
        inp.type = 'password';
        iconElem.classList.remove('fa-eye-slash');
        iconElem.classList.add('fa-eye');
    }
}

// Fermeture par clic en arrière-plan
window.addEventListener('click', function(e) {
    const m1 = document.getElementById('modalCreateAgent');
    const m2 = document.getElementById('modalResetPassword');
    if (e.target === m1) closeCreateAgentModal();
    if (e.target === m2) closeResetPasswordModal();
});
</script>

<?php include 'footer.php'; ?>
