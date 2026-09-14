<?php
// ==============================================================================
// LISTE D'INVITÉS (WHITELIST) POUR ÉVÉNEMENTS PRIVÉS (promoteur/liste-invites.php)
// Import CSV / saisie manuelle des bénéficiaires autorisés à acheter sur un
// événement à accès restreint. Voir includes/whitelist.php pour la logique
// de vérification côté acheteur (client/evenement.php + ajax/otp_*.php).
// ==============================================================================

$page_title = "Liste d'Invités - Espace Organisateur";
include 'header.php';
require_once '../includes/whitelist.php';

$user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Événements de l'organisateur (tous, mais seuls les événements PRIVÉS exploitent la whitelist)
$stmt_events = $pdo->prepare("SELECT id, nom, date_evenement, visibilite, access_token FROM events WHERE user_id = ? ORDER BY date_evenement DESC");
$stmt_events->execute([$user_id]);
$my_events = $stmt_events->fetchAll();

$selected_event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
if (!$selected_event_id && !empty($my_events)) {
    $selected_event_id = (int) $my_events[0]['id'];
}

function ownsEvent(array $my_events, int $event_id): ?array {
    foreach ($my_events as $ev) {
        if ((int) $ev['id'] === $event_id) {
            return $ev;
        }
    }
    return null;
}

$selected_event = $selected_event_id ? ownsEvent($my_events, $selected_event_id) : null;

// 2. Ajout manuel d'un invité
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add'])) {
    $ev_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $ev = ownsEvent($my_events, (int) $ev_id);

    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tickets_autorises = max(1, (int) ($_POST['tickets_autorises'] ?? 1));

    if (!$ev) {
        $message = "Événement introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom) || empty($telephone)) {
        $message = "Le nom et le numéro de téléphone sont obligatoires.";
        $msg_type = "error";
    } else {
        $tel_norm = normalizePhone($telephone);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO event_guest_whitelist (event_id, nom, prenom, telephone, email, tickets_autorises, ajoute_par)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (event_id, telephone) DO UPDATE SET
                    nom = EXCLUDED.nom, prenom = EXCLUDED.prenom, email = EXCLUDED.email,
                    tickets_autorises = EXCLUDED.tickets_autorises
            ");
            $stmt->execute([$ev['id'], $nom, $prenom ?: null, $tel_norm, $email ?: null, $tickets_autorises, $user_id]);
            $message = "Invité « " . htmlspecialchars($nom) . " » ajouté à la liste.";
            $msg_type = "success";
            $selected_event_id = (int) $ev['id'];
        } catch (PDOException $e) {
            $message = "Erreur lors de l'ajout : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// 2.1 Modification d'un invité existant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit'])) {
    $guest_id = filter_input(INPUT_POST, 'guest_id', FILTER_VALIDATE_INT);
    $ev_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $ev = ownsEvent($my_events, (int) $ev_id);

    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tickets_autorises = max(1, (int) ($_POST['tickets_autorises'] ?? 1));

    if (!$ev) {
        $message = "Événement introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom) || empty($telephone)) {
        $message = "Le nom et le numéro de téléphone sont obligatoires.";
        $msg_type = "error";
    } else {
        $tel_norm = normalizePhone($telephone);
        try {
            $stmt_chk_g = $pdo->prepare("SELECT id FROM event_guest_whitelist WHERE id = ? AND event_id = ?");
            $stmt_chk_g->execute([$guest_id, $ev['id']]);
            if (!$stmt_chk_g->fetch()) {
                $message = "Invité introuvable sur cet événement.";
                $msg_type = "error";
            } else {
                $stmt_upd = $pdo->prepare("
                    UPDATE event_guest_whitelist 
                    SET nom = ?, prenom = ?, telephone = ?, email = ?, tickets_autorises = ?
                    WHERE id = ? AND event_id = ?
                ");
                $stmt_upd->execute([$nom, $prenom ?: null, $tel_norm, $email ?: null, $tickets_autorises, $guest_id, $ev['id']]);
                $message = "Informations de l'invité « " . htmlspecialchars($nom) . " » mises à jour avec succès.";
                $msg_type = "success";
                $selected_event_id = (int) $ev['id'];
            }
        } catch (PDOException $e) {
            $message = "Erreur lors de la modification : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// 3. Import CSV (colonnes attendues : nom,prenom,telephone,email,tickets_autorises — avec ou sans en-tête)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_import'])) {
    $ev_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $ev = ownsEvent($my_events, (int) $ev_id);

    if (!$ev) {
        $message = "Événement introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Veuillez sélectionner un fichier CSV valide.";
        $msg_type = "error";
    } else {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $imported = 0;
        $skipped = 0;
        if ($handle) {
            $stmt = $pdo->prepare("
                INSERT INTO event_guest_whitelist (event_id, nom, prenom, telephone, email, tickets_autorises, ajoute_par)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (event_id, telephone) DO UPDATE SET
                    nom = EXCLUDED.nom, prenom = EXCLUDED.prenom, email = EXCLUDED.email,
                    tickets_autorises = EXCLUDED.tickets_autorises
            ");
            $row_index = 0;
            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $row_index++;
                if (count($row) < 3) {
                    $skipped++;
                    continue;
                }
                // Ignore une éventuelle ligne d'en-tête (ex: "nom,prenom,telephone,...")
                if ($row_index === 1 && stripos((string) $row[0], 'nom') === 0 && !preg_match('/\d/', (string) ($row[2] ?? ''))) {
                    continue;
                }
                $r_nom = trim($row[0] ?? '');
                $r_prenom = trim($row[1] ?? '');
                $r_tel = trim($row[2] ?? '');
                $r_email = trim($row[3] ?? '');
                $r_qty = isset($row[4]) && is_numeric($row[4]) ? max(1, (int) $row[4]) : 1;

                if (empty($r_nom) || empty($r_tel)) {
                    $skipped++;
                    continue;
                }

                try {
                    $stmt->execute([$ev['id'], $r_nom, $r_prenom ?: null, normalizePhone($r_tel), $r_email ?: null, $r_qty, $user_id]);
                    $imported++;
                } catch (PDOException $e) {
                    $skipped++;
                }
            }
            fclose($handle);
        }
        $message = "$imported invité(s) importé(s)" . ($skipped > 0 ? ", $skipped ligne(s) ignorée(s)." : ".");
        $msg_type = "success";
        $selected_event_id = (int) $ev['id'];
    }
}

// 4. Suppression d'un invité
if (isset($_GET['delete'])) {
    $del_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    $stmt_chk = $pdo->prepare("
        SELECT w.id, w.event_id FROM event_guest_whitelist w
        JOIN events e ON w.event_id = e.id
        WHERE w.id = ? AND e.user_id = ?
    ");
    $stmt_chk->execute([$del_id, $user_id]);
    $row = $stmt_chk->fetch();
    if ($row) {
        $pdo->prepare("DELETE FROM event_guest_whitelist WHERE id = ?")->execute([$del_id]);
        $message = "Invité retiré de la liste.";
        $msg_type = "success";
        $selected_event_id = (int) $row['event_id'];
    }
}

// Recharger l'événement sélectionné après traitement (peut avoir changé ci-dessus)
$selected_event = $selected_event_id ? ownsEvent($my_events, $selected_event_id) : null;

// 5. Liste des invités de l'événement sélectionné
$guests = [];
if ($selected_event) {
    $stmt_g = $pdo->prepare("SELECT * FROM event_guest_whitelist WHERE event_id = ? ORDER BY created_at DESC");
    $stmt_g->execute([$selected_event['id']]);
    $guests = $stmt_g->fetchAll();
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<div class="dash-container">
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-user-shield" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Liste d'Invités (Whitelist)
            </h1>
            <p>Gérez les bénéficiaires autorisés à acheter sur vos événements privés. Ils devront saisir leur numéro
                exact puis confirmer un code SMS avant de pouvoir payer.</p>
        </div>

        <div class="dash-filter-bar">
            <form method="GET" action="liste-invites.php" style="margin: 0;">
                <div class="dash-control-select" style="padding: 0.4rem 0.8rem;">
                    <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary);"></i>
                    <select name="event_id" onchange="this.form.submit()"
                        style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; max-width: 260px; text-overflow: ellipsis; box-sizing: border-box;">
                        <?php foreach ($my_events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>" <?php echo ((int) $ev['id'] === (int) $selected_event_id) ? 'selected' : ''; ?>>
                                <?php echo ($ev['visibilite'] === 'prive') ? '🔒 ' : '🌐 '; ?><?php echo htmlspecialchars(mb_strimwidth($ev['nom'], 0, 28, '...')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selected_event): ?>
                <button type="button" class="dash-btn-action btn-primary" onclick="toggleAddModal(true)">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Ajouter un invité</span>
                </button>
                <button type="button" class="dash-btn-action" onclick="toggleImportModal(true)">
                    <i class="fa-solid fa-file-csv" style="color: #FF4A0D;"></i>
                    <span>Importer un CSV</span>
                </button>
                <a href="export.php?type=invites&event_id=<?php echo (int) $selected_event['id']; ?>" class="dash-btn-action" style="text-decoration: none;" title="Exporter la liste complète au format CSV compatible Excel">
                    <i class="fa-solid fa-file-export" style="color: #10B981;"></i>
                    <span>Exporter la liste</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; color: #000000; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$selected_event): ?>
        <div class="dash-card" style="padding: 3rem 1rem; text-align: center; color: var(--dash-muted);">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2rem; color: #E5E5E5; margin-bottom: 0.5rem; display: block;"></i>
            Vous n'avez pas encore d'événement. Créez-en un pour gérer sa liste d'invités.
        </div>
    <?php else: ?>

        <?php if ($selected_event['visibilite'] !== 'prive'): ?>
            <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.85rem; font-weight: 600; background: #F5F5F5; color: #000000; border: 1px solid #E5E5E5;">
                <i class="fa-solid fa-circle-info"></i> Cet événement est actuellement <strong>public</strong> : la liste
                ci-dessous n'aura d'effet que si vous demandez à un administrateur de le basculer en mode « Privé ».
            </div>
        <?php elseif (!empty($selected_event['access_token'])): ?>
            <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.82rem; font-weight: 600; background: #FFF2ED; color: #000000; border: 1px solid #FFD9C4; word-break: break-all;">
                <i class="fa-solid fa-link" style="color: #FF4A0D;"></i> Lien d'accès unique à partager avec vos invités :
                <code>../client/evenement.php?id=<?php echo (int) $selected_event['id']; ?>&token=<?php echo htmlspecialchars($selected_event['access_token']); ?></code>
            </div>
        <?php endif; ?>

        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i>
                        Invités enregistrés — <?php echo htmlspecialchars($selected_event['nom']); ?>
                    </h3>
                    <div class="dash-card-subtitle">Nombre de billets autorisés par personne, suivi de consommation en temps réel</div>
                </div>
                <span style="background: var(--dash-primary-light); color: var(--dash-primary); padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 800;">
                    <?php echo count($guests); ?> invité(s)
                </span>
            </div>

            <div class="dash-table-wrapper">
                <table class="dash-pro-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th>Billets autorisés</th>
                            <th>Utilisés</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($guests) > 0): ?>
                            <?php foreach ($guests as $g): ?>
                                <?php $restant = max(0, (int) $g['tickets_autorises'] - (int) $g['tickets_utilises']); ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(trim($g['nom'] . ' ' . ($g['prenom'] ?? ''))); ?></strong></td>
                                    <td style="font-family: var(--ev-font-mono, monospace); font-size: 0.85rem;"><?php echo htmlspecialchars($g['telephone']); ?></td>
                                    <td style="font-size: 0.85rem; color: var(--dash-muted);"><?php echo htmlspecialchars($g['email'] ?? '—'); ?></td>
                                    <td><?php echo (int) $g['tickets_autorises']; ?></td>
                                    <td>
                                        <span style="background: <?php echo $restant > 0 ? '#FFF2ED' : '#F5F5F5'; ?>; color: <?php echo $restant > 0 ? '#FF4A0D' : '#000000'; ?>; border-radius: 6px; padding: 2px 7px; font-weight: 800; font-size: 0.75rem;">
                                            <?php echo (int) $g['tickets_utilises']; ?> / <?php echo (int) $g['tickets_autorises']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" 
                                            class="dash-btn-action" 
                                            style="padding: 4px 8px; font-size: 0.76rem; background: #FFF2ED; color: #FF4A0D; border: 1px solid #FF4A0D; margin-right: 4px; cursor: pointer;"
                                            onclick='openEditGuestModal(<?php echo json_encode([
                                                "id" => (int)$g["id"],
                                                "event_id" => (int)$selected_event["id"],
                                                "nom" => $g["nom"],
                                                "prenom" => $g["prenom"] ?? "",
                                                "telephone" => $g["telephone"],
                                                "email" => $g["email"] ?? "",
                                                "tickets_autorises" => (int)$g["tickets_autorises"]
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' 
                                            title="Modifier l'invité">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <a href="liste-invites.php?event_id=<?php echo (int) $selected_event['id']; ?>&delete=<?php echo (int) $g['id']; ?>"
                                            class="dash-btn-action btn-danger" style="padding: 4px 8px; font-size: 0.76rem;"
                                            onclick="return confirm('Retirer cet invité de la liste ?')" title="Retirer">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--dash-muted); padding: 3rem 1rem;">
                                    <i class="fa-solid fa-user-slash" style="font-size: 2rem; color: #E5E5E5; margin-bottom: 0.5rem; display: block;"></i>
                                    Aucun invité enregistré pour cet événement.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Ajout manuel -->
<div id="modalAddGuest" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-user-plus" style="color: var(--dash-primary);"></i> Ajouter un invité
            </h3>
            <button type="button" onclick="toggleAddModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="liste-invites.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_add" value="1">
            <input type="hidden" name="event_id" value="<?php echo (int) $selected_event_id; ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom *</label>
                    <input type="text" name="nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prénom</label>
                    <input type="text" name="prenom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Numéro de téléphone *</label>
                <input type="tel" name="telephone" required placeholder="Ex: 07 00 00 00 00" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Email</label>
                    <input type="email" name="email" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Billets autorisés</label>
                    <input type="number" name="tickets_autorises" min="1" value="1" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleAddModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Import CSV -->
<div id="modalImportGuests" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-file-csv" style="color: #FF4A0D;"></i> Importer une liste CSV
            </h3>
            <button type="button" onclick="toggleImportModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="liste-invites.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <input type="hidden" name="action_import" value="1">
            <input type="hidden" name="event_id" value="<?php echo (int) $selected_event_id; ?>">
            <p style="font-size: 0.8rem; color: var(--dash-muted); margin: 0 0 1rem;">
                Format attendu (une ligne par invité, sans en-tête obligatoire) :<br>
                <code style="background: #F5F5F5; padding: 2px 6px; border-radius: 4px;">nom,prenom,telephone,email,tickets_autorises</code>
            </p>
            <div style="margin-bottom: 1.5rem;">
                <input type="file" name="csv_file" accept=".csv,text/csv" required
                    style="width: 100%; padding: 0.5rem; border: 1px solid #E5E5E5; border-radius: 8px; box-sizing: border-box;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleImportModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Importer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modification Invité -->
<div id="modalEditGuest" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-user-pen" style="color: var(--dash-primary);"></i> Modifier un invité
            </h3>
            <button type="button" onclick="closeEditGuestModal()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="liste-invites.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_edit" value="1">
            <input type="hidden" name="event_id" value="<?php echo (int) $selected_event_id; ?>">
            <input type="hidden" name="guest_id" id="edit_guest_id" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom *</label>
                    <input type="text" name="nom" id="edit_nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prénom</label>
                    <input type="text" name="prenom" id="edit_prenom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Numéro de téléphone *</label>
                <input type="tel" name="telephone" id="edit_telephone" required placeholder="Ex: 07 00 00 00 00" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Email</label>
                    <input type="email" name="email" id="edit_email" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Billets autorisés</label>
                    <input type="number" name="tickets_autorises" id="edit_tickets_autorises" min="1" value="1" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="closeEditGuestModal()" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleAddModal(show) {
        document.getElementById('modalAddGuest').style.display = show ? 'grid' : 'none';
    }
    function toggleImportModal(show) {
        document.getElementById('modalImportGuests').style.display = show ? 'grid' : 'none';
    }
    function openEditGuestModal(guest) {
        document.getElementById('edit_guest_id').value = guest.id;
        document.getElementById('edit_nom').value = guest.nom || '';
        document.getElementById('edit_prenom').value = guest.prenom || '';
        document.getElementById('edit_telephone').value = guest.telephone || '';
        document.getElementById('edit_email').value = guest.email || '';
        document.getElementById('edit_tickets_autorises').value = guest.tickets_autorises || 1;
        document.getElementById('modalEditGuest').style.display = 'grid';
    }
    function closeEditGuestModal() {
        document.getElementById('modalEditGuest').style.display = 'none';
    }
    window.addEventListener('click', function(e) {
        const mAdd = document.getElementById('modalAddGuest');
        const mImp = document.getElementById('modalImportGuests');
        const mEdit = document.getElementById('modalEditGuest');
        if (e.target === mAdd) toggleAddModal(false);
        if (e.target === mImp) toggleImportModal(false);
        if (e.target === mEdit) closeEditGuestModal();
    });
</script>

<?php include 'footer.php'; ?>
