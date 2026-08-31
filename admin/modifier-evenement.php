<?php
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
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date = $_POST['date'] ?? '';
    $heure = $_POST['heure'] ?? '';
    $lieu = trim($_POST['lieu'] ?? '');
    $statut = $_POST['statut'] ?? 'actif';

    try {
        $sql = 'UPDATE events SET nom = ?, description = ?, date_evenement = ?, heure = ?, lieu = ?, statut = ? WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nom, $description, $date, $heure, $lieu, $statut, $id]);

        header('Location: evenements.php');
        exit();
    } catch (PDOException $e) {
        $message = 'Erreur lors de la modification de l’événement.';
        $msg_type = 'error';
    }
} else {
    $nom = $event['nom'];
    $description = $event['description'];
    $date = $event['date_evenement'];
    $heure = $event['heure'];
    $lieu = $event['lieu'];
    $statut = $event['statut'];
}
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Billetterie</span>
        <h1>Modifier l’événement</h1>
        <p>Mettez à jour les informations de votre événement.</p>
    </div>
    <a href="evenements.php" class="back-link">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Retour à la liste
    </a>
</div>

<div class="content-section event-form-section">
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label for="nom">Nom de l'événement</label>
            <input type="text" id="nom" name="nom" value="<?php echo htmlspecialchars($nom); ?>" required>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($description); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>" required>
            </div>
            <div class="form-group">
                <label for="heure">Heure</label>
                <input type="time" id="heure" name="heure" value="<?php echo htmlspecialchars($heure); ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="lieu">Lieu</label>
            <input type="text" id="lieu" name="lieu" value="<?php echo htmlspecialchars($lieu); ?>" required>
        </div>

        <div class="form-group">
            <label for="statut">Statut</label>
            <select id="statut" name="statut">
                <option value="actif" <?php echo $statut === 'actif' ? 'selected' : ''; ?>>Actif</option>
                <option value="inactif" <?php echo $statut === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                <option value="terminé" <?php echo $statut === 'terminé' ? 'selected' : ''; ?>>Terminé</option>
            </select>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Enregistrer les modifications
        </button>
    </form>
</div>

<?php include 'footer.php'; ?>
