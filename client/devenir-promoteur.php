<?php
// ==============================================================================
// CANDIDATURE & DOSSIER D'ÉLIGIBILITÉ PROMOTEUR (client/devenir-promoteur.php)
// Évaluation d'éligibilité légale : Personne Physique vs Personne Morale
// Accessible publiquement ou pré-rempli pour les utilisateurs connectés.
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/auth.php';

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_id_session = $is_logged_in ? (int) $_SESSION['user_id'] : null;

$message = "";
$msg_type = "";

// Pré-remplissage éventuel depuis la session
$default_nom = $_SESSION['user_nom'] ?? '';
$default_prenom = $_SESSION['user_prenom'] ?? '';
$default_email = $_SESSION['user_email'] ?? '';
$default_tel = '';

if ($is_logged_in) {
    $st_u = $pdo->prepare("SELECT nom, prenom, telephone, role FROM users WHERE id = ?");
    $st_u->execute([$user_id_session]);
    $u_curr = $st_u->fetch();
    if ($u_curr) {
        $default_nom = $u_curr['nom'] ?? $default_nom;
        $default_prenom = $u_curr['prenom'] ?? $default_prenom;
        $default_tel = $u_curr['telephone'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_entite = $_POST['type_entite'] ?? 'physique';
    if (!in_array($type_entite, ['physique', 'morale'], true)) {
        $type_entite = 'physique';
    }

    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $nom_complet = trim("$prenom $nom");

    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    $activite = trim($_POST['activite'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $volume_estime = trim($_POST['volume_estime'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $reseaux_sociaux = trim($_POST['reseaux_sociaux'] ?? '');
    $autres_infos = trim($_POST['autres_infos'] ?? '');

    // Spécifique Personne Morale
    $raison_sociale = trim($_POST['raison_sociale'] ?? '');
    $numero_registre = trim($_POST['numero_registre'] ?? '');
    $rep_nom = trim($_POST['representant_nom'] ?? '');
    $rep_prenom = trim($_POST['representant_prenom'] ?? '');
    $representant_legal = trim("$rep_nom $rep_prenom");

    // Mot de passe pour les nouveaux candidats non connectés
    $password = $_POST['password'] ?? '';

    // Validation
    $errors = [];
    if ($type_entite === 'morale') {
        if (empty($raison_sociale))
            $errors[] = "Veuillez renseigner la Raison Sociale / Dénomination de l'entreprise.";
        if (empty($numero_registre))
            $errors[] = "Veuillez renseigner le N° de Registre du Commerce (RCCM / ID Fiscal).";
        if (empty($rep_nom) || empty($rep_prenom))
            $errors[] = "Veuillez indiquer distinctement le Nom et le Prénom du Représentant Légal.";
    }

    if (empty($nom) || empty($prenom))
        $errors[] = "Veuillez indiquer distinctement votre Nom et votre Prénom.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = "L'adresse email est requise et doit être valide.";
    if (empty($telephone))
        $errors[] = "Le numéro de téléphone est obligatoire.";
    if (empty($activite))
        $errors[] = "Veuillez décrire votre activité dans l'événementiel.";
    if (empty($experience))
        $errors[] = "Veuillez indiquer votre expérience dans l'organisation.";
    if (empty($description))
        $errors[] = "Veuillez présenter vos projets d'événements à venir.";

    if (!$is_logged_in && (empty($password) || strlen($password) < 6)) {
        $errors[] = "Veuillez définir un mot de passe d'au moins 6 caractères pour votre futur compte.";
    }

    // Gestion des téléversements de fichiers
    $upload_dir = __DIR__ . '/../uploads/ids/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0775, true);
    }
    $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];

    // 1. Pièce d'identité (obligatoire)
    $piece_id_filename = 'default.jpg';
    if (isset($_FILES['piece_identite']) && $_FILES['piece_identite']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['piece_identite']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = "Format de la pièce d'identité non accepté (JPG, PNG ou PDF requis).";
        } else {
            $piece_id_filename = 'id_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($_FILES['piece_identite']['tmp_name'], $upload_dir . $piece_id_filename)) {
                $errors[] = "Erreur lors de l'enregistrement de votre pièce d'identité.";
            }
        }
    } else {
        $errors[] = "La pièce d'identité est obligatoire pour l'examen d'éligibilité.";
    }

    // 2. Pièce Entreprise / Registre (pour Personne Morale)
    $piece_entreprise_filename = null;
    if ($type_entite === 'morale') {
        if (isset($_FILES['piece_entreprise']) && $_FILES['piece_entreprise']['error'] === UPLOAD_ERR_OK) {
            $ext_ent = strtolower(pathinfo($_FILES['piece_entreprise']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext_ent, $allowed_ext, true)) {
                $errors[] = "Format du document d'entreprise non accepté (JPG, PNG ou PDF requis).";
            } else {
                $piece_entreprise_filename = 'doc_ent_' . uniqid() . '.' . $ext_ent;
                if (!move_uploaded_file($_FILES['piece_entreprise']['tmp_name'], $upload_dir . $piece_entreprise_filename)) {
                    $errors[] = "Erreur lors de l'enregistrement du document de l'entreprise.";
                }
            }
        } else {
            $errors[] = "Pour une personne morale, le document officiel d'enregistrement (RCCM / DFE) est obligatoire.";
        }
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $user_id = null;

            if ($is_logged_in) {
                $user_id = $user_id_session;
                // Met à jour l'utilisateur existant en rôle promoteur non vérifié
                $st_u = $pdo->prepare("UPDATE users SET statut = 'en_attente' WHERE id = ?");
                $st_u->execute([$user_id]);
            } else {
                // Vérification si email déjà existant
                $st_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $st_check->execute([$email]);
                $existing_user = $st_check->fetch();

                if ($existing_user) {
                    throw new Exception("Cette adresse email est déjà associée à un compte Eventia. Veuillez vous connecter avant de soumettre votre dossier.");
                }

                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $st_nu = $pdo->prepare("
                    INSERT INTO users (nom, prenom, email, telephone, password, role, statut, est_verifie) 
                    VALUES (?, ?, ?, ?, ?, 'promoteur', 'en_attente', 0)
                ");
                $st_nu->execute([$nom, $prenom, $email, $telephone, $pass_hash]);
                $user_id = (int) $pdo->lastInsertId();
            }

            // Enregistrement de la demande d'éligibilité
            $sql_req = "
                INSERT INTO promoter_requests (
                    user_id, type_entite, raison_sociale, numero_registre, representant_legal,
                    nom_complet, telephone, ville, email, activite, experience, volume_estime,
                    piece_identite, piece_entreprise, description, reseaux_sociaux, autres_infos, statut
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')
            ";
            $stmt_req = $pdo->prepare($sql_req);
            $stmt_req->execute([
                $user_id,
                $type_entite,
                $raison_sociale,
                $numero_registre,
                $representant_legal,
                $nom_complet,
                $telephone,
                $ville,
                $email,
                $activite,
                $experience,
                $volume_estime,
                $piece_id_filename,
                $piece_entreprise_filename,
                $description,
                $reseaux_sociaux,
                $autres_infos
            ]);
            $request_id = (int) $pdo->lastInsertId();

            // Enregistrement / mise à jour du profil promoteur
            $nom_commercial = ($type_entite === 'morale' && !empty($raison_sociale)) ? $raison_sociale : $nom_complet;
            $st_p = $pdo->prepare("
                INSERT INTO promoters (user_id, type_entite, nom_commercial, numero_registre, representant_legal, telephone_contact, email_contact, ville, statut, solde)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'en_attente', 0.00)
                ON DUPLICATE KEY UPDATE 
                    type_entite = VALUES(type_entite),
                    nom_commercial = VALUES(nom_commercial),
                    numero_registre = VALUES(numero_registre),
                    representant_legal = VALUES(representant_legal),
                    telephone_contact = VALUES(telephone_contact),
                    email_contact = VALUES(email_contact),
                    ville = VALUES(ville),
                    statut = 'en_attente'
            ");
            $st_p->execute([
                $user_id,
                $type_entite,
                $nom_commercial,
                $numero_registre,
                $representant_legal,
                $telephone,
                $email,
                $ville
            ]);

            $pdo->commit();

            // Envoi de l'e-mail officiel d'accusé de réception
            sendPromoterRegistrationEmail($email, $nom_complet, $activite);
            logActivity('demande_promoteur', 'user', $user_id, "Dossier d'éligibilité promoteur ($type_entite) soumis par $nom_complet ($email)", $user_id);

            $msg_type = "success";
            $message = "Votre dossier d'éligibilité promoteur a été soumis avec succès ! Notre équipe de conformité examine actuellement vos informations. Vous recevrez un e-mail de confirmation dès que votre compte sera activé par l'administrateur.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $e->getMessage();
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devenir Promoteur Partenaire - Eventia</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS & Eventia Brand -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/eventia-brand.css">
    <link rel="stylesheet" href="../css/responsive-pro.css">

    <style>
        body {
            background-color: var(--eventia-background, #F5F5F5);
            color: var(--eventia-text, #000000);
            font-family: var(--font-body, 'Inter', sans-serif);
            min-height: 100vh;
            padding-bottom: 3rem;
        }

        .promo-hero {
            background: linear-gradient(135deg, var(--eventia-navy-dark, #000000) 0%, var(--eventia-navy, #000000) 100%);
            color: #ffffff;
            padding: 3.5rem 1.5rem 3rem;
            text-align: center;
            border-bottom: 4px solid var(--eventia-amber, #FF4A0D);
            box-shadow: var(--eventia-shadow-md);
        }

        .promo-hero h1 {
            color: #ffffff;
            font-family: var(--font-heading, 'Outfit', sans-serif);
            font-size: clamp(1.8rem, 3.5vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0.5rem auto 0.75rem;
            max-width: 760px;
        }

        .promo-hero p {
            color: #737373;
            font-size: 1rem;
            max-width: 650px;
            margin: 0 auto;
            line-height: 1.55;
        }

        .promo-wrapper {
            max-width: 840px;
            margin: -2rem auto 0;
            padding: 0 1rem;
        }

        .promo-card {
            background: #ffffff;
            border: 1px solid var(--eventia-border, #E5E5E5);
            border-radius: var(--eventia-radius-lg, 14px);
            padding: clamp(1.5rem, 4vw, 2.75rem);
            box-shadow: var(--eventia-shadow-lg, 0 15px 35px -5px rgba(15, 23, 42, 0.08));
        }

        /* Sélecteur de type d'entité (Physique vs Morale) */
        .entity-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 580px) {
            .entity-selector {
                grid-template-columns: 1fr;
            }
        }

        .entity-option {
            position: relative;
            border: 2px solid var(--eventia-border, #E5E5E5);
            border-radius: var(--eventia-radius-md, 10px);
            padding: 1.25rem;
            cursor: pointer;
            transition: var(--eventia-transition);
            background: #F5F5F5;
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .entity-option input[type="radio"] {
            margin-top: 3px;
            accent-color: var(--eventia-navy, #000000);
            transform: scale(1.2);
            cursor: pointer;
        }

        .entity-option.active {
            border-color: var(--eventia-navy, #000000);
            background: #ffffff;
            box-shadow: 0 4px 14px rgba(11, 29, 58, 0.12);
        }

        .entity-option strong {
            display: block;
            color: var(--eventia-navy, #000000);
            font-family: var(--font-heading, 'Outfit', sans-serif);
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .entity-option span {
            color: var(--eventia-muted, #737373);
            font-size: 0.82rem;
            line-height: 1.4;
            display: block;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        @media (max-width: 640px) {
            .form-row-2 {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            color: var(--ink, #000000);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem 0.95rem;
            border: 1px solid var(--line, #E5E5E5);
            border-radius: var(--radius-md, 6px);
            background: #ffffff;
            color: var(--ink, #000000);
            font-size: 0.9rem;
            font-family: inherit;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary, #000000);
            box-shadow: 0 0 0 3px rgba(22, 35, 63, 0.1);
        }

        .upload-field-box {
            background: #F5F5F5;
            border: 1px dashed #E5E5E5;
            border-radius: 8px;
            padding: 0.85rem 1rem;
        }

        .section-separator {
            border-top: 1px solid var(--line, #E5E5E5);
            margin: 1.75rem 0 1.25rem;
            padding-top: 1.25rem;
        }

        .section-title {
            color: var(--eventia-navy, #000000);
            font-family: var(--font-heading, 'Outfit', sans-serif);
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-submit-promo {
            width: 100%;
            padding: 1rem;
            background: var(--eventia-amber, #FF4A0D);
            color: var(--eventia-navy-dark, #000000);
            border: 1px solid var(--eventia-amber-dark, #FF4A0D);
            border-radius: var(--eventia-radius-md, 10px);
            font-family: var(--font-body, 'Inter', sans-serif);
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--eventia-transition);
            box-shadow: 0 4px 15px rgba(255, 177, 46, 0.3);
            margin-top: 1rem;
        }

        .btn-submit-promo:hover {
            background: var(--eventia-amber-dark, #FF4A0D);
            color: #000000;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(229, 148, 0, 0.4);
        }

        .alert-box {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .alert-error {
            background: #F5F5F5;
            color: #000000;
            border: 1px solid #E5E5E5;
        }

        .alert-success {
            background: #FFF2ED;
            color: #000000;
            border: 1px solid #FFF2ED;
        }
    </style>
</head>

<body>

    <!-- Hero d'introduction -->
    <header class="promo-hero">
        <a href="accueil.php"
            style="color: #737373; text-decoration: none; font-weight: 500; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 1rem;">
            <i class="fa-solid fa-arrow-left"></i> Retour à la Billetterie
        </a>
        <span class="page-kicker" style="color: var(--accent, #FF4A0D); display: block; margin-bottom: 0.4rem;">
            <i class="fa-solid fa-bullhorn"></i> Partenariat Officiel
        </span>
        <h1>Devenez Promoteur Officiel sur Eventia</h1>
        <p>
            Vendez vos billets en ligne, gérez vos jauges et places interactives, et encaissez vos recettes
            instantanément par Mobile Money en toute sécurité.
        </p>
    </header>

    <main class="promo-wrapper">
        <div class="promo-card">

            <?php if (!empty($message)): ?>
                <div class="alert-box alert-<?php echo $msg_type; ?>">
                    <div style="font-weight: 600; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                        <i
                            class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                        <span><?php echo $msg_type === 'success' ? 'Candidature Transmise !' : 'Veuillez corriger les points suivants :'; ?></span>
                    </div>
                    <?php echo $message; ?>
                    <?php if ($msg_type === 'success'): ?>
                        <div style="margin-top: 1rem;">
                            <a href="../connexion.php"
                                style="background: #000000; color: #ffffff; padding: 0.5rem 1.25rem; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 0.85rem; display: inline-block;">
                                Aller à la page de connexion
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($msg_type !== 'success'): ?>
                <form method="POST" action="devenir-promoteur.php" enctype="multipart/form-data" id="promoterForm">

                    <!-- 1. SÉLECTION DU STATUT JURIDIQUE -->
                    <div class="section-title">
                        <i class="fa-solid fa-scale-balanced" style="color: var(--primary, #000000);"></i> 1. Forme
                        Juridique de l'Organisateur *
                    </div>

                    <div class="entity-selector">
                        <label class="entity-option active" id="opt_physique">
                            <input type="radio" name="type_entite" value="physique" checked
                                onchange="handleEntityTypeChange('physique')">
                            <div>
                                <strong>Personne Physique</strong>
                                <span>Particulier, Organisateur individuel, Créateur ou Promoteur indépendant.</span>
                            </div>
                        </label>

                        <label class="entity-option" id="opt_morale">
                            <input type="radio" name="type_entite" value="morale"
                                onchange="handleEntityTypeChange('morale')">
                            <div>
                                <strong>Personne Morale</strong>
                                <span>Entreprise enregistrée, Agence événementielle, Société SARL/SAS, Association
                                    déclarée.</span>
                            </div>
                        </label>
                    </div>

                    <!-- 2. BLOC SPÉCIFIQUE ENTREPRISE / ASSOCIATION (PERSONNE MORALE) -->
                    <div id="morale_fields"
                        style="display: none; background: #F5F5F5; border: 1px solid var(--line, #E5E5E5); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                        <div
                            style="font-weight: 600; color: var(--navy, #000000); font-size: 0.95rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-building" style="color: var(--primary, #000000);"></i> Informations
                            Légales de la Structure
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="raison_sociale">Raison Sociale / Nom de la structure</label>
                                <input type="text" id="raison_sociale" name="raison_sociale"
                                    placeholder="Ex: Live Production SARL, Agence Event..."
                                    value="<?php echo htmlspecialchars($_POST['raison_sociale'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="numero_registre">N° RCCM / ID Fiscal / N° Enregistrement</label>
                                <input type="text" id="numero_registre" name="numero_registre"
                                    placeholder="Ex: CI-ABJ-2024-B-XXXXX"
                                    value="<?php echo htmlspecialchars($_POST['numero_registre'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="representant_nom">Nom du Représentant Légal *</label>
                                <input type="text" id="representant_nom" name="representant_nom" placeholder="Ex: Koffi"
                                    value="<?php echo htmlspecialchars($_POST['representant_nom'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="representant_prenom">Prénom du Représentant Légal *</label>
                                <input type="text" id="representant_prenom" name="representant_prenom"
                                    placeholder="Ex: Marc (Gérant / Dirigeant)"
                                    value="<?php echo htmlspecialchars($_POST['representant_prenom'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="piece_entreprise">Document d'Enregistrement (DFE, RCCM ou Déclaration) *</label>
                            <div class="upload-field-box">
                                <input type="file" id="piece_entreprise" name="piece_entreprise"
                                    accept=".jpg,.jpeg,.png,.pdf">
                                <small style="color: var(--muted); display: block; margin-top: 4px;">Formats acceptés :
                                    PDF, JPG, PNG (Max 5 Mo)</small>
                            </div>
                        </div>
                    </div>

                    <!-- 3. COORDONNÉES & CONTACT PRINCIPAL -->
                    <div class="section-title">
                        <i class="fa-solid fa-id-card" style="color: var(--primary, #000000);"></i> 2. Coordonnées &
                        Responsable du Compte
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="nom"><span id="lbl_nom">Nom</span> *</label>
                            <input type="text" id="nom" name="nom" required placeholder="Ex: Bédié"
                                value="<?php echo htmlspecialchars($_POST['nom'] ?? $default_nom); ?>">
                        </div>

                        <div class="form-group">
                            <label for="prenom"><span id="lbl_prenom">Prénom(s)</span> *</label>
                            <input type="text" id="prenom" name="prenom" required placeholder="Ex: Jean-Eudes"
                                value="<?php echo htmlspecialchars($_POST['prenom'] ?? $default_prenom); ?>">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="email">Adresse Email professionnelle *</label>
                            <input type="email" id="email" name="email" required placeholder="contact@votre-structure.ci"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? $default_email); ?>">
                            <small style="color: var(--muted); font-size: 0.78rem;">Identifiant de connexion.</small>
                        </div>

                        <div class="form-group">
                            <label for="telephone">Numéro de Téléphone (Mobile Money & WhatsApp) *</label>
                            <input type="tel" id="telephone" name="telephone" required placeholder="Ex: +225 07 00 00 00 00"
                                value="<?php echo htmlspecialchars($_POST['telephone'] ?? $default_tel); ?>">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="ville">Ville & Pays de résidence *</label>
                            <input type="text" id="ville" name="ville" required placeholder="Ex: Abidjan, Côte d'Ivoire"
                                value="<?php echo htmlspecialchars($_POST['ville'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="piece_identite"><span id="lbl_piece_id">Pièce d'identité du déclarant (CNI / Passeport)</span> *</label>
                            <div class="upload-field-box">
                                <input type="file" id="piece_identite" name="piece_identite" accept=".jpg,.jpeg,.png,.pdf" required>
                                <small style="color: var(--muted); display: block; margin-top: 4px;">PDF, JPG, PNG (Max 5 Mo)</small>
                            </div>
                        </div>
                    </div>

                    <?php if (!$is_logged_in): ?>
                        <div class="form-group">
                            <label for="password"><i class="fa-solid fa-lock"></i> Définir votre Mot de passe de connexion
                            </label>
                            <input type="password" id="password" name="password" required placeholder="Minimum 6 caractères">
                            <small style="color: var(--muted); font-size: 0.78rem;">Conservez-le précieusement pour vous
                                connecter une fois votre dossier approuvé.</small>
                        </div>
                    <?php endif; ?>

                    <!-- 4. EXPÉRIENCE & ÉLIGIBILITÉ ÉVÉNEMENTIELLE -->
                    <div class="section-separator"></div>
                    <div class="section-title">
                        <i class="fa-solid fa-chart-line" style="color: var(--primary, #000000);"></i> 3. Éligibilité &
                        Profil Événementiel
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="activite">Types d'événements organisés</label>
                            <input type="text" id="activite" name="activite" required
                                placeholder="Ex: Concerts live, Soirées VIP, Conférences, Festivals..."
                                value="<?php echo htmlspecialchars($_POST['activite'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="volume_estime">Volume annuel estimé de billets</label>
                            <select id="volume_estime" name="volume_estime">
                                <option value="Moins de 500 billets/an">Moins de 500 billets / an</option>
                                <option value="500 à 2 000 billets/an" selected>500 à 2 000 billets / an</option>
                                <option value="2 000 à 10 000 billets/an">2 000 à 10 000 billets / an</option>
                                <option value="Plus de 10 000 billets/an">Plus de 10 000 billets / an (Gros festivals)
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="experience">Expérience dans le secteur événementiel</label>
                        <input type="text" id="experience" name="experience" required
                            placeholder="Ex: 4 ans d'expérience, 12 concerts organisés au Palais de la Culture"
                            value="<?php echo htmlspecialchars($_POST['experience'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="description">Présentation de vos projets à venir sur Eventia</label>
                        <textarea id="description" name="description" rows="3" required
                            placeholder="Décrivez les prochains événements que vous prévoyez de mettre en vente..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="reseaux_sociaux">Liens Réseaux Sociaux / Site Web (Optionnel)</label>
                            <input type="text" id="reseaux_sociaux" name="reseaux_sociaux"
                                placeholder="Ex: https://instagram.com/monagence"
                                value="<?php echo htmlspecialchars($_POST['reseaux_sociaux'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="autres_infos">Informations complémentaires (Optionnel)</label>
                            <input type="text" id="autres_infos" name="autres_infos"
                                placeholder="Références d'anciens événements, partenaires..."
                                value="<?php echo htmlspecialchars($_POST['autres_infos'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Conditions & Engagement -->
                    <div
                        style="background: #F5F5F5; border: 1px solid var(--line, #E5E5E5); border-radius: 8px; padding: 1rem; margin-top: 1.5rem; font-size: 0.82rem; color: var(--muted, #737373); line-height: 1.5;">
                        <i class="fa-solid fa-shield-halved" style="color: var(--primary, #000000); margin-right: 4px;"></i>
                        En soumettant ce dossier, vous certifiez l'exactitude des pièces fournies. L'accès aux
                        fonctionnalités de vente de billets, d'encaissement et de retrait est conditionné à la validation
                        définitive par l'administration d'Eventia.
                    </div>

                    <button type="submit" class="btn-submit-promo">
                        <i class="fa-solid fa-paper-plane"></i> Soumettre ma Demande d'Éligibilité
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </main>

    <script>
        function handleEntityTypeChange(type) {
            const optPhysique = document.getElementById('opt_physique');
            const optMorale = document.getElementById('opt_morale');
            const moraleFields = document.getElementById('morale_fields');
            const pieceEnt = document.getElementById('piece_entreprise');
            const raisonSociale = document.getElementById('raison_sociale');
            const numRegistre = document.getElementById('numero_registre');
            const repNom = document.getElementById('representant_nom');
            const repPrenom = document.getElementById('representant_prenom');

            if (type === 'morale') {
                optMorale.classList.add('active');
                optPhysique.classList.remove('active');
                moraleFields.style.display = 'block';
                if (pieceEnt) pieceEnt.required = true;
                if (raisonSociale) raisonSociale.required = true;
                if (numRegistre) numRegistre.required = true;
                if (repNom) repNom.required = true;
                if (repPrenom) repPrenom.required = true;
                if (document.getElementById('lbl_nom')) document.getElementById('lbl_nom').textContent = "Nom du déclarant";
                if (document.getElementById('lbl_prenom')) document.getElementById('lbl_prenom').textContent = "Prénom du déclarant";
            } else {
                optPhysique.classList.add('active');
                optMorale.classList.remove('active');
                moraleFields.style.display = 'none';
                if (pieceEnt) pieceEnt.required = false;
                if (raisonSociale) raisonSociale.required = false;
                if (numRegistre) numRegistre.required = false;
                if (repNom) repNom.required = false;
                if (repPrenom) repPrenom.required = false;
                if (document.getElementById('lbl_nom')) document.getElementById('lbl_nom').textContent = "Nom";
                if (document.getElementById('lbl_prenom')) document.getElementById('lbl_prenom').textContent = "Prénom(s)";
            }
        }
    </script>
</body>

</html>