<?php
// ==============================================================================
// PAGE DE CONNEXION (connexion.php)
// Authentification des utilisateurs et redirection selon leur rôle
// ==============================================================================

// 1. Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Connexion à la base de données & Moteur d'authentification
require_once 'config/database.php';
require_once 'includes/auth.php';

$error = "";
$success_msg = "";

// Message éventuel passé par paramètre GET (ex: déconnexion ou redirection)
if (isset($_GET['logout'])) {
    $success_msg = "Vous avez été déconnecté avec succès.";
} elseif (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success_msg = "Votre mot de passe a été réinitialisé avec succès ! Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.";
} elseif (isset($_GET['error'])) {
    if ($_GET['error'] === 'acces_interdit') {
        $error = "Accès refusé. Vous devez vous connecter avec un compte autorisé.";
    } elseif ($_GET['error'] === 'compte_suspendu') {
        $error = !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "Ce compte a été suspendu par l'administration.";
    } elseif ($_GET['error'] === 'permission_refusee') {
        $error = "Accès refusé : vous ne disposez pas des permissions requises.";
    }
}

// 3. Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
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
            // A. Vérification de l'état du compte (Actif / Suspendu / Réactivable)
            $statusCheck = checkAccountStatus($user);
            if (!$statusCheck['allowed']) {
                $error = $statusCheck['message'];
                logActivity('connexion.refusee_suspension', 'user', (int) $user['id'], "Tentative de connexion sur un compte suspendu ({$user['statut']})", (int) $user['id']);
            } else {
                // B. Mise à jour de la dernière connexion
                $stmt_last = $pdo->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
                $stmt_last->execute([(int) $user['id']]);

                // C. Enregistrement des informations essentielles dans la session
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'] ?? '';
                $_SESSION['nom'] = $user['nom'];
                $_SESSION['prenom'] = $user['prenom'] ?? '';
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_profile_id'] = (int) ($user['profile_id'] ?? 0);
                $_SESSION['est_verifie'] = (int) $user['est_verifie'];

                // D. Enregistrement dans le journal d'activité
                logActivity('connexion', 'user', (int) $user['id'], "Connexion réussie depuis {$_SERVER['REMOTE_ADDR']}", (int) $user['id']);

                // E. Redirection automatique selon le rôle de l'utilisateur
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
            }
        } else {
            $error = "Adresse email ou mot de passe incorrect.";
            logActivity('connexion.echec', 'user', null, "Échec de connexion pour l'email : " . substr($email, 0, 80));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Connexion - Eventia</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <!-- Icônes FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS & Eventia Brand -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/eventia-brand.css">
    <link rel="stylesheet" href="css/responsive-pro.css">
    <style>
        body {
            background: var(--eventia-background, #F5F5F5) !important;
            min-height: 100vh;
            margin: 0 !important;
            padding: 2.5rem 1rem 3.5rem !important;
            box-sizing: border-box !important;
            display: block !important;
            font-family: var(--font-body, 'Inter', sans-serif);
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            margin: 0 auto !important;
            padding: 2.25rem 2rem;
            background: #ffffff;
            border: 1px solid var(--eventia-border, #E5E5E5);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(11, 29, 58, 0.06);
            box-sizing: border-box;
            position: relative;
        }

        .auth-container::before {
            display: none !important;
        }

        @media (max-width: 480px) {
            body {
                padding: 1.25rem 0.75rem 2rem !important;
            }
            .auth-container {
                padding: 1.5rem 1.25rem;
                border-radius: 14px;
            }
        }
    </style>
</head>

<body>

    <!-- Bouton Retour hors de la section -->
    <div style="width: 100%; max-width: 440px; margin: 0 auto 0.85rem; box-sizing: border-box;">
        <a href="client/accueil.php"
            style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000); text-decoration: none; padding: 7px 14px; border-radius: 10px; background: #ffffff; border: 1px solid var(--eventia-border, #E5E5E5); box-shadow: 0 2px 5px rgba(11, 29, 58, 0.04); transition: all 0.2s;"
            onmouseover="this.style.background='#F5F5F5'; this.style.color='var(--eventia-amber-dark, #FF4A0D)'; this.style.transform='translateX(-2px)';"
            onmouseout="this.style.background='#ffffff'; this.style.color='var(--eventia-navy, #000000)'; this.style.transform='none';">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Retour à l'accueil</span>
        </a>
    </div>

    <div class="auth-container eventia-card">
        
        <!-- Logo & Titre Identique -->
        <div style="text-align: center; margin-bottom: 1.75rem;">
            <a href="client/accueil.php"
                style="display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; text-decoration: none;">
                <img src="images/logo.png" alt="Tikéli" style="height: 52px; width: auto; max-width: 190px; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.1));">
            </a>
            <h1 style="font-size: 1.35rem; font-weight: 800; color: var(--eventia-navy, #000000); margin: 0.35rem 0 0.25rem; font-family: var(--font-heading, 'Outfit', sans-serif);">
                Connexion
            </h1>
            <p style="color: var(--eventia-muted, #737373); margin: 0; font-size: 0.88rem;">
                Accédez à votre espace sécurisé Eventia
            </p>
        </div>

        <!-- Message d'erreur -->
        <?php if (!empty($error)): ?>
            <div class="eventia-alert eventia-alert-error" style="background: #F5F5F5; border: 1px solid #E5E5E5; color: #000000; border-radius: 10px; padding: 0.75rem 1rem; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-circle-exclamation" style="flex-shrink: 0;"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Message de succès -->
        <?php if (!empty($success_msg)): ?>
            <div class="eventia-alert eventia-alert-success" style="background: #FFF2ED; border: 1px solid #FFF2ED; color: #FF4A0D; border-radius: 10px; padding: 0.75rem 1rem; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-circle-check" style="flex-shrink: 0;"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulaire de connexion -->
        <form method="POST" action="connexion.php" style="display: flex; flex-direction: column; gap: 1.15rem;">
            
            <!-- Champ Email avec Label Parfaitement Positionné -->
            <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 6px;">
                <label for="email" style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                    <i class="fa-solid fa-envelope" style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                    <span>Adresse Email</span>
                </label>
                <input type="email" id="email" name="email" required
                    placeholder="nom@exemple.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    autocomplete="email" autofocus
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff; transition: border-color 0.2s, box-shadow 0.2s;">
            </div>

            <!-- Champ Mot de Passe avec Label & Affichage/Masquage -->
            <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 6px;">
                <label for="password" style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                    <i class="fa-solid fa-key" style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                    <span>Mot de passe</span>
                </label>
                <div style="position: relative; width: 100%;">
                    <input type="password" id="password" name="password" required
                        placeholder="••••••••"
                        autocomplete="current-password"
                        style="width: 100%; box-sizing: border-box; padding: 0.75rem 2.5rem 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff; transition: border-color 0.2s, box-shadow 0.2s;">
                    <button type="button" onclick="togglePasswordVisibility()" aria-label="Afficher le mot de passe"
                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--eventia-muted, #737373); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                        <i class="fa-solid fa-eye" id="togglePwdBtn"></i>
                    </button>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 4px;">
                    <a href="mot-de-passe-oublie.php"
                        style="font-size: 0.84rem; font-weight: 700; color: var(--tikeli-orange, #FF4A0D); text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;"
                        onmouseover="this.style.opacity='0.85'; this.style.textDecoration='underline';"
                        onmouseout="this.style.opacity='1'; this.style.textDecoration='none';">
                        <i class="fa-solid fa-lock-open" style="font-size: 0.76rem;"></i>
                        <span>Mot de passe oublié ?</span>
                    </a>
                </div>
            </div>

            <!-- Bouton de Connexion -->
            <button type="submit" class="eventia-btn-primary"
                style="width: 100%; padding: 0.85rem; margin-top: 0.35rem; font-size: 0.98rem; font-weight: 800; border-radius: 12px; justify-content: center; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3); border: none; cursor: pointer;">
                <i class="fa-solid fa-right-to-bracket"></i> Se connecter
            </button>
        </form>

        <!-- Lien Création de Compte -->
        <div class="auth-footer"
            style="margin-top: 1.5rem; padding-top: 1.15rem; border-top: 1px solid var(--eventia-border, #E5E5E5); text-align: center; font-size: 0.86rem; color: var(--eventia-muted, #737373);">
            Pas encore de compte ? <a href="inscription.php"
                style="color: var(--eventia-navy, #000000); font-weight: 800; text-decoration: none;">Créer un compte</a>
        </div>
    </div>

    <!-- Script Bascule Visibilité Mot de Passe -->
    <script>
        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('password');
            const icon = document.getElementById('togglePwdBtn');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwdInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>