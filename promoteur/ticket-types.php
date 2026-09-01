<?php
// ==============================================================================
// GESTION DES TYPES DE BILLETS (promoteur/ticket-types.php)
// Configuration des catégories, tarifs et quotas pour les événements
// ==============================================================================

$page_title = "Types de Billets & Quotas - Espace Organisateur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Liste des événements de l'organisateur
$stmt_events = $pdo->prepare("SELECT id, nom, date_evenement FROM events WHERE user_id = ? ORDER BY date_evenement DESC");
$stmt_events->execute([$user_id]);
$my_events = $stmt_events->fetchAll();

// 2. Traitement : Ajout d'un type de billet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add'])) {
    $ev_id    = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $nom      = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $prix     = (float)str_replace(',', '.', $_POST['prix'] ?? 0);
    $quantite = (int)($_POST['quantite'] ?? 0);

    $ok_owner = false;
    foreach ($my_events as $ev) { if ((int)$ev['id'] === $ev_id) { $ok_owner = true; break; } }

    if (!$ok_owner) {
        $message  = "Événement introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom) || $prix < 0 || $quantite <= 0) {
        $message  = "Veuillez renseigner un nom, un prix et une quantité valides.";
        $msg_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO ticket_types (event_id, nom, description, prix, quantite) VALUES (?, ?, ?, ?, ?)");
        $stmt_ins->execute([$ev_id, $nom, $desc, $prix, $quantite]);
        $message  = "Type de billet « " . htmlspecialchars($nom) . " » créé avec succès.";
        $msg_type = "success";
    }
}

// 3. Traitement : Modification d'un type de billet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit'])) {
    $tt_id    = filter_input(INPUT_POST, 'tt_id', FILTER_VALIDATE_INT);
    $nom      = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $prix     = (float)str_replace(',', '.', $_POST['prix'] ?? 0);
    $quantite = (int)($_POST['quantite'] ?? 0);

    $stmt_chk = $pdo->prepare("SELECT tt.id, tt.quantite_vendue FROM ticket_types tt JOIN events e ON tt.event_id = e.id WHERE tt.id = ? AND e.user_id = ?");
    $stmt_chk->execute([$tt_id, $user_id]);
    $tt_row = $stmt_chk->fetch();

    if (!$tt_row) {
        $message  = "Type de billet introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom) || $prix < 0 || $quantite <= 0) {
        $message  = "Nom, prix et quantité sont obligatoires.";
        $msg_type = "error";
    } elseif ($quantite < (int)$tt_row['quantite_vendue']) {
        $message  = "Le quota total ne peut pas être inférieur aux billets déjà vendus (" . (int)$tt_row['quantite_vendue'] . ").";
        $msg_type = "error";
    } else {
        $stmt_upd = $pdo->prepare("UPDATE ticket_types SET nom = ?, description = ?, prix = ?, quantite = ? WHERE id = ?");
        $stmt_upd->execute([$nom, $desc, $prix, $quantite, $tt_id]);
        $message  = "Type de billet « " . htmlspecialchars($nom) . " » mis à jour avec succès.";
        $msg_type = "success";
    }
}

// 4. Traitement : Suppression d'un type de billet
if (isset($_GET['delete'])) {
    $del_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    $stmt_chk = $pdo->prepare("SELECT tt.id, tt.quantite_vendue, tt.nom FROM ticket_types tt JOIN events e ON tt.event_id = e.id WHERE tt.id = ? AND e.user_id = ?");
    $stmt_chk->execute([$del_id, $user_id]);
    $tt_row = $stmt_chk->fetch();

    if (!$tt_row) {
        $message  = "Type de billet introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif ((int)$tt_row['quantite_vendue'] > 0) {
        $message  = "Impossible de supprimer « " . htmlspecialchars($tt_row['nom']) . " » car des billets ont déjà été vendus.";
        $msg_type = "error";
    } else {
        $pdo->prepare("DELETE FROM ticket_types WHERE id = ?")->execute([$del_id]);
        $message  = "Type de billet supprimé avec succès.";
        $msg_type = "success";
    }
}

// 5. Filtre par événement
$filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$sql_list = "
    SELECT tt.*, e.nom AS event_nom, e.date_evenement, e.lieu
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    WHERE e.user_id = ?
";
$params_list = [$user_id];
if ($filter_event) {
    $sql_list .= " AND e.id = ?";
    $params_list[] = $filter_event;
}
$sql_list .= " ORDER BY e.date_evenement DESC, tt.prix DESC";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params_list);
$ticket_types = $stmt_list->fetchAll();
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<div class="dash-container">
    <!-- En-tête -->
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-tags" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Types de Billets & Tarifs
            </h1>
            <p>Créez et configurez les catégories de places (Standard, VIP, Pass) et leurs quotas respectifs.</p>
        </div>

        <div class="dash-filter-bar">
            <!-- Filtre par événement -->
            <form method="GET" action="ticket-types.php" style="margin: 0;">
                <div class="dash-control-select" style="padding: 0.4rem 0.8rem;">
                    <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary);"></i>
                    <select name="event_id" onchange="this.form.submit()" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer;">
                        <option value="">Tous mes événements</option>
                        <?php foreach ($my_events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>" <?php echo ($filter_event == $ev['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(mb_strimwidth($ev['nom'], 0, 26, '...')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <button type="button" class="dash-btn-action btn-primary" onclick="toggleAddModal(true)">
                <i class="fa-solid fa-plus"></i>
                <span>Nouveau Type de Billet</span>
            </button>
        </div>
    </div>

    <!-- Notifications Flash -->
    <?php if (!empty($message)): ?>
        <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; color: <?php echo $msg_type === 'success' ? '#065f46' : '#991b1b'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <!-- Tableau de gestion des types de tickets -->
    <div class="dash-card">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i>
                    Grille Tarifaire & Quotas
                </h3>
                <div class="dash-card-subtitle">Liste des formules de billets actives pour vos événements</div>
            </div>
            <span style="background: var(--dash-primary-light); color: var(--dash-primary); padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 800;">
                <?php echo count($ticket_types); ?> catégorie(s)
            </span>
        </div>

        <div class="dash-table-wrapper">
            <table class="dash-pro-table">
                <thead>
                    <tr>
                        <th>Événement</th>
                        <th>Nom de la Catégorie</th>
                        <th>Prix Unitaire</th>
                        <th>Vendus</th>
                        <th>Restants</th>
                        <th>Taux de Vente</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($ticket_types) > 0): ?>
                        <?php foreach ($ticket_types as $tt): ?>
                            <?php 
                            $v = (int)$tt['quantite_vendue'];
                            $tot = (int)$tt['quantite'];
                            $r = max(0, $tot - $v);
                            $pct = ($tot > 0) ? min(100, round(($v / $tot) * 100)) : 0;
                            $bar_color = ($pct >= 85) ? '#ef4444' : (($pct >= 50) ? '#f59e0b' : '#10b981');
                            ?>
                            <tr>
                                <td>
                                    <a href="mes-evenements.php" style="color: var(--dash-text); text-decoration: none; font-weight: 700;" title="Voir l'événement">
                                        <?php echo htmlspecialchars($tt['event_nom']); ?>
                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; color: var(--dash-muted); margin-left: 2px;"></i>
                                    </a>
                                    <small style="display: block; color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo date('d/m/Y', strtotime($tt['date_evenement'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <a href="mes-ventes.php?type=<?php echo urlencode($tt['nom']); ?>" style="text-decoration: none;" title="Voir les ventes de cette formule">
                                        <span style="background: #eeedfd; color: #5b50e6; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.82rem;">
                                            <i class="fa-solid fa-tag" style="font-size: 0.7rem; margin-right: 4px;"></i><?php echo htmlspecialchars($tt['nom']); ?>
                                        </span>
                                    </a>
                                    <?php if (!empty($tt['description'])): ?>
                                        <small style="display: block; color: var(--dash-muted); font-size: 0.74rem; margin-top: 3px; max-width: 260px;">
                                            <?php echo htmlspecialchars($tt['description']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem;"><?php echo number_format($tt['prix'], 0, ',', ' '); ?> F</strong>
                                </td>
                                <td>
                                    <a href="mes-ventes.php?event_id=<?php echo $tt['event_id']; ?>&type=<?php echo urlencode($tt['nom']); ?>" style="text-decoration: none; color: #0ea5e9;" title="Voir les acheteurs de cette catégorie">
                                        <strong style="font-size: 0.9rem; display: inline-flex; align-items: center; gap: 3px;">
                                            <i class="fa-solid fa-circle-check" style="font-size: 0.75rem;"></i> <?php echo $v; ?>
                                            <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem;"></i>
                                        </strong>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($r === 0 && $tot > 0): ?>
                                        <span style="background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; border-radius: 6px; padding: 2px 7px; font-weight: 800; font-size: 0.75rem;">Épuisé</span>
                                    <?php else: ?>
                                        <span style="background: #ecfdf5; color: #10b981; border: 1px solid #d1fae5; border-radius: 6px; padding: 2px 7px; font-weight: 800; font-size: 0.75rem;"><?php echo $r; ?> restant(s)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span class="dash-gauge-track" style="width: 80px;">
                                            <span class="dash-gauge-progress" style="width: <?php echo $pct; ?>%; background: <?php echo $bar_color; ?>; display: block;"></span>
                                        </span>
                                        <strong style="font-size: 0.76rem;"><?php echo $pct; ?>%</strong>
                                        <small style="color: var(--dash-muted); font-size: 0.72rem;">(<?php echo $tot; ?>)</small>
                                    </div>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem;" onclick='openEditModal(<?php echo json_encode($tt); ?>)' title="Modifier les tarifs ou quotas">
                                        <i class="fa-solid fa-pen-to-square" style="color: #0284c7;"></i> Modifier
                                    </button>
                                    <?php if ($v === 0): ?>
                                        <a href="ticket-types.php?delete=<?php echo $tt['id']; ?>" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem; color: #ef4444; margin-left: 4px;" onclick="return confirm('Voulez-vous vraiment supprimer ce type de ticket ?')" title="Supprimer">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem; color: #94a3b8; margin-left: 4px; cursor: not-allowed;" title="Impossible : ventes déjà enregistrées">
                                            <i class="fa-solid fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--dash-muted); padding: 3rem 1rem;">
                                <i class="fa-solid fa-tags" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                Aucun type de billet configuré.
                                <br><button type="button" class="dash-btn-action btn-primary" onclick="toggleAddModal(true)" style="margin-top: 0.75rem;">+ Créer un premier type de billet</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Ajout -->
<div id="modalAddTicket" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 520px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0;">
                <i class="fa-solid fa-plus-circle" style="color: var(--dash-primary);"></i> Nouveau Type de Billet
            </h3>
            <button type="button" onclick="toggleAddModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <form method="POST" action="ticket-types.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_add" value="1">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Événement *</label>
                <select name="event_id" required style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem;">
                    <?php foreach ($my_events as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>"><?php echo htmlspecialchars($ev['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom de la catégorie *</label>
                <input type="text" name="nom" required placeholder="Ex: VIP, Pass 2 Jours, Standard" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prix Unitaire (FCFA) *</label>
                    <input type="number" name="prix" min="0" step="100" required placeholder="0" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Quota / Places *</label>
                    <input type="number" name="quantite" min="1" required placeholder="100" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Description / Avantages</label>
                <textarea name="description" rows="2" placeholder="Accès salon VIP, coupe-file, boisson offerte..." style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleAddModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Créer le Billet</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modification -->
<div id="modalEditTicket" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 520px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0;">
                <i class="fa-solid fa-pen-to-square" style="color: #0284c7;"></i> Modifier le Type de Billet
            </h3>
            <button type="button" onclick="toggleEditModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <form method="POST" action="ticket-types.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_edit" value="1">
            <input type="hidden" name="tt_id" id="edit_tt_id">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom de la catégorie *</label>
                <input type="text" name="nom" id="edit_nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prix (FCFA) *</label>
                    <input type="number" name="prix" id="edit_prix" min="0" step="100" required style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Quota Total *</label>
                    <input type="number" name="quantite" id="edit_quantite" min="1" required style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                    <small id="edit_vendus_notice" style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;"></small>
                </div>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Description</label>
                <textarea name="description" id="edit_description" rows="2" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleEditModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer les Modifications</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAddModal(show) {
    document.getElementById('modalAddTicket').style.display = show ? 'grid' : 'none';
}
function toggleEditModal(show) {
    document.getElementById('modalEditTicket').style.display = show ? 'grid' : 'none';
}
function openEditModal(tt) {
    document.getElementById('edit_tt_id').value = tt.id;
    document.getElementById('edit_nom').value = tt.nom || tt.category_name;
    document.getElementById('edit_prix').value = parseFloat(tt.prix);
    document.getElementById('edit_quantite').value = parseInt(tt.quantite || tt.total_places);
    document.getElementById('edit_description').value = tt.description || '';
    const v = parseInt(tt.quantite_vendue || tt.vendus || 0);
    document.getElementById('edit_quantite').min = v;
    document.getElementById('edit_vendus_notice').innerText = v + ' billet(s) déjà vendu(s) (minimum obligatoire)';
    toggleEditModal(true);
}
</script>

<?php include 'footer.php'; ?>
