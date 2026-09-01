<?php
// ==============================================================================
// CRÉATION D'UN ÉVÉNEMENT ADMIN (admin/creer-evenement.php)
// Design Dashboard Pro - Formulaire complet avec options de billetterie et votes
// ==============================================================================

$admin_page_title = "Créer un Événement - Administration";
include 'header.php';

$message = "";
$msg_type = "";

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

    // Image upload
    $image = "default.jpg";
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowed, true)) {
            $upload_events = '../uploads/events/';
            if (!is_dir($upload_events)) {
                mkdir($upload_events, 0777, true);
            }
            $image = 'event_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image);
        }
    }

    if (empty($nom) || empty($date) || empty($heure) || empty($lieu)) {
        $message = "Veuillez remplir tous les champs obligatoires (*).";
        $msg_type = "error";
    } else {
        try {
            $sql = "INSERT INTO events (user_id, nom, description, categorie, image, date_evenement, heure, lieu, type_vote, prix_vote, commission_rate, statut) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id'], $nom, $description, $categorie, $image, $date, $heure, $lieu, $type_vote, $prix_vote, $commission, $statut]);

            $message = "L'événement « " . htmlspecialchars($nom) . " » a été créé et mis en ligne avec succès !";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
?>

<div class="dash-container">
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-plus" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Créer un Événement Officiel
            </h1>
            <p>Renseignez la programmation, la formule de vote et la tarification de l'événement.</p>
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
                    <input type="text" name="nom" required placeholder="Ex: Festival International d'Abidjan 2026" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.9rem; box-sizing: border-box;">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Description détaillée</label>
                    <textarea name="description" rows="4" placeholder="Artistes invités, déroulé du spectacle, accès..." style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box;"></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Catégorie *</label>
                    <select name="categorie" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="Concert">Concert</option>
                        <option value="Festival">Festival</option>
                        <option value="Théâtre">Théâtre</option>
                        <option value="Humour">Humour</option>
                        <option value="Sport">Sport</option>
                        <option value="Conférence">Conférence</option>
                        <option value="Autre">Autre</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Lieu / Salle *</label>
                    <input type="text" name="lieu" required placeholder="Ex: Palais de la Culture, Treichville" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Date *</label>
                    <input type="date" name="date" required style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Heure *</label>
                    <input type="time" name="heure" required style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Affiche de l'événement</label>
                    <input type="file" name="image" accept="image/png, image/jpeg, image/webp" style="width: 100%; padding: 0.45rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Statut initial</label>
                    <select name="statut" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="actif" selected>Actif (En ligne)</option>
                        <option value="en_attente">En attente</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>

                <!-- Paramètres de vote & concours -->
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Système de Vote</label>
                    <select name="type_vote" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="aucun" selected>Aucun vote (Billetterie classique)</option>
                        <option value="concours">🏆 Concours (avec candidats inscrits)</option>
                        <option value="realisation">🗳️ Réalisation directe (vote d'appréciation)</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Prix du Vote (FCFA)</label>
                    <input type="number" name="prix_vote" value="0" min="0" step="50" placeholder="0 = Gratuit" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Taux de Commission Plateforme (%)</label>
                    <input type="number" name="commission_rate" value="5.0" min="0" max="30" step="0.5" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>
            </div>

            <div style="border-top: 1px solid var(--dash-border); padding-top: 1.25rem;">
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.75rem 1.8rem; font-size: 0.9rem;">
                    <i class="fa-solid fa-calendar-plus"></i> Enregistrer et Mettre en Ligne
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>