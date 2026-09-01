<?php
// ==============================================================================
// DEMANDE DE CRÉATION D'ÉVÉNEMENT (promoteur/demande-evenement.php)
// Mise en page soignée, transparence de la commission plateforme et calculs en temps réel
// ==============================================================================

$page_title = "Proposer un Événement - Espace Promoteur";
include 'header.php';

$message = "";
$msg_type = "";

// Onglet actif de la page (Événement | Cotisation | Vote Payant)
$onglet = $_GET['onglet'] ?? 'evenement';
if (!in_array($onglet, ['evenement', 'cotisation', 'vote'], true)) {
    $onglet = 'evenement';
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categorie = trim($_POST['categorie'] ?? 'Concert');
    $date_evenement = $_POST['date_evenement'] ?? '';
    $heure = $_POST['heure'] ?? '';
    $lieu = trim($_POST['lieu'] ?? '');
    $prix_vote = (float) ($_POST['prix_vote'] ?? 0);
    $infos_supp = trim($_POST['infos_supplementaires'] ?? '');

    // Statut juridique & documents
    $type_personne = $_POST['type_personne'] ?? 'physique';
    $nom_structure = trim($_POST['nom_structure'] ?? '');
    $numero_rccm = trim($_POST['numero_rccm'] ?? '');

    // Types de tickets
    $ticket_noms = $_POST['ticket_nom'] ?? [];
    $ticket_prix = $_POST['ticket_prix'] ?? [];
    $ticket_qtys = $_POST['ticket_quantite'] ?? [];
    $ticket_frais = $_POST['ticket_frais'] ?? []; // Frais supplémentaires si le client choisit sa place

    // Validation
    if (empty($nom) || empty($description) || empty($date_evenement) || empty($heure) || empty($lieu)) {
        $message = "Veuillez remplir tous les champs obligatoires de l'événement.";
        $msg_type = "error";
    } elseif ($type_personne === 'morale' && empty($nom_structure)) {
        $message = "Veuillez indiquer la raison sociale de votre entreprise ou association.";
        $msg_type = "error";
    } elseif (empty($ticket_noms) || count($ticket_noms) === 0) {
        $message = "Veuillez définir au moins un tarif de ticket pour votre événement.";
        $msg_type = "error";
    } else {
        $docs_dir = '../uploads/event_docs/';
        if (!is_dir($docs_dir)) {
            mkdir($docs_dir, 0777, true);
        }

        // 1. Upload de l'affiche
        $image_name = 'default.jpg';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_events = '../uploads/events/';
                if (!is_dir($upload_events)) {
                    mkdir($upload_events, 0777, true);
                }
                $image_name = 'event_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image_name);
            }
        }

        // 2. Upload du Document Justificatif
        $doc_justificatif = null;
        if (isset($_FILES['document_justificatif']) && $_FILES['document_justificatif']['error'] === UPLOAD_ERR_OK) {
            $ext_doc = strtolower(pathinfo($_FILES['document_justificatif']['name'], PATHINFO_EXTENSION));
            $allowed_docs = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext_doc, $allowed_docs, true)) {
                $doc_justificatif = 'justif_' . uniqid() . '.' . $ext_doc;
                move_uploaded_file($_FILES['document_justificatif']['tmp_name'], $docs_dir . $doc_justificatif);
            }
        }

        // 3. Upload de l'autorisation de manifestation
        $doc_autorisation = null;
        if (isset($_FILES['document_autorisation']) && $_FILES['document_autorisation']['error'] === UPLOAD_ERR_OK) {
            $ext_auth = strtolower(pathinfo($_FILES['document_autorisation']['name'], PATHINFO_EXTENSION));
            $allowed_docs = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext_auth, $allowed_docs, true)) {
                $doc_autorisation = 'auth_' . uniqid() . '.' . $ext_auth;
                move_uploaded_file($_FILES['document_autorisation']['tmp_name'], $docs_dir . $doc_autorisation);
            }
        }

        // 4. Structuration des types de tickets
        $tickets_data = [];
        for ($i = 0; $i < count($ticket_noms); $i++) {
            $t_nom = trim($ticket_noms[$i]);
            $t_prix = (float) ($ticket_prix[$i] ?? 0);
            $t_qty = (int) ($ticket_qtys[$i] ?? 0);

            if (!empty($t_nom) && $t_prix > 0 && $t_qty > 0) {
                $tickets_data[] = [
                    'nom' => $t_nom,
                    'prix' => $t_prix,
                    'quantite' => $t_qty,
                    'frais_place' => max(0, (float) ($ticket_frais[$i] ?? 0))
                ];
            }
        }

        if (empty($tickets_data)) {
            $message = "Veuillez configurer au moins un type de ticket valide (nom, prix supérieur à 0 et quantité positive).";
            $msg_type = "error";
        } else {
            $candidats_json = null;

            try {
                $sql = "INSERT INTO event_requests (
                            user_id, nom, description, image, categorie, date_evenement, heure, lieu, prix_vote,
                            infos_supplementaires, type_personne, nom_structure, numero_rccm,
                            document_justificatif, document_autorisation, ticket_types_data, candidats_data, statut
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_SESSION['user_id'],
                    $nom,
                    $description,
                    $image_name,
                    $categorie,
                    $date_evenement,
                    $heure,
                    $lieu,
                    $prix_vote,
                    $infos_supp,
                    $type_personne,
                    $nom_structure,
                    $numero_rccm,
                    $doc_justificatif,
                    $doc_autorisation,
                    json_encode($tickets_data, JSON_UNESCAPED_UNICODE),
                    $candidats_json
                ]);

                $message = "Votre proposition d'événement a été transmise à l'administrateur avec succès ! Vous pouvez suivre son approbation dans « Mes Événements ».";
                $msg_type = "success";

            } catch (PDOException $e) {
                $message = "Erreur lors de l'envoi de la demande : " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}


// ===== Traitement : Créer une campagne de cotisation (onglet Cotisation) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_campagne') {
    $titre_c = trim($_POST['titre'] ?? '');
    $description_c = trim($_POST['description'] ?? '');
    $objectif_c = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
    $date_limite_c = trim($_POST['date_limite'] ?? '');

    if ($titre_c === '' || !$objectif_c || $objectif_c < 1000) {
        $message = "Veuillez renseigner un titre et un montant à atteindre valide (minimum 1 000 FCFA).";
        $msg_type = "error";
    } else {
        $image_c = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext_c = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_c = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext_c, $allowed_c, true)) {
                $upload_c = '../uploads/events/';
                if (!is_dir($upload_c))
                    mkdir($upload_c, 0777, true);
                $image_c = 'campagne_' . uniqid() . '.' . $ext_c;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_c . $image_c);
            }
        }
        try {
            $stmt_c = $pdo->prepare("INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut) VALUES (?, ?, ?, ?, ?, ?, 'en_attente')");
            $stmt_c->execute([
                $_SESSION['user_id'],
                $titre_c,
                $description_c ?: null,
                $image_c,
                $objectif_c,
                $date_limite_c ?: null
            ]);
            $message = "La campagne de cotisation « " . htmlspecialchars($titre_c) . " » a été soumise avec succès ! Vous pouvez suivre son statut dans « Mes Demandes ».";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la soumission de la campagne : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ===== Traitement : Configurer le vote (Concours avec candidats OU Vote de réalisation) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'definir_prix_vote') {
    $event_v = (int) ($_POST['event_id'] ?? 0);
    $type_v = trim($_POST['type_vote'] ?? 'concours');
    if (!in_array($type_v, ['concours', 'realisation_evenement'], true)) {
        $type_v = 'concours';
    }
    $vote_q = trim($_POST['vote_question'] ?? '');
    $prix_v = filter_input(INPUT_POST, 'prix_vote', FILTER_VALIDATE_FLOAT);
    if ($prix_v === false || $prix_v === null || $prix_v < 0)
        $prix_v = 0;

    if ($event_v > 0) {
        $stmt_v = $pdo->prepare("UPDATE events SET type_vote = ?, vote_question = ?, prix_vote = ? WHERE id = ? AND user_id = ?");
        $stmt_v->execute([$type_v, $vote_q ?: null, $prix_v, $event_v, $_SESSION['user_id']]);

        $type_label = ($type_v === 'concours') ? "Concours / Compétition (avec candidats)" : "Vote pour la réalisation d'un événement";
        $message = "Configuration enregistrée : « " . $type_label . " » (Tarif : " . ($prix_v > 0 ? number_format($prix_v, 0, ',', ' ') . " FCFA" : "Gratuit") . ").";
        $msg_type = "success";
    } else {
        $message = "Veuillez sélectionner un événement à configurer.";
        $msg_type = "error";
    }
}

// ===== Traitement 1 : Proposer un Concours avec nom saisi, photo d'événement et plusieurs candidats =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'proposer_concours') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date_evenement = $_POST['date_evenement'] ?? date('Y-m-d');
    $heure = $_POST['heure'] ?? '20:00';
    $lieu = trim($_POST['lieu'] ?? 'Abidjan');
    $prix_vote = filter_input(INPUT_POST, 'prix_vote', FILTER_VALIDATE_FLOAT) ?: 0;

    // Téléversement de l'affiche / photo officielle de l'événement concours
    $image_event = 'default.jpg';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $upload_ev = '../uploads/events/';
            if (!is_dir($upload_ev))
                mkdir($upload_ev, 0777, true);
            $image_event = 'concours_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_ev . $image_event);
        }
    }

    // Traitement des multiples candidats enregistrés
    $cands_nom = $_POST['cand_nom'] ?? [];
    $cands_desc = $_POST['cand_desc'] ?? [];
    $candidats_data = [];
    $upload_cands = '../uploads/candidats/';
    if (!is_dir($upload_cands))
        mkdir($upload_cands, 0777, true);

    if (is_array($cands_nom)) {
        foreach ($cands_nom as $idx => $cnom) {
            $cnom = trim($cnom);
            if ($cnom === '')
                continue;

            $cdesc = trim($cands_desc[$idx] ?? '');
            $cphoto = null;

            if (isset($_FILES['cand_photo']['name'][$idx]) && $_FILES['cand_photo']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['cand_photo']['name'][$idx], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $cphoto = 'cand_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['cand_photo']['tmp_name'][$idx], $upload_cands . $cphoto);
                }
            }

            $candidats_data[] = [
                'nom' => $cnom,
                'description' => $cdesc,
                'photo' => $cphoto
            ];
        }
    }

    if ($nom === '') {
        $message = "Veuillez renseigner le nom du concours.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO event_requests (
                    user_id, nom, description, image, categorie, date_evenement, heure, lieu,
                    prix_vote, type_vote, candidats_data, statut, type_personne
                ) VALUES (?, ?, ?, ?, 'Concours', ?, ?, ?, ?, 'concours', ?, 'en_attente', 'physique')
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $nom,
                $description ?: 'Concours officiel avec vote du public',
                $image_event,
                $date_evenement,
                $heure,
                $lieu,
                $prix_vote,
                !empty($candidats_data) ? json_encode($candidats_data, JSON_UNESCAPED_UNICODE) : null
            ]);

            $message = "La demande de concours « " . htmlspecialchars($nom) . " » avec " . count($candidats_data) . " participant(s) a été transmise à l'administrateur avec succès ! Vous pouvez suivre sa validation dans Mes Demandes.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la soumission : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ===== Traitement 2 : Proposer un Vote pour la Réalisation d'un Événement =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'proposer_vote_realisation') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $vote_question = trim($_POST['vote_question'] ?? '');
    $date_evenement = $_POST['date_evenement'] ?? date('Y-m-d');
    $heure = $_POST['heure'] ?? '20:00';
    $lieu = trim($_POST['lieu'] ?? 'Abidjan');
    $prix_vote = filter_input(INPUT_POST, 'prix_vote', FILTER_VALIDATE_FLOAT) ?: 0;

    // Téléversement de l'affiche / photo de l'événement à réaliser
    $image_event = 'default.jpg';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $upload_ev = '../uploads/events/';
            if (!is_dir($upload_ev))
                mkdir($upload_ev, 0777, true);
            $image_event = 'vote_realisation_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_ev . $image_event);
        }
    }

    if ($nom === '' || $vote_question === '') {
        $message = "Veuillez renseigner le nom de l'événement et la question posée au public.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO event_requests (
                    user_id, nom, description, image, categorie, date_evenement, heure, lieu,
                    prix_vote, type_vote, vote_question, statut, type_personne
                ) VALUES (?, ?, ?, ?, 'Vote', ?, ?, ?, ?, 'realisation_evenement', ?, 'en_attente', 'physique')
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $nom,
                $description ?: 'Projet soumis au vote du public pour confirmation de réalisation',
                $image_event,
                $date_evenement,
                $heure,
                $lieu,
                $prix_vote,
                $vote_question
            ]);

            $message = "Votre proposition de vote pour la réalisation de « " . htmlspecialchars($nom) . " » a été transmise à l'administrateur avec succès ! Vous pouvez suivre sa validation dans Mes Demandes.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la soumission : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ===== Traitement : Supprimer un candidat / choix de vote =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_candidat') {
    $cand_del_id = (int) ($_POST['cand_id'] ?? ($_POST['candidat_id'] ?? 0));
    $chk_del = $pdo->prepare("
        SELECT c.id, c.nom, c.photo
        FROM event_candidats c
        JOIN events e ON e.id = c.event_id
        WHERE c.id = ? AND e.user_id = ?
    ");
    $chk_del->execute([$cand_del_id, $_SESSION['user_id']]);
    $del_row = $chk_del->fetch();

    if ($del_row) {
        if (!empty($del_row['photo']) && file_exists('../uploads/candidats/' . $del_row['photo'])) {
            @unlink('../uploads/candidats/' . $del_row['photo']);
        }
        $del_stmt = $pdo->prepare("DELETE FROM event_candidats WHERE id = ?");
        $del_stmt->execute([$cand_del_id]);
        $message = "Le candidat / choix « " . htmlspecialchars($del_row['nom']) . " » a été supprimé.";
        $msg_type = "success";
    } else {
        $message = "Candidat introuvable ou non autorisé.";
        $msg_type = "error";
    }
}

// Événements actifs du promoteur (pour l'onglet Vote)
$evts_vote = [];
$candidats_promoteur = [];
try {
    $stmt_ev = $pdo->prepare("
        SELECT e.id, e.nom, e.date_evenement, e.prix_vote, e.type_vote, e.vote_question,
               (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id AND v.candidat_id IS NULL) AS nb_votes_realisation
        FROM events e
        WHERE e.user_id = ? AND e.statut = 'actif'
        ORDER BY e.nom ASC
    ");
    $stmt_ev->execute([$_SESSION['user_id']]);
    $evts_vote = $stmt_ev->fetchAll();

    if (!empty($evts_vote)) {
        $ev_ids = array_column($evts_vote, 'id');
        $in_ids = implode(',', array_map('intval', $ev_ids));
        $stmt_cand_prom = $pdo->query("
            SELECT c.*, e.nom AS event_nom,
                   (SELECT COUNT(*) FROM event_votes v WHERE v.candidat_id = c.id) AS nb_votes
            FROM event_candidats c
            JOIN events e ON e.id = c.event_id
            WHERE c.event_id IN ($in_ids)
            ORDER BY c.event_id ASC, nb_votes DESC, c.nom ASC
        ");
        $candidats_promoteur = $stmt_cand_prom->fetchAll();
    }
} catch (PDOException $e) {
    $evts_vote = [];
    $candidats_promoteur = [];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
    /* Styles complémentaires intégrés au design system */
    .dash-step-badge {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #5b50e6 0%, #7c3aed 100%);
        color: #ffffff;
        display: grid;
        place-items: center;
        font-weight: 800;
        font-size: 0.85rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(91, 80, 230, 0.25);
    }

    .dash-form-group {
        margin-bottom: 1.25rem;
    }

    .dash-form-group label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        font-weight: 800;
        color: var(--dash-text);
        margin-bottom: 0.45rem;
        letter-spacing: -0.2px;
    }

    .dash-form-group input[type="text"],
    .dash-form-group input[type="date"],
    .dash-form-group input[type="time"],
    .dash-form-group input[type="number"],
    .dash-form-group select,
    .dash-form-group textarea {
        width: 100%;
        padding: 0.72rem 0.95rem;
        background: #f8fafc;
        border: 1px solid var(--dash-border);
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.88rem;
        color: var(--dash-text);
        outline: none;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .dash-form-group input:focus,
    .dash-form-group select:focus,
    .dash-form-group textarea:focus {
        background: #ffffff;
        border-color: var(--dash-primary);
        box-shadow: 0 0 0 3px rgba(91, 80, 230, 0.12);
    }

    .dash-entity-card {
        border: 2px solid var(--dash-border);
        border-radius: 12px;
        padding: 1.1rem 1.25rem;
        cursor: pointer;
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        transition: all 0.2s ease;
        background: #f8fafc;
    }

    .dash-entity-card:hover {
        border-color: #cbd5e1;
        background: #ffffff;
    }

    .dash-entity-card.active {
        border-color: var(--dash-primary);
        background: #f5f3ff;
        box-shadow: 0 4px 14px rgba(91, 80, 230, 0.08);
    }

    .dash-upload-box {
        background: #f8fafc;
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 1.25rem;
        transition: all 0.2s ease;
    }

    .dash-upload-box:hover {
        border-color: var(--dash-primary);
        background: #ffffff;
    }

    .dash-ticket-row {
        background: #f8fafc;
        border: 1px solid var(--dash-border);
        border-radius: 12px;
        padding: 1rem 1.15rem;
        display: grid;
        grid-template-columns: 2fr 1.2fr 1fr 1.2fr auto;
        gap: 0.85rem;
        align-items: end;
        margin-bottom: 0.85rem;
        transition: all 0.15s ease;
    }

    .dash-ticket-row:hover {
        border-color: #cbd5e1;
        background: #ffffff;
    }

    .dash-summary-strip {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1.25rem;
        margin-top: 1.25rem;
        margin-bottom: 1.5rem;
    }
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. BARRE D'EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-plus" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Proposer un Événement
            </h1>
            <p>Formulaire professionnel de soumission d'événement et configuration de billetterie.</p>
        </div>

        <div class="dash-filter-bar">
            <a href="mes-evenements.php" class="dash-btn-action" style="padding: 0.5rem 1rem;">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Mes Événements</span>
            </a>
        </div>
    </div>

    <!-- Notifications Flash -->
    <?php if (!empty($message)): ?>
        <div
            style="padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.85rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; color: <?php echo $msg_type === 'success' ? '#065f46' : '#991b1b'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"
                style="font-size: 1.2rem;"></i>
            <div style="flex: 1;">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($msg_type === 'success'): ?>
                    <div style="margin-top: 0.35rem;">
                        <a href="mes-evenements.php"
                            style="color: inherit; text-decoration: underline; font-weight: 800;">Suivre l'approbation dans Mes
                            Événements →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Navigation par Onglets Épurée -->
    <div
        style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; background: #ffffff; padding: 6px; border-radius: 12px; border: 1px solid var(--dash-border); width: fit-content; flex-wrap: wrap;">
        <a href="demande-evenement.php" class="dash-chart-tab <?php echo $onglet === 'evenement' ? 'active' : ''; ?>"
            style="text-decoration: none; border-radius: 8px; padding: 0.5rem 1.15rem; font-size: 0.85rem;">
            <i class="fa-solid fa-calendar-plus" style="margin-right: 5px;"></i> Événement & Billetterie
        </a>
        <a href="demande-evenement.php?onglet=cotisation"
            class="dash-chart-tab <?php echo $onglet === 'cotisation' ? 'active' : ''; ?>"
            style="text-decoration: none; border-radius: 8px; padding: 0.5rem 1.15rem; font-size: 0.85rem;">
            <i class="fa-solid fa-hand-holding-heart" style="margin-right: 5px;"></i> Campagne de Cotisation
        </a>
        <a href="demande-evenement.php?onglet=vote"
            class="dash-chart-tab <?php echo $onglet === 'vote' ? 'active' : ''; ?>"
            style="text-decoration: none; border-radius: 8px; padding: 0.5rem 1.15rem; font-size: 0.85rem;">
            <i class="fa-solid fa-vote-yea" style="margin-right: 5px;"></i> Concours & Vote Payant
        </a>
    </div>

    <!-- ==============================================================================
         ONGLET 1 : CRÉATION D'ÉVÉNEMENT & BILLETTERIE
         ============================================================================== -->
    <?php if ($onglet === 'evenement'): ?>
        <form method="POST" enctype="multipart/form-data" style="max-width: 960px;">

            <!-- ÉTAPE 1 : STATUT JURIDIQUE & PIÈCES JUSTIFICATIVES -->
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">1</div>
                        <div>
                            <h3 class="dash-card-title">Statut Juridique de l'Organisateur</h3>
                            <div class="dash-card-subtitle">Précisez le cadre légal de votre structure pour la
                                contractualisation</div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                    <label class="dash-entity-card active" id="option-physique">
                        <input type="radio" name="type_personne" value="physique" checked onchange="updateEntityType(this)"
                            style="margin-top: 0.2rem; accent-color: var(--dash-primary);">
                        <div>
                            <strong
                                style="color: var(--dash-text); font-size: 0.92rem; display: block; margin-bottom: 2px;">
                                <i class="fa-solid fa-user" style="color: var(--dash-primary);"></i> Personne Physique
                            </strong>
                            <small style="color: var(--dash-muted); font-size: 0.78rem; line-height: 1.35; display: block;">
                                Particulier, artiste indépendant, organisateur individuel.
                            </small>
                        </div>
                    </label>

                    <label class="dash-entity-card" id="option-morale">
                        <input type="radio" name="type_personne" value="morale" onchange="updateEntityType(this)"
                            style="margin-top: 0.2rem; accent-color: var(--dash-primary);">
                        <div>
                            <strong
                                style="color: var(--dash-text); font-size: 0.92rem; display: block; margin-bottom: 2px;">
                                <i class="fa-solid fa-building" style="color: #6366f1;"></i> Personne Morale
                            </strong>
                            <small style="color: var(--dash-muted); font-size: 0.78rem; line-height: 1.35; display: block;">
                                Entreprise, agence événementielle, association ou ONG.
                            </small>
                        </div>
                    </label>
                </div>

                <!-- Champs entreprise (conditionnels) -->
                <div id="fields-morale"
                    style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 1.15rem; margin-bottom: 1.25rem;">
                    <div
                        style="font-weight: 800; color: #166534; margin-bottom: 0.75rem; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-id-card"></i> Identifiants de la Structure / Entreprise
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="dash-form-group" style="margin: 0;">
                            <label for="nom_structure">Raison sociale / Nom commercial *</label>
                            <input type="text" id="nom_structure" name="nom_structure"
                                placeholder="Ex: Live Nation CI, Pulse Event...">
                        </div>
                        <div class="dash-form-group" style="margin: 0;">
                            <label for="numero_rccm">Numéro RCCM / SIRET</label>
                            <input type="text" id="numero_rccm" name="numero_rccm" placeholder="Ex: CI-ABJ-2026-B-9988">
                        </div>
                    </div>
                </div>

                <!-- Téléversement des justificatifs -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="dash-upload-box">
                        <div class="dash-form-group" style="margin: 0;">
                            <label for="document_justificatif" id="label-justif">
                                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Pièce d'identité (CNI /
                                Passeport) *
                            </label>
                            <input type="file" id="document_justificatif" name="document_justificatif"
                                accept=".pdf,.jpg,.jpeg,.png" style="font-size: 0.82rem; margin-top: 4px;">
                            <small
                                style="color: var(--dash-muted); display: block; margin-top: 0.35rem; font-size: 0.75rem;">
                                Document officiel de conformité en format PDF, JPG ou PNG.
                            </small>
                        </div>
                    </div>

                    <div class="dash-upload-box">
                        <div class="dash-form-group" style="margin: 0;">
                            <label for="document_autorisation">
                                <i class="fa-solid fa-file-contract" style="color: var(--dash-primary);"></i> Contrat de
                                salle / Autorisation (Optionnel)
                            </label>
                            <input type="file" id="document_autorisation" name="document_autorisation"
                                accept=".pdf,.jpg,.jpeg,.png" style="font-size: 0.82rem; margin-top: 4px;">
                            <small
                                style="color: var(--dash-muted); display: block; margin-top: 0.35rem; font-size: 0.75rem;">
                                Accord de réservation de lieu ou autorisation municipale.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 2 : INFORMATIONS DÉTAILLÉES DE L'ÉVÉNEMENT -->
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">2</div>
                        <div>
                            <h3 class="dash-card-title">Informations de la Manifestation</h3>
                            <div class="dash-card-subtitle">Présentez le programme, les horaires, l'affiche et le lieu aux
                                spectateurs</div>
                        </div>
                    </div>
                </div>

                <div class="dash-form-group">
                    <label for="nom"><i class="fa-solid fa-heading" style="color: var(--dash-primary);"></i> Nom complet de
                        l'événement *</label>
                    <input type="text" id="nom" name="nom" required placeholder="Ex: Mega Concert Live Abidjan 2026"
                        value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="dash-form-group">
                        <label for="categorie"><i class="fa-solid fa-layer-group" style="color: #0ea5e9;"></i> Catégorie
                            *</label>
                        <select id="categorie" name="categorie" required>
                            <option value="Concert">Concert / Musique</option>
                            <option value="Festival">Festival</option>
                            <option value="Spectacle">Spectacle / Humour / Théâtre</option>
                            <option value="Conférence">Conférence / Séminaire</option>
                            <option value="Sport">Sport & Tournoi</option>
                            <option value="Soirée">Soirée & Gala</option>
                            <option value="Autre">Autre événement</option>
                        </select>
                    </div>

                    <div class="dash-form-group">
                        <label for="image"><i class="fa-solid fa-image" style="color: #ec4899;"></i> Affiche officielle
                            (Poster)</label>
                        <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp"
                            style="padding: 0.5rem 0.75rem;">
                    </div>
                </div>

                <div class="dash-form-group">
                    <label for="description"><i class="fa-solid fa-align-left" style="color: var(--dash-muted);"></i>
                        Description & Programme détaillé *</label>
                    <textarea id="description" name="description" rows="4" required
                        placeholder="Artistes invités, déroulé de la soirée, temps forts, conditions d'accès..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="dash-form-group">
                        <label for="date_evenement"><i class="fa-regular fa-calendar"
                                style="color: var(--dash-primary);"></i> Date de l'événement *</label>
                        <input type="date" id="date_evenement" name="date_evenement" required
                            value="<?php echo htmlspecialchars($_POST['date_evenement'] ?? ''); ?>">
                    </div>

                    <div class="dash-form-group">
                        <label for="heure"><i class="fa-regular fa-clock" style="color: #f59e0b;"></i> Heure de début
                            *</label>
                        <input type="time" id="heure" name="heure" required
                            value="<?php echo htmlspecialchars($_POST['heure'] ?? ''); ?>">
                    </div>
                </div>

                <div class="dash-form-group">
                    <label for="lieu"><i class="fa-solid fa-location-dot" style="color: #ef4444;"></i> Salle / Lieu & Ville
                        *</label>
                    <input type="text" id="lieu" name="lieu" required
                        placeholder="Ex: Palais de la Culture de Treichville, Abidjan"
                        value="<?php echo htmlspecialchars($_POST['lieu'] ?? ''); ?>">
                </div>

                <div class="dash-form-group" style="margin-top: 1rem; margin-bottom: 0;">
                    <label for="infos_supplementaires"><i class="fa-solid fa-circle-info"
                            style="color: var(--dash-muted);"></i> Informations pratiques & Accès (Optionnel)</label>
                    <textarea id="infos_supplementaires" name="infos_supplementaires" rows="2"
                        placeholder="Accès parking, restrictions d'âge, consignes de sécurité..."><?php echo htmlspecialchars($_POST['infos_supplementaires'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- ÉTAPE 3 : TARIFICATION DE LA BILLETTERIE & COMMISSION -->
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">3</div>
                        <div>
                            <h3 class="dash-card-title">Grille Tarifaire, Quotas & Rémunération</h3>
                            <div class="dash-card-subtitle">Configurez vos types de billets et visualisez instantanément vos
                                gains nets</div>
                        </div>
                    </div>

                    <button type="button" onclick="addTicketRow()" class="dash-btn-action"
                        style="padding: 5px 12px; font-size: 0.8rem; background: var(--dash-primary); color: #ffffff;">
                        <i class="fa-solid fa-plus"></i> Ajouter un tarif
                    </button>
                </div>

                <!-- Encadré explicatif Commission 5% -->
                <div
                    style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                    <div
                        style="width: 42px; height: 42px; border-radius: 50%; background: #dbeafe; color: #1d4ed8; display: grid; place-items: center; font-size: 1.15rem; flex-shrink: 0;">
                        <i class="fa-solid fa-percent"></i>
                    </div>
                    <div>
                        <strong style="color: #1e3a8a; font-size: 0.9rem; display: block; margin-bottom: 2px;">
                            Commission Plateforme Standard : 5.0% par billet vendu
                        </strong>
                        <p style="color: #2563eb; font-size: 0.8rem; margin: 0; line-height: 1.4;">
                            La plateforme prélève automatiquement 5% sur chaque billet encaissé. Vous percevez <strong>95%
                                du montant brut</strong> directement sur votre solde disponible pour virement.
                        </p>
                    </div>
                </div>

                <div id="tickets-container">
                    <!-- Ligne de tarif 1 par défaut -->
                    <div class="dash-ticket-row">
                        <div>
                            <label
                                style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Catégorie</label>
                            <input type="text" name="ticket_nom[]" required placeholder="Ex: STANDARD" value="STANDARD"
                                style="padding: 0.6rem 0.8rem;">
                        </div>
                        <div>
                            <label
                                style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Prix
                                unitaire (F)</label>
                            <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="5000"
                                value="5000" oninput="calculateEventSummary()" style="padding: 0.6rem 0.8rem;">
                        </div>
                        <div>
                            <label
                                style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Places</label>
                            <input type="number" name="ticket_quantite[]" required min="1" placeholder="500" value="500"
                                oninput="calculateEventSummary()" style="padding: 0.6rem 0.8rem;">
                        </div>
                        <div>
                            <label
                                style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Frais
                                place (F)</label>
                            <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0"
                                title="Supplément pour place numérotée" oninput="calculateEventSummary()"
                                style="padding: 0.6rem 0.8rem;">
                        </div>
                        <div>
                            <button type="button" onclick="removeTicketRow(this)"
                                style="background: #fee2e2; color: #ef4444; border: 0; border-radius: 8px; padding: 0.65rem 0.8rem; cursor: pointer;"
                                title="Supprimer">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Synthèse Financière Estimée -->
                <div class="dash-summary-strip">
                    <div>
                        <small
                            style="color: #94a3b8; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Capacité
                            Totale</small>
                        <strong id="summary-capacity" style="font-size: 1.15rem; color: #ffffff;">500 places</strong>
                    </div>

                    <div>
                        <small
                            style="color: #94a3b8; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Recette
                            Brute Max</small>
                        <strong id="summary-gross" style="font-size: 1.15rem; color: #ffffff;">2 500 000 F</strong>
                    </div>

                    <div>
                        <small
                            style="color: #94a3b8; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Commission
                            (5%)</small>
                        <strong id="summary-commission" style="font-size: 1.15rem; color: #f59e0b;">125 000 F</strong>
                    </div>

                    <div>
                        <small
                            style="color: #94a3b8; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Votre
                            Gain Net Estimé</small>
                        <strong id="summary-net" style="font-size: 1.25rem; color: #10b981;">2 375 000 FCFA</strong>
                    </div>
                </div>

                <!-- Bouton de Soumission -->
                <button type="submit" class="dash-btn-action btn-primary"
                    style="width: 100%; justify-content: center; padding: 0.95rem 1.5rem; font-size: 1rem; border-radius: 12px; font-weight: 800;">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Transmettre la Demande d'Événement à l'Admin</span>
                </button>
            </div>
        </form>

        <!-- ==============================================================================
         ONGLET 2 : PROPOSER UNE CAMPAGNE DE COTISATION (FINANCEMENT PARTICIPATIF)
         ============================================================================== -->
    <?php elseif ($onglet === 'cotisation'): ?>
        <div style="max-width: 960px;">
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                    <div>
                        <h3 class="dash-card-title">
                            <i class="fa-solid fa-hand-holding-heart" style="color: #f97316;"></i>
                            Proposer une Campagne de Cotisation
                        </h3>
                        <div class="dash-card-subtitle">Financez votre projet ou événement : les visiteurs contribuent
                            directement par Mobile Money</div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="creer_campagne">

                    <div class="dash-form-group">
                        <label for="camp_titre"><i class="fa-solid fa-heading" style="color: #f97316;"></i> Titre de la
                            campagne *</label>
                        <input type="text" id="camp_titre" name="titre" required
                            placeholder="Ex: Festival Nuits d'Abidjan 2026 - Financement Scène & Son">
                    </div>

                    <div class="dash-form-group">
                        <label for="camp_desc"><i class="fa-solid fa-align-left" style="color: var(--dash-muted);"></i>
                            Présentation du projet & Utilisation des fonds</label>
                        <textarea id="camp_desc" name="description" rows="4"
                            placeholder="Expliquez en détail aux donateurs le projet financé, la destination des fonds et l'impact de leur contribution..."></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="dash-form-group">
                            <label for="camp_objectif"><i class="fa-solid fa-bullseye" style="color: #10b981;"></i> Montant
                                cible à atteindre (FCFA) *</label>
                            <input type="number" id="camp_objectif" name="montant_objectif" required min="1000" step="1000"
                                placeholder="Ex: 2000000">
                        </div>

                        <div class="dash-form-group">
                            <label for="camp_date"><i class="fa-regular fa-calendar"
                                    style="color: var(--dash-primary);"></i> Date limite de collecte (Optionnelle)</label>
                            <input type="date" id="camp_date" name="date_limite">
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="camp_image"><i class="fa-solid fa-image" style="color: #ec4899;"></i> Affiche / Image
                            illustrative de la campagne</label>
                        <input type="file" id="camp_image" name="image" accept="image/*" style="padding: 0.5rem 0.75rem;">
                    </div>

                    <button type="submit" class="dash-btn-action btn-primary"
                        style="width: 100%; justify-content: center; padding: 0.95rem 1.5rem; font-size: 1rem; border-radius: 12px; font-weight: 800; margin-top: 1rem;">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span>Soumettre la Campagne de Cotisation à l'Admin</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==============================================================================
         ONGLET 3 : CONFIGURATION DU VOTE PAYANT & CANDIDATS
         ============================================================================== -->
    <?php elseif ($onglet === 'vote'): ?>
        <div style="max-width: 960px;">
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                    <div>
                        <h3 class="dash-card-title">
                            <i class="fa-solid fa-vote-yea" style="color: var(--dash-primary);"></i>
                            Gestion des Concours & Votes du Public
                        </h3>
                        <div class="dash-card-subtitle">Organisez des compétitions avec candidats ou soumettez la
                            réalisation d'un projet au plébiscite des spectateurs</div>
                    </div>
                </div>

                <!-- Sélecteur de mode : Concours vs Vote de réalisation -->
                <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <button type="button" id="btn-mode-concours" onclick="switchVoteMode('concours')"
                        class="dash-chart-tab active"
                        style="padding: 0.65rem 1.35rem; font-size: 0.9rem; font-weight: 800; border-radius: 10px; border: 1px solid var(--dash-border); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
                        <span>1. Concours & Compétition (Plusieurs Candidats)</span>
                    </button>

                    <button type="button" id="btn-mode-realisation" onclick="switchVoteMode('realisation')"
                        class="dash-chart-tab"
                        style="padding: 0.65rem 1.35rem; font-size: 0.9rem; font-weight: 800; border-radius: 10px; border: 1px solid var(--dash-border); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-square-poll-vertical" style="color: #0284c7;"></i>
                        <span>2. Vote pour la Réalisation d'un Événement</span>
                    </button>
                </div>

                <!-- =====================================================================
                     PANNEAU A : CONCOURS & COMPÉTITION (PLUSIEURS CANDIDATS DYNAMIQUES)
                     ===================================================================== -->
                <div id="panel-vote-concours" style="display: block;">
                    <form method="POST" enctype="multipart/form-data"
                        style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 14px; padding: 1.75rem; margin-bottom: 1.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                        <input type="hidden" name="action" value="proposer_concours">

                        <div
                            style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem;">
                            <span
                                style="background: #f59e0b; color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 0.9rem;">
                                <i class="fa-solid fa-trophy"></i>
                            </span>
                            <div>
                                <h4 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800;">
                                    Créer un Concours / Compétition avec Candidats
                                </h4>
                                <small style="color: var(--dash-muted); font-size: 0.8rem;">
                                    Saisissez le nom du concours, ajoutez son affiche officielle et enregistrez vos
                                    différents participants.
                                </small>
                            </div>
                        </div>

                        <!-- 1. Informations du Concours (Nom rentré manuellement + Photo de l'événement) -->
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_nom"><i class="fa-solid fa-tag"
                                        style="color: var(--dash-primary);"></i> Nom du concours / de la compétition
                                    *</label>
                                <input type="text" id="concours_nom" name="nom" required
                                    placeholder="Ex: Miss Abidjan 2026, Tremplin Voix d'Or, Battle Dance..."
                                    style="font-weight: 600;">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_image"><i class="fa-solid fa-image" style="color: #ec4899;"></i> Photo
                                    / Affiche officielle de l'événement</label>
                                <input type="file" id="concours_image" name="image" accept="image/*"
                                    style="padding: 0.5rem; background: #f8fafc; border-radius: 8px;">
                            </div>
                        </div>

                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_date"><i class="fa-solid fa-calendar"
                                        style="color: var(--dash-primary);"></i> Date de fin / finale *</label>
                                <input type="date" id="concours_date" name="date_evenement" required
                                    value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_heure"><i class="fa-solid fa-clock"
                                        style="color: var(--dash-primary);"></i> Heure de clôture *</label>
                                <input type="time" id="concours_heure" name="heure" required value="20:00">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_lieu"><i class="fa-solid fa-location-dot" style="color: #ef4444;"></i>
                                    Ville / Lieu</label>
                                <input type="text" id="concours_lieu" name="lieu"
                                    placeholder="Ex: Palais de la Culture, Abidjan" value="Abidjan">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="concours_prix_vote"><i class="fa-solid fa-coins" style="color: #f59e0b;"></i>
                                    Prix du vote par candidat (FCFA)</label>
                                <input type="number" id="concours_prix_vote" name="prix_vote" min="0" step="500"
                                    placeholder="0 = gratuit — Ex: 500 pour 500 F / vote">
                            </div>
                        </div>

                        <div class="dash-form-group" style="margin-bottom: 1.5rem;">
                            <label for="concours_desc"><i class="fa-solid fa-align-left"
                                    style="color: var(--dash-muted);"></i> Description & Règlement du concours</label>
                            <textarea id="concours_desc" name="description" rows="2"
                                placeholder="Présentation du concours, règles de vote, récompenses à la clé..."></textarea>
                        </div>

                        <!-- 2. Bloc dynamique des multiples candidats -->
                        <div
                            style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.35rem; margin-bottom: 1.5rem;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                <div>
                                    <strong style="color: var(--dash-text); font-size: 0.98rem;">
                                        <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i> Participants &
                                        Candidats en Compétition
                                    </strong>
                                    <small style="display: block; color: var(--dash-muted); font-size: 0.75rem;">
                                        Ajoutez autant de candidats que nécessaire avec leur nom, biographie et photo
                                        individuelle.
                                    </small>
                                </div>
                                <button type="button" onclick="addCandidateRowVote()" class="dash-btn-action"
                                    style="background: var(--dash-primary); color: #ffffff; padding: 6px 14px; font-size: 0.82rem; border-radius: 8px;">
                                    <i class="fa-solid fa-user-plus"></i> + Ajouter un candidat
                                </button>
                            </div>

                            <div id="wrapper-candidates-rows" style="display: flex; flex-direction: column; gap: 0.85rem;">
                            </div>
                        </div>

                        <button type="submit" class="dash-btn-action btn-primary"
                            style="font-size: 0.95rem; padding: 0.8rem 1.75rem; border-radius: 10px;">
                            <i class="fa-solid fa-paper-plane"></i> Soumettre la Demande de Concours avec ses Candidats
                        </button>
                    </form>
                </div>

                <!-- =====================================================================
                     PANNEAU B : VOTE POUR LA RÉALISATION D'UN ÉVÉNEMENT (INFORMATIONS NÉCESSAIRES)
                     ===================================================================== -->
                <div id="panel-vote-realisation" style="display: none;">
                    <form method="POST" enctype="multipart/form-data"
                        style="background: #ffffff; border: 1px solid #bfdbfe; border-radius: 14px; padding: 1.75rem; margin-bottom: 1.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                        <input type="hidden" name="action" value="proposer_vote_realisation">

                        <div
                            style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid #dbeafe; padding-bottom: 1rem;">
                            <span
                                style="background: #0284c7; color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 0.9rem;">
                                <i class="fa-solid fa-square-poll-vertical"></i>
                            </span>
                            <div>
                                <h4 style="margin: 0; font-size: 1.1rem; color: #1e3a8a; font-weight: 800;">
                                    Vote pour la Réalisation d'un Événement (Plébiscite)
                                </h4>
                                <small style="color: #64748b; font-size: 0.8rem;">
                                    Renseignez les informations de l'événement et la question soumise aux spectateurs (aucun
                                    candidat requis).
                                </small>
                            </div>
                        </div>

                        <!-- 1. Informations de l'Événement (Nom rentré manuellement + Photo de l'événement) -->
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_nom" style="color: #1e3a8a; font-weight: 800;">
                                    <i class="fa-solid fa-bullhorn" style="color: #0284c7;"></i> Nom de l'événement / du
                                    projet envisagé *
                                </label>
                                <input type="text" id="realisation_nom" name="nom" required
                                    placeholder="Ex: Concert de Burna Boy à Abidjan, Festival Afro-Beat..."
                                    style="font-weight: 600;">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_image" style="color: #1e3a8a; font-weight: 800;">
                                    <i class="fa-solid fa-image" style="color: #0284c7;"></i> Visuel / Affiche de
                                    l'événement à réaliser
                                </label>
                                <input type="file" id="realisation_image" name="image" accept="image/*"
                                    style="padding: 0.5rem; background: #f8fafc; border-radius: 8px;">
                            </div>
                        </div>

                        <!-- 2. Question ou Proposition soumise au vote du public -->
                        <div class="dash-form-group" style="margin-bottom: 1.25rem;">
                            <label for="realisation_vote_question" style="color: #1e3a8a; font-weight: 800;">
                                <i class="fa-solid fa-circle-question" style="color: #0284c7;"></i> Question ou Proposition
                                soumise au public *
                            </label>
                            <input type="text" id="realisation_vote_question" name="vote_question" required
                                placeholder="Ex: Souhaitez-vous la tenue du spectacle de Burna Boy à Abidjan en Décembre ?"
                                style="background: #ffffff; border: 1.5px solid #93c5fd;">
                            <small style="color: #64748b; font-size: 0.75rem; margin-top: 3px; display: block;">
                                Cette question figurera en tête de vote pour inviter les spectateurs à exprimer leur accord
                                et soutien.
                            </small>
                        </div>

                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_date" style="color: #1e3a8a; font-weight: 800;"><i
                                        class="fa-solid fa-calendar" style="color: #0284c7;"></i> Période / Date envisagée
                                    *</label>
                                <input type="date" id="realisation_date" name="date_evenement" required
                                    value="<?php echo date('Y-m-d', strtotime('+2 months')); ?>">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_heure" style="color: #1e3a8a; font-weight: 800;"><i
                                        class="fa-solid fa-clock" style="color: #0284c7;"></i> Heure envisagée *</label>
                                <input type="time" id="realisation_heure" name="heure" required value="20:00">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_lieu" style="color: #1e3a8a; font-weight: 800;"><i
                                        class="fa-solid fa-location-dot" style="color: #ef4444;"></i> Lieu / Ville
                                    envisagée</label>
                                <input type="text" id="realisation_lieu" name="lieu"
                                    placeholder="Ex: Stade Félix Houphouët-Boigny, Abidjan" value="Abidjan">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_prix_vote" style="color: #1e3a8a; font-weight: 800;"><i
                                        class="fa-solid fa-coins" style="color: #f59e0b;"></i> Prix du vote de soutien
                                    (FCFA)</label>
                                <input type="number" id="realisation_prix_vote" name="prix_vote" min="0" step="500"
                                    placeholder="0 = gratuit — Ex: 1000 pour 1 000 FCFA">
                            </div>
                        </div>

                        <div class="dash-form-group" style="margin-bottom: 1.35rem;">
                            <label for="realisation_desc" style="color: #1e3a8a; font-weight: 800;"><i
                                    class="fa-solid fa-align-left" style="color: var(--dash-muted);"></i> Présentation du
                                projet & Enjeux de la réalisation</label>
                            <textarea id="realisation_desc" name="description" rows="2"
                                placeholder="Expliquez pourquoi le public doit voter pour que ce projet voie le jour..."></textarea>
                        </div>

                        <div
                            style="background: #eff6ff; border-left: 4px solid #0284c7; padding: 0.85rem 1.15rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.82rem; color: #1e40af;">
                            <i class="fa-solid fa-circle-info" style="margin-right: 5px;"></i>
                            <strong>Aucun candidat à enregistrer</strong> : En mode « Vote pour la réalisation », les
                            spectateurs votent pour valider ou encourager la tenue globale du projet.
                        </div>

                        <button type="submit" class="dash-btn-action"
                            style="font-size: 0.95rem; padding: 0.8rem 1.75rem; background: #0284c7; color: #ffffff; border-radius: 10px;">
                            <i class="fa-solid fa-paper-plane"></i> Soumettre la Demande de Vote de Réalisation
                        </button>
                    </form>
                </div>

                <!-- =====================================================================
                     3. TABLEAU DE BORD : CANDIDATS EN LICE & VOTES DE RÉALISATION
                     ===================================================================== -->
                <!-- A. Candidats enregistrés pour les Concours -->
                <div style="margin-bottom: 2rem;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                        <h4 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 800;">
                            <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i> Candidats enregistrés aux
                            Concours (<?php echo count($candidats_promoteur); ?>)
                        </h4>
                    </div>

                    <?php if (empty($candidats_promoteur)): ?>
                        <div
                            style="background: #f8fafc; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 2rem; text-align: center; color: var(--dash-muted); font-size: 0.88rem;">
                            <i class="fa-solid fa-user-xmark"
                                style="font-size: 1.8rem; color: #cbd5e1; display: block; margin-bottom: 0.5rem;"></i>
                            Aucun candidat enregistré pour le moment. Remplissez le formulaire de concours ci-dessus pour
                            ajouter vos participants.
                        </div>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                            <?php foreach ($candidats_promoteur as $cp): ?>
                                <?php
                                $cp_photo = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                                if (!empty($cp['photo'])) {
                                    if (strpos($cp['photo'], 'http') === 0) {
                                        $cp_photo = htmlspecialchars($cp['photo']);
                                    } elseif (file_exists('../uploads/candidats/' . $cp['photo'])) {
                                        $cp_photo = '../uploads/candidats/' . htmlspecialchars($cp['photo']);
                                    }
                                }
                                ?>
                                <div
                                    style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                    <div style="display: flex; gap: 0.85rem; align-items: center;">
                                        <img src="<?php echo $cp_photo; ?>" alt="<?php echo htmlspecialchars($cp['nom']); ?>"
                                            style="width: 55px; height: 55px; border-radius: 10px; object-fit: cover; border: 1px solid var(--dash-border); flex-shrink: 0;">
                                        <div style="min-width: 0; flex: 1;">
                                            <strong
                                                style="color: var(--dash-text); font-size: 0.95rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?php echo htmlspecialchars($cp['nom']); ?>
                                            </strong>
                                            <span
                                                style="color: #6366f1; font-weight: 700; display: block; font-size: 0.75rem; margin-top: 2px;">
                                                <i class="fa-solid fa-trophy"></i> <?php echo htmlspecialchars($cp['event_nom']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($cp['description'])): ?>
                                        <p
                                            style="margin: 0; font-size: 0.78rem; color: var(--dash-muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                            <?php echo htmlspecialchars($cp['description']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 0.65rem; margin-top: auto;">
                                        <span
                                            style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 6px; padding: 3px 8px; font-size: 0.78rem; font-weight: 800;">
                                            <i class="fa-solid fa-vote-yea"></i> <?php echo (int) $cp['nb_votes']; ?>
                                            vote<?php echo (int) $cp['nb_votes'] > 1 ? 's' : ''; ?>
                                        </span>

                                        <form method="POST"
                                            onsubmit="return confirm('Confirmez-vous la suppression de ce participant ?');"
                                            style="margin: 0;">
                                            <input type="hidden" name="action" value="supprimer_candidat">
                                            <input type="hidden" name="candidat_id" value="<?php echo (int) $cp['id']; ?>">
                                            <button type="submit"
                                                style="background: transparent; border: 0; color: #ef4444; cursor: pointer; font-size: 0.85rem; padding: 4px;"
                                                title="Supprimer ce candidat">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- B. Programmes en mode « Vote pour la Réalisation d'Événement » -->
                <?php
                $evts_realisation = array_filter($evts_vote, fn($e) => ($e['type_vote'] ?? '') === 'realisation_evenement');
                ?>
                <div>
                    <h4 style="margin: 0 0 0.85rem; font-size: 1rem; color: var(--dash-text); font-weight: 800;">
                        <i class="fa-solid fa-square-poll-vertical" style="color: #0284c7;"></i> Votes pour la Réalisation
                        d'Événements (<?php echo count($evts_realisation); ?>)
                    </h4>

                    <?php if (empty($evts_realisation)): ?>
                        <div
                            style="background: #f8fafc; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 1.5rem; text-align: center; color: var(--dash-muted); font-size: 0.85rem;">
                            Aucun événement actuellement configuré en vote de réalisation.
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <?php foreach ($evts_realisation as $er): ?>
                                <div
                                    style="background: #ffffff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                    <div>
                                        <strong style="color: var(--dash-text); font-size: 0.95rem; display: block;">
                                            <?php echo htmlspecialchars($er['nom']); ?>
                                        </strong>
                                        <span
                                            style="color: #0284c7; font-size: 0.82rem; font-weight: 700; display: block; margin-top: 2px;">
                                            <i class="fa-solid fa-circle-question"></i>
                                            <?php echo htmlspecialchars($er['vote_question'] ?: 'Soutenez la tenue de cet événement'); ?>
                                        </span>
                                    </div>

                                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                                        <span
                                            style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 8px; padding: 4px 12px; font-size: 0.82rem; font-weight: 800;">
                                            <i class="fa-solid fa-check"></i> <?php echo (int) ($er['nb_votes_realisation'] ?? 0); ?>
                                            votes de soutien
                                        </span>
                                        <span
                                            style="background: #f1f5f9; color: var(--dash-muted); border-radius: 8px; padding: 4px 10px; font-size: 0.78rem;">
                                            Tarif :
                                            <?php echo (float) $er['prix_vote'] > 0 ? number_format((float) $er['prix_vote'], 0, ',', ' ') . ' F' : 'Gratuit'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    const COMMISSION_RATE = 0.05; // 5%

    // 1. Basculement dynamique Personne Physique vs Personne Morale
    function updateEntityType(input) {
        const isMorale = (input.value === 'morale');
        const fieldsMorale = document.getElementById('fields-morale');
        const labelJustif = document.getElementById('label-justif');
        const optPhysique = document.getElementById('option-physique');
        const optMorale = document.getElementById('option-morale');

        if (isMorale) {
            optMorale.classList.add('active');
            optPhysique.classList.remove('active');
            fieldsMorale.style.display = 'block';
            labelJustif.innerHTML = '<i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Registre de Commerce (RCCM) / Statuts (PDF, JPG) *';
        } else {
            optPhysique.classList.add('active');
            optMorale.classList.remove('active');
            fieldsMorale.style.display = 'none';
            labelJustif.innerHTML = '<i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Pièce d\'identité (CNI / Passeport) (PDF, JPG) *';
        }
    }

    // 2. Ajout dynamique d'une ligne de tarif
    function addTicketRow() {
        const container = document.getElementById('tickets-container');
        const row = document.createElement('div');
        row.className = 'dash-ticket-row';
        row.innerHTML = `
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Catégorie</label>
                <input type="text" name="ticket_nom[]" required placeholder="Ex: VIP" value="VIP" style="padding: 0.6rem 0.8rem;">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Prix unitaire (F)</label>
                <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="15000" value="15000" oninput="calculateEventSummary()" style="padding: 0.6rem 0.8rem;">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Places</label>
                <input type="number" name="ticket_quantite[]" required min="1" placeholder="100" value="100" oninput="calculateEventSummary()" style="padding: 0.6rem 0.8rem;">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--dash-text); display: block; margin-bottom: 4px;">Frais place (F)</label>
                <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0" title="Supplément facturé pour le choix de place" oninput="calculateEventSummary()" style="padding: 0.6rem 0.8rem;">
            </div>
            <div>
                <button type="button" onclick="removeTicketRow(this)" style="background: #fee2e2; color: #ef4444; border: 0; border-radius: 8px; padding: 0.65rem 0.8rem; cursor: pointer;" title="Supprimer">
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

    // 3. Calcul en temps réel de la capacité, du brut, de la commission et du net
    function calculateEventSummary() {
        const priceInputs = document.querySelectorAll('input[name="ticket_prix[]"]');
        const qtyInputs = document.querySelectorAll('input[name="ticket_quantite[]"]');

        let totalCapacity = 0;
        let totalGross = 0;

        for (let i = 0; i < priceInputs.length; i++) {
            const price = Number(priceInputs[i].value) || 0;
            const qty = Number(qtyInputs[i].value) || 0;

            totalCapacity += qty;
            totalGross += (price * qty);
        }

        const totalCommission = totalGross * COMMISSION_RATE;
        const totalNet = totalGross - totalCommission;

        const capEl = document.getElementById('summary-capacity');
        const grossEl = document.getElementById('summary-gross');
        const commEl = document.getElementById('summary-commission');
        const netEl = document.getElementById('summary-net');

        if (capEl) capEl.textContent = totalCapacity.toLocaleString('fr-FR') + ' places';
        if (grossEl) grossEl.textContent = totalGross.toLocaleString('fr-FR') + ' FCFA';
        if (commEl) commEl.textContent = totalCommission.toLocaleString('fr-FR') + ' FCFA';
        if (netEl) netEl.textContent = totalNet.toLocaleString('fr-FR') + ' FCFA';
    }

    // Initialisation
    calculateEventSummary();

    // 4. Basculement entre Concours et Vote de réalisation
    function switchVoteMode(mode) {
        const pConcours = document.getElementById('panel-vote-concours');
        const pRealisation = document.getElementById('panel-vote-realisation');
        const bConcours = document.getElementById('btn-mode-concours');
        const bRealisation = document.getElementById('btn-mode-realisation');

        if (mode === 'realisation') {
            if (pRealisation) pRealisation.style.display = 'block';
            if (pConcours) pConcours.style.display = 'none';
            if (bRealisation) bRealisation.classList.add('active');
            if (bConcours) bConcours.classList.remove('active');
        } else {
            if (pConcours) pConcours.style.display = 'block';
            if (pRealisation) pRealisation.style.display = 'none';
            if (bConcours) bConcours.classList.add('active');
            if (bRealisation) bRealisation.classList.remove('active');
        }
    }

    // 5. Ajout dynamique de candidats pour les concours
    function addCandidateRowVote() {
        const wrapper = document.getElementById('wrapper-candidates-rows');
        if (!wrapper) return;

        const count = wrapper.children.length + 1;
        const row = document.createElement('div');
        row.className = 'dash-cand-vote-row';
        row.style.background = '#f8fafc';
        row.style.border = '1px solid var(--dash-border)';
        row.style.borderRadius = '10px';
        row.style.padding = '0.85rem 1rem';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = 'repeat(auto-fit, minmax(220px, 1fr)) 45px';
        row.style.gap = '0.75rem';
        row.style.alignItems = 'center';

        row.innerHTML = `
            <div>
                <label style="font-size: 0.75rem; font-weight: 800; color: var(--dash-text); display: block; margin-bottom: 3px;">
                    Nom du candidat #${count} *
                </label>
                <input type="text" name="cand_nom[]" required placeholder="Ex: Candidat #${count}" style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--dash-border); border-radius: 8px; background: #ffffff;">
            </div>

            <div>
                <label style="font-size: 0.75rem; font-weight: 800; color: var(--dash-text); display: block; margin-bottom: 3px;">
                    Biographie & Talent
                </label>
                <input type="text" name="cand_desc[]" placeholder="Description courte ou talent..." style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--dash-border); border-radius: 8px; background: #ffffff;">
            </div>

            <div>
                <label style="font-size: 0.75rem; font-weight: 800; color: var(--dash-text); display: block; margin-bottom: 3px;">
                    Photo officielle
                </label>
                <input type="file" name="cand_photo[]" accept="image/*" style="width: 100%; padding: 0.4rem; font-size: 0.75rem; background: #ffffff; border-radius: 8px;">
            </div>

            <div style="text-align: right; padding-top: 1.1rem;">
                <button type="button" onclick="this.closest('.dash-cand-vote-row').remove()" style="background: #fee2e2; color: #ef4444; border: 0; border-radius: 8px; padding: 0.55rem 0.75rem; cursor: pointer;" title="Supprimer ce candidat">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `;
        wrapper.appendChild(row);
    }

    // Auto-remplir avec 2 lignes au chargement pour guider le promoteur
    document.addEventListener('DOMContentLoaded', function () {
        const wrapper = document.getElementById('wrapper-candidates-rows');
        if (wrapper && wrapper.children.length === 0) {
            addCandidateRowVote();
            addCandidateRowVote();
        }
    });

</script>

<?php include 'footer.php'; ?>