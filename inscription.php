<?php
// ==============================================================================
// PAGE D'INSCRIPTION PUBLIQUE (inscription.php)
// Inscription ouverte exclusivement aux Acheteurs / Clients.
// Les demandes d'éligibilité Promoteurs sont gérées sur : client/devenir-promoteur.php
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/mailer.php';
require_once 'includes/auth.php';

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_role = $_SESSION['user_role'] ?? 'client';

$message = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = 'client'; // Rôle unique pour l'inscription générale

    // Validation des champs obligatoires
    if (empty($nom) || empty($prenom) || empty($email) || empty($telephone) || empty($password)) {
        $message = "Veuillez remplir tous les champs obligatoires (Nom, Prénom, Email, Téléphone, Mot de passe).";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "L'adresse email n'a pas un format valide.";
        $msg_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Le mot de passe doit contenir au moins 6 caractères.";
        $msg_type = "error";
    } else {
        try {
            $password_hache = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users (nom, prenom, email, telephone, password, role, statut, est_verifie) VALUES (?, ?, ?, ?, ?, 'client', 'actif', 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nom, $prenom, $email, $telephone, $password_hache]);

            $new_user_id = (int) $pdo->lastInsertId();
            $full_name = trim("$prenom $nom");

            // Envoi de l'e-mail de bienvenue au client
            sendWelcomeClientEmail($email, $full_name);
            logActivity('inscription_client', 'user', $new_user_id, "Nouveau compte client créé par $full_name ($email)", $new_user_id);

            $message = "Votre compte client a été créé avec succès ! Un e-mail de bienvenue vous a été envoyé. Vous pouvez maintenant vous connecter.";
            $msg_type = "success";

        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE')) {
                $message = "Cette adresse email est déjà associée à un compte.";
            } else {
                $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Créer un compte - Tikéli</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS & Tikéli Brand -->
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
            max-width: 520px;
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

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
        }

        .promoteur-invite-box {
            margin-top: 1.25rem;
            background: #F5F5F5;
            border: 1px dashed #E5E5E5;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.82rem;
            color: var(--eventia-text, #000000);
        }

        .promoteur-invite-btn {
            background: #ffffff;
            border: 1px solid var(--eventia-navy, #000000);
            color: var(--eventia-navy, #000000);
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.78rem;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .promoteur-invite-btn:hover {
            background: var(--eventia-navy, #000000);
            color: #ffffff;
        }

        @media (max-width: 520px) {
            body {
                padding: 1.25rem 0.75rem 2rem !important;
            }

            .auth-container {
                padding: 1.5rem 1.25rem;
                border-radius: 14px;
            }

            .form-grid-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .promoteur-invite-box {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .promoteur-invite-btn {
                width: 100%;
                text-align: center;
                box-sizing: border-box;
            }
        }
    </style>
</head>

<body>

    <!-- Bouton Retour hors de la section -->
    <div style="width: 100%; max-width: 520px; margin: 0 auto 0.85rem; box-sizing: border-box;">
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
                <img src="images/logo.png" alt="Tikéli"
                    style="height: 52px; width: auto; max-width: 190px; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.1));">
            </a>
            <h1
                style="font-size: 1.35rem; font-weight: 800; color: var(--eventia-navy, #000000); margin: 0.35rem 0 0.25rem; font-family: var(--font-heading, 'Outfit', sans-serif);">
                Créer un compte
            </h1>
            <p style="color: var(--eventia-muted, #737373); margin: 0; font-size: 0.88rem;">
                Rejoignez Tikéli et réservez vos places en quelques clics
            </p>
        </div>

        <!-- Message d'alerte (succès ou erreur) -->
        <?php if (!empty($message)): ?>
            <div class="eventia-alert eventia-alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>"
                style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; color: <?php echo $msg_type === 'success' ? '#FF4A0D' : '#000000'; ?>; border-radius: 10px; padding: 0.75rem 1rem; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem;">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"
                    style="flex-shrink: 0;"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulaire d'inscription -->
        <form method="POST" action="inscription.php" style="display: flex; flex-direction: column; gap: 1rem;">

            <div class="form-grid-2">
                <!-- Nom -->
                <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 5px;">
                    <label for="nom"
                        style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                        <i class="fa-solid fa-user"
                            style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                        <span>Nom *</span>
                    </label>
                    <input type="text" id="nom" name="nom" required placeholder="Ex: Koffi"
                        value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>"
                        style="width: 100%; box-sizing: border-box; padding: 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff;">
                </div>

                <!-- Prénom -->
                <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 5px;">
                    <label for="prenom"
                        style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                        <i class="fa-regular fa-user"
                            style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                        <span>Prénom *</span>
                    </label>
                    <input type="text" id="prenom" name="prenom" required placeholder="Ex: Jean"
                        value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>"
                        style="width: 100%; box-sizing: border-box; padding: 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff;">
                </div>
            </div>

            <!-- Email -->
            <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 5px;">
                <label for="email"
                    style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                    <i class="fa-solid fa-envelope"
                        style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                    <span>Adresse Email *</span>
                </label>
                <input type="email" id="email" name="email" required placeholder="nom@exemple.com"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autocomplete="email"
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff;">
            </div>

            <!-- Téléphone -->
            <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 5px;">
                <label for="telephone"
                    style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                    <i class="fa-solid fa-phone"
                        style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                    <span>Numéro de Téléphone (Mobile Money) *</span>
                </label>
                <input type="tel" id="telephone" name="telephone" required placeholder="Ex: +225 07 00 00 00 00"
                    value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>" autocomplete="tel"
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff;">
            </div>

            <!-- Mot de passe -->
            <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 5px;">
                <label for="password"
                    style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: var(--eventia-navy, #000000);">
                    <i class="fa-solid fa-key"
                        style="color: var(--eventia-navy-light, #000000); font-size: 0.82rem;"></i>
                    <span>Mot de passe (min. 6 caractères) *</span>
                </label>
                <div style="position: relative; width: 100%;">
                    <input type="password" id="password" name="password" required placeholder="••••••••"
                        autocomplete="new-password"
                        style="width: 100%; box-sizing: border-box; padding: 0.75rem 2.5rem 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.92rem; font-family: inherit; color: var(--eventia-navy, #000000); background: #ffffff;">
                    <button type="button" onclick="togglePasswordVisibility()" aria-label="Afficher le mot de passe"
                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--eventia-muted, #737373); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                        <i class="fa-solid fa-eye" id="togglePwdBtn"></i>
                    </button>
                </div>
            </div>

            <!-- Bouton Inscription -->
            <button type="submit" class="eventia-btn-primary"
                style="width: 100%; padding: 0.85rem; margin-top: 0.35rem; font-size: 0.98rem; font-weight: 800; border-radius: 12px; justify-content: center; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3); border: none; cursor: pointer;">
                <i class="fa-solid fa-user-plus"></i> Créer mon compte Client
            </button>
        </form>

        <!-- Passerelle Promoteur -->
        <div class="promoteur-invite-box">
            <div>
                <strong>Vous êtes organisateur ?</strong><br>
                <span style="color: var(--eventia-muted, #737373);">Créez et vendez vos événements officiels.</span>
            </div>
            <a href="client/devenir-promoteur.php" class="promoteur-invite-btn">
                <i class="fa-solid fa-bullhorn"></i> Devenir Promoteur
            </a>
        </div>

        <!-- Footer Connexion -->
        <div class="auth-footer"
            style="margin-top: 1.5rem; padding-top: 1.15rem; border-top: 1px solid var(--eventia-border, #E5E5E5); text-align: center; font-size: 0.86rem; color: var(--eventia-muted, #737373);">
            Vous avez déjà un compte ? <a href="connexion.php"
                style="color: var(--eventia-navy, #000000); font-weight: 800; text-decoration: none;">Se connecter</a>
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