<?php
// ==============================================================================
// PAGE D'INSCRIPTION PUBLIQUE (inscription.php)
// Inscription ouverte pour :
// - Client (Acheteur de billets)
// - Promoteur (Organisateur avec dossier d'éligibilité)
// NOTE : Les agents de contrôle sont créés par les promoteurs et les administrateurs.
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$message = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Informations de base communes
    $nom       = trim($_POST['nom'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = $_POST['role'] ?? 'client';

    // Seuls les rôles 'client' et 'promoteur' sont autorisés à l'inscription publique
    if (!in_array($role, ['client', 'promoteur'], true)) {
        $role = 'client';
    }

    // Informations supplémentaires pour le Promoteur
    $activite        = trim($_POST['activite'] ?? '');
    $experience      = trim($_POST['experience'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $reseaux_sociaux = trim($_POST['reseaux_sociaux'] ?? '');
    $autres_infos    = trim($_POST['autres_infos'] ?? '');

    // Validation des champs obligatoires
    if (empty($nom) || empty($email) || empty($telephone) || empty($password)) {
        $message = "Veuillez remplir tous les champs obligatoires.";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "L'adresse email n'a pas un format valide.";
        $msg_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Le mot de passe doit contenir au moins 6 caractères.";
        $msg_type = "error";
    } elseif ($role === 'promoteur' && (empty($activite) || empty($experience) || empty($description))) {
        $message = "Pour un compte promoteur, veuillez préciser votre activité, votre expérience et décrire vos projets.";
        $msg_type = "error";
    } else {
        // Upload de la pièce d'identité du promoteur
        $piece_id_filename = 'default.jpg';

        if ($role === 'promoteur') {
            if (isset($_FILES['piece_identite']) && $_FILES['piece_identite']['error'] === UPLOAD_ERR_OK) {
                $fileTmp  = $_FILES['piece_identite']['tmp_name'];
                $fileName = $_FILES['piece_identite']['name'];
                $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed  = ['jpg', 'jpeg', 'png', 'pdf'];

                if (!in_array($ext, $allowed, true)) {
                    $message = "Format de la pièce d'identité invalide (formats acceptés : JPG, PNG, PDF).";
                    $msg_type = "error";
                } else {
                    $uploadDir = __DIR__ . '/uploads/ids/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $piece_id_filename = 'id_' . uniqid() . '.' . $ext;
                    if (!move_uploaded_file($fileTmp, $uploadDir . $piece_id_filename)) {
                        $message = "Erreur lors du téléchargement de votre pièce d'identité.";
                        $msg_type = "error";
                    }
                }
            } else {
                $message = "Veuillez joindre une pièce d'identité (format JPG, PNG ou PDF).";
                $msg_type = "error";
            }
        }

        if (empty($message)) {
            try {
                $password_hache = password_hash($password, PASSWORD_DEFAULT);
                $est_verifie = ($role === 'promoteur') ? 0 : 1;

                $sql = "INSERT INTO users (nom, email, telephone, password, role, est_verifie) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $email, $telephone, $password_hache, $role, $est_verifie]);

                $new_user_id = (int)$pdo->lastInsertId();

                if ($role === 'promoteur') {
                    // Enregistrement de la demande promoteur
                    $sql_req = "INSERT INTO promoter_requests (user_id, nom_complet, telephone, email, activite, experience, piece_identite, description, reseaux_sociaux, autres_infos, statut) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')";
                    $stmt_req = $pdo->prepare($sql_req);
                    $stmt_req->execute([$new_user_id, $nom, $telephone, $email, $activite, $experience, $piece_id_filename, $description, $reseaux_sociaux, $autres_infos]);

                    // Profil promoteur
                    $sql_promoter = "INSERT INTO promoters (user_id, nom_commercial, telephone_contact, email_contact, statut, solde) 
                                     VALUES (?, ?, ?, ?, 'en_attente', 0.00)";
                    $stmt_p = $pdo->prepare($sql_promoter);
                    $stmt_p->execute([$new_user_id, $nom, $telephone, $email]);

                    $message = "Votre dossier de promoteur a été soumis avec succès ! L'administrateur va examiner vos informations pour validation.";
                } else {
                    $message = "Votre compte client a été créé avec succès ! Vous pouvez maintenant vous connecter.";
                }

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
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - Ticket Flow</title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="css/style.css">
    <style>
        .promoter-box {
            display: none;
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-top: 1rem;
            margin-bottom: 1.25rem;
            text-align: left;
        }
        .promoter-box.active {
            display: block;
            animation: fadeIn 0.25s ease;
        }
        .promoter-box-title {
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="auth-container" style="width: min(100%, 520px);">
        <h2><i class="fa-solid fa-ticket"></i> Créer un compte</h2>
        <p style="color: var(--muted); margin-bottom: 1.5rem; font-size: 0.92rem;">
            Rejoignez Ticket Flow pour réserver ou organiser des événements
        </p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <div>
                    <?php echo htmlspecialchars($message); ?>
                    <?php if ($msg_type === 'success'): ?>
                        <div style="margin-top: 0.4rem;">
                            <a href="connexion.php" style="font-weight: bold; color: inherit; text-decoration: underline;">Se connecter maintenant →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="inscription.php" enctype="multipart/form-data">
            <!-- 1. Informations générales -->
            <div class="form-group">
                <label for="nom"><i class="fa-solid fa-user"></i> Nom complet *</label>
                <input type="text" id="nom" name="nom" required placeholder="Ex: Jean Koffi" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email"><i class="fa-solid fa-envelope"></i> Adresse Email *</label>
                <input type="email" id="email" name="email" required placeholder="exemple@mail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="telephone"><i class="fa-solid fa-phone"></i> Numéro de Téléphone *</label>
                <input type="tel" id="telephone" name="telephone" required placeholder="Ex: +225 07 00 00 00 00" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>">
            </div>

            <!-- Choix du rôle : Client ou Promoteur uniquement -->
            <div class="form-group">
                <label for="role"><i class="fa-solid fa-user-tag"></i> Je m'inscris en tant que : *</label>
                <select name="role" id="role" onchange="togglePromoterFields()" required>
                    <option value="client" <?php echo (!isset($_POST['role']) || $_POST['role'] === 'client') ? 'selected' : ''; ?>>👤 Client (Acheter des billets)</option>
                    <option value="promoteur" <?php echo (isset($_POST['role']) && $_POST['role'] === 'promoteur') ? 'selected' : ''; ?>>🎪 Promoteur (Organiser et vendre des événements)</option>
                </select>
            </div>

            <!-- 2. Bloc dossier d'éligibilité pour le Promoteur -->
            <div id="promoter-fields" class="promoter-box <?php echo (isset($_POST['role']) && $_POST['role'] === 'promoteur') ? 'active' : ''; ?>">
                <div class="promoter-box-title">
                    <i class="fa-solid fa-id-card"></i> Dossier d'éligibilité Promoteur
                </div>
                
                <div class="form-group">
                    <label for="activite">Activité principale / Structure *</label>
                    <input type="text" id="activite" name="activite" placeholder="Ex: Production de concerts, Festivalier, Agence" value="<?php echo htmlspecialchars($_POST['activite'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="experience">Expérience dans l'événementiel *</label>
                    <input type="text" id="experience" name="experience" placeholder="Ex: 5 ans dans l'organisation de spectacles" value="<?php echo htmlspecialchars($_POST['experience'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="piece_identite">Pièce d'identité (CNI / Passeport) *</label>
                    <input type="file" id="piece_identite" name="piece_identite" accept=".jpg,.jpeg,.png,.pdf">
                    <small style="color: var(--muted); display: block; margin-top: 0.25rem;">Formats acceptés : JPG, PNG, PDF</small>
                </div>

                <div class="form-group">
                    <label for="description">Description de vos projets d'événements *</label>
                    <textarea id="description" name="description" rows="3" placeholder="Présentez les événements que vous souhaitez organiser..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="reseaux_sociaux">Réseaux sociaux / Site web (Optionnel)</label>
                    <input type="text" id="reseaux_sociaux" name="reseaux_sociaux" placeholder="Ex: https://facebook.com/monagence" value="<?php echo htmlspecialchars($_POST['reseaux_sociaux'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="autres_infos">Informations supplémentaires (Optionnel)</label>
                    <textarea id="autres_infos" name="autres_infos" rows="2" placeholder="Tout autre détail utile pour l'examen de votre dossier..."><?php echo htmlspecialchars($_POST['autres_infos'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- 3. Mot de passe -->
            <div class="form-group">
                <label for="password"><i class="fa-solid fa-lock"></i> Mot de passe *</label>
                <input type="password" id="password" name="password" required placeholder="Minimum 6 caractères">
            </div>

            <button type="submit" class="btn-submit" style="margin-top: 0.5rem;">
                <i class="fa-solid fa-user-plus"></i> Créer mon compte
            </button>
        </form>

        <div class="auth-footer">
            Vous avez déjà un compte ? <a href="connexion.php">Se connecter</a>
        </div>
    </div>

    <script>
        function togglePromoterFields() {
            const roleSelect = document.getElementById('role');
            const promoterBox = document.getElementById('promoter-fields');
            const isPromoter = (roleSelect.value === 'promoteur');

            if (isPromoter) {
                promoterBox.classList.add('active');
                document.getElementById('activite').required = true;
                document.getElementById('experience').required = true;
                document.getElementById('piece_identite').required = true;
                document.getElementById('description').required = true;
            } else {
                promoterBox.classList.remove('active');
                document.getElementById('activite').required = false;
                document.getElementById('experience').required = false;
                document.getElementById('piece_identite').required = false;
                document.getElementById('description').required = false;
            }
        }

        document.addEventListener('DOMContentLoaded', togglePromoterFields);
    </script>
</body>
</html>