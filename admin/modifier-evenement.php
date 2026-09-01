<?php
// ==============================================================================
// MODIFICATION D'UN ÉVÉNEMENT ADMIN (admin/modifier-evenement.php)
// Design Dashboard Pro - Formulaire de mise à jour complète
// ==============================================================================

$admin_page_title = "Modifier l'Événement - Administration";
include 'header.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: evenements.php');
    exit();
}

$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: evenements.php');
    exit();
}

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom         = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categorie   = trim($_POST['categorie'] ?? 'Concert');
    $date        = $_POST['date'] ?? '';
    $heure       = $_POST['heure'] ?? '';
    $lieu        = trim($_POST['lieu'] ?? '');
    $statut      = $_POST['statut'] ?? 'actif';
    $type_vote   = $_POST['type_vote'] ?? 'aucun';
    $prix_vote   = (float)($_POST['prix_vote'] ?? 0);
    $commission  = (float)($_POST['commission_rate'] ?? 5.0);

    // Image upload si nouvelle image
    $image = $event['image'];
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowed, true)) {
            $upload_events = '../uploads/events/';
            if (!is_dir($upload_events)) {
                mkdir($upload_events, 0777, true);
            }
            $new_img = 'event_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $new_img)) {
                $image = $new_img;
            }
        }
    }

    try {
        $sql = 'UPDATE events SET nom = ?, description = ?, categorie = ?, image = ?, date_evenement = ?, heure = ?, lieu = ?, type_vote = ?, prix_vote = ?, commission_rate = ?, statut = ? WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nom, $description, $categorie, $image, $date, $heure, $lieu, $type_vote, $prix_vote, $commission, $statut, $id]);

        $message = "L'événement a été mis à jour avec succès !";
        $msg_type = 'success';

        // Rechargement des infos actualisées
        $stmt_r = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt_r->execute([$id]);
        $event = $stmt_r->fetch();
    } catch (PDOException $e) {
        $message = 'Erreur lors de la modification : ' . $e->getMessage();
        $msg_type = 'error';
    }
}
?>

<div class="dash-container">
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Modifier l'Événement : <?php echo htmlspecialchars($event['nom']); ?>
            </h1>
            <p>Mettez à jour les caractéristiques, le statut ou la tarification de l'événement.</p>
        </div>

        <a href="evenements.php" class="dash-btn-action" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Retour à la Liste
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <div class="dash-card">
        <form method="POST" enctype="multipart/form-data">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Nom de l'événement *</label>
                    <input type="text" name="nom" required value="<?php echo htmlspecialchars($event['nom']); ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.9rem; box-sizing: border-box;">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Description détaillée</label>
                    <textarea name="description" rows="4" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box;"><?php echo htmlspecialchars($event['description'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Catégorie *</label>
                    <select name="categorie" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <?php 
                        $cats = ['Concert', 'Festival', 'Théâtre', 'Humour', 'Sport', 'Conférence', 'Autre'];
                        foreach ($cats as $c): 
                        ?>
                            <option value="<?php echo $c; ?>" <?php echo ($event['categorie'] === $c) ? 'selected' : ''; ?>><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Lieu / Salle *</label>
                    <input type="text" name="lieu" required value="<?php echo htmlspecialchars($event['lieu']); ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Date *</label>
                    <input type="date" name="date" required value="<?php echo htmlspecialchars($event['date_evenement']); ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Heure *</label>
                    <input type="time" name="heure" required value="<?php echo htmlspecialchars($event['heure']); ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Remplacer l'affiche (Optionnel)</label>
                    <input type="file" name="image" accept="image/png, image/jpeg, image/webp" style="width: 100%; padding: 0.45rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Statut</label>
                    <select name="statut" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="actif" <?php echo ($event['statut'] === 'actif') ? 'selected' : ''; ?>>🟢 Actif (En ligne)</option>
                        <option value="termine" <?php echo ($event['statut'] === 'termine') ? 'selected' : ''; ?>>🏁 Terminé (Clos)</option>
                        <option value="annule" <?php echo ($event['statut'] === 'annule') ? 'selected' : ''; ?>>🔴 Annulé</option>
                        <option value="en_attente" <?php echo ($event['statut'] === 'en_attente') ? 'selected' : ''; ?>>🟡 En attente</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Système de Vote</label>
                    <select name="type_vote" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="aucun" <?php echo ($event['type_vote'] === 'aucun' || empty($event['type_vote'])) ? 'selected' : ''; ?>>Aucun vote</option>
                        <option value="concours" <?php echo ($event['type_vote'] === 'concours') ? 'selected' : ''; ?>>🏆 Concours (candidats)</option>
                        <option value="realisation" <?php echo ($event['type_vote'] === 'realisation') ? 'selected' : ''; ?>>🗳️ Réalisation directe</option>
                        <option value="ferme" <?php echo ($event['type_vote'] === 'ferme') ? 'selected' : ''; ?>>🏁 Vote clôturé</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Prix du Vote (FCFA)</label>
                    <input type="number" name="prix_vote" value="<?php echo htmlspecialchars($event['prix_vote'] ?? 0); ?>" min="0" step="50" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Commission (%)</label>
                    <input type="number" name="commission_rate" value="<?php echo htmlspecialchars($event['commission_rate'] ?? 5.0); ?>" min="0" max="30" step="0.5" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>
            </div>

            <div style="border-top: 1px solid var(--dash-border); padding-top: 1.25rem; display: flex; gap: 0.75rem;">
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.75rem 1.8rem; font-size: 0.9rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer les Modifications
                </button>
                <a href="evenements.php" class="dash-btn-action" style="text-decoration: none; padding: 0.75rem 1.2rem;">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
