<?php
// ==============================================================================
// MOT DE PASSE OUBLIÉ (mot-de-passe-oublie.php)
// Demande de réinitialisation de mot de passe par email sécurisé
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

$error = "";
$success_msg = "";
$email_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Veuillez renseigner une adresse email valide.";
    } else {
        // 1. Recherche de l'utilisateur par son email
        $stmt = $pdo->prepare("SELECT id, nom, prenom, email, statut FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalider les anciens tokens non utilisés pour cet email
            $stmt_inv = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
            $stmt_inv->execute([$email]);

            // 2. Générer un jeton cryptographiquement sécurisé
            $token = bin2hex(random_bytes(32));

            // 3. Enregistrer le token avec validité de 60 minutes
            $stmt_ins = $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at, used) 
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0)
            ");
            $stmt_ins->execute([$email, $token]);

            // 4. Construction de l'URL sécurisée de réinitialisation
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $reset_url = "{$scheme}://{$host}{$dir}/reinitialiser-mot-de-passe.php?token=" . urlencode($token) . "&email=" . urlencode($email);

            // 5. Envoi de l'email via le service de messagerie Eventia
            $to_name = trim(($user['prenom'] ?? '') . ' ' . $user['nom']);
            if (empty($to_name)) $to_name = "Utilisateur Eventia";

            $mail_ok = sendPasswordResetEmail($user['email'], $to_name, $reset_url, 60);

            logActivity(
                'user.password_reset_request', 
                'user', 
                (int)$user['id'], 
                "Demande de réinitialisation de mot de passe par email" . ($mail_ok ? " (Email envoyé)" : " (Échec d'envoi email)"),
                (int)$user['id']
            );
        } else {
            // Journaliser la tentative sans révéler l'inexistence de l'email
            logActivity('user.password_reset_unknown', 'user', null, "Tentative de réinitialisation pour email inconnu : " . substr($email, 0, 80));
        }

        // Message générique de confirmation (protection contre l'énumération des comptes)
        $email_sent = true;
        $success_msg = "Si cette adresse est associée à un compte Eventia, un email contenant les instructions et votre lien de réinitialisation vient de vous être envoyé. Pensez à vérifier vos courriers indésirables (spams).";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Mot de passe oublié - Eventia</title>
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
            max-width: 440px;
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
                Mot de passe oublié ?
            </h1>
            <p style="color: var(--eventia-muted); margin: 0; font-size: 0.88rem; line-height: 1.45;">
                Entrez votre adresse email pour recevoir un lien de réinitialisation sécurisé.
            </p>
        </div>

        <!-- Message d'erreur -->
        <?php if (!empty($error)): ?>
            <div style="background: #F5F5F5; border: 1px solid #E5E5E5; color: #000000; border-radius: 10px; padding: 0.75rem 1rem; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-circle-exclamation" style="flex-shrink: 0;"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Message de succès après envoi -->
        <?php if ($email_sent): ?>
            <div style="background: #FFF2ED; border: 1px solid #FFF2ED; color: #FF4A0D; border-radius: 12px; padding: 1.25rem; font-size: 0.88rem; line-height: 1.5; margin-bottom: 1.5rem; text-align: center;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #FFF2ED; color: #FF4A0D; display: grid; place-items: center; font-size: 1.4rem; margin: 0 auto 0.75rem;">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
                <strong style="display: block; font-size: 1rem; color: #000000; margin-bottom: 0.35rem;">Email envoyé !</strong>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="connexion.php" class="eventia-btn-primary" style="width: 100%; padding: 0.85rem; font-size: 0.95rem; font-weight: 800; border-radius: 12px; justify-content: center; box-sizing: border-box; text-align: center;">
                    <i class="fa-solid fa-right-to-bracket"></i> Retour à la connexion
                </a>
                <a href="mot-de-passe-oublie.php" style="text-align: center; font-size: 0.82rem; color: var(--eventia-muted); text-decoration: underline; padding: 0.25rem;">
                    Renvoyer un autre lien
                </a>
            </div>
        <?php else: ?>
            <!-- Formulaire de demande de réinitialisation -->
            <form method="POST" action="mot-de-passe-oublie.php" style="display: flex; flex-direction: column; gap: 1.25rem;">
                <div class="eventia-form-group" style="display: flex; flex-direction: column; gap: 6px;">
                    <label for="email" style="display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 700; color: #000000;">
                        <i class="fa-solid fa-envelope" style="color: var(--eventia-navy-light); font-size: 0.82rem;"></i>
                        <span>Votre Adresse Email *</span>
                    </label>
                    <input type="email" id="email" name="email" required
                        placeholder="nom@exemple.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        autocomplete="email" autofocus
                        style="width: 100%; box-sizing: border-box; padding: 0.8rem 0.95rem; border-radius: 10px; border: 1px solid var(--eventia-border); font-size: 0.92rem; font-family: inherit; color: #000000; background: #ffffff; transition: border-color 0.2s, box-shadow 0.2s;">
                </div>

                <button type="submit" class="eventia-btn-primary"
                    style="width: 100%; padding: 0.85rem; margin-top: 0.25rem; font-size: 0.98rem; font-weight: 800; border-radius: 12px; justify-content: center; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3); border: none; cursor: pointer;">
                    <i class="fa-solid fa-paper-plane"></i> M'envoyer le lien par email
                </button>
            </form>
        <?php endif; ?>

        <!-- Lien de retour vers la connexion -->
        <div class="auth-footer"
            style="margin-top: 1.75rem; padding-top: 1.15rem; border-top: 1px solid var(--eventia-border); text-align: center; font-size: 0.86rem; color: var(--eventia-muted);">
            Vous vous souvenez de votre mot de passe ? <a href="connexion.php"
                style="color: #000000; font-weight: 800; text-decoration: none;">Se connecter</a>
        </div>
    </div>
</body>

</html>
