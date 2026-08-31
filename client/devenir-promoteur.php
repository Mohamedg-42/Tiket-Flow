<?php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../connexion.php");
    exit();
}

$message = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $nom = $_POST['nom'];
    $tel = $_POST['telephone'];
    $email = $_POST['email'];
    $activite = $_POST['activite'];
    $exp = $_POST['experience'];
    $desc = $_POST['description'];
    $sociaux = $_POST['sociaux'];
    $autres = $_POST['autres'];

    $piece_id = "default.jpg";
    if (isset($_FILES['piece_id']) && $_FILES['piece_id']['error'] === 0) {
        $ext = pathinfo($_FILES['piece_id']['name'], PATHINFO_EXTENSION);
        $filename = "id_" . uniqid() . "." . $ext;
        if (!is_dir('../uploads/ids')) {
            mkdir('../uploads/ids', 0777, true);
        }
        move_uploaded_file($_FILES['piece_id']['tmp_name'], '../uploads/ids/' . $filename);
        $piece_id = $filename;
    }

    try {
        $sql = "INSERT INTO promoter_requests (user_id, nom_complet, telephone, email, activite, experience, piece_identite, description, reseaux_sociaux, autres_infos) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $nom, $tel, $email, $activite, $exp, $piece_id, $desc, $sociaux, $autres]);

        $message = "✅ Votre demande a été envoyée avec succès. L'administrateur va l'examiner.";
        $msg_type = "success";
    } catch (PDOException $e) {
        $message = "❌ Erreur : " . $e->getMessage();
        $msg_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Devenir Promoteur - Ticket Flow</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body style="display: block; padding: 2rem;">

    <div class="auth-container" style="margin: 0 auto; text-align: left; max-width: 600px;">
        <h2 style="text-align: center;">Demande d'éligibilité Promoteur</h2>
        <p style="text-align: center; color: var(--text-dim); margin-bottom: 2rem;">
            Remplissez ce formulaire pour demander l'accès aux fonctionnalités de promotion.
        </p>

        <?php if($message): ?>
            <div class="alert alert-<?php echo $msg_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Nom complet</label>
                    <input type="text" name="nom" required>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" name="telephone" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email professionnel</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Votre activité principale</label>
                <input type="text" name="activite" required placeholder="Ex: Organisation de concerts, Agent artistique...">
            </div>
            <div class="form-group">
                <label>Expérience dans le domaine</label>
                <input type="text" name="experience" placeholder="Ex: 3 ans dans l'événementiel">
            </div>
            <div class="form-group">
                <label>Pièce d'identité (Image/PDF)</label>
                <input type="file" name="piece_id" required>
            </div>
            <div class="form-group">
                <label>Description de vos projets</label>
                <input type="text" name="description" placeholder="Ce que vous souhaitez organiser...">
            </div>
            <div class="form-group">
                <label>Réseaux Sociaux (Liens)</label>
                <input type="text" name="sociaux" placeholder="Facebook, Instagram, etc.">
            </div>
            <div class="form-group">
                <label>Autres informations</label>
                <input type="text" name="autres">
            </div>

            <button type="submit" class="btn-submit">Envoyer ma candidature</button>
        </form>
        
        <div class="auth-footer" style="text-align: center;">
            <a href="accueil.php">← Retour à l'accueil</a>
        </div>
    </div>
</body>
</html>
