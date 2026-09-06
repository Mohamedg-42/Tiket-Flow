<?php
// ==============================================================================
// RÉINITIALISATION DE MOT DE PASSE (reinitialiser-mot-de-passe.php)
// Définition d'un nouveau mot de passe après validation du jeton reçu par email
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');

$error = "";
$token_valid = false;
$reset_record = null;
$user_record = null;

if (empty($token) || empty($email)) {
    $error = "Lien de réinitialisation invalide ou incomplet.";
} else {
    // 1. Vérification du jeton dans la table password_resets
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets 
        WHERE token = ? AND email = ? AND used = 0 AND expires_at >= NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$token, $email]);
    $reset_record = $stmt->fetch();

    if (!$reset_record) {
        $error = "Ce lien de réinitialisation est invalide, a déjà été utilisé ou a expiré (validité de 60 minutes).";
    } else {
        // 2. Vérifier que l'utilisateur existe toujours
        $stmt_u = $pdo->prepare("SELECT id, nom, prenom, email FROM users WHERE email = ?");
        $stmt_u->execute([$email]);
        $user_record = $stmt_u->fetch();

        if (!$user_record) {
            $error = "Compte utilisateur introuvable.";
        } else {
            $token_valid = true;
        }
    }
}

// 3. Traitement du formulaire de changement de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Veuillez remplir tous les champs.";
    } elseif (strlen($new_password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Les deux mots de passe saisis ne correspondent pas.";
    } else {
        // A. Hachage sécurisé du nouveau mot de passe
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // B. Mise à jour dans la table users
        $stmt_upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?");
        $stmt_upd->execute([$hashed_password, $email]);

        // C. Marquer le jeton comme utilisé
        $stmt_mark = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        $stmt_mark->execute([(int)$reset_record['id']]);

        // D. Envoi d'un email de confirmation de changement
        $to_name = trim(($user_record['prenom'] ?? '') . ' ' . $user_record['nom']);
        if (empty($to_name)) $to_name = "Utilisateur Eventia";
        sendPasswordChangedConfirmationEmail($user_record['email'], $to_name);

        // E. Enregistrement dans le journal d'audit
        logActivity(
            'user.password_reset_success', 
            'user', 
            (int)$user_record['id'], 
            "Mot de passe réinitialisé avec succès via lien email sécurisé", 
            (int)$user_record['id']
        );

        // F. Redirection vers la page de connexion avec message de succès
        header("Location: connexion.php?reset=success");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Nouveau mot de passe - Eventia</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <!-- FontAwesome 6 Pro Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Style Principal Eventia -->
    <link rel="stylesheet" href="Css/main.css">
    <style>
        :root {
            --eventia-navy: #000000;
            --eventia-navy-light: #000000;
            --eventia-amber: #FF4A0D;
            --eventia-amber-hover: #FF4A0D;
            --eventia-bg: #F5F5F5;
            --eventia-card-bg: #ffffff;
            --eventia-border: #E5E5E5;
            --eventia-muted: #737373;
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        body {
            font-family: var(--font-body);
            background: linear-gradient(135deg, #000000 0%, #000000 50%, #000000 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            box-sizing: border-box;
            position: relative;
        }

        .auth-container {
            width: 100%;
            max-width: 450px;
            background: var(--eventia-card-bg);
            border-radius: 20px;
            box-shadow: 0 20px 40px -15px rgba(11, 29, 58, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            padding: 2.25rem 2rem;
            box-sizing: border-box;
            position: relative;
            z-index: 10;
        }

        .auth-logo {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #000000;
            font-family: var(--font-heading);
            font-size: 1.6rem;
            font-weight: 900;
            letter-spacing: -0.5px;
        }

        .auth-logo span.dot {
            color: var(--eventia-amber);
        }

        .eventia-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--eventia-amber);
            color: #000000;
            font-family: var(--font-heading);
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .eventia-btn-primary:hover {
            background: var(--eventia-amber-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 74, 13, 0.45);
        }

        .eventia-form-group input:focus {
            outline: none;
            border-color: var(--eventia-amber) !important;
            box-shadow: 0 0 0 3.5px rgba(255, 74, 13, 0.2) !important;
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <!-- Logo & Titre -->
        <div style="text-align: center; margin-bottom: 1.75rem;">
            <a href="client/accueil.php" title="Retour à l'accueil" style="display: inline-flex; align-items: center; justify-content: center; text-decoration: none; margin-bottom: 0.5rem;">
                <img src="images/logo.png" alt="Tikéli" style="height: 48px; width: auto; max-width: 170px; object-fit: contain;">
            </a>
            <h1 style="font-size: 1.35rem; font-weight: 800; color: #000000; margin: 0.5rem 0 0.25rem; font-family: var(--font-heading);">
                Nouveau mot de passe
            </h1>
            <p style="color: var(--eventia-muted); margin: 0; font-size: 0.88rem;">
                Choisissez un mot de passe robuste pour votre compte
            </p>
        </div>

        <!-- Message d'erreur -->
        <?php if (!empty($error)): ?>
            <div style="background: #F5F5F5; border: 1px solid #E5E5E5; color: #000000; border-radius: 10px; padding: 0.85rem 1rem; font-size: 0.85rem; font-weight: 600; display: flex; align-items: flex-start; gap: 8px; margin-bottom: 1.25rem; line-height: 1.45;">
                <i class="fa-solid fa-circle-exclamation" style="flex-shrink: 0; margin-top: 2px;"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
            <!-- Formulaire de réinitialisation -->
            <form method="POST" action="reinitialiser-mot-de-passe.php" style="display: flex; flex-direction: column; gap: 1.15rem;">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                <!-- Info compte -->
                <div style="background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 8px; padding: 0.65rem 0.85rem; font-size: 0.82rem; color: #FF4A0D; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Compte : <strong><?php echo htmlspecialchars($email); ?></strong></span>
                </div>

                <!-- Nouveau mot de passe -->
                <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 6px;">
                    <label for="new_password" style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: #000000;">
                        <i class="fa-solid fa-lock" style="color: var(--eventia-navy-light); font-size: 0.82rem;"></i>
                        <span>Nouveau mot de passe *</span>
                    </label>
                    <div style="position: relative; width: 100%;">
                        <input type="password" id="new_password" name="new_password" required minlength="6"
                            placeholder="Minimum 6 caractères" autocomplete="new-password" autofocus
                            style="width: 100%; box-sizing: border-box; padding: 0.75rem 2.5rem 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border); font-size: 0.92rem; font-family: inherit; color: #000000; background: #ffffff; transition: border-color 0.2s, box-shadow 0.2s;">
                        <button type="button" onclick="togglePassword('new_password', 'toggleNewBtn')" aria-label="Afficher"
                            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--eventia-muted); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                            <i class="fa-solid fa-eye" id="toggleNewBtn"></i>
                        </button>
                    </div>
                </div>

                <!-- Confirmation mot de passe -->
                <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 6px;">
                    <label for="confirm_password" style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: #000000;">
                        <i class="fa-solid fa-check-double" style="color: var(--eventia-navy-light); font-size: 0.82rem;"></i>
                        <span>Confirmer le mot de passe *</span>
                    </label>
                    <div style="position: relative; width: 100%;">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                            placeholder="Répétez le mot de passe" autocomplete="new-password"
                            style="width: 100%; box-sizing: border-box; padding: 0.75rem 2.5rem 0.75rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border); font-size: 0.92rem; font-family: inherit; color: #000000; background: #ffffff; transition: border-color 0.2s, box-shadow 0.2s;">
                        <button type="button" onclick="togglePassword('confirm_password', 'toggleConfBtn')" aria-label="Afficher"
                            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--eventia-muted); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                            <i class="fa-solid fa-eye" id="toggleConfBtn"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="eventia-btn-primary"
                    style="width: 100%; padding: 0.85rem; margin-top: 0.35rem; font-size: 0.98rem; font-weight: 800; border-radius: 12px; justify-content: center; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3); border: none; cursor: pointer;">
                    <i class="fa-solid fa-key"></i> Enregistrer le nouveau mot de passe
                </button>
            </form>
        <?php else: ?>
            <!-- Lien expiré ou invalide : bouton pour refaire une demande -->
            <div style="text-align: center; margin-top: 1rem;">
                <a href="mot-de-passe-oublie.php" class="eventia-btn-primary" style="width: 100%; padding: 0.85rem; font-size: 0.95rem; font-weight: 800; border-radius: 12px; justify-content: center; box-sizing: border-box;">
                    <i class="fa-solid fa-arrows-rotate"></i> Demander un nouveau lien
                </a>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="auth-footer"
            style="margin-top: 1.75rem; padding-top: 1.15rem; border-top: 1px solid var(--eventia-border); text-align: center; font-size: 0.86rem; color: var(--eventia-muted);">
            <a href="connexion.php" style="color: #000000; font-weight: 800; text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Retour à la connexion
            </a>
        </div>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>

</html>
