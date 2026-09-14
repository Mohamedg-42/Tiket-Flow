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
    $activite_autre = trim($_POST['activite_autre'] ?? '');
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

    // Mots de passe pour les nouveaux candidats non connectés
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // Validation des champs
    $errors = [];

    if ($type_entite === 'morale') {
        if (empty($raison_sociale)) {
            $errors[] = "Veuillez renseigner la Raison Sociale / Dénomination de l'entreprise.";
        }
        if (empty($numero_registre)) {
            $errors[] = "Veuillez renseigner le N° de Registre du Commerce (RCCM / ID Fiscal).";
        }
        if (empty($rep_nom) || empty($rep_prenom)) {
            $errors[] = "Veuillez indiquer distinctement le Nom et le Prénom du Représentant Légal.";
        }
    }

    if (empty($nom) || empty($prenom)) {
        $errors[] = "Veuillez indiquer distinctement votre Nom et votre Prénom.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'adresse email est requise et doit avoir un format valide.";
    }
    if (empty($telephone)) {
        $errors[] = "Le numéro de téléphone est obligatoire.";
    }
    if (empty($ville)) {
        $errors[] = "Veuillez renseigner votre Ville et Pays de résidence.";
    }
    if (empty($activite)) {
        $errors[] = "Veuillez sélectionner le type d'événement principal que vous organisez.";
    } elseif ($activite === "Autre type d'événement" && empty($activite_autre)) {
        $errors[] = "Vous avez choisi « Autre type d'événement ». Veuillez préciser la nature de vos activités dans le champ dédié.";
    }
    if (empty($experience)) {
        $errors[] = "Veuillez sélectionner votre niveau d'expérience dans l'organisation événementielle.";
    }
    if (empty($description)) {
        $errors[] = "Veuillez renseigner la description de vos activités.";
    }

    // Validation du mot de passe & confirmation
    if (!$is_logged_in) {
        if (empty($password) || strlen($password) < 6) {
            $errors[] = "Veuillez définir un mot de passe d'au moins 6 caractères pour votre compte.";
        } elseif ($password !== $password_confirm) {
            $errors[] = "La confirmation du mot de passe ne correspond pas au mot de passe saisi.";
        }
    }

    // Gestion proactive des doublons
    $clean_tel = preg_replace('/[^0-9]/', '', $telephone);

    // 1. Vérification dans la table des utilisateurs
    if (!$is_logged_in && !empty($email)) {
        $st_check_u = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
        $st_check_u->execute([$email]);
        if ($st_check_u->fetch()) {
            $errors[] = "Cette adresse email ($email) est déjà associée à un compte Tike WA. Veuillez vous connecter avant de soumettre votre dossier promoteur.";
        }
    }

    $user_filter = ($is_logged_in && !empty($user_id_session)) ? " AND user_id != " . (int)$user_id_session : "";

    if (!empty($clean_tel)) {
        $st_check_tel = $pdo->prepare("
            SELECT id FROM users 
            WHERE regexp_replace(telephone, '[^0-9]', '', 'g') = ?
            $user_filter
            LIMIT 1
        ");
        $st_check_tel->execute([$clean_tel]);
        if ($st_check_tel->fetch()) {
            $errors[] = "Ce numéro de téléphone est déjà associé à un autre compte existant sur Tike WA.";
        }
    }

    // 2. Vérification des demandes d'éligibilité déjà en cours ou validées
    if (!empty($email) || !empty($clean_tel)) {
        $st_check_req = $pdo->prepare("
            SELECT id, statut FROM promoter_requests 
            WHERE (LOWER(email) = LOWER(?) OR regexp_replace(telephone, '[^0-9]', '', 'g') = ?)
              AND statut IN ('en_attente', 'approuve')
              $user_filter
            ORDER BY id DESC LIMIT 1
        ");
        $st_check_req->execute([$email, $clean_tel]);
        $existing_req = $st_check_req->fetch();
        if ($existing_req) {
            if ($existing_req['statut'] === 'en_attente') {
                $errors[] = "Une demande d'éligibilité promoteur (Dossier #" . $existing_req['id'] . ") est déjà en cours de traitement pour ces coordonnées. Notre équipe examine actuellement vos pièces.";
            } elseif ($existing_req['statut'] === 'approuve') {
                $errors[] = "Un compte promoteur approuvé existe déjà pour ces coordonnées. Vous pouvez vous connecter pour accéder à votre espace.";
            }
        }
    }

    // 3. Vérification de l'unicité du RCCM pour les personnes morales
    if ($type_entite === 'morale' && !empty($numero_registre)) {
        $st_check_rccm = $pdo->prepare("
            SELECT id, statut FROM promoter_requests 
            WHERE LOWER(TRIM(numero_registre)) = LOWER(TRIM(?)) 
              AND statut IN ('en_attente', 'approuve')
              $user_filter
            LIMIT 1
        ");
        $st_check_rccm->execute([$numero_registre]);
        $existing_rccm = $st_check_rccm->fetch();
        if ($existing_rccm) {
            $errors[] = "Le N° de registre (RCCM / ID Fiscal : « " . htmlspecialchars($numero_registre) . " ») est déjà enregistré pour une autre structure.";
        }
    }

    // Gestion des téléversements de fichiers sécurisés & hachés cryptographiquement
    $upload_dir = __DIR__ . '/../uploads/ids/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0775, true);
    }
    $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_file_size = 5 * 1024 * 1024; // 5 Mo

    // 1. Pièce d'identité (obligatoire)
    $piece_id_filename = 'default.jpg';
    if (isset($_FILES['piece_identite']) && $_FILES['piece_identite']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['piece_identite']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['piece_identite']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = "Format de la pièce d'identité non accepté (JPG, PNG ou PDF requis).";
        } elseif ($_FILES['piece_identite']['size'] > $max_file_size) {
            $errors[] = "La pièce d'identité dépasse la limite autorisée de 5 Mo.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file_tmp);
            if (!in_array($mime, $allowed_mimes, true)) {
                $errors[] = "Le fichier de la pièce d'identité n'est pas un format valide (JPG, PNG ou PDF).";
            } else {
                // Nom de fichier chiffré / haché cryptographiquement (SHA-256)
                $secure_seed = bin2hex(random_bytes(24)) . microtime(true) . $email;
                $piece_id_filename = 'id_' . hash('sha256', $secure_seed) . '.' . $ext;
                if (!move_uploaded_file($file_tmp, $upload_dir . $piece_id_filename)) {
                    $errors[] = "Erreur lors de l'enregistrement sécurisé de votre pièce d'identité.";
                }
            }
        }
    } else {
        $errors[] = "La pièce d'identité est obligatoire pour l'examen d'éligibilité.";
    }

    // 2. Pièce Entreprise / Registre (pour Personne Morale)
    $piece_entreprise_filename = null;
    if ($type_entite === 'morale') {
        if (isset($_FILES['piece_entreprise']) && $_FILES['piece_entreprise']['error'] === UPLOAD_ERR_OK) {
            $file_ent_tmp = $_FILES['piece_entreprise']['tmp_name'];
            $ext_ent = strtolower(pathinfo($_FILES['piece_entreprise']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext_ent, $allowed_ext, true)) {
                $errors[] = "Format du document d'entreprise non accepté (JPG, PNG ou PDF requis).";
            } elseif ($_FILES['piece_entreprise']['size'] > $max_file_size) {
                $errors[] = "Le document de l'entreprise dépasse la limite autorisée de 5 Mo.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_ent = $finfo->file($file_ent_tmp);
                if (!in_array($mime_ent, $allowed_mimes, true)) {
                    $errors[] = "Le document d'entreprise n'est pas un document valide (JPG, PNG ou PDF).";
                } else {
                    $secure_ent_seed = bin2hex(random_bytes(24)) . microtime(true) . ($numero_registre ?: 'ent');
                    $piece_entreprise_filename = 'doc_ent_' . hash('sha256', $secure_ent_seed) . '.' . $ext_ent;
                    if (!move_uploaded_file($file_ent_tmp, $upload_dir . $piece_entreprise_filename)) {
                        $errors[] = "Erreur lors de l'enregistrement sécurisé du document de l'entreprise.";
                    }
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
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $st_nu = $pdo->prepare("
                    INSERT INTO users (nom, prenom, email, telephone, password, role, statut, est_verifie) 
                    VALUES (?, ?, ?, ?, ?, 'promoteur', 'en_attente', 0)
                ");
                $st_nu->execute([$nom, $prenom, $email, $telephone, $pass_hash]);
                $user_id = (int) $pdo->lastInsertId();
            }

            // Valeur d'activité enregistrée (si "Autre", inclure la précision saisie)
            $activite_enregistree = ($activite === "Autre type d'événement" && !empty($activite_autre)) ? "Autre : " . $activite_autre : $activite;

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
                $activite_enregistree,
                $experience,
                $volume_estime,
                $piece_id_filename,
                $piece_entreprise_filename,
                $description,
                $reseaux_sociaux,
                $autres_infos
            ]);
            $request_id = (int) $pdo->lastInsertId();

            // Enregistrement / mise à jour du profil promoteur (100% compatible PostgreSQL & MySQL)
            $nom_commercial = ($type_entite === 'morale' && !empty($raison_sociale)) ? $raison_sociale : $nom_complet;
            $st_chk_prom = $pdo->prepare("SELECT id FROM promoters WHERE user_id = ?");
            $st_chk_prom->execute([$user_id]);
            $promoter_exists = $st_chk_prom->fetch();

            if ($promoter_exists) {
                $st_p = $pdo->prepare("
                    UPDATE promoters SET 
                        type_entite = ?,
                        nom_commercial = ?,
                        numero_registre = ?,
                        representant_legal = ?,
                        telephone_contact = ?,
                        email_contact = ?,
                        ville = ?,
                        statut = 'en_attente'
                    WHERE user_id = ?
                ");
                $st_p->execute([
                    $type_entite,
                    $nom_commercial,
                    $numero_registre,
                    $representant_legal,
                    $telephone,
                    $email,
                    $ville,
                    $user_id
                ]);
            } else {
                $st_p = $pdo->prepare("
                    INSERT INTO promoters (user_id, type_entite, nom_commercial, numero_registre, representant_legal, telephone_contact, email_contact, ville, statut, solde)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'en_attente', 0.00)
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
            }

            $pdo->commit();

            // Envoi de l'e-mail officiel d'accusé de réception
            sendPromoterRegistrationEmail($email, $nom_complet, $activite_enregistree);
            logActivity('demande_promoteur', 'user', $user_id, "Dossier d'éligibilité promoteur ($type_entite) soumis par $nom_complet ($email)", $user_id);

            $msg_type = "success";
            $message = "Votre dossier d'éligibilité promoteur a été soumis avec succès ! Notre équipe de conformité examine actuellement vos informations. Vous recevrez un e-mail de confirmation dès que votre compte sera activé par l'administrateur.";

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() == 23505 || $e->getCode() == 23000 || str_contains($e->getMessage(), 'duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                $message = "Une donnée transmise (adresse email, numéro de téléphone ou identifiant de registre) existe déjà dans notre système.";
            } else {
                $message = "Erreur lors de l'enregistrement de votre dossier : " . $e->getMessage();
            }
            $msg_type = "error";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $e->getMessage();
            $msg_type = "error";
        }
    }
}

// Support WhatsApp pour l'accompagnement des candidats promoteurs
$whatsapp_support_phone = getenv('WHATSAPP_SUPPORT') ?: '2250596569054';
$wa_phone_clean = preg_replace('/[^0-9]/', '', $whatsapp_support_phone);
$wa_help_msg = "Bonjour l'équipe Tike WA, je prépare ma demande pour devenir promoteur d'événements et j'aimerais être guidé(e) pour remplir mon dossier.";
$wa_support_url = "https://api.whatsapp.com/send?phone=" . $wa_phone_clean . "&text=" . urlencode($wa_help_msg);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devenir Promoteur Partenaire - Tike WA</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS & Tike WA Brand -->
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

        .btn-submit-promo:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .link-wa-inline {
            color: #16A34A !important;
            font-weight: 700;
            text-decoration: underline;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 4px;
            transition: color 0.2s ease;
        }

        .link-wa-inline:hover {
            color: #15803D !important;
        }

        /* Bannière d'indication des champs obligatoires sans astérisques */
        .required-fields-banner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-left: 4px solid var(--eventia-amber, #FF4A0D);
            border-radius: 8px;
            padding: 0.85rem 1.15rem;
            margin-bottom: 1.75rem;
            font-size: 0.86rem;
            color: #475569;
            line-height: 1.45;
        }

        .required-fields-banner i {
            color: var(--eventia-amber, #FF4A0D);
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        /* Indicateur de confirmation du mot de passe */
        .pwd-feedback {
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
            transition: all 0.2s ease;
        }
        .pwd-feedback.match {
            color: #16A34A;
        }
        .pwd-feedback.mismatch {
            color: #DC2626;
        }

        /* Feedback interactif pour les fichiers téléversés */
        .file-feedback-box {
            display: none;
            margin-top: 0.65rem;
            padding: 0.6rem 0.85rem;
            border-radius: 6px;
            font-size: 0.82rem;
            line-height: 1.4;
            align-items: center;
            gap: 8px;
        }
        .file-feedback-box.valid {
            display: flex;
            background: #F0FDF4;
            border: 1px solid #BBF7D0;
            color: #166534;
        }
        .file-feedback-box.invalid {
            display: flex;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #991B1B;
        }
        .file-feedback-name {
            font-weight: 600;
            word-break: break-all;
            flex: 1;
        }
        .file-feedback-size {
            background: rgba(0,0,0,0.06);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-family: 'Space Mono', monospace;
            white-space: nowrap;
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

        /* Assistance & Guide WhatsApp pour les candidats promoteurs */
        .promo-whatsapp-guide {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
            border: 1px solid #86EFAC;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.08);
        }

        .promo-whatsapp-guide .pwa-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #25D366;
            color: #ffffff;
            display: grid;
            place-items: center;
            font-size: 1.6rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
        }

        .promo-whatsapp-guide .pwa-content {
            flex: 1;
        }

        .promo-whatsapp-guide .pwa-title {
            font-family: var(--font-heading, 'Outfit', sans-serif);
            font-size: 1.05rem;
            font-weight: 700;
            color: #14532D;
            margin-bottom: 0.25rem;
        }

        .promo-whatsapp-guide .pwa-text {
            font-size: 0.85rem;
            color: #166534;
            line-height: 1.45;
            margin: 0;
        }

        .promo-whatsapp-guide .pwa-action {
            flex-shrink: 0;
        }

        .btn-whatsapp-guide {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #25D366;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 0.88rem;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            box-shadow: 0 3px 10px rgba(37, 211, 102, 0.3);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-whatsapp-guide:hover {
            background: #128C7E;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(18, 140, 126, 0.4);
            color: #ffffff !important;
        }

        @media (max-width: 680px) {
            .promo-whatsapp-guide {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem 1.15rem;
            }

            .promo-whatsapp-guide .pwa-action {
                width: 100%;
            }

            .btn-whatsapp-guide {
                width: 100%;
                justify-content: center;
            }
        }

        /* Bouton flottant persistant WhatsApp */
        .floating-wa-help {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999;
            background: #25D366;
            color: #ffffff !important;
            border-radius: 50px;
            padding: 0.75rem 1.25rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .floating-wa-help i {
            font-size: 1.35rem;
        }

        .floating-wa-help:hover {
            background: #128C7E;
            transform: translateY(-3px) scale(1.03);
            box-shadow: 0 10px 25px rgba(18, 140, 126, 0.5);
            color: #ffffff !important;
        }

        @media (max-width: 540px) {
            .floating-wa-help {
                bottom: 16px;
                right: 16px;
                padding: 0.7rem;
                border-radius: 50%;
                width: 48px;
                height: 48px;
                justify-content: center;
            }

            .floating-wa-help .floating-wa-label {
                display: none;
            }
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
        <h1>Devenez Promoteur Officiel sur Tike WA</h1>
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
                        <div style="margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
                            <a href="../connexion.php"
                                style="background: #000000; color: #ffffff; padding: 0.5rem 1.25rem; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 0.85rem; display: inline-block;">
                                Aller à la page de connexion
                            </a>
                            <a href="<?php echo htmlspecialchars($wa_support_url); ?>" target="_blank" rel="noopener noreferrer"
                                style="background: #25D366; color: #ffffff; padding: 0.5rem 1.25rem; border-radius: 6px; text-decoration: none; font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-brands fa-whatsapp"></i> Suivre mon dossier sur WhatsApp
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- ===== BANDEAU D'ASSISTANCE WHATSAPP POUR LE PROMOTEUR ===== -->
            <div class="promo-whatsapp-guide">
                <div class="pwa-icon">
                    <i class="fa-brands fa-whatsapp"></i>
                </div>
                <div class="pwa-content">
                    <div class="pwa-title">Besoin d'aide pour constituer votre dossier ?</div>
                    <p class="pwa-text">
                        Vous avez des doutes sur le statut (particulier ou entreprise), les documents à fournir ou le fonctionnement de la billetterie ? Un conseiller Tike WA vous répond et vous guide directement sur WhatsApp.
                    </p>
                </div>
                <div class="pwa-action">
                    <a href="<?php echo htmlspecialchars($wa_support_url); ?>" target="_blank" rel="noopener noreferrer" class="btn-whatsapp-guide">
                        <i class="fa-brands fa-whatsapp"></i>
                        <span>Être guidé sur WhatsApp</span>
                    </a>
                </div>
            </div>

            <?php if ($msg_type !== 'success'): ?>
                <form method="POST" action="devenir-promoteur.php" enctype="multipart/form-data" id="promoterForm" novalidate>

                    <!-- Bannière d'indication des champs obligatoires (sans astérisques) -->
                    <div class="required-fields-banner">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Tous les champs de ce dossier sont obligatoires pour l'examen de conformité, sauf ceux explicitement marqués comme <em>(Optionnel)</em>.</span>
                    </div>

                    <!-- 1. SÉLECTION DU STATUT JURIDIQUE -->
                    <div class="section-title">
                        <i class="fa-solid fa-scale-balanced" style="color: var(--primary, #000000);"></i> 1. Forme Juridique de l'Organisateur
                    </div>

                    <div class="entity-selector">
                        <label class="entity-option <?php echo ($type_entite ?? 'physique') === 'physique' ? 'active' : ''; ?>" id="opt_physique">
                            <input type="radio" name="type_entite" value="physique" <?php echo ($type_entite ?? 'physique') === 'physique' ? 'checked' : ''; ?>
                                onchange="handleEntityTypeChange('physique')">
                            <div>
                                <strong>Personne Physique</strong>
                                <span>Particulier, Organisateur individuel, Créateur ou Promoteur indépendant.</span>
                            </div>
                        </label>

                        <label class="entity-option <?php echo ($type_entite ?? '') === 'morale' ? 'active' : ''; ?>" id="opt_morale">
                            <input type="radio" name="type_entite" value="morale" <?php echo ($type_entite ?? '') === 'morale' ? 'checked' : ''; ?>
                                onchange="handleEntityTypeChange('morale')">
                            <div>
                                <strong>Personne Morale</strong>
                                <span>Entreprise enregistrée, Agence événementielle, Société SARL/SAS, Association déclarée.</span>
                            </div>
                        </label>
                    </div>

                    <!-- 2. BLOC SPÉCIFIQUE ENTREPRISE / ASSOCIATION (PERSONNE MORALE) -->
                    <div id="morale_fields"
                        style="display: <?php echo ($type_entite ?? '') === 'morale' ? 'block' : 'none'; ?>; background: #F5F5F5; border: 1px solid var(--line, #E5E5E5); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                        <div
                            style="font-weight: 600; color: var(--navy, #000000); font-size: 0.95rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-building" style="color: var(--primary, #000000);"></i> Informations Légales de la Structure
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
                                <label for="representant_nom">Nom du Représentant Légal</label>
                                <input type="text" id="representant_nom" name="representant_nom" placeholder="Ex: Koffi"
                                    value="<?php echo htmlspecialchars($_POST['representant_nom'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="representant_prenom">Prénom du Représentant Légal</label>
                                <input type="text" id="representant_prenom" name="representant_prenom"
                                    placeholder="Ex: Marc (Gérant / Dirigeant)"
                                    value="<?php echo htmlspecialchars($_POST['representant_prenom'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="piece_entreprise">Document d'Enregistrement (DFE, RCCM ou Déclaration)</label>
                            <div class="upload-field-box">
                                <input type="file" id="piece_entreprise" name="piece_entreprise"
                                    accept=".jpg,.jpeg,.png,.pdf" onchange="handleFileSelected(this, 'feedback_piece_entreprise')">
                                <div id="feedback_piece_entreprise" class="file-feedback-box"></div>
                                <small style="color: var(--muted); display: block; margin-top: 4px;">Formats acceptés : PDF, JPG, PNG (Max 5 Mo)</small>
                            </div>
                        </div>
                    </div>

                    <!-- 3. COORDONNÉES & CONTACT PRINCIPAL -->
                    <div class="section-title">
                        <i class="fa-solid fa-id-card" style="color: var(--primary, #000000);"></i> 2. Coordonnées & Responsable du Compte
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="nom"><span id="lbl_nom"><?php echo ($type_entite ?? '') === 'morale' ? 'Nom du déclarant' : 'Nom'; ?></span></label>
                            <input type="text" id="nom" name="nom" required placeholder="Ex: Bédié"
                                value="<?php echo htmlspecialchars($_POST['nom'] ?? $default_nom); ?>">
                        </div>

                        <div class="form-group">
                            <label for="prenom"><span id="lbl_prenom"><?php echo ($type_entite ?? '') === 'morale' ? 'Prénom du déclarant' : 'Prénom(s)'; ?></span></label>
                            <input type="text" id="prenom" name="prenom" required placeholder="Ex: Jean-Eudes"
                                value="<?php echo htmlspecialchars($_POST['prenom'] ?? $default_prenom); ?>">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="email">Adresse Email professionnelle</label>
                            <input type="email" id="email" name="email" required placeholder="contact@votre-structure.ci"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? $default_email); ?>">
                            <small style="color: var(--muted); font-size: 0.78rem;">Identifiant officiel de connexion.</small>
                        </div>

                        <div class="form-group">
                            <label for="telephone">Numéro de Téléphone (Mobile Money & WhatsApp)</label>
                            <input type="tel" id="telephone" name="telephone" required placeholder="Ex: +225 07 00 00 00 00"
                                value="<?php echo htmlspecialchars($_POST['telephone'] ?? $default_tel); ?>">
                            <small style="color: var(--muted); font-size: 0.78rem;">Utilisé pour les notifications et reversements.</small>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="ville">Ville & Pays de résidence</label>
                            <input type="text" id="ville" name="ville" required placeholder="Ex: Abidjan, Côte d'Ivoire"
                                value="<?php echo htmlspecialchars($_POST['ville'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="piece_identite"><span id="lbl_piece_id">Pièce d'identité du déclarant (CNI / Passeport)</span></label>
                            <div class="upload-field-box">
                                <input type="file" id="piece_identite" name="piece_identite" accept=".jpg,.jpeg,.png,.pdf" required
                                    onchange="handleFileSelected(this, 'feedback_piece_identite')">
                                <div id="feedback_piece_identite" class="file-feedback-box"></div>
                                <small style="color: var(--muted); display: block; margin-top: 4px;">PDF, JPG, PNG (Max 5 Mo)</small>
                            </div>
                        </div>
                    </div>

                    <?php if (!$is_logged_in): ?>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="password"><i class="fa-solid fa-lock"></i> Définir votre Mot de passe de connexion</label>
                                <div style="position: relative; width: 100%;">
                                    <input type="password" id="password" name="password" required minlength="6" placeholder="Minimum 6 caractères"
                                        style="width: 100%; box-sizing: border-box; padding-right: 2.5rem;" autocomplete="new-password">
                                    <button type="button" onclick="togglePassVisibility('password', this.querySelector('i'))" aria-label="Afficher le mot de passe"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted, #737373); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <small style="color: var(--muted); font-size: 0.78rem;">6 caractères minimum.</small>
                            </div>

                            <div class="form-group">
                                <label for="password_confirm"><i class="fa-solid fa-lock"></i> Confirmer votre Mot de passe</label>
                                <div style="position: relative; width: 100%;">
                                    <input type="password" id="password_confirm" name="password_confirm" required minlength="6" placeholder="Répétez le mot de passe"
                                        style="width: 100%; box-sizing: border-box; padding-right: 2.5rem;" autocomplete="new-password">
                                    <button type="button" onclick="togglePassVisibility('password_confirm', this.querySelector('i'))" aria-label="Afficher le mot de passe"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted, #737373); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div id="pwd_match_feedback" style="min-height: 20px;"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 4. EXPÉRIENCE & ÉLIGIBILITÉ ÉVÉNEMENTIELLE -->
                    <div class="section-separator"></div>
                    <div class="section-title">
                        <i class="fa-solid fa-chart-line" style="color: var(--primary, #000000);"></i> 3. Éligibilité & Profil Événementiel
                    </div>

                    <div class="form-row-2">
                        <!-- LISTE DÉROULANTE : TYPE D'ÉVÉNEMENT -->
                        <div class="form-group">
                            <label for="activite">Type d'événement principal</label>
                            <select id="activite" name="activite" required onchange="handleActiviteChange(this.value)">
                                <option value="" disabled <?php echo empty($_POST['activite']) ? 'selected' : ''; ?>>Sélectionnez votre type d'événement...</option>
                                <?php
                                $types_events = [
                                    "Concerts & Spectacles Musicaux",
                                    "Festivals & Grands Rassemblements",
                                    "Soirées, Galas & Événements VIP",
                                    "Conférences, Forums & Séminaires Pro",
                                    "Événements Sportifs & Tournois",
                                    "Spectacles d'Humour & Théâtre",
                                    "Formations, Ateliers & Masterclass",
                                    "Foires, Salons & Expositions",
                                    "Événements Religieux & Cultes",
                                    "Mariages & Célébrations Privées",
                                    "Événements Caritatifs & ONG",
                                    "Autre type d'événement"
                                ];
                                foreach ($types_events as $te):
                                    $sel = (isset($_POST['activite']) && $_POST['activite'] === $te) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($te); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($te); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Champ de précision obligatoire lorsque 'Autre type d'événement' est choisi -->
                            <div id="activite_autre_box" style="display: <?php echo (isset($_POST['activite']) && $_POST['activite'] === "Autre type d'événement") ? 'block' : 'none'; ?>; margin-top: 0.85rem; background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 8px; padding: 0.85rem 1rem;">
                                <label for="activite_autre" style="color: #0F172A; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; margin-bottom: 0.35rem;">
                                    <i class="fa-solid fa-pen-to-square" style="color: var(--eventia-amber, #FF4A0D);"></i> Précisez votre type d'événement
                                </label>
                                <input type="text" id="activite_autre" name="activite_autre" placeholder="Ex: Salon littéraire, E-sport, Kermesse..."
                                    value="<?php echo htmlspecialchars($_POST['activite_autre'] ?? ''); ?>"
                                    <?php echo (isset($_POST['activite']) && $_POST['activite'] === "Autre type d'événement") ? 'required' : ''; ?>>
                                <small style="color: var(--muted); font-size: 0.76rem; display: block; margin-top: 4px;">Indiquez la spécificité de vos manifestations.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="volume_estime">Volume annuel estimé de billets</label>
                            <select id="volume_estime" name="volume_estime">
                                <?php
                                $volumes = [
                                    "Moins de 500 billets/an" => "Moins de 500 billets / an",
                                    "500 à 2 000 billets/an" => "500 à 2 000 billets / an",
                                    "2 000 à 10 000 billets/an" => "2 000 à 10 000 billets / an",
                                    "Plus de 10 000 billets/an" => "Plus de 10 000 billets / an (Gros festivals)"
                                ];
                                $cur_vol = $_POST['volume_estime'] ?? '500 à 2 000 billets/an';
                                foreach ($volumes as $v_val => $v_lbl):
                                    $sel_vol = ($cur_vol === $v_val) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($v_val); ?>" <?php echo $sel_vol; ?>><?php echo htmlspecialchars($v_lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- LISTE DÉROULANTE : EXPÉRIENCE DANS LE SECTEUR -->
                    <div class="form-group">
                        <label for="experience">Expérience dans le secteur événementiel</label>
                        <select id="experience" name="experience" required>
                            <option value="" disabled <?php echo empty($_POST['experience']) ? 'selected' : ''; ?>>Sélectionnez votre niveau d'expérience...</option>
                            <?php
                            $exp_levels = [
                                "Moins d'un an (Nouveau promoteur / Lancement)" => "Moins d'un an (Nouveau promoteur / Premier événement)",
                                "1 à 2 ans (Quelques événements organisés)" => "1 à 2 ans (Quelques événements organisés)",
                                "3 à 5 ans (Promoteur régulier et expérimenté)" => "3 à 5 ans (Promoteur régulier et expérimenté)",
                                "5 à 10 ans (Expérience solide & grands événements)" => "5 à 10 ans (Expérience solide & grands événements)",
                                "Plus de 10 ans (Acteur historique de l'événementiel)" => "Plus de 10 ans (Acteur historique de l'événementiel)"
                            ];
                            $cur_exp = $_POST['experience'] ?? '';
                            foreach ($exp_levels as $el_val => $el_lbl):
                                $sel_exp = ($cur_exp === $el_val) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($el_val); ?>" <?php echo $sel_exp; ?>><?php echo htmlspecialchars($el_lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- DESCRIPTION DE VOS ACTIVITÉS -->
                    <div class="form-group">
                        <label for="description">Description de vos activités</label>
                        <textarea id="description" name="description" rows="4" required
                            placeholder="Décrivez vos activités principales, votre positionnement, vos types d'événements, votre public et vos projets..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        <small style="color: var(--muted); font-size: 0.78rem;">Présentez vos réalisations et la nature des événements que vous comptez commercialiser sur Tike WA.</small>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="reseaux_sociaux">Liens Réseaux Sociaux / Site Web <em>(Optionnel)</em></label>
                            <input type="text" id="reseaux_sociaux" name="reseaux_sociaux"
                                placeholder="Ex: https://instagram.com/monagence"
                                value="<?php echo htmlspecialchars($_POST['reseaux_sociaux'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="autres_infos">Informations complémentaires <em>(Optionnel)</em></label>
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
                        définitive par l'administration de Tike WA.
                    </div>

                    <button type="submit" class="btn-submit-promo" id="btnSubmitPromo">
                        <i class="fa-solid fa-paper-plane" id="btnSubmitIcon"></i>
                        <span id="btnSubmitText">Soumettre ma Demande d'Éligibilité</span>
                    </button>

                    <!-- Mention WhatsApp sous le bouton d'envoi -->
                    <div style="text-align: center; margin-top: 1.15rem;">
                        <span style="font-size: 0.85rem; color: var(--muted, #737373);">
                            Un doute avant d'envoyer votre dossier ?
                            <a href="<?php echo htmlspecialchars($wa_support_url); ?>" target="_blank" rel="noopener noreferrer" class="link-wa-inline">
                                <i class="fa-brands fa-whatsapp"></i> Discutez avec un conseiller sur WhatsApp
                            </a>
                        </span>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </main>

    <script>
        // Gestion dynamique du basculement d'entité (Physique vs Morale)
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
                if (optMorale) optMorale.classList.add('active');
                if (optPhysique) optPhysique.classList.remove('active');
                if (moraleFields) moraleFields.style.display = 'block';
                if (pieceEnt) pieceEnt.required = true;
                if (raisonSociale) raisonSociale.required = true;
                if (numRegistre) numRegistre.required = true;
                if (repNom) repNom.required = true;
                if (repPrenom) repPrenom.required = true;
                if (document.getElementById('lbl_nom')) document.getElementById('lbl_nom').textContent = "Nom du déclarant";
                if (document.getElementById('lbl_prenom')) document.getElementById('lbl_prenom').textContent = "Prénom du déclarant";
            } else {
                if (optPhysique) optPhysique.classList.add('active');
                if (optMorale) optMorale.classList.remove('active');
                if (moraleFields) moraleFields.style.display = 'none';
                if (pieceEnt) pieceEnt.required = false;
                if (raisonSociale) raisonSociale.required = false;
                if (numRegistre) numRegistre.required = false;
                if (repNom) repNom.required = false;
                if (repPrenom) repPrenom.required = false;
                if (document.getElementById('lbl_nom')) document.getElementById('lbl_nom').textContent = "Nom";
                if (document.getElementById('lbl_prenom')) document.getElementById('lbl_prenom').textContent = "Prénom(s)";
            }
        }

        // Affichage dynamique et contrôle obligatoire du champ 'Autre type d'événement'
        function handleActiviteChange(val) {
            const autreBox = document.getElementById('activite_autre_box');
            const autreInput = document.getElementById('activite_autre');
            if (!autreBox || !autreInput) return;

            if (val === "Autre type d'événement") {
                autreBox.style.display = 'block';
                autreInput.required = true;
                autreInput.focus();
            } else {
                autreBox.style.display = 'none';
                autreInput.required = false;
            }
        }

        // Basculer l'affichage du mot de passe
        function togglePassVisibility(inputId, iconElem) {
            const inp = document.getElementById(inputId);
            if (!inp) return;
            if (inp.type === 'password') {
                inp.type = 'text';
                if (iconElem) {
                    iconElem.classList.remove('fa-eye');
                    iconElem.classList.add('fa-eye-slash');
                }
            } else {
                inp.type = 'password';
                if (iconElem) {
                    iconElem.classList.remove('fa-eye-slash');
                    iconElem.classList.add('fa-eye');
                }
            }
        }

        // Communication Homme-Machine : Vérification interactive de la concordance des mots de passe
        const pwdInput = document.getElementById('password');
        const pwdConfInput = document.getElementById('password_confirm');
        const pwdFeedback = document.getElementById('pwd_match_feedback');

        function checkPasswordMatch() {
            if (!pwdInput || !pwdConfInput || !pwdFeedback) return;
            const p1 = pwdInput.value;
            const p2 = pwdConfInput.value;

            if (p2.length === 0) {
                pwdFeedback.innerHTML = '';
                pwdFeedback.className = '';
                return;
            }

            if (p1 === p2) {
                pwdFeedback.className = 'pwd-feedback match';
                pwdFeedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> Les mots de passe correspondent parfaitement';
            } else {
                pwdFeedback.className = 'pwd-feedback mismatch';
                pwdFeedback.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Les mots de passe ne correspondent pas encore';
            }
        }

        if (pwdInput && pwdConfInput) {
            pwdInput.addEventListener('input', checkPasswordMatch);
            pwdConfInput.addEventListener('input', checkPasswordMatch);
        }

        // Communication Homme-Machine : Feedback interactif sur les fichiers téléversés
        function handleFileSelected(inputElem, feedbackId) {
            const feedbackElem = document.getElementById(feedbackId);
            if (!feedbackElem) return;

            if (!inputElem.files || inputElem.files.length === 0) {
                feedbackElem.className = 'file-feedback-box';
                feedbackElem.innerHTML = '';
                feedbackElem.style.display = 'none';
                return;
            }

            const file = inputElem.files[0];
            const maxBytes = 5 * 1024 * 1024; // 5 Mo
            const allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
            const fileExt = file.name.split('.').pop().toLowerCase();

            // Formatage lisible de la taille
            let sizeFormatted = '';
            if (file.size < 1024 * 1024) {
                sizeFormatted = (file.size / 1024).toFixed(1) + ' Ko';
            } else {
                sizeFormatted = (file.size / (1024 * 1024)).toFixed(2) + ' Mo';
            }

            if (!allowedExts.includes(fileExt)) {
                feedbackElem.className = 'file-feedback-box invalid';
                feedbackElem.innerHTML = `
                    <i class="fa-solid fa-circle-xmark"></i>
                    <span class="file-feedback-name">${escapeHtml(file.name)}</span>
                    <span class="file-feedback-size">${sizeFormatted}</span>
                    <span>- Format non accepté (JPG, PNG ou PDF uniquement)</span>
                `;
                inputElem.value = '';
            } else if (file.size > maxBytes) {
                feedbackElem.className = 'file-feedback-box invalid';
                feedbackElem.innerHTML = `
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span class="file-feedback-name">${escapeHtml(file.name)}</span>
                    <span class="file-feedback-size">${sizeFormatted}</span>
                    <span>- Fichier trop volumineux (Max 5 Mo)</span>
                `;
                inputElem.value = '';
            } else {
                feedbackElem.className = 'file-feedback-box valid';
                feedbackElem.innerHTML = `
                    <i class="fa-solid fa-circle-check"></i>
                    <span class="file-feedback-name">${escapeHtml(file.name)}</span>
                    <span class="file-feedback-size">${sizeFormatted}</span>
                    <span>(Document prêt pour analyse)</span>
                `;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Communication Homme-Machine : Prévention du double-envoi et état de chargement
        const formPromo = document.getElementById('promoterForm');
        if (formPromo) {
            formPromo.addEventListener('submit', function(e) {
                // Contrôle côté client du mot de passe
                if (pwdInput && pwdConfInput) {
                    if (pwdInput.value.length < 6) {
                        e.preventDefault();
                        alert("Le mot de passe doit comporter au moins 6 caractères.");
                        pwdInput.focus();
                        return;
                    }
                    if (pwdInput.value !== pwdConfInput.value) {
                        e.preventDefault();
                        alert("La confirmation du mot de passe ne correspond pas au mot de passe saisi.");
                        pwdConfInput.focus();
                        return;
                    }
                }

                // Contrôle côté client pour "Autre type d'événement"
                const actSelect = document.getElementById('activite');
                const actAutreInput = document.getElementById('activite_autre');
                if (actSelect && actSelect.value === "Autre type d'événement" && actAutreInput && !actAutreInput.value.trim()) {
                    e.preventDefault();
                    alert("Vous avez sélectionné « Autre type d'événement ». Veuillez préciser la nature de vos activités dans le champ dédié.");
                    actAutreInput.focus();
                    return;
                }

                const btn = document.getElementById('btnSubmitPromo');
                const icon = document.getElementById('btnSubmitIcon');
                const txt = document.getElementById('btnSubmitText');

                if (btn) {
                    btn.disabled = true;
                    if (icon) {
                        icon.className = 'fa-solid fa-circle-notch fa-spin';
                    }
                    if (txt) {
                        txt.textContent = 'Transmission de votre dossier en cours...';
                    }
                }
            });
        }

        // Auto-scroll vers le message d'alerte s'il existe
        window.addEventListener('DOMContentLoaded', function() {
            const alertBox = document.querySelector('.alert-box');
            if (alertBox) {
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>

    <!-- Bouton flottant persistant d'assistance WhatsApp -->
    <a href="<?php echo htmlspecialchars($wa_support_url); ?>" target="_blank" rel="noopener noreferrer" class="floating-wa-help" title="Besoin d'aide ? Écrivez-nous sur WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
        <span class="floating-wa-label">Aide WhatsApp</span>
    </a>
</body>

</html>