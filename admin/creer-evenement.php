<?php
// ==============================================================================
// CENTRE DE CRÉATION MULTI-SERVICES ADMIN (admin/creer-evenement.php)
// Hub unifié d'administration : Événement & Billetterie, Cotisation & Cagnottes, Votes & Concours
// Publication directe et mise en ligne immédiate
// ==============================================================================

$admin_page_title = "Création Multi-Services - Administration";
include 'header.php';

$message = "";
$msg_type = "";
$created_id = null;

// Onglet actif (evenement | cotisation | vote)
$onglet = $_GET['onglet'] ?? 'evenement';
if (!in_array($onglet, ['evenement', 'cotisation', 'vote'], true)) {
    $onglet = 'evenement';
}

// Récupération des promoteurs enregistrés pour attribution optionnelle
$db_promoters = [];
try {
    $db_promoters = $pdo->query("
        SELECT p.id AS promoter_id, p.user_id, p.nom_commercial, u.nom, u.prenom, u.email 
        FROM promoters p 
        JOIN users u ON p.user_id = u.id 
        ORDER BY p.nom_commercial ASC, u.nom ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $db_promoters = [];
}

// Récupération des salles actives pour le filtrage dynamique par ville
$db_salles = [];
try {
    $db_salles = $pdo->query("SELECT id, nom, ville, commune, capacite, type_salle FROM salles WHERE statut = 'active' ORDER BY ville ASC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $db_salles = [];
}

// Dossiers d'uploads
$upload_events = '../uploads/events/';
$upload_cands  = '../uploads/candidats/';
if (!is_dir($upload_events)) mkdir($upload_events, 0777, true);
if (!is_dir($upload_cands))  mkdir($upload_cands, 0777, true);

// ==============================================================================
// TRAITEMENT DES SOUMISSIONS POST
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Détermination du propriétaire (Admin ou Promoteur assigné)
    $owner_user_id = (int)($_POST['organisateur_user_id'] ?? $_SESSION['user_id']);
    if ($owner_user_id <= 0) $owner_user_id = (int)$_SESSION['user_id'];

    // --------------------------------------------------------------------------
    // ACTION 1 : CRÉATION D'UN ÉVÉNEMENT & BILLETTERIE
    // --------------------------------------------------------------------------
    if ($action === 'creer_evenement' || (!isset($_POST['action']) && isset($_POST['nom']) && !isset($_POST['vote_question']))) {
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Catégorie
        $categorie = trim($_POST['categorie'] ?? 'Concert');
        $categorie_custom = trim($_POST['categorie_custom'] ?? '');
        if ($categorie === 'Autre' && !empty($categorie_custom)) {
            $categorie = $categorie_custom;
        }
        if (empty($categorie)) $categorie = 'Concert';

        $date_evenement = $_POST['date_evenement'] ?? '';
        $heure = $_POST['heure'] ?? '20:00';

        // Localisation : Ville + Salle répertoriée vs Espace non répertorié
        $ville = trim($_POST['ville'] ?? 'Abidjan');
        $ville_custom = trim($_POST['ville_custom'] ?? '');
        if ($ville === 'Autre' && !empty($ville_custom)) {
            $ville = $ville_custom;
        }

        $salle_connue = $_POST['salle_connue'] ?? 'oui';
        $salle_id = null;

        if ($salle_connue === 'non') {
            // Espace non répertorié : pas de vue 3D de scène
            $statut_salle = trim($_POST['statut_salle_inconnue'] ?? 'Espace libre');
            $espace_nom = trim($_POST['espace_nom_custom'] ?? '');
            if (!empty($espace_nom)) {
                $lieu = $espace_nom . ' (' . $ville . ')';
            } else {
                $lieu = $statut_salle . ' (' . $ville . ')';
            }
        } else {
            // Salle répertoriée : modélisation 3D active
            $salle_id = !empty($_POST['salle_id']) ? (int)$_POST['salle_id'] : null;
            $salle_nom = trim($_POST['salle_nom'] ?? '');
            $salle_select = trim($_POST['salle_select'] ?? '');
            $nom_choisi = !empty($salle_nom) ? $salle_nom : (($salle_select !== 'Autre' && !empty($salle_select)) ? $salle_select : '');

            if (!empty($nom_choisi)) {
                $lieu = $nom_choisi . ', ' . $ville;
            } else {
                $lieu = !empty($_POST['lieu']) ? trim($_POST['lieu']) : $ville;
            }
        }

        $statut_initial = in_array($_POST['statut'] ?? '', ['actif', 'en_attente', 'inactif'], true) ? $_POST['statut'] : 'actif';
        $commission_rate = (float)($_POST['commission_rate'] ?? 5.0);

        // Upload de l'affiche
        $image_name = 'default.jpg';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $image_name = 'event_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image_name);
            }
        }

        // Grille de tickets
        $ticket_noms   = $_POST['ticket_nom'] ?? [];
        $ticket_prix   = $_POST['ticket_prix'] ?? [];
        $ticket_qtys   = $_POST['ticket_quantite'] ?? [];
        $ticket_choix  = $_POST['ticket_places_choisies'] ?? [];
        $ticket_frais  = $_POST['ticket_frais'] ?? [];

        if (empty($nom) || empty($date_evenement) || empty($heure) || empty($lieu)) {
            $message = "Veuillez remplir tous les champs obligatoires de l'événement (*).";
            $msg_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_ev = $pdo->prepare("
                    INSERT INTO events (
                        user_id, nom, description, categorie, image, date_evenement, heure, lieu, 
                        salle_id, statut, commission_rate, type_vote, prix_vote, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aucun', 0, NOW(), NOW())
                    RETURNING id
                ");
                $stmt_ev->execute([
                    $owner_user_id, $nom, $description, $categorie, $image_name, 
                    $date_evenement, $heure, $lieu, $salle_id, $statut_initial, $commission_rate
                ]);
                $new_event_id = $stmt_ev->fetchColumn();

                // Insertion des catégories de billets
                $inserted_tickets = 0;
                if (!empty($ticket_noms)) {
                    $stmt_tk = $pdo->prepare("
                        INSERT INTO ticket_types (event_id, nom, prix, quantite, places_choisies, frais_place, quantite_vendue, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                    ");

                    for ($i = 0; $i < count($ticket_noms); $i++) {
                        $t_nom = trim($ticket_noms[$i] ?? '');
                        $t_prix = (float)($ticket_prix[$i] ?? 0);
                        $t_qty = (int)($ticket_qtys[$i] ?? 0);
                        $t_choix = (isset($ticket_choix[$i]) && is_numeric($ticket_choix[$i])) ? max(0, min($t_qty, (int)$ticket_choix[$i])) : 0;
                        $t_frais = max(0, (float)($ticket_frais[$i] ?? 0));

                        if (!empty($t_nom) && $t_prix > 0 && $t_qty > 0) {
                            $stmt_tk->execute([$new_event_id, $t_nom, $t_prix, $t_qty, $t_choix, $t_frais]);
                            $inserted_tickets++;
                        }
                    }
                }

                $pdo->commit();
                $created_id = $new_event_id;
                $message = "L'événement « " . htmlspecialchars($nom) . " » a été créé et mis en ligne avec succès (" . $inserted_tickets . " catégorie(s) de billet(s) configurée(s)) !";
                $msg_type = "success";
                $onglet = 'evenement';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur lors de la création de l'événement : " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    // --------------------------------------------------------------------------
    // ACTION 2 : CRÉATION D'UNE CAMPAGNE DE COTISATION
    // --------------------------------------------------------------------------
    elseif ($action === 'creer_campagne_cotisation') {
        $titre = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $montant_objectif = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
        $date_limite = trim($_POST['date_limite'] ?? '');
        $statut_c = in_array($_POST['statut_campagne'] ?? '', ['active', 'en_attente'], true) ? $_POST['statut_campagne'] : 'active';

        if (empty($titre) || !$montant_objectif || $montant_objectif < 1000) {
            $message = "Veuillez indiquer un titre et un montant objectif d'au moins 1 000 FCFA pour la campagne.";
            $msg_type = "error";
            $onglet = 'cotisation';
        } else {
            $image_c = null;
            if (isset($_FILES['image_cotisation']) && $_FILES['image_cotisation']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image_cotisation']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $image_c = 'campagne_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['image_cotisation']['tmp_name'], $upload_events . $image_c);
                }
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $owner_user_id, $titre, $description ?: null, $image_c, $montant_objectif, $date_limite ?: null, $statut_c
                ]);
                $message = "La campagne de cotisation « " . htmlspecialchars($titre) . " » a été créée et mise en ligne avec succès !";
                $msg_type = "success";
                $onglet = 'cotisation';
            } catch (Exception $e) {
                $message = "Erreur lors de la création de la campagne : " . $e->getMessage();
                $msg_type = "error";
                $onglet = 'cotisation';
            }
        }
    }

    // --------------------------------------------------------------------------
    // ACTION 3 : CRÉATION D'UN CONCOURS AVEC CANDIDATS
    // --------------------------------------------------------------------------
    elseif ($action === 'proposer_concours') {
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? 'Concours officiel avec vote du public');
        $date_evenement = $_POST['date_evenement'] ?? date('Y-m-d');
        $heure = $_POST['heure'] ?? '20:00';
        $lieu = trim($_POST['lieu'] ?? 'Abidjan');
        $prix_vote = max(0, (float)($_POST['prix_vote'] ?? 0));

        $image_concours = 'default.jpg';
        if (isset($_FILES['image_concours']) && $_FILES['image_concours']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image_concours']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $image_concours = 'concours_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image_concours']['tmp_name'], $upload_events . $image_concours);
            }
        }

        $cands_nom = $_POST['cand_nom'] ?? [];
        $cands_desc = $_POST['cand_desc'] ?? [];

        if (empty($nom)) {
            $message = "Veuillez renseigner le nom du concours.";
            $msg_type = "error";
            $onglet = 'vote';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_v = $pdo->prepare("
                    INSERT INTO events (
                        user_id, nom, description, categorie, image, date_evenement, heure, lieu,
                        type_vote, prix_vote, statut, created_at, updated_at
                    ) VALUES (?, ?, ?, 'Concours', ?, ?, ?, ?, 'concours', ?, 'actif', NOW(), NOW())
                    RETURNING id
                ");
                $stmt_v->execute([$owner_user_id, $nom, $description, $image_concours, $date_evenement, $heure, $lieu, $prix_vote]);
                $event_vote_id = $stmt_v->fetchColumn();

                // Enregistrement des candidats
                $nb_cands = 0;
                if (!empty($cands_nom)) {
                    $stmt_cand = $pdo->prepare("
                        INSERT INTO event_candidats (event_id, nom, description, photo, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");

                    foreach ($cands_nom as $idx => $cnom) {
                        $cnom = trim($cnom);
                        if ($cnom === '') continue;

                        $cdesc = trim($cands_desc[$idx] ?? '');
                        $cphoto = null;

                        if (isset($_FILES['cand_photo']['name'][$idx]) && $_FILES['cand_photo']['error'][$idx] === UPLOAD_ERR_OK) {
                            $ext = strtolower(pathinfo($_FILES['cand_photo']['name'][$idx], PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                                $cphoto = 'cand_' . uniqid() . '.' . $ext;
                                move_uploaded_file($_FILES['cand_photo']['tmp_name'][$idx], $upload_cands . $cphoto);
                            }
                        }

                        $stmt_cand->execute([$event_vote_id, $cnom, $cdesc ?: null, $cphoto]);
                        $nb_cands++;
                    }
                }

                $pdo->commit();
                $message = "Le concours « " . htmlspecialchars($nom) . " » a été créé et activé avec " . $nb_cands . " candidat(s) enregistré(s) !";
                $msg_type = "success";
                $onglet = 'vote';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur lors de la création du concours : " . $e->getMessage();
                $msg_type = "error";
                $onglet = 'vote';
            }
        }
    }

    // --------------------------------------------------------------------------
    // ACTION 4 : CRÉATION D'UN VOTE DE RÉALISATION D'ÉVÉNEMENT (PLÉBISCITE)
    // --------------------------------------------------------------------------
    elseif ($action === 'proposer_vote_realisation') {
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $vote_question = trim($_POST['vote_question'] ?? '');
        $date_evenement = $_POST['date_evenement'] ?? date('Y-m-d', strtotime('+2 months'));
        $heure = $_POST['heure'] ?? '20:00';
        $lieu = trim($_POST['lieu'] ?? 'Abidjan');
        $prix_vote = max(0, (float)($_POST['prix_vote'] ?? 0));

        $image_realisation = 'default.jpg';
        if (isset($_FILES['image_realisation']) && $_FILES['image_realisation']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image_realisation']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $image_realisation = 'realisation_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image_realisation']['tmp_name'], $upload_events . $image_realisation);
            }
        }

        if (empty($nom) || empty($vote_question)) {
            $message = "Veuillez renseigner le nom du projet et la question soumise aux spectateurs.";
            $msg_type = "error";
            $onglet = 'vote';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO events (
                        user_id, nom, description, categorie, image, date_evenement, heure, lieu,
                        type_vote, vote_question, prix_vote, statut, created_at, updated_at
                    ) VALUES (?, ?, ?, 'Vote', ?, ?, ?, ?, 'realisation_evenement', ?, ?, 'actif', NOW(), NOW())
                ");
                $stmt->execute([
                    $owner_user_id, $nom, $description ?: null, $image_realisation,
                    $date_evenement, $heure, $lieu, $vote_question, $prix_vote
                ]);
                $message = "Le vote de réalisation « " . htmlspecialchars($nom) . " » a été créé et ouvert au public avec succès !";
                $msg_type = "success";
                $onglet = 'vote';
            } catch (Exception $e) {
                $message = "Erreur lors de la création du vote : " . $e->getMessage();
                $msg_type = "error";
                $onglet = 'vote';
            }
        }
    }
}
?>

<style>
    /* ==============================================================================
       STYLES DESIGN DASHBOARD PRO - HUB DE CRÉATION ADMIN (MÜLLER-BROCKMANN)
       ============================================================================== */
    .dash-container {
        padding: clamp(0.85rem, 2.5vw, 1.75rem);
        max-width: 100%;
        box-sizing: border-box;
    }

    .dash-header-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

    .dash-title-box h1 {
        font-size: clamp(1.25rem, 3.2vw, 1.75rem);
        font-weight: 800;
        margin: 0 0 0.35rem 0;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        color: var(--dash-text, #000000);
        letter-spacing: -0.02em;
    }

    .dash-title-box p {
        margin: 0;
        font-size: 0.88rem;
        color: var(--dash-muted, #737373);
    }

    /* Onglets de navigation suisse */
    .admin-creation-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        background: #ffffff;
        padding: 0.45rem;
        border-radius: 12px;
        border: 1px solid var(--dash-border, #E5E5E5);
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .admin-tab-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.65rem 1.2rem;
        border-radius: 8px;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 700;
        white-space: nowrap;
        color: #737373;
        transition: all 0.2s ease;
        border: none;
        background: transparent;
        cursor: pointer;
    }

    .admin-tab-btn:hover {
        color: #000000;
        background: #F5F5F5;
    }

    .admin-tab-btn.is-active {
        background: #000000 !important;
        color: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    }

    .admin-tab-btn.is-active i {
        color: #FF4A0D !important;
    }

    /* Cartes de contenu */
    .dash-card {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 14px;
        padding: clamp(1rem, 2.5vw, 1.75rem);
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        box-sizing: border-box;
    }

    .dash-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #F5F5F5;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .dash-step-badge {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #000000;
        color: #ffffff;
        display: grid;
        place-items: center;
        font-weight: 900;
        font-size: 0.92rem;
        flex-shrink: 0;
    }

    .dash-form-group {
        margin-bottom: 1.15rem;
    }

    .dash-form-group label {
        display: block;
        font-size: 0.82rem;
        font-weight: 700;
        margin-bottom: 4px;
        color: var(--dash-text, #000000);
    }

    .dash-form-group input[type="text"],
    .dash-form-group input[type="number"],
    .dash-form-group input[type="date"],
    .dash-form-group input[type="time"],
    .dash-form-group select,
    .dash-form-group textarea {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 8px;
        font-size: 0.88rem;
        font-family: inherit;
        color: var(--dash-text, #000000);
        background: #ffffff;
        box-sizing: border-box;
        transition: all 0.2s ease;
    }

    .dash-form-group input:focus,
    .dash-form-group select:focus,
    .dash-form-group textarea:focus {
        outline: none;
        border-color: #FF4A0D;
        box-shadow: 0 0 0 3px rgba(255, 74, 13, 0.12);
    }

    .form-row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .venue-toggle-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .venue-toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0.65rem 0.85rem;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
        min-height: 42px;
        box-sizing: border-box;
    }

    /* Grille des tarifs responsive & anti-débordement */
    #tickets-container {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .dash-ticket-row {
        background: #ffffff;
        border: 1px solid var(--dash-border, #E2E8F0);
        border-radius: 12px;
        padding: 1rem 1.15rem;
        display: grid;
        grid-template-columns: minmax(130px, 2fr) minmax(90px, 1.1fr) minmax(75px, 0.9fr) minmax(95px, 1.1fr) minmax(90px, 1fr) 38px;
        gap: 0.75rem;
        align-items: end;
        margin-bottom: 0.85rem;
        transition: all 0.15s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        box-sizing: border-box;
        width: 100%;
        max-width: 100%;
    }

    .dash-ticket-row:hover {
        border-color: #CBD5E1;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    }

    .dash-ticket-row > div {
        min-width: 0 !important;
        box-sizing: border-box;
    }

    .dash-ticket-row label {
        font-size: 0.76rem !important;
        font-weight: 700 !important;
        color: var(--dash-text, #000000);
        display: block;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dash-ticket-row input {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        padding: 0.6rem 0.7rem !important;
        font-size: 0.86rem !important;
        height: 40px !important;
        border: 1px solid var(--dash-border, #E2E8F0) !important;
        border-radius: 8px !important;
        background: #ffffff !important;
        color: var(--dash-text, #000000) !important;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .dash-ticket-row input:focus {
        border-color: #FF4A0D !important;
        box-shadow: 0 0 0 3px rgba(255, 74, 13, 0.12) !important;
    }

    .ticket-delete-btn-box {
        display: flex;
        align-items: flex-end;
        justify-content: center;
        min-width: 38px !important;
    }

    .ticket-delete-btn-box button {
        background: #F5F5F5;
        color: #000000;
        border: 0;
        border-radius: 8px;
        cursor: pointer;
        width: 38px;
        height: 40px;
        display: grid;
        place-items: center;
        transition: all 0.2s ease;
        padding: 0;
        box-sizing: border-box;
    }

    .ticket-delete-btn-box button:hover {
        background: #FEE2E2;
        color: #DC2626;
    }

    /* Paliers de commission dégressive */
    .scale-tiers-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.65rem;
        margin-top: 0.85rem;
    }

    .scale-tier-card {
        background: #ffffff;
        border: 1px solid #E5E5E5;
        border-radius: 10px;
        padding: 0.75rem 0.6rem;
        text-align: center;
        position: relative;
        transition: all 0.2s ease;
    }

    .scale-tier-card.is-active {
        border-color: #FF4A0D;
        background: #FFF2ED;
        box-shadow: 0 3px 10px rgba(255, 74, 13, 0.15);
    }

    .scale-tier-card .tier-tag {
        display: none;
        position: absolute;
        top: -8px;
        left: 50%;
        transform: translateX(-50%);
        background: #FF4A0D;
        color: #ffffff;
        font-size: 0.6rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 1px 7px;
        border-radius: 999px;
        white-space: nowrap;
    }

    .scale-tier-card.is-active .tier-tag {
        display: inline-block;
    }

    .scale-tier-card .tier-badge {
        font-size: 0.75rem;
        font-weight: 800;
        color: #000000;
        margin-bottom: 2px;
    }

    .scale-tier-card .tier-rate {
        font-size: 1.15rem;
        font-weight: 900;
        color: #FF4A0D;
    }

    .scale-tier-card .tier-range {
        font-size: 0.68rem;
        color: #737373;
        margin-top: 2px;
    }

    /* Synthèse financière */
    .dash-summary-strip {
        background: #000000;
        color: #ffffff;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-top: 1.25rem;
        margin-bottom: 1.5rem;
        box-sizing: border-box;
    }

    .dash-summary-strip small {
        color: #A3A3A3;
        display: block;
        font-size: 0.72rem;
        text-transform: uppercase;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .dash-summary-strip strong {
        font-size: clamp(1.05rem, 2.5vw, 1.25rem);
        display: block;
        line-height: 1.2;
        white-space: nowrap;
    }

    /* Responsive Breakpoints */
    @media (max-width: 1250px) {
        .dash-ticket-row {
            grid-template-columns: repeat(4, 1fr) 38px !important;
            gap: 0.75rem !important;
            padding: 1.1rem 1rem !important;
        }
        .dash-ticket-row .ticket-col-name {
            grid-column: 1 / 5 !important;
        }
        .dash-ticket-row .ticket-delete-btn-box {
            grid-column: 5 / 6 !important;
            align-self: end !important;
        }
        .dash-ticket-row .ticket-col-price { grid-column: 1 / 2 !important; }
        .dash-ticket-row .ticket-col-qty   { grid-column: 2 / 3 !important; }
        .dash-ticket-row .ticket-col-choix { grid-column: 3 / 4 !important; }
        .dash-ticket-row .ticket-col-fee   { grid-column: 4 / 5 !important; }

        .dash-summary-strip {
            grid-template-columns: 1fr 1fr !important;
            gap: 1.1rem !important;
        }
    }

    @media (max-width: 720px) {
        .form-row-2col {
            grid-template-columns: 1fr !important;
            gap: 0.85rem !important;
        }
        .venue-toggle-buttons {
            grid-template-columns: 1fr !important;
        }
        .dash-ticket-row {
            grid-template-columns: 1fr 1fr !important;
            padding: 1rem 0.85rem !important;
            position: relative !important;
            gap: 0.65rem !important;
        }
        .dash-ticket-row .ticket-col-name {
            grid-column: 1 / -1 !important;
            padding-right: 44px !important;
        }
        .dash-ticket-row .ticket-delete-btn-box {
            position: absolute !important;
            top: 0.95rem !important;
            right: 0.85rem !important;
            width: 36px !important;
            height: 36px !important;
        }
        .scale-tiers-grid {
            grid-template-columns: repeat(2, 1fr) !important;
        }
    }

    @media (max-width: 480px) {
        .dash-summary-strip {
            grid-template-columns: 1fr !important;
            gap: 0.85rem !important;
        }
        .scale-tiers-grid {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. BARRE D'EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-circle-plus" style="color: #FF4A0D;"></i>
                Création Multi-Services Officielle
            </h1>
            <p>Créez, paramétrez et publiez directement des Événements, Cotisations et Votes sur la plateforme.</p>
        </div>

        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="evenements.php" class="dash-btn-action" style="padding: 0.5rem 0.95rem; text-decoration: none; font-size: 0.82rem;">
                <i class="fa-solid fa-calendar-days"></i> Événements
            </a>
            <a href="cotisations.php" class="dash-btn-action" style="padding: 0.5rem 0.95rem; text-decoration: none; font-size: 0.82rem;">
                <i class="fa-solid fa-hand-holding-heart"></i> Cotisations
            </a>
            <a href="votes.php" class="dash-btn-action" style="padding: 0.5rem 0.95rem; text-decoration: none; font-size: 0.82rem;">
                <i class="fa-solid fa-ranking-star"></i> Votes
            </a>
        </div>
    </div>

    <!-- Message Flash d'Action -->
    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #000000; font-weight: 700;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>" style="font-size: 1.25rem; color: #FF4A0D;"></i>
            <div style="flex: 1;">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($msg_type === 'success'): ?>
                    <div style="margin-top: 4px; font-size: 0.8rem;">
                        <?php if ($onglet === 'evenement'): ?>
                            <a href="evenements.php" style="color: #FF4A0D; text-decoration: underline;">Voir dans Tous les Événements →</a>
                        <?php elseif ($onglet === 'cotisation'): ?>
                            <a href="cotisations.php" style="color: #FF4A0D; text-decoration: underline;">Voir dans Gestion des Cotisations →</a>
                        <?php else: ?>
                            <a href="votes.php" style="color: #FF4A0D; text-decoration: underline;">Voir dans Supervision des Votes →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Navigation par Onglets 3 Services -->
    <div class="admin-creation-tabs">
        <a href="creer-evenement.php?onglet=evenement" class="admin-tab-btn <?php echo $onglet === 'evenement' ? 'is-active' : ''; ?>">
            <i class="fa-solid fa-calendar-plus"></i> 1. Événement & Billetterie
        </a>
        <a href="creer-evenement.php?onglet=cotisation" class="admin-tab-btn <?php echo $onglet === 'cotisation' ? 'is-active' : ''; ?>">
            <i class="fa-solid fa-hand-holding-heart"></i> 2. Campagne de Cotisation
        </a>
        <a href="creer-evenement.php?onglet=vote" class="admin-tab-btn <?php echo $onglet === 'vote' ? 'is-active' : ''; ?>">
            <i class="fa-solid fa-trophy"></i> 3. Concours & Vote Payant
        </a>
    </div>

    <!-- ==============================================================================
         ONGLET 1 : ÉVÉNEMENT & BILLETTERIE
         ============================================================================== -->
    <?php if ($onglet === 'evenement'): ?>
        <form method="POST" enctype="multipart/form-data" id="form-evenement-admin">
            <input type="hidden" name="action" value="creer_evenement">

            <!-- ÉTAPE 1 : DÉTAILS DE L'ÉVÉNEMENT -->
            <div class="dash-card">
                <div class="dash-card-head">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">1</div>
                        <div>
                            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #000000;">Programmation & Localisation</h3>
                            <small style="color: #737373; font-size: 0.8rem;">Détails généraux, attribution d'organisateur et configuration du lieu d'accueil</small>
                        </div>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; background: #FFF2ED; color: #FF4A0D; padding: 4px 10px; border-radius: 6px;">
                        Mise en ligne directe
                    </span>
                </div>

                <!-- Attribution : Organisateur / Promoteur -->
                <div class="dash-form-group" style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem;">
                    <label for="organisateur_user_id" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <i class="fa-solid fa-user-shield" style="color: #FF4A0D;"></i> Porteur / Organisateur officiel de l'événement *
                    </label>
                    <select name="organisateur_user_id" id="organisateur_user_id" style="font-weight: 700;">
                        <option value="<?php echo (int)$_SESSION['user_id']; ?>" selected>👑 Administration Plateforme (Officiel Tikéli)</option>
                        <?php foreach ($db_promoters as $dp): ?>
                            <option value="<?php echo (int)$dp['user_id']; ?>">
                                🏢 Promoteur : <?php echo htmlspecialchars($dp['nom_commercial'] ?: ($dp['prenom'] . ' ' . $dp['nom'])); ?> (<?php echo htmlspecialchars($dp['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #737373; font-size: 0.75rem;">
                        Vous pouvez publier cet événement en tant qu'événement officiel de la plateforme ou au nom d'un promoteur partenaire.
                    </small>
                </div>

                <div class="dash-form-group">
                    <label for="nom"><i class="fa-solid fa-heading" style="color: #FF4A0D;"></i> Nom officiel de l'événement *</label>
                    <input type="text" id="nom" name="nom" required placeholder="Ex: Grand Festival International d'Abidjan 2026">
                </div>

                <div class="form-row-2col">
                    <div class="dash-form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="categorie" style="margin: 0;"><i class="fa-solid fa-layer-group" style="color: #FF4A0D;"></i> Catégorie *</label>
                            <button type="button" onclick="toggleAdminCatInput()" style="background: none; border: none; font-size: 0.76rem; color: #FF4A0D; font-weight: 700; cursor: pointer; text-decoration: underline;">
                                <i class="fa-solid fa-pen"></i> Personnaliser
                            </button>
                        </div>
                        <select id="categorie" name="categorie" onchange="onAdminCatChange(this)" required>
                            <option value="Concert" selected>Concert / Musique</option>
                            <option value="Festival">Festival</option>
                            <option value="Spectacle">Spectacle / Humour / Théâtre</option>
                            <option value="Sport">Sport & Tournoi</option>
                            <option value="Conférence">Conférence / Forum / Sommet</option>
                            <option value="Soirée">Soirée, Gala & Clubbing</option>
                            <option value="Foire">Foire & Exposition</option>
                            <option value="Cinéma">Cinéma & Avant-première</option>
                            <option value="Autre">✏️ Autre / Saisir une catégorie sur mesure...</option>
                        </select>
                        <div id="container_custom_cat" style="display: none; margin-top: 6px;">
                            <input type="text" id="categorie_custom" name="categorie_custom" placeholder="Saisissez la catégorie personnalisée..." style="border: 1px solid #FF4A0D; background: #FFF2ED;">
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="image"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Affiche officielle (Poster)</label>
                        <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp" style="padding: 0.5rem 0.75rem;">
                    </div>
                </div>

                <div class="dash-form-group">
                    <label for="description"><i class="fa-solid fa-align-left" style="color: #737373;"></i> Description & Programme détaillé</label>
                    <textarea id="description" name="description" rows="3" placeholder="Présentation du spectacle, temps forts, artistes programmés..."></textarea>
                </div>

                <div class="form-row-2col">
                    <div class="dash-form-group">
                        <label for="date_evenement"><i class="fa-regular fa-calendar" style="color: #FF4A0D;"></i> Date de l'événement *</label>
                        <input type="date" id="date_evenement" name="date_evenement" required value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                    </div>

                    <div class="dash-form-group">
                        <label for="heure"><i class="fa-regular fa-clock" style="color: #FF4A0D;"></i> Heure de début *</label>
                        <input type="time" id="heure" name="heure" required value="20:00">
                    </div>
                </div>

                <!-- Localisation & Configuration Salle Répertoriée (3D) vs Espace Non Répertorié -->
                <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem; margin-top: 0.5rem;">
                    <div style="font-weight: 800; font-size: 0.88rem; color: #000000; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-location-dot" style="color: #FF4A0D;"></i> Localisation & Configuration de l'Espace
                        </span>
                        <span id="venue_badge_status" style="font-size: 0.74rem; font-weight: 800; background: #FFF2ED; color: #FF4A0D; border: 1px solid #F0D9D0; border-radius: 6px; padding: 2px 8px;">
                            🏛️ Salle répertoriée (Vue 3D active)
                        </span>
                    </div>

                    <input type="hidden" name="salle_id" id="input_salle_id" value="">

                    <div class="form-row-2col" style="margin-bottom: 1rem;">
                        <div>
                            <label for="select_ville" style="font-size: 0.82rem; font-weight: 700; display: block; margin-bottom: 4px; color: #000000;">
                                Ville / Localité * <span style="font-size: 0.72rem; color: #737373; font-weight: normal;">(Filtre les salles)</span>
                            </label>
                            <select id="select_ville" name="ville" onchange="onAdminVilleFilter(this.value)" required style="font-weight: 700;">
                                <option value="Abidjan" selected>Abidjan (District Autonome)</option>
                                <option value="Yamoussoukro">Yamoussoukro (Capitale)</option>
                                <option value="Bouaké">Bouaké (Gbêkê)</option>
                                <option value="San-Pédro">San-Pédro (Bas-Sassandra)</option>
                                <option value="Korhogo">Korhogo (Poro)</option>
                                <option value="Daloa">Daloa (Haut-Sassandra)</option>
                                <option value="Grand-Bassam">Grand-Bassam (Sud-Comoé)</option>
                                <option value="Assinie">Assinie-Mafia</option>
                                <option value="Man">Man (Tonkpi)</option>
                                <option value="Gagnoa">Gagnoa (Gôh)</option>
                                <option value="Soubré">Soubré (Nawa)</option>
                                <option value="En Ligne">Événement 100% En Ligne</option>
                                <option value="Autre">✏️ Autre localité...</option>
                            </select>
                            <div id="box_ville_custom" style="display: none; margin-top: 6px;">
                                <input type="text" name="ville_custom" id="input_ville_custom" placeholder="Nom de la ville..." style="border: 1px solid #FF4A0D;">
                            </div>
                        </div>

                        <div>
                            <label style="font-size: 0.82rem; font-weight: 700; display: block; margin-bottom: 4px; color: #000000;">
                                Type d'espace d'accueil *
                            </label>
                            <div class="venue-toggle-buttons">
                                <label id="label_salle_connue" onclick="setAdminVenueMode(true)" class="venue-toggle-btn" style="border: 1.5px solid #FF4A0D; background: #FFF2ED;">
                                    <input type="radio" name="salle_connue" value="oui" checked style="accent-color: #FF4A0D; margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #000000; white-space: nowrap;">
                                        <i class="fa-solid fa-hotel" style="color: #FF4A0D;"></i> Salle répertoriée (3D)
                                    </span>
                                </label>
                                <label id="label_salle_inconnue" onclick="setAdminVenueMode(false)" class="venue-toggle-btn" style="border: 1px solid #E5E5E5; background: #ffffff;">
                                    <input type="radio" name="salle_connue" value="non" style="accent-color: #FF4A0D; margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #737373; white-space: nowrap;">
                                        <i class="fa-solid fa-tree" style="color: #737373;"></i> Espace non répertorié
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 3.A : Si Salle Répertoriée (Connue) -->
                    <div id="section_salle_connue">
                        <div class="form-row-2col">
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: #000000;">
                                    Sélectionnez parmi les salles de <span id="label_ville_active" style="color: #FF4A0D;">Abidjan</span> *
                                </label>
                                <select id="salle_select" name="salle_select" onchange="onAdminSalleSelectChange(this.value)" style="font-weight: 600;">
                                    <!-- Rempli dynamiquement en JS -->
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: #000000;">
                                    Précision ou Nom interne (Optionnel)
                                </label>
                                <input type="text" id="input_salle_nom" name="salle_nom" placeholder="Ex: Salle Lougah, Esplanade, Hall 1...">
                            </div>
                        </div>

                        <!-- Fiche d'information sur la salle -->
                        <div id="salle_info_card" style="display: none; margin-top: 0.85rem; background: #ffffff; border: 1.5px solid #F0D9D0; border-radius: 10px; padding: 0.9rem 1.1rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #737373;">Salle officielle sélectionnée</div>
                                    <strong id="salle_info_nom" style="font-size: 0.96rem; color: #000000; display: block; margin-top: 1px;">-</strong>
                                    <span id="salle_info_loc" style="font-size: 0.78rem; color: #737373;">-</span>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #737373;">Capacité officielle</div>
                                    <strong id="salle_info_cap" style="font-size: 1.1rem; color: #000000; font-weight: 900;">-</strong>
                                    <span style="display: block; font-size: 0.72rem; color: #16A34A; font-weight: 800;">
                                        <i class="fa-solid fa-cube"></i> Vue 3D & Plan actifs
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3.B : Si Espace Non Répertorié (Pas de vue 3D requise) -->
                    <div id="section_salle_inconnue" style="display: none; background: #FFF2ED; border: 1px solid #F0D9D0; border-radius: 10px; padding: 1rem 1.15rem; margin-top: 0.5rem;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                            <i class="fa-solid fa-circle-check" style="color: #FF4A0D; font-size: 1.05rem;"></i>
                            <strong style="color: #000000; font-size: 0.88rem;">Espace Non Répertorié : Pas de vue de scène 3D requise</strong>
                        </div>
                        <p style="margin: 0 0 0.85rem; font-size: 0.78rem; color: #404040; line-height: 1.45;">
                            Cet événement se déroulera dans un espace libre ou ouvert. 
                            <strong>Vous pourrez décider vous-même du nombre de places que les clients pourront choisir</strong> dans la grille tarifaire ci-dessous.
                        </p>

                        <div class="form-row-2col">
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: #000000;">
                                    Type d'espace
                                </label>
                                <select name="statut_salle_inconnue" style="font-weight: 700; border-color: #FF4A0D;">
                                    <option value="Terrain / Esplanade en plein air">🎪 Terrain / Esplanade en plein air</option>
                                    <option value="Plage / Espace bord de mer">🏖️ Plage / Espace bord de mer</option>
                                    <option value="Stade ou terrain municipal">⚽ Stade ou terrain municipal ouvert</option>
                                    <option value="Cour privée / Jardin événementiel">🏡 Cour privée / Jardin événementiel</option>
                                    <option value="Rooftop / Terrasse panoramique">🌆 Rooftop / Terrasse</option>
                                    <option value="Hangar / Entrepôt aménagé">🏭 Hangar / Entrepôt aménagé</option>
                                    <option value="Lieu secret (bientôt dévoilé)">🤫 Lieu secret (dévoilé aux inscrits par SMS/Email)</option>
                                    <option value="Lieu à confirmer très prochainement">📍 Lieu à confirmer très prochainement</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: #000000;">
                                    Précision du nom ou repère (Optionnel)
                                </label>
                                <input type="text" name="espace_nom_custom" placeholder="Ex: Plage Cocody Danga, Terrain INJS...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 2 : BILLETTERIE, QUOTAS DE PLACES & TARIFICATION -->
            <div class="dash-card">
                <div class="dash-card-head">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">2</div>
                        <div>
                            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #000000;">Grille Tarifaire, Quotas & Rémunération</h3>
                            <small style="color: #737373; font-size: 0.8rem;">Configurez les catégories de billets et le quota de places choisies par les spectateurs</small>
                        </div>
                    </div>
                </div>

                <!-- Encadré Barème Dégressif selon l'Ampleur -->
                <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem; margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #FFF2ED; color: #FF4A0D; display: grid; place-items: center; font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <strong style="color: #000000; font-size: 0.9rem; display: block; margin-bottom: 2px;">
                                Barème de Commission Plateforme Indexé sur l'Ampleur
                            </strong>
                            <p style="color: #737373; font-size: 0.78rem; margin: 0; line-height: 1.35;">
                                Le taux s'ajuste dynamiquement selon la capacité totale de l'événement calculée en temps réel.
                            </p>
                        </div>
                    </div>

                    <div class="scale-tiers-grid">
                        <div class="scale-tier-card" id="tier-card-intimiste" data-tier="intimiste">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Intimiste</div>
                            <div class="tier-rate">7.0%</div>
                            <div class="tier-range">&lt; 300 places</div>
                        </div>
                        <div class="scale-tier-card is-active" id="tier-card-standard" data-tier="standard">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Standard</div>
                            <div class="tier-rate">5.0%</div>
                            <div class="tier-range">300 – 1 499 pl.</div>
                        </div>
                        <div class="scale-tier-card" id="tier-card-grand" data-tier="grand">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Grand Evt</div>
                            <div class="tier-rate">4.0%</div>
                            <div class="tier-range">1 500 – 4 999 pl.</div>
                        </div>
                        <div class="scale-tier-card" id="tier-card-mega" data-tier="mega">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Festival / Stade</div>
                            <div class="tier-rate">3.0%</div>
                            <div class="tier-range">≥ 5 000 places</div>
                        </div>
                    </div>

                    <div id="scale-live-status" style="margin-top: 0.85rem; padding: 0.65rem 0.85rem; background: #ffffff; border: 1px solid #F0D9D0; border-radius: 8px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-bolt" style="color: #FF4A0D;"></i>
                        <div>
                            Ampleur détectée : <strong id="live-tier-name">Événement Standard (500 places)</strong> • 
                            Commission : <strong id="live-tier-rate" style="color: #FF4A0D;">5.0%</strong>
                        </div>
                    </div>
                </div>

                <!-- Gestion du Choix des Places par les Spectateurs -->
                <div style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1.15rem; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1 1 300px;">
                        <div style="width: 36px; height: 36px; border-radius: 8px; background: #FFF2ED; color: #FF4A0D; display: grid; place-items: center; font-size: 1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-chair"></i>
                        </div>
                        <div>
                            <strong style="font-size: 0.85rem; color: #000000; display: block;">Gestion du Choix des Places par les Spectateurs</strong>
                            <span style="font-size: 0.77rem; color: #737373;">
                                Définissez pour chaque catégorie le nombre de places sélectionnables (0 = placement 100% libre).
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <button type="button" onclick="quickSetAllPlacesChoisies(true)" style="padding: 5px 12px; font-size: 0.75rem; font-weight: 700; border: 1px solid #E5E5E5; background: #F5F5F5; border-radius: 6px; cursor: pointer;">
                            <i class="fa-solid fa-check-double" style="color: #FF4A0D;"></i> Toutes au choix
                        </button>
                        <button type="button" onclick="quickSetAllPlacesChoisies(false)" style="padding: 5px 12px; font-size: 0.75rem; font-weight: 700; border: 1px solid #E5E5E5; background: #F5F5F5; border-radius: 6px; cursor: pointer; color: #737373;">
                            <i class="fa-solid fa-ban"></i> 100% Libre (0)
                        </button>
                    </div>
                </div>

                <!-- En-tête Direct de la Grille des Billets & Bouton Ajouter un Tarif -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.25rem; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 800; color: #000000; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-tags" style="color: #FF4A0D;"></i> Vos Catégories de Billets & Tarifs
                        </h4>
                        <small style="color: #737373; font-size: 0.78rem;">Définissez vos prix, quotas et options de places ci-dessous</small>
                    </div>

                    <button type="button" onclick="addTicketRow()" class="dash-btn-action"
                        style="padding: 7px 15px; font-size: 0.84rem; background: #FF4A0D; color: #ffffff; border-radius: 8px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; border: none; box-shadow: 0 2px 6px rgba(255, 74, 13, 0.25);">
                        <i class="fa-solid fa-plus"></i> Ajouter un tarif
                    </button>
                </div>

                <div id="tickets-container">
                    <!-- Ligne 1 par défaut -->
                    <div class="dash-ticket-row">
                        <div class="ticket-col-name">
                            <label>Catégorie</label>
                            <input type="text" name="ticket_nom[]" required placeholder="Ex: STANDARD" value="STANDARD">
                        </div>
                        <div class="ticket-col-price">
                            <label>Prix unitaire (F)</label>
                            <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="5000" value="5000" oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-col-qty">
                            <label>Places</label>
                            <input type="number" name="ticket_quantite[]" required min="1" placeholder="500" value="500" oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-col-choix">
                            <label title="Nombre de places que les clients peuvent choisir individuellement">Places au choix</label>
                            <input type="number" name="ticket_places_choisies[]" min="0" placeholder="0" value="0" title="Nombre de places sélectionnables (0 = libre)" oninput="validatePlacesChoisies(this); calculateEventSummary();">
                        </div>
                        <div class="ticket-col-fee">
                            <label>Frais choix (F)</label>
                            <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0" title="Supplément pour place choisie" oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-delete-btn-box">
                            <button type="button" onclick="removeTicketRow(this)" title="Supprimer">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Synthèse Financière Estimée -->
                <div class="dash-summary-strip">
                    <div>
                        <small>Capacité Totale</small>
                        <strong id="summary-capacity">500 places</strong>
                        <span id="summary-tier-pill" style="display: block; font-size: 0.72rem; color: #FF4A0D; font-weight: 800; margin-top: 2px;">Standard</span>
                    </div>
                    <div>
                        <small>Recette Brute Max</small>
                        <strong id="summary-gross">2 500 000 F</strong>
                    </div>
                    <div>
                        <small>Commission (<span id="summary-rate-pct">5.0%</span>)</small>
                        <strong id="summary-commission" style="color: #FF4A0D;">125 000 F</strong>
                    </div>
                    <div>
                        <small>Gain Net Estimé</small>
                        <strong id="summary-net" style="color: #FF4A0D;">2 375 000 FCFA</strong>
                    </div>
                </div>

                <input type="hidden" name="commission_rate" id="form_commission_rate" value="5.0">

                <div class="form-row-2col" style="align-items: center;">
                    <div class="dash-form-group" style="margin: 0;">
                        <label for="statut_evenement" style="font-weight: 700;"><i class="fa-solid fa-toggle-on" style="color: #FF4A0D;"></i> Statut Initial de Publication *</label>
                        <select name="statut" id="statut_evenement" style="font-weight: 700;">
                            <option value="actif" selected>🟢 Actif (En ligne & Billetterie immédiatement ouverte)</option>
                            <option value="en_attente">🟡 En attente / Brouillon</option>
                            <option value="inactif">⚪ Inactif / Caché</option>
                        </select>
                    </div>

                    <div style="text-align: right; padding-top: 1.4rem;">
                        <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.85rem 1.75rem; font-size: 0.95rem; width: 100%; justify-content: center;">
                            <i class="fa-solid fa-calendar-check"></i> Enregistrer et Mettre en Ligne l'Événement
                        </button>
                    </div>
                </div>
            </div>
        </form>

    <!-- ==============================================================================
         ONGLET 2 : CAMPAGNE DE COTISATION (CAGNOTTE & SOLIDARITÉ)
         ============================================================================== -->
    <?php elseif ($onglet === 'cotisation'): ?>
        <form method="POST" enctype="multipart/form-data" id="form-cotisation-admin">
            <input type="hidden" name="action" value="creer_campagne_cotisation">

            <div class="dash-card">
                <div class="dash-card-head">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge"><i class="fa-solid fa-hand-holding-heart" style="font-size: 0.9rem;"></i></div>
                        <div>
                            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #000000;">Lancer une Campagne de Cotisation / Cagnotte Solidaire</h3>
                            <small style="color: #737373; font-size: 0.8rem;">Créez une cagnotte en ligne avec objectif financier en FCFA et collecte de dons sécurisée</small>
                        </div>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; background: #FFF2ED; color: #FF4A0D; padding: 4px 10px; border-radius: 6px;">
                        Mise en ligne directe
                    </span>
                </div>

                <!-- Attribution Porteur -->
                <div class="dash-form-group" style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem;">
                    <label for="cotis_user_id" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <i class="fa-solid fa-user-shield" style="color: #FF4A0D;"></i> Porteur ou Bénéficiaire de la Campagne *
                    </label>
                    <select name="organisateur_user_id" id="cotis_user_id" style="font-weight: 700;">
                        <option value="<?php echo (int)$_SESSION['user_id']; ?>" selected>👑 Administration Plateforme (Campagne Officielle Tikéli)</option>
                        <?php foreach ($db_promoters as $dp): ?>
                            <option value="<?php echo (int)$dp['user_id']; ?>">
                                🏢 Promoteur / Asso : <?php echo htmlspecialchars($dp['nom_commercial'] ?: ($dp['prenom'] . ' ' . $dp['nom'])); ?> (<?php echo htmlspecialchars($dp['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-form-group">
                    <label for="titre_cotisation"><i class="fa-solid fa-heading" style="color: #FF4A0D;"></i> Titre de la Campagne de Cotisation *</label>
                    <input type="text" id="titre_cotisation" name="titre" required placeholder="Ex: Rénovation de la Maison de la Culture, Soutien aux Jeunes Artistes...">
                </div>

                <div class="form-row-2col">
                    <div class="dash-form-group">
                        <label for="montant_objectif"><i class="fa-solid fa-coins" style="color: #FF4A0D;"></i> Montant Objectif à Atteindre (FCFA) *</label>
                        <input type="number" id="montant_objectif" name="montant_objectif" required min="1000" step="5000" placeholder="Ex: 5000000" value="5000000">
                        <small style="display: block; margin-top: 3px; color: #737373; font-size: 0.75rem;">Minimum : 1 000 FCFA</small>
                    </div>

                    <div class="dash-form-group">
                        <label for="date_limite"><i class="fa-solid fa-calendar-xmark" style="color: #FF4A0D;"></i> Date Limite de Collecte</label>
                        <input type="date" id="date_limite" name="date_limite" value="<?php echo date('Y-m-d', strtotime('+3 months')); ?>">
                    </div>
                </div>

                <div class="form-row-2col">
                    <div class="dash-form-group">
                        <label for="image_cotisation"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Visuel / Affiche de la Campagne</label>
                        <input type="file" id="image_cotisation" name="image_cotisation" accept=".jpg,.jpeg,.png,.webp" style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="dash-form-group">
                        <label for="statut_campagne"><i class="fa-solid fa-toggle-on" style="color: #FF4A0D;"></i> Statut Initial *</label>
                        <select name="statut_campagne" id="statut_campagne" style="font-weight: 700;">
                            <option value="active" selected>🟢 Active (Collecte de dons ouverte immédiatement)</option>
                            <option value="en_attente">🟡 En attente / Brouillon</option>
                        </select>
                    </div>
                </div>

                <div class="dash-form-group">
                    <label for="description_cotisation"><i class="fa-solid fa-align-left" style="color: #737373;"></i> Présentation du Projet & Cause Solidaire *</label>
                    <textarea id="description_cotisation" name="description" rows="4" required placeholder="Expliquez la destination des fonds collectés, l'impact pour la communauté et les paliers d'avancement..."></textarea>
                </div>

                <div style="border-top: 1px solid #F5F5F5; padding-top: 1.25rem; text-align: right;">
                    <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.85rem 1.75rem; font-size: 0.95rem;">
                        <i class="fa-solid fa-paper-plane"></i> Publier et Lancer la Campagne de Cotisation
                    </button>
                </div>
            </div>
        </form>

    <!-- ==============================================================================
         ONGLET 3 : CONCOURS & VOTE PAYANT / RÉALISATION
         ============================================================================== -->
    <?php elseif ($onglet === 'vote'): ?>
        <div class="dash-card">
            <div class="dash-card-head">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div class="dash-step-badge"><i class="fa-solid fa-trophy" style="font-size: 0.9rem;"></i></div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #000000;">Système de Vote & Concours Officiel</h3>
                        <small style="color: #737373; font-size: 0.8rem;">Choisissez entre un concours avec candidats inscrits ou un plébiscite d'appréciation pour la réalisation d'un projet</small>
                    </div>
                </div>
            </div>

            <!-- Boutons de bascule Formule A vs Formule B -->
            <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                <button type="button" onclick="switchAdminVoteMode('concours')" id="btn-mode-concours" class="dash-btn-action" style="flex: 1 1 240px; justify-content: center; padding: 0.75rem; background: #000000; color: #ffffff; border: none; font-weight: 800;">
                    <i class="fa-solid fa-users-viewfinder" style="color: #FF4A0D;"></i> Formule A : Concours avec Candidats
                </button>
                <button type="button" onclick="switchAdminVoteMode('realisation')" id="btn-mode-realisation" class="dash-btn-action" style="flex: 1 1 240px; justify-content: center; padding: 0.75rem; background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5; font-weight: 700;">
                    <i class="fa-solid fa-square-poll-vertical"></i> Formule B : Vote Réalisation d'Événement
                </button>
            </div>

            <!-- FORMULE A : CONCOURS AVEC CANDIDATS -->
            <div id="panel-admin-concours">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="proposer_concours">

                    <div class="dash-form-group" style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem;">
                        <label for="vote_user_id" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                            <i class="fa-solid fa-user-shield" style="color: #FF4A0D;"></i> Organisateur du Concours *
                        </label>
                        <select name="organisateur_user_id" id="vote_user_id" style="font-weight: 700;">
                            <option value="<?php echo (int)$_SESSION['user_id']; ?>" selected>👑 Administration Plateforme (Concours Officiel Tikéli)</option>
                            <?php foreach ($db_promoters as $dp): ?>
                                <option value="<?php echo (int)$dp['user_id']; ?>">
                                    🏢 Promoteur : <?php echo htmlspecialchars($dp['nom_commercial'] ?: ($dp['prenom'] . ' ' . $dp['nom'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="concours_nom"><i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i> Nom du Concours / Compétition *</label>
                            <input type="text" id="concours_nom" name="nom" required placeholder="Ex: Tremplin Musical Jeunes Talents 2026, Élection Miss...">
                        </div>

                        <div class="dash-form-group">
                            <label for="image_concours"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Affiche officielle du Concours</label>
                            <input type="file" id="image_concours" name="image_concours" accept=".jpg,.jpeg,.png,.webp" style="padding: 0.5rem 0.75rem;">
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="concours_prix_vote"><i class="fa-solid fa-coins" style="color: #FF4A0D;"></i> Prix unitaire du Vote (FCFA) *</label>
                            <input type="number" id="concours_prix_vote" name="prix_vote" min="0" step="50" placeholder="0 = Gratuit — Ex: 200 pour 200 F" value="200">
                        </div>

                        <div class="dash-form-group">
                            <label for="concours_lieu"><i class="fa-solid fa-location-dot" style="color: #FF4A0D;"></i> Ville / Lieu</label>
                            <input type="text" id="concours_lieu" name="lieu" value="Abidjan" placeholder="Ex: Abidjan">
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="concours_date"><i class="fa-regular fa-calendar" style="color: #FF4A0D;"></i> Date de Clôture / Finale *</label>
                            <input type="date" id="concours_date" name="date_evenement" required value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                        </div>

                        <div class="dash-form-group">
                            <label for="concours_heure"><i class="fa-regular fa-clock" style="color: #FF4A0D;"></i> Heure</label>
                            <input type="time" id="concours_heure" name="heure" value="20:00">
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="concours_desc"><i class="fa-solid fa-align-left" style="color: #737373;"></i> Règlement & Présentation du Concours</label>
                        <textarea id="concours_desc" name="description" rows="2" placeholder="Critères de sélection, barème de votes, récompenses pour les lauréats..."></textarea>
                    </div>

                    <!-- Enregistrement des Candidats Directement -->
                    <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem; margin-top: 1rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <h4 style="margin: 0; font-size: 0.92rem; font-weight: 800; color: #000000;">
                                    <i class="fa-solid fa-user-group" style="color: #FF4A0D;"></i> Candidats & Participants en Compétition
                                </h4>
                                <small style="color: #737373; font-size: 0.76rem;">Ajoutez les participants qui recevront les votes du public</small>
                            </div>
                            <button type="button" onclick="addAdminCandidateRow()" class="dash-btn-action" style="padding: 5px 12px; font-size: 0.8rem; background: #FF4A0D; color: #ffffff; border: none; border-radius: 6px; cursor: pointer;">
                                <i class="fa-solid fa-plus"></i> Ajouter un candidat
                            </button>
                        </div>

                        <div id="admin-candidates-container" style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <!-- Candidat 1 par défaut -->
                            <div class="admin-cand-row" style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem; display: grid; grid-template-columns: 1fr 1fr 1fr 38px; gap: 0.75rem; align-items: end;">
                                <div>
                                    <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Nom du Candidat *</label>
                                    <input type="text" name="cand_nom[]" required placeholder="Ex: Kouamé Jean-Marc" style="padding: 0.55rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.82rem; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Photo / Portrait</label>
                                    <input type="file" name="cand_photo[]" accept="image/*" style="padding: 0.45rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.78rem; box-sizing: border-box; background: #F5F5F5;">
                                </div>
                                <div>
                                    <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Description / Slogan</label>
                                    <input type="text" name="cand_desc[]" placeholder="Ex: N°1 - Ville d'Abidjan..." style="padding: 0.55rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.82rem; box-sizing: border-box;">
                                </div>
                                <div>
                                    <button type="button" onclick="removeAdminCandRow(this)" style="background: #F5F5F5; color: #000000; border: 0; border-radius: 6px; width: 38px; height: 36px; display: grid; place-items: center; cursor: pointer;" title="Supprimer">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="border-top: 1px solid #F5F5F5; padding-top: 1.25rem; text-align: right;">
                        <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.85rem 1.75rem; font-size: 0.95rem;">
                            <i class="fa-solid fa-trophy"></i> Publier et Lancer le Concours avec ses Candidats
                        </button>
                    </div>
                </form>
            </div>

            <!-- FORMULE B : VOTE DE RÉALISATION D'ÉVÉNEMENT (PLÉBISCITE) -->
            <div id="panel-admin-realisation" style="display: none;">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="proposer_vote_realisation">

                    <div class="dash-form-group" style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem;">
                        <label for="realisation_user_id" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                            <i class="fa-solid fa-user-shield" style="color: #FF4A0D;"></i> Porteur du Projet Envisagé *
                        </label>
                        <select name="organisateur_user_id" id="realisation_user_id" style="font-weight: 700;">
                            <option value="<?php echo (int)$_SESSION['user_id']; ?>" selected>👑 Administration Plateforme (Initiative Officielle)</option>
                            <?php foreach ($db_promoters as $dp): ?>
                                <option value="<?php echo (int)$dp['user_id']; ?>">
                                    🏢 Promoteur : <?php echo htmlspecialchars($dp['nom_commercial'] ?: ($dp['prenom'] . ' ' . $dp['nom'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="realisation_nom"><i class="fa-solid fa-bullhorn" style="color: #FF4A0D;"></i> Nom du Projet / Spectacle Envisagé *</label>
                            <input type="text" id="realisation_nom" name="nom" required placeholder="Ex: Concert Géant de Burna Boy à Abidjan">
                        </div>

                        <div class="dash-form-group">
                            <label for="image_realisation"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Visuel du Projet</label>
                            <input type="file" id="image_realisation" name="image_realisation" accept=".jpg,.jpeg,.png,.webp" style="padding: 0.5rem 0.75rem;">
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="realisation_question"><i class="fa-solid fa-circle-question" style="color: #FF4A0D;"></i> Question Soumise aux Spectateurs *</label>
                        <input type="text" id="realisation_question" name="vote_question" required placeholder="Ex: Souhaitez-vous la venue de Burna Boy à Abidjan en Décembre ?" style="border-color: #FF4A0D; background: #FFF2ED;">
                        <small style="color: #737373; font-size: 0.75rem; display: block; margin-top: 3px;">Cette question sera affichée en gros titre sur la page de vote public.</small>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="realisation_date"><i class="fa-regular fa-calendar" style="color: #FF4A0D;"></i> Période / Date Envisagée *</label>
                            <input type="date" id="realisation_date" name="date_evenement" required value="<?php echo date('Y-m-d', strtotime('+2 months')); ?>">
                        </div>

                        <div class="dash-form-group">
                            <label for="realisation_heure"><i class="fa-regular fa-clock" style="color: #FF4A0D;"></i> Heure</label>
                            <input type="time" id="realisation_heure" name="heure" value="20:00">
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="realisation_lieu"><i class="fa-solid fa-location-dot" style="color: #FF4A0D;"></i> Lieu / Ville Envisagée</label>
                            <input type="text" id="realisation_lieu" name="lieu" value="Abidjan" placeholder="Ex: Stade Félix Houphouët-Boigny, Abidjan">
                        </div>

                        <div class="dash-form-group">
                            <label for="realisation_prix"><i class="fa-solid fa-coins" style="color: #FF4A0D;"></i> Prix du Vote de Soutien (FCFA)</label>
                            <input type="number" id="realisation_prix" name="prix_vote" min="0" step="500" value="0" placeholder="0 = Gratuit — Ex: 1000">
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="realisation_desc"><i class="fa-solid fa-align-left" style="color: #737373;"></i> Présentation du Projet & Enjeux</label>
                        <textarea id="realisation_desc" name="description" rows="3" placeholder="Présentez les conditions de concrétisation du spectacle pour inciter le public à se mobiliser..."></textarea>
                    </div>

                    <div style="border-top: 1px solid #F5F5F5; padding-top: 1.25rem; text-align: right;">
                        <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.85rem 1.75rem; font-size: 0.95rem;">
                            <i class="fa-solid fa-paper-plane"></i> Publier et Ouvrir le Vote de Réalisation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Liste des salles transmise depuis la BDD
    const DB_SALLES = <?php echo json_encode($db_salles, JSON_UNESCAPED_UNICODE); ?>;

    // 1. Filtrage dynamique des salles selon la ville
    function onAdminVilleFilter(ville) {
        const boxCustom = document.getElementById('box_ville_custom');
        const inputCustom = document.getElementById('input_ville_custom');
        const labelVille = document.getElementById('label_ville_active');

        if (boxCustom) {
            boxCustom.style.display = (ville === 'Autre') ? 'block' : 'none';
            if (inputCustom) inputCustom.required = (ville === 'Autre');
        }
        if (labelVille) labelVille.textContent = ville;

        populateAdminSalles(ville);
    }

    function populateAdminSalles(ville) {
        const sel = document.getElementById('salle_select');
        if (!sel) return;

        sel.innerHTML = '';

        const sallesVille = DB_SALLES.filter(s => s.ville && s.ville.toLowerCase() === ville.toLowerCase());

        if (sallesVille.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = `Aucune salle répertoriée à ${ville} (Basculez sur "Espace non répertorié")`;
            sel.appendChild(opt);
        } else {
            const optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.textContent = `-- Choisissez parmi les ${sallesVille.length} salles de ${ville} --`;
            sel.appendChild(optEmpty);

            sallesVille.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = `${s.nom}${s.commune ? ' (' + s.commune + ')' : ''} — ${Number(s.capacite).toLocaleString()} places`;
                opt.dataset.nom = s.nom;
                opt.dataset.commune = s.commune || '';
                opt.dataset.capacite = s.capacite || '';
                sel.appendChild(opt);
            });
        }

        const optAutre = document.createElement('option');
        optAutre.value = 'Autre';
        optAutre.textContent = '✏️ Autre espace / Préciser manuellement...';
        sel.appendChild(optAutre);

        onAdminSalleSelectChange(sel.value);
    }

    function onAdminSalleSelectChange(salleId) {
        const inputSalleId = document.getElementById('input_salle_id');
        const inputSalleNom = document.getElementById('input_salle_nom');
        const infoCard = document.getElementById('salle_info_card');
        const infoNom = document.getElementById('salle_info_nom');
        const infoLoc = document.getElementById('salle_info_loc');
        const infoCap = document.getElementById('salle_info_cap');

        if (!salleId || salleId === 'Autre') {
            if (inputSalleId) inputSalleId.value = '';
            if (infoCard) infoCard.style.display = 'none';
            if (salleId === 'Autre' && inputSalleNom) {
                inputSalleNom.value = '';
                inputSalleNom.focus();
            }
            return;
        }

        const salle = DB_SALLES.find(s => String(s.id) === String(salleId));
        if (salle) {
            if (inputSalleId) inputSalleId.value = salle.id;
            if (inputSalleNom && !inputSalleNom.value) inputSalleNom.value = salle.nom;
            if (infoNom) infoNom.textContent = salle.nom;
            if (infoLoc) infoLoc.textContent = (salle.commune ? salle.commune + ', ' : '') + salle.ville;
            if (infoCap) infoCap.textContent = Number(salle.capacite).toLocaleString() + ' places';
            if (infoCard) infoCard.style.display = 'block';
        }
    }

    // 2. Bascule Salle Répertoriée (3D) vs Espace Non Répertorié
    function setAdminVenueMode(isKnown) {
        const radOui = document.querySelector('input[name="salle_connue"][value="oui"]');
        const radNon = document.querySelector('input[name="salle_connue"][value="non"]');
        if (radOui) radOui.checked = isKnown;
        if (radNon) radNon.checked = !isKnown;

        const lblOui = document.getElementById('label_salle_connue');
        const lblNon = document.getElementById('label_salle_inconnue');
        const secOui = document.getElementById('section_salle_connue');
        const secNon = document.getElementById('section_salle_inconnue');
        const badgeStatus = document.getElementById('venue_badge_status');

        if (isKnown) {
            if (lblOui) { lblOui.style.border = '1.5px solid #FF4A0D'; lblOui.style.background = '#FFF2ED'; }
            if (lblNon) { lblNon.style.border = '1px solid #E5E5E5'; lblNon.style.background = '#ffffff'; }
            if (secOui) secOui.style.display = 'block';
            if (secNon) secNon.style.display = 'none';
            if (badgeStatus) {
                badgeStatus.innerHTML = '🏛️ Salle répertoriée (Vue 3D active)';
                badgeStatus.style.background = '#FFF2ED';
                badgeStatus.style.color = '#FF4A0D';
            }
        } else {
            if (lblNon) { lblNon.style.border = '1.5px solid #FF4A0D'; lblNon.style.background = '#FFF2ED'; }
            if (lblOui) { lblOui.style.border = '1px solid #E5E5E5'; lblOui.style.background = '#ffffff'; }
            if (secOui) secOui.style.display = 'none';
            if (secNon) secNon.style.display = 'block';
            if (badgeStatus) {
                badgeStatus.innerHTML = '📍 Espace libre (Sans vue 3D requise)';
                badgeStatus.style.background = '#F5F5F5';
                badgeStatus.style.color = '#000000';
            }
            const inputSalleId = document.getElementById('input_salle_id');
            if (inputSalleId) inputSalleId.value = '';
        }
    }

    // 3. Catégorie personnalisée
    function onAdminCatChange(sel) {
        const box = document.getElementById('container_custom_cat');
        const input = document.getElementById('categorie_custom');
        if (sel.value === 'Autre') {
            box.style.display = 'block';
            input.required = true;
            input.focus();
        } else {
            box.style.display = 'none';
            input.required = false;
        }
    }

    function toggleAdminCatInput() {
        const sel = document.getElementById('categorie');
        const box = document.getElementById('container_custom_cat');
        const input = document.getElementById('categorie_custom');
        if (box.style.display === 'block') {
            box.style.display = 'none';
            input.required = false;
            sel.value = 'Concert';
        } else {
            sel.value = 'Autre';
            box.style.display = 'block';
            input.required = true;
            input.focus();
        }
    }

    // 4. Gestion des Tarifs & Quotas de Places au Choix
    function quickSetAllPlacesChoisies(enabled) {
        const rows = document.querySelectorAll('.dash-ticket-row');
        rows.forEach(function (row) {
            const qtyInput = row.querySelector('input[name="ticket_quantite[]"]');
            const choixInput = row.querySelector('input[name="ticket_places_choisies[]"]');
            if (choixInput && qtyInput) {
                choixInput.value = enabled ? (Number(qtyInput.value) || 0) : 0;
            }
        });
        calculateEventSummary();
    }

    function validatePlacesChoisies(input) {
        if (!input) return;
        const row = input.closest('.dash-ticket-row');
        if (!row) return;
        const qtyInput = row.querySelector('input[name="ticket_quantite[]"]');
        const maxQty = qtyInput ? (Number(qtyInput.value) || 0) : 0;
        let val = Number(input.value) || 0;
        if (val < 0) val = 0;
        if (maxQty > 0 && val > maxQty) val = maxQty;
        input.value = val;
    }

    function addTicketRow() {
        const container = document.getElementById('tickets-container');
        const row = document.createElement('div');
        row.className = 'dash-ticket-row';
        row.innerHTML = `
            <div class="ticket-col-name">
                <label>Catégorie</label>
                <input type="text" name="ticket_nom[]" required placeholder="Ex: VIP" value="VIP">
            </div>
            <div class="ticket-col-price">
                <label>Prix unitaire (F)</label>
                <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="15000" value="15000" oninput="calculateEventSummary()">
            </div>
            <div class="ticket-col-qty">
                <label>Places</label>
                <input type="number" name="ticket_quantite[]" required min="1" placeholder="100" value="100" oninput="validatePlacesChoisies(this.closest('.dash-ticket-row').querySelector('input[name=\\'ticket_places_choisies[]\\']')); calculateEventSummary();">
            </div>
            <div class="ticket-col-choix">
                <label title="Nombre de places que les spectateurs peuvent choisir">Places au choix</label>
                <input type="number" name="ticket_places_choisies[]" min="0" placeholder="0" value="0" title="Nombre de places que les spectateurs peuvent choisir (0 = libre)" oninput="validatePlacesChoisies(this); calculateEventSummary();">
            </div>
            <div class="ticket-col-fee">
                <label>Frais choix (F)</label>
                <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0" title="Supplément pour place choisie" oninput="calculateEventSummary()">
            </div>
            <div class="ticket-delete-btn-box">
                <button type="button" onclick="removeTicketRow(this)" title="Supprimer">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(row);
        calculateEventSummary();
    }

    function removeTicketRow(btn) {
        const rows = document.querySelectorAll('.dash-ticket-row');
        if (rows.length > 1) {
            btn.closest('.dash-ticket-row').remove();
            calculateEventSummary();
        } else {
            alert("Votre événement doit comporter au moins un type de billet.");
        }
    }

    // Barème de commission dégressive
    function getEventScale(capacity, gross) {
        if (capacity >= 5000 || gross >= 50000000) {
            return { tier: 'mega', name: 'Festival & Stade', rate: 0.03, percent: 3.0 };
        } else if (capacity >= 1500 || gross >= 15000000) {
            return { tier: 'grand', name: 'Grand Événement', rate: 0.04, percent: 4.0 };
        } else if (capacity >= 300 || gross >= 3000000) {
            return { tier: 'standard', name: 'Événement Standard', rate: 0.05, percent: 5.0 };
        } else {
            return { tier: 'intimiste', name: 'Événement Intimiste', rate: 0.07, percent: 7.0 };
        }
    }

    function calculateEventSummary() {
        let totalCapacity = 0;
        let totalGross = 0;

        const rows = document.querySelectorAll('.dash-ticket-row');
        rows.forEach(function (row) {
            const price = parseFloat(row.querySelector('input[name="ticket_prix[]"]')?.value) || 0;
            const qty = parseInt(row.querySelector('input[name="ticket_quantite[]"]')?.value, 10) || 0;
            if (price > 0 && qty > 0) {
                totalCapacity += qty;
                totalGross += (price * qty);
            }
        });

        const scale = getEventScale(totalCapacity, totalGross);
        const commissionAmount = Math.round(totalGross * scale.rate);
        const netAmount = Math.max(0, totalGross - commissionAmount);

        // Mise à jour de l'affichage
        const elCap = document.getElementById('summary-capacity');
        const elGross = document.getElementById('summary-gross');
        const elRatePct = document.getElementById('summary-rate-pct');
        const elCommission = document.getElementById('summary-commission');
        const elNet = document.getElementById('summary-net');
        const elTierPill = document.getElementById('summary-tier-pill');
        const elFormRate = document.getElementById('form_commission_rate');

        if (elCap) elCap.textContent = totalCapacity.toLocaleString() + ' places';
        if (elGross) elGross.textContent = totalGross.toLocaleString() + ' F';
        if (elRatePct) elRatePct.textContent = scale.percent.toFixed(1) + '%';
        if (elCommission) elCommission.textContent = commissionAmount.toLocaleString() + ' F';
        if (elNet) elNet.textContent = netAmount.toLocaleString() + ' FCFA';
        if (elTierPill) elTierPill.textContent = scale.name;
        if (elFormRate) elFormRate.value = scale.percent.toFixed(1);

        // Mise à jour des cartes de palier
        document.querySelectorAll('.scale-tier-card').forEach(c => c.classList.remove('is-active'));
        const activeCard = document.getElementById('tier-card-' + scale.tier);
        if (activeCard) activeCard.classList.add('is-active');

        const liveName = document.getElementById('live-tier-name');
        const liveRate = document.getElementById('live-tier-rate');
        if (liveName) liveName.textContent = `${scale.name} (${totalCapacity.toLocaleString()} places)`;
        if (liveRate) liveRate.textContent = scale.percent.toFixed(1) + '%';
    }

    // 5. Gestion des Candidats (Formule Concours)
    function addAdminCandidateRow() {
        const c = document.getElementById('admin-candidates-container');
        const div = document.createElement('div');
        div.className = 'admin-cand-row';
        div.style = 'background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem; display: grid; grid-template-columns: 1fr 1fr 1fr 38px; gap: 0.75rem; align-items: end;';
        div.innerHTML = `
            <div>
                <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Nom du Candidat *</label>
                <input type="text" name="cand_nom[]" required placeholder="Nom et prénom..." style="padding: 0.55rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.82rem; box-sizing: border-box;">
            </div>
            <div>
                <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Photo / Portrait</label>
                <input type="file" name="cand_photo[]" accept="image/*" style="padding: 0.45rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.78rem; box-sizing: border-box; background: #F5F5F5;">
            </div>
            <div>
                <label style="font-size: 0.76rem; font-weight: 700; color: #000000; display: block; margin-bottom: 3px;">Description / Slogan</label>
                <input type="text" name="cand_desc[]" placeholder="Description ou numéro..." style="padding: 0.55rem; width: 100%; border: 1px solid var(--dash-border); border-radius: 6px; font-size: 0.82rem; box-sizing: border-box;">
            </div>
            <div>
                <button type="button" onclick="removeAdminCandRow(this)" style="background: #F5F5F5; color: #000000; border: 0; border-radius: 6px; width: 38px; height: 36px; display: grid; place-items: center; cursor: pointer;" title="Supprimer">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `;
        c.appendChild(div);
    }

    function removeAdminCandRow(btn) {
        const rows = document.querySelectorAll('.admin-cand-row');
        if (rows.length > 1) {
            btn.closest('.admin-cand-row').remove();
        } else {
            alert("Le concours doit comporter au moins un candidat.");
        }
    }

    // 6. Bascule Concours vs Vote de Réalisation
    function switchAdminVoteMode(mode) {
        const pConcours = document.getElementById('panel-admin-concours');
        const pRealisation = document.getElementById('panel-admin-realisation');
        const bConcours = document.getElementById('btn-mode-concours');
        const bRealisation = document.getElementById('btn-mode-realisation');

        if (mode === 'concours') {
            if (pConcours) pConcours.style.display = 'block';
            if (pRealisation) pRealisation.style.display = 'none';
            if (bConcours) { bConcours.style.background = '#000000'; bConcours.style.color = '#ffffff'; bConcours.style.border = 'none'; }
            if (bRealisation) { bRealisation.style.background = '#F5F5F5'; bRealisation.style.color = '#737373'; bRealisation.style.border = '1px solid #E5E5E5'; }
        } else {
            if (pConcours) pConcours.style.display = 'none';
            if (pRealisation) pRealisation.style.display = 'block';
            if (bRealisation) { bRealisation.style.background = '#000000'; bRealisation.style.color = '#ffffff'; bRealisation.style.border = 'none'; }
            if (bConcours) { bConcours.style.background = '#F5F5F5'; bConcours.style.color = '#737373'; bConcours.style.border = '1px solid #E5E5E5'; }
        }
    }

    // Initialisation au chargement
    document.addEventListener('DOMContentLoaded', function () {
        const villeInit = document.getElementById('select_ville')?.value || 'Abidjan';
        populateAdminSalles(villeInit);
        calculateEventSummary();
    });
</script>

<?php include 'footer.php'; ?>