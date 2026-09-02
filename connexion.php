<?php
// ==============================================================================
// PAGE DE CONNEXION (connexion.php)
// Authentification des utilisateurs et redirection selon leur rôle
// ==============================================================================

// 1. Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Connexion à la base de données
require_once 'config/database.php';

$error = "";
$success_msg = "";

// Message éventuel passé par paramètre GET (ex: déconnexion ou redirection)
if (isset($_GET['logout'])) {
    $success_msg = "Vous avez été déconnecté avec succès.";
} elseif (isset($_GET['error']) && $_GET['error'] === 'acces_interdit') {
    $error = "Accès refusé. Vous devez vous connecter avec un compte autorisé.";
}

// 3. Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Veuillez renseigner votre email et votre mot de passe.";
    } else {
        // 4. Recherche de l'utilisateur par son email avec requête préparée
        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 5. Vérification du mot de passe avec password_verify
        if ($user && password_verify($password, $user['password'])) {
            // Enregistrement des informations essentielles dans la session
            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['user_nom']    = $user['nom'];
            $_SESSION['user_email']  = $user['email'];
            $_SESSION['user_role']   = $user['role'];
            $_SESSION['est_verifie'] = (int)$user['est_verifie'];

            // 6. Redirection automatique selon le rôle de l'utilisateur
            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } elseif ($user['role'] === 'agent') {
                header("Location: agent/verification.php");
            } elseif ($user['role'] === 'promoteur') {
                header("Location: promoteur/dashboard.php");
            } else {
                header("Location: client/accueil.php");
            }
            exit();
        } else {
            $error = "Adresse email ou mot de passe incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Eventia</title>
    <!-- Icônes FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-container">
        <h2><i class="fa-solid fa-lock"></i> Connexion</h2>
        <p style="color: var(--muted); margin-bottom: 1.5rem; font-size: 0.95rem;">
            Accédez à votre espace Eventia
        </p>

        <!-- Message d'erreur -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Message de succès (ex: déconnexion) -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="connexion.php">
            <div class="form-group">
                <label for="email"><i class="fa-solid fa-envelope"></i> Adresse Email</label>
                <input type="email" id="email" name="email" required placeholder="exemple@mail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password"><i class="fa-solid fa-key"></i> Mot de passe</label>
                <input type="password" id="password" name="password" required placeholder="Votre mot de passe">
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Se connecter
            </button>
        </form>

        <div class="auth-footer">
            Pas encore de compte ? <a href="inscription.php">Créer un compte</a>
        </div>
    </div>
</body>
</html>