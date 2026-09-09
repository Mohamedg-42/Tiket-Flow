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

// ------------------------------------------------------------------------------
// Statut Juridique Défini à la Création du Promoteur (Héritage Automatique)
// ------------------------------------------------------------------------------
$user_id = (int)($_SESSION['user_id'] ?? 0);
$st_p = $pdo->prepare("
    SELECT p.*, u.nom, u.prenom, u.email, u.telephone 
    FROM promoters p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.user_id = ? 
    LIMIT 1
");
$st_p->execute([$user_id]);
$prom_info = $st_p->fetch(PDO::FETCH_ASSOC);

if (!$prom_info) {
    $st_u = $pdo->prepare("SELECT id, nom, prenom, email, telephone FROM users WHERE id = ? LIMIT 1");
    $st_u->execute([$user_id]);
    $u_fb = $st_u->fetch(PDO::FETCH_ASSOC) ?: [];
    $prom_info = [
        'type_entite' => 'physique',
        'nom_commercial' => trim(($u_fb['prenom'] ?? '') . ' ' . ($u_fb['nom'] ?? '')),
        'numero_registre' => '',
        'representant_legal' => '',
        'nom' => $u_fb['nom'] ?? '',
        'prenom' => $u_fb['prenom'] ?? '',
        'email' => $u_fb['email'] ?? '',
        'telephone' => $u_fb['telephone'] ?? ''
    ];
}

$type_personne = $prom_info['type_entite'] ?? 'physique';
$nom_structure = !empty($prom_info['nom_commercial']) ? $prom_info['nom_commercial'] : trim(($prom_info['prenom'] ?? '') . ' ' . ($prom_info['nom'] ?? ''));
$numero_rccm   = $prom_info['numero_registre'] ?? '';

// Récupération dynamique des salles actives créées par l'administrateur
$db_salles = [];
try {
    $db_salles = $pdo->query("SELECT id, nom, ville, commune, capacite, type_salle FROM salles WHERE statut = 'active' ORDER BY ville ASC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $db_salles = [];
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Catégorie personnalisée ou sélectionnée
    $categorie = trim($_POST['categorie'] ?? 'Concert');
    $categorie_custom = trim($_POST['categorie_custom'] ?? '');
    if ($categorie === 'Autre' || !empty($categorie_custom)) {
        if (!empty($categorie_custom)) {
            $categorie = $categorie_custom;
        }
    }
    if (empty($categorie)) {
        $categorie = 'Concert';
    }

    $date_evenement = $_POST['date_evenement'] ?? '';
    $heure = $_POST['heure'] ?? '';

    // Détermination de la Ville et de la Salle (Connue / Répertoriée vs Espace Non Répertorié)
    $ville = trim($_POST['ville'] ?? '');
    $ville_custom = trim($_POST['ville_custom'] ?? '');
    if ($ville === 'Autre' && !empty($ville_custom)) {
        $ville = $ville_custom;
    }
    if (empty($ville)) $ville = 'Abidjan';

    $salle_connue = $_POST['salle_connue'] ?? 'oui';
    $salle_id = null;

    if ($salle_connue === 'non') {
        // Espace Non Répertorié / Lieu Libre : Pas de vue de scène 3D requise
        $statut_salle = trim($_POST['statut_salle_inconnue'] ?? 'Espace non répertorié');
        $espace_nom = trim($_POST['espace_nom_custom'] ?? '');
        if (!empty($espace_nom)) {
            $lieu = $espace_nom . ' (' . $ville . ')';
        } else {
            if (empty($statut_salle)) $statut_salle = 'Espace non répertorié';
            $lieu = $statut_salle . ' (' . $ville . ')';
        }
    } else {
        // Salle Répertoriée : Filtrée selon la ville avec vue 3D
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

    $prix_vote = (float) ($_POST['prix_vote'] ?? 0);
    $infos_supp = trim($_POST['infos_supplementaires'] ?? '');

    // Types de tickets
    $ticket_noms = $_POST['ticket_nom'] ?? [];
    $ticket_prix = $_POST['ticket_prix'] ?? [];
    $ticket_qtys = $_POST['ticket_quantite'] ?? [];
    $ticket_frais = $_POST['ticket_frais'] ?? []; // Frais supplémentaires si le client choisit sa place

    // Validation
    if (empty($nom) || empty($description) || empty($date_evenement) || empty($heure) || empty($lieu)) {
        $message = "Veuillez remplir tous les champs obligatoires de l'événement.";
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

        // 4. Structuration des types de tickets (avec quota de places au choix défini par le promoteur)
        $ticket_choix = $_POST['ticket_places_choisies'] ?? [];
        $tickets_data = [];
        for ($i = 0; $i < count($ticket_noms); $i++) {
            $t_nom = trim($ticket_noms[$i]);
            $t_prix = (float) ($ticket_prix[$i] ?? 0);
            $t_qty = (int) ($ticket_qtys[$i] ?? 0);
            $t_choix = (isset($ticket_choix[$i]) && is_numeric($ticket_choix[$i])) ? max(0, min($t_qty, (int)$ticket_choix[$i])) : 0;

            if (!empty($t_nom) && $t_prix > 0 && $t_qty > 0) {
                $tickets_data[] = [
                    'nom' => $t_nom,
                    'prix' => $t_prix,
                    'quantite' => $t_qty,
                    'places_choisies' => $t_choix,
                    'frais_place' => max(0, (float) ($ticket_frais[$i] ?? 0))
                ];
            }
        }

        if (empty($tickets_data)) {
            $message = "Veuillez configurer au moins un type de ticket valide (nom, prix supérieur à 0 et quantité positive).";
            $msg_type = "error";
        } else {
            $candidats_json = null;

            // Calcul dynamique du taux de commission selon l'ampleur de l'événement
            require_once '../includes/commission.php';
            $req_capacity = 0;
            $req_gross = 0;
            foreach ($tickets_data as $tk) {
                $req_capacity += (int)$tk['quantite'];
                $req_gross += ((int)$tk['quantite'] * (float)$tk['prix']);
            }
            $scale_info = get_event_scale_tier($req_capacity, $req_gross);
            $commission_rate = (isset($prom_info['commission_rate']) && (float)$prom_info['commission_rate'] > 0 && abs((float)$prom_info['commission_rate'] - 5.00) > 0.001) 
                ? (float)$prom_info['commission_rate'] 
                : (float)$scale_info['rate'];

            try {
                $sql = "INSERT INTO event_requests (
                            user_id, nom, description, image, categorie, date_evenement, heure, lieu, prix_vote,
                            infos_supplementaires, type_personne, nom_structure, numero_rccm,
                            document_justificatif, document_autorisation, ticket_types_data, candidats_data, commission_rate, salle_id, statut
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')";

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
                    $candidats_json,
                    $commission_rate,
                    $salle_id
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

<style>
    /* ==============================================================================
       RESPONSIVE DESIGN SYSTEM — PROPOSER UN ÉVÉNEMENT (TIKÉLI PRO)
       Optimisation Multi-Écrans, Tablette, Mobile & Petits Écrans (Zéro Débordement)
       ============================================================================== */

    /* 1. Base du Conteneur & Protection Débordement */
    .dash-container {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }

    form[style*="max-width: 960px"],
    div[style*="max-width: 960px"] {
        width: 100% !important;
        box-sizing: border-box !important;
    }

    .dash-step-badge {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #FF4A0D;
        color: #ffffff;
        display: grid;
        place-items: center;
        font-weight: 800;
        font-size: 0.85rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(255, 74, 13, 0.2);
    }

    /* 2. En-tête de page & Titre fluide */
    .dash-header-section {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .dash-title-box {
        min-width: 0;
        flex: 1 1 280px;
    }

    .dash-title-box h1 {
        font-size: clamp(1.35rem, 3.5vw, 1.85rem) !important;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.25;
        margin: 0 0 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        color: var(--dash-text, #000000);
    }

    .dash-title-box p {
        font-size: clamp(0.82rem, 2vw, 0.95rem);
        color: var(--dash-muted, #737373);
        margin: 0;
        line-height: 1.4;
    }

    .dash-filter-bar .dash-btn-action {
        white-space: nowrap;
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* 3. Onglets de Navigation */
    .events-creation-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        background: #ffffff;
        padding: 6px;
        border-radius: 12px;
        border: 1px solid var(--dash-border, #E5E5E5);
        width: fit-content;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }

    .events-creation-tabs::-webkit-scrollbar {
        display: none;
    }

    .events-creation-tabs .dash-chart-tab {
        flex-shrink: 0;
        white-space: nowrap;
        text-decoration: none;
        border-radius: 8px;
        padding: 0.55rem 1rem;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    /* 4. Bannière d'Identité & Promoteur */
    .dash-identity-banner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .dash-identity-banner > div:first-child {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
        flex: 1 1 320px;
    }

    /* 5. Groupes de Formulaire & Entrées */
    .dash-form-group {
        margin-bottom: 1.15rem;
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }

    .dash-form-group label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        font-weight: 800;
        color: var(--dash-text, #000000);
        margin-bottom: 0.4rem;
        letter-spacing: -0.2px;
        flex-wrap: wrap;
    }

    .dash-form-group input,
    .dash-form-group select,
    .dash-form-group textarea {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0.72rem 0.95rem;
        background: #F5F5F5;
        border: 1px solid var(--dash-border, #E5E5E5);
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.88rem;
        color: var(--dash-text, #000000);
        outline: none;
        transition: all 0.2s ease;
        box-sizing: border-box !important;
    }

    .dash-form-group input:focus,
    .dash-form-group select:focus,
    .dash-form-group textarea:focus {
        background: #ffffff;
        border-color: #FF4A0D;
        box-shadow: 0 0 0 3px rgba(255, 74, 13, 0.12);
    }

    .dash-form-group input[type="file"] {
        background: #F5F5F5;
        padding: 0.55rem 0.75rem;
        font-size: 0.82rem;
        cursor: pointer;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* 6. Grilles 2 colonnes & Localisation */
    .form-row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .venue-main-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .venue-toggle-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        width: 100%;
        box-sizing: border-box;
    }

    .venue-toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0.65rem 0.85rem;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
        box-sizing: border-box;
        min-height: 44px;
        user-select: none;
    }

    .venue-toggle-btn input[type="radio"] {
        width: auto !important;
        margin: 0 !important;
        flex-shrink: 0;
    }

    .venue-details-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 1rem;
    }

    .dash-entity-card {
        border: 2px solid var(--dash-border, #E5E5E5);
        border-radius: 12px;
        padding: 1.1rem 1.25rem;
        cursor: pointer;
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        transition: all 0.2s ease;
        background: #F5F5F5;
    }

    .dash-entity-card:hover {
        border-color: #CBD5E1;
        background: #ffffff;
    }

    .dash-entity-card.active {
        border-color: #FF4A0D;
        background: #FFF2ED;
        box-shadow: 0 4px 14px rgba(255, 74, 13, 0.08);
    }

    .step-header-with-action {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    /* 7. Bannière & Grille de Commission par Ampleur d'Événement */
    .dash-commission-notice {
        background: #FFFBF9;
        border: 1px solid #F3DDD5;
        border-radius: 12px;
        padding: 1.15rem 1.25rem;
        margin-bottom: 1.25rem;
        box-sizing: border-box;
    }

    .scale-tiers-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.75rem;
        margin-top: 0.85rem;
    }

    .scale-tier-card {
        background: #ffffff;
        border: 1.5px solid #E5E5E5;
        border-radius: 10px;
        padding: 0.85rem 0.65rem 0.75rem;
        text-align: center;
        transition: all 0.2s ease;
        position: relative;
    }

    .scale-tier-card.is-active {
        border-color: #FF4A0D;
        background: #FFF2ED;
        box-shadow: 0 3px 10px rgba(255, 74, 13, 0.12);
    }

    .scale-tier-card .tier-badge {
        font-size: 0.7rem;
        font-weight: 800;
        color: #737373;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 2px;
    }

    .scale-tier-card.is-active .tier-badge {
        color: #FF4A0D;
    }

    .scale-tier-card .tier-rate {
        font-size: 1.35rem;
        font-weight: 900;
        color: #000000;
        line-height: 1.15;
    }

    .scale-tier-card.is-active .tier-rate {
        color: #FF4A0D;
    }

    .scale-tier-card .tier-range {
        font-size: 0.72rem;
        color: #737373;
        margin-top: 3px;
    }

    .scale-tier-card .tier-tag {
        display: none;
        position: absolute;
        top: -9px;
        left: 50%;
        transform: translateX(-50%);
        background: #FF4A0D;
        color: #ffffff;
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 1px 7px;
        border-radius: 999px;
        white-space: nowrap;
        letter-spacing: 0.4px;
    }

    .scale-tier-card.is-active .tier-tag {
        display: inline-block;
    }

    .scale-live-status {
        margin-top: 0.85rem;
        padding: 0.7rem 0.95rem;
        background: #ffffff;
        border: 1px solid #F0D9D0;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.82rem;
        color: #000000;
        line-height: 1.4;
    }

    /* 8. Lignes de Billets / Tarifs (Responsive & Anti-Débordement) */
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

    /* 9. Synthèse Financière Estimée */
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
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
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
        font-size: clamp(1.05rem, 2.8vw, 1.25rem);
        display: block;
        line-height: 1.2;
        white-space: nowrap;
    }

    /* 10. Boutons de Soumission Fluides */
    .btn-submit-large {
        width: 100% !important;
        justify-content: center !important;
        padding: 0.95rem 1.5rem !important;
        font-size: clamp(0.92rem, 2.5vw, 1.05rem) !important;
        border-radius: 12px !important;
        font-weight: 800 !important;
        white-space: normal !important;
        text-align: center !important;
        line-height: 1.35 !important;
        min-height: 50px !important;
        box-sizing: border-box !important;
    }

    /* 11. Sélecteur de Modes de Vote (Tab 3) */
    .vote-mode-buttons {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .vote-mode-buttons button {
        flex: 1 1 240px;
        justify-content: center;
        white-space: normal;
        text-align: center;
        line-height: 1.3;
    }

    /* ==============================================================================
       POINTS DE RUPTURE (RESPONSIVE BREAKPOINTS)
       ============================================================================== */

    /* Écrans Moyens, Laptops & Tablettes Paysage (<= 1250px) */
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
        .dash-ticket-row .ticket-col-price {
            grid-column: 1 / 2 !important;
        }
        .dash-ticket-row .ticket-col-qty {
            grid-column: 2 / 3 !important;
        }
        .dash-ticket-row .ticket-col-choix {
            grid-column: 3 / 4 !important;
        }
        .dash-ticket-row .ticket-col-fee {
            grid-column: 4 / 5 !important;
        }

        .dash-summary-strip {
            grid-template-columns: 1fr 1fr !important;
            gap: 1.1rem !important;
            padding: 1.15rem 1.25rem !important;
        }

        .venue-main-grid {
            grid-template-columns: 1fr !important;
            gap: 0.95rem !important;
        }
    }

    /* Tablettes Portrait (<= 860px) */
    @media (max-width: 860px) {
        .form-row-2col {
            grid-template-columns: 1fr !important;
            gap: 0.85rem !important;
        }
        .venue-details-grid {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }
        .events-creation-tabs {
            width: 100% !important;
        }
    }

    /* Mobiles Larges & Tablettes Étroites (<= 720px) */
    @media (max-width: 720px) {
        .dash-container {
            padding: 1rem 0.85rem 2.5rem !important;
        }
        .dash-card {
            padding: 1.15rem 0.95rem !important;
            border-radius: 12px !important;
        }
        .dash-header-section {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 0.85rem !important;
        }
        .dash-filter-bar {
            width: 100% !important;
        }
        .dash-filter-bar .dash-btn-action {
            width: 100% !important;
            justify-content: center !important;
        }
        .step-header-with-action {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 0.75rem !important;
        }
        .step-header-with-action button {
            width: 100% !important;
            justify-content: center !important;
        }
        .dash-identity-banner {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 0.75rem !important;
        }
        .dash-identity-banner > div:last-child {
            width: 100% !important;
            justify-content: center !important;
            box-sizing: border-box !important;
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
        .dash-ticket-row .ticket-delete-btn-box button {
            width: 36px !important;
            height: 36px !important;
        }
        .dash-ticket-row .ticket-col-price {
            grid-column: 1 / 2 !important;
        }
        .dash-ticket-row .ticket-col-qty {
            grid-column: 2 / 3 !important;
        }
        .dash-ticket-row .ticket-col-choix {
            grid-column: 1 / 2 !important;
        }
        .dash-ticket-row .ticket-col-fee {
            grid-column: 2 / 3 !important;
        }
        .dash-commission-notice {
            padding: 0.95rem 1rem !important;
        }
        .scale-tiers-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 0.6rem !important;
        }
        .scale-live-status {
            font-size: 0.78rem !important;
            padding: 0.6rem 0.75rem !important;
        }
        .vote-mode-buttons button {
            width: 100% !important;
            flex: 1 1 100% !important;
        }
        .dash-cand-vote-row {
            grid-template-columns: 1fr !important;
            position: relative !important;
            padding: 1rem 0.9rem !important;
        }
        .dash-cand-vote-row .cand-delete-box {
            text-align: right !important;
            padding-top: 0.35rem !important;
        }
    }

    /* Mobiles Standards & Étroits (<= 480px) */
    @media (max-width: 480px) {
        .dash-container {
            padding: 0.85rem 0.65rem 2rem !important;
        }
        .dash-card {
            padding: 1rem 0.8rem !important;
        }
        .venue-toggle-buttons {
            grid-template-columns: 1fr !important;
        }
        .form-row-2col.datetime-row {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }
        .dash-summary-strip {
            grid-template-columns: 1fr 1fr !important;
            gap: 0.85rem !important;
            padding: 1rem !important;
        }
        .dash-summary-strip strong {
            font-size: 1.05rem !important;
        }
        /* Empêcher le zoom automatique iOS sur les contrôles de formulaire */
        .dash-form-group input,
        .dash-form-group select,
        .dash-form-group textarea,
        .dash-ticket-row input {
            font-size: 16px !important;
        }
    }

    /* Mobiles Très Étroits (<= 360px) */
    @media (max-width: 360px) {
        .dash-ticket-row {
            grid-template-columns: 1fr !important;
        }
        .dash-ticket-row .ticket-col-name,
        .dash-ticket-row .ticket-col-price,
        .dash-ticket-row .ticket-col-qty,
        .dash-ticket-row .ticket-col-choix,
        .dash-ticket-row .ticket-col-fee {
            grid-column: 1 / -1 !important;
        }
        .dash-summary-strip {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }
        .events-creation-tabs .dash-chart-tab {
            font-size: 0.78rem !important;
            padding: 0.5rem 0.75rem !important;
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
            style="padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.85rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>;">
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
    <div class="events-creation-tabs">
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

            <!-- BANNIÈRE D'IDENTITÉ DE L'ORGANISATEUR (Statut Défini à la Création) -->
            <div class="dash-card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #F5F5F5 0%, #F5F5F5 100%); border: 1px solid var(--dash-border); padding: 1.15rem 1.4rem; border-radius: 14px;">
                <div class="dash-identity-banner">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; border-radius: 10px; background: <?php echo ($type_personne === 'morale') ? '#FFF2ED' : '#FFF2ED'; ?>; color: <?php echo ($type_personne === 'morale') ? '#FF4A0D' : '#FF4A0D'; ?>; display: grid; place-items: center; font-size: 1.3rem; flex-shrink: 0;">
                            <i class="fa-solid <?php echo ($type_personne === 'morale') ? 'fa-building' : 'fa-user-check'; ?>"></i>
                        </div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <strong style="color: var(--dash-text); font-size: 1.05rem; font-weight: 800;">
                                    <?php echo htmlspecialchars($nom_structure); ?>
                                </strong>
                                <span style="background: #FFF2ED; color: #000000; padding: 2px 8px; border-radius: 6px; font-size: 0.74rem; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="fa-solid fa-certificate"></i> Promoteur Certifié
                                </span>
                            </div>
                            <small style="color: var(--dash-muted); font-size: 0.8rem; margin-top: 2px; display: block;">
                                <?php if ($type_personne === 'morale'): ?>
                                    <span style="font-weight: 700; color: #000000;">Personne Morale</span>
                                    <?php echo !empty($numero_rccm) ? '· N° RCCM : <strong>' . htmlspecialchars($numero_rccm) . '</strong>' : ''; ?>
                                    <?php echo !empty($prom_info['representant_legal']) ? '· Représentant : <strong>' . htmlspecialchars($prom_info['representant_legal']) . '</strong>' : ''; ?>
                                <?php else: ?>
                                    <span style="font-weight: 700; color: #FF4A0D;">Personne Physique</span> (Artiste / Organisateur Indépendant)
                                <?php endif; ?>
                                · Déclarant : <strong><?php echo htmlspecialchars(trim(($prom_info['prenom'] ?? '') . ' ' . ($prom_info['nom'] ?? ''))); ?></strong>
                            </small>
                        </div>
                    </div>
                    <div style="font-size: 0.78rem; color: #737373; background: #ffffff; padding: 6px 12px; border-radius: 8px; border: 1px solid var(--dash-border); display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-shield-halved" style="color: #FF4A0D;"></i> Statut juridique validé à l'inscription
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 1 : INFORMATIONS DÉTAILLÉES DE L'ÉVÉNEMENT -->
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head" style="margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">1</div>
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

                <div class="form-row-2col">
                    <div class="dash-form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; flex-wrap: wrap; gap: 4px;">
                            <label for="categorie" style="margin-bottom: 0;">
                                <i class="fa-solid fa-layer-group" style="color: #FF4A0D;"></i> Catégorie *
                            </label>
                            <button type="button" onclick="toggleCustomCategoryInput()" id="btn_toggle_custom_cat" style="background: none; border: none; font-size: 0.76rem; color: var(--dash-primary); font-weight: 700; cursor: pointer; text-decoration: underline; padding: 0; white-space: nowrap;">
                                <i class="fa-solid fa-pen"></i> Personnaliser
                            </button>
                        </div>
                        <select id="categorie" name="categorie" onchange="onCategorySelectChange(this)" required>
                            <option value="Concert">Concert / Musique</option>
                            <option value="Festival">Festival</option>
                            <option value="Spectacle">Spectacle / Humour / Théâtre</option>
                            <option value="Conférence">Conférence / Séminaire / Forum</option>
                            <option value="Sport">Sport & Tournoi</option>
                            <option value="Soirée">Soirée, Gala & Clubbing</option>
                            <option value="Foire">Foire, Salon & Expo</option>
                            <option value="Cinéma">Cinéma & Projection</option>
                            <option value="Autre">✏️ Autre / Saisir ma propre catégorie...</option>
                        </select>
                        <div id="container_custom_cat" style="display: none; margin-top: 6px;">
                            <input type="text" id="categorie_custom" name="categorie_custom" placeholder="Tapez votre propre catégorie (Ex: Mode, Dédicace, Masterclass...)" style="font-size: 0.85rem; padding: 0.55rem 0.75rem; border: 1px solid #FF4A0D; background: #FFF2ED; border-radius: 8px;">
                            <small style="color: #FF4A0D; font-size: 0.72rem; display: block; margin-top: 3px;">
                                <i class="fa-solid fa-circle-check"></i> Cette catégorie personnalisée sera affichée sur la billetterie.
                            </small>
                        </div>
                    </div>

                    <div class="dash-form-group">
                        <label for="image"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Affiche officielle
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

                <div class="form-row-2col datetime-row">
                    <div class="dash-form-group">
                        <label for="date_evenement"><i class="fa-regular fa-calendar"
                                style="color: var(--dash-primary);"></i> Date de l'événement *</label>
                        <input type="date" id="date_evenement" name="date_evenement" required
                            value="<?php echo htmlspecialchars($_POST['date_evenement'] ?? ''); ?>">
                    </div>

                    <div class="dash-form-group">
                        <label for="heure"><i class="fa-regular fa-clock" style="color: #FF4A0D;"></i> Heure de début
                            *</label>
                        <input type="time" id="heure" name="heure" required
                            value="<?php echo htmlspecialchars($_POST['heure'] ?? ''); ?>">
                    </div>
                </div>

                <!-- ==========================================================
                     LOCALISATION : VILLE (FILTRAGE SALLES) & SALLE CONNUE VS ESPACE NON RÉPERTORIÉ
                     ========================================================== -->
                <div style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem; margin-top: 1rem; margin-bottom: 0.5rem;">
                    <div style="font-weight: 800; font-size: 0.88rem; color: var(--dash-text); margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-location-dot" style="color: var(--dash-primary);"></i> Localisation & Configuration de l'Espace
                        </span>
                        <span id="venue_type_status_badge" style="font-size: 0.74rem; font-weight: 800; background: #FFF2ED; color: var(--dash-primary); border: 1px solid #F0D9D0; border-radius: 6px; padding: 2px 8px;">
                            🏛️ Salle répertoriée (Vue 3D active)
                        </span>
                    </div>

                    <!-- Champ caché pour stocker l'ID de la salle sélectionnée -->
                    <input type="hidden" name="salle_id" id="input_salle_id" value="">

                    <!-- 1. Sélection de la VILLE (Liste Déroulante) & Mode de Salle -->
                    <div class="venue-main-grid">
                        <div class="dash-form-group" style="margin: 0;">
                            <label for="select_ville" style="font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; display: block; color: var(--dash-text);">
                                Ville / Localité * <span style="font-size: 0.72rem; color: var(--dash-muted); font-weight: normal;">(Filtre les salles disponibles)</span>
                            </label>
                            <select id="select_ville" name="ville" onchange="onVilleChange(this.value)" required style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; background: #ffffff; color: var(--dash-text); box-sizing: border-box;">
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
                                <option value="En Ligne">Événement 100% En Ligne (Webinaire / Live)</option>
                                <option value="Autre">✏️ Autre localité...</option>
                            </select>
                            <div id="box_ville_custom" style="display: none; margin-top: 6px;">
                                <input type="text" name="ville_custom" id="input_ville_custom" placeholder="Saisissez le nom de la ville..." style="width: 100%; padding: 0.55rem; border: 1px solid var(--dash-primary); background: #ffffff; border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                            </div>
                        </div>

                        <!-- 2. Choix : Salle Répertoriée (3D) ou Espace Non Répertorié (Sans vue de scène) -->
                        <div class="dash-form-group" style="margin: 0;">
                            <label style="font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; display: block; color: var(--dash-text);">
                                Type d'espace d'accueil *
                            </label>
                            <div class="venue-toggle-buttons">
                                <label id="label_salle_connue" onclick="setSalleConnue(true)" class="venue-toggle-btn" style="border: 1.5px solid var(--dash-primary); background: #FFF2ED;">
                                    <input type="radio" name="salle_connue" value="oui" checked style="accent-color: var(--dash-primary); margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #000000; white-space: nowrap;">
                                        <i class="fa-solid fa-hotel" style="color: var(--dash-primary);"></i> Salle répertoriée (3D)
                                    </span>
                                </label>
                                <label id="label_salle_inconnue" onclick="setSalleConnue(false)" class="venue-toggle-btn" style="border: 1px solid #E5E5E5; background: #ffffff;">
                                    <input type="radio" name="salle_connue" value="non" style="accent-color: var(--dash-primary); margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #737373; white-space: nowrap;">
                                        <i class="fa-solid fa-tree" style="color: #FF4A0D;"></i> Espace non répertorié
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 3.A : Si la salle EST CONNUE / RÉPERTORIÉE (Filtrée par Ville) -->
                    <div id="section_salle_connue">
                        <div class="venue-details-grid" style="margin-top: 0.85rem;">
                            <div id="salle_select_box">
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">
                                    Sélectionnez parmi les salles de <span id="label_ville_active" style="color: var(--dash-primary);">Abidjan</span> *
                                </label>
                                <select id="salle_select" name="salle_select" onchange="onSalleSelectChange(this.value)" style="width: 100%; padding: 0.65rem 0.8rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; font-weight: 600; background: #ffffff; color: var(--dash-text); box-sizing: border-box;">
                                    <!-- Rempli dynamiquement en JS selon la ville -->
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">
                                    Précision / Nom exact ou Salle interne
                                </label>
                                <input type="text" id="input_salle_nom" name="salle_nom" placeholder="Ex: Salle Lougah, Esplanade, Chapiteau..." style="width: 100%; padding: 0.65rem 0.8rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box; background: #ffffff;">
                            </div>
                        </div>

                        <!-- Notice si aucune salle 3D répertoriée dans la ville -->
                        <div id="salle_no_rooms_notice" style="display: none; margin-top: 0.75rem; background: #FFFBF0; border: 1px solid #FDE68A; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.78rem; color: #92400E; line-height: 1.4;">
                            <i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i>
                            Aucune salle avec modélisation 3D n'est encore répertoriée pour cette localité. Vous pouvez basculer sur <strong>« Espace non répertorié »</strong> ci-dessus ou saisir le nom de votre salle librement dans le champ prévu à cet effet.
                        </div>

                        <!-- Fiche d'information sur la salle sélectionnée (Capacité & 3D) -->
                        <div id="salle_info_card" style="display: none; margin-top: 0.85rem; background: #ffffff; border: 1.5px solid #F0D9D0; border-radius: 10px; padding: 0.9rem 1.1rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #737373; letter-spacing: 0.4px;">Salle répertoriée validée</div>
                                    <strong id="salle_info_nom" style="font-size: 0.96rem; color: #000000; display: block; margin-top: 1px;">-</strong>
                                    <span id="salle_info_loc" style="font-size: 0.78rem; color: #737373;">-</span>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #737373;">Capacité officielle</div>
                                    <strong id="salle_info_cap" style="font-size: 1.1rem; color: #000000; font-weight: 900;">-</strong>
                                    <span style="display: block; font-size: 0.72rem; color: #16A34A; font-weight: 800;">
                                        <i class="fa-solid fa-cube"></i> Vue 3D & Plan intégrés
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3.B : Si l'ESPACE N'EST PAS RÉPERTORIÉ (Pas de vue 3D requise) -->
                    <div id="section_salle_inconnue" style="display: none; margin-top: 0.85rem; background: #FFF2ED; border: 1px solid #E5E5E5; border-radius: 10px; padding: 1rem 1.15rem;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                            <i class="fa-solid fa-circle-check" style="color: #FF4A0D; font-size: 1.1rem;"></i>
                            <strong style="color: #000000; font-size: 0.88rem;">Espace Non Répertorié : Pas de vue de scène 3D requise</strong>
                        </div>
                        <p style="margin: 0 0 0.85rem; font-size: 0.78rem; color: #404040; line-height: 1.45;">
                            Cet événement se déroulera dans un espace ouvert, un terrain ou un lieu libre sans besoin de modélisation 3D complexe. 
                            <strong>Vous pourrez décider vous-même du nombre de places que les clients pourront choisir</strong> dans la grille tarifaire ci-dessous.
                        </p>

                        <div class="form-row-2col" style="gap: 0.75rem;">
                            <div>
                                <label style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: #000000;">
                                    Type d'espace ou statut
                                </label>
                                <select name="statut_salle_inconnue" style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #FF4A0D; border-radius: 8px; font-size: 0.82rem; font-weight: 700; background: #ffffff; color: #000000;">
                                    <option value="Terrain / Esplanade en plein air">🎪 Terrain / Esplanade en plein air</option>
                                    <option value="Plage / Espace bord de mer">🏖️ Plage / Espace bord de mer</option>
                                    <option value="Stade ou terrain communal">⚽ Stade ou terrain municipal ouvert</option>
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
                                <input type="text" name="espace_nom_custom" placeholder="Ex: Plage Cocody Danga, Grand terrain de l'INJS..." style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 0.82rem; background: #ffffff; box-sizing: border-box;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dash-form-group" style="margin-top: 1rem; margin-bottom: 0;">
                    <label for="infos_supplementaires"><i class="fa-solid fa-circle-info"
                            style="color: var(--dash-muted);"></i> Informations pratiques & Accès (Optionnel)</label>
                    <textarea id="infos_supplementaires" name="infos_supplementaires" rows="2"
                        placeholder="Accès parking, restrictions d'âge, consignes de sécurité..."><?php echo htmlspecialchars($_POST['infos_supplementaires'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- ÉTAPE 2 : TARIFICATION DE LA BILLETTERIE & COMMISSION -->
            <div class="dash-card" style="margin-bottom: 1.5rem;">
                <div class="dash-card-head">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="dash-step-badge">2</div>
                        <div>
                            <h3 class="dash-card-title">Grille Tarifaire, Quotas & Rémunération</h3>
                            <div class="dash-card-subtitle">Configurez vos types de billets et visualisez instantanément vos
                                gains nets</div>
                        </div>
                    </div>
                </div>

                <!-- Encadré explicatif Commission Dégressive selon l'Ampleur de l'Événement -->
                <div class="dash-commission-notice">
                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                        <div
                            style="width: 42px; height: 42px; border-radius: 50%; background: #FFF2ED; color: #FF4A0D; display: grid; place-items: center; font-size: 1.15rem; flex-shrink: 0;">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <strong style="color: #000000; font-size: 0.92rem; display: block; margin-bottom: 2px;">
                                Commission Plateforme Dégressive : indexée sur l'ampleur de l'événement
                            </strong>
                            <p style="color: #737373; font-size: 0.8rem; margin: 0; line-height: 1.35;">
                                Le pourcentage appliqué s'ajuste automatiquement selon la capacité totale de votre événement. Plus votre public est nombreux, plus vous gagnez !
                            </p>
                        </div>
                    </div>

                    <!-- Grille des 4 Paliers d'Ampleur -->
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
                            <div class="tier-range">300 – 1 499 places</div>
                        </div>
                        <div class="scale-tier-card" id="tier-card-grand" data-tier="grand">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Grand Evt</div>
                            <div class="tier-rate">4.0%</div>
                            <div class="tier-range">1 500 – 4 999 places</div>
                        </div>
                        <div class="scale-tier-card" id="tier-card-mega" data-tier="mega">
                            <span class="tier-tag">Actuel</span>
                            <div class="tier-badge">Festival / Stade</div>
                            <div class="tier-rate">3.0%</div>
                            <div class="tier-range">≥ 5 000 places</div>
                        </div>
                    </div>

                    <!-- Statut Réactif Détecté en Direct -->
                    <div class="scale-live-status" id="scale-live-status">
                        <i class="fa-solid fa-bolt" style="color: #FF4A0D; font-size: 1rem; flex-shrink: 0;"></i>
                        <div style="flex: 1; min-width: 0;">
                            Ampleur détectée : <strong id="live-tier-name" style="color: #000000;">Événement Standard (500 places)</strong> • 
                            Commission : <strong id="live-tier-rate" style="color: #FF4A0D;">5.0%</strong> • 
                            Vous encaissez <strong id="live-tier-payout" style="color: #000000;">95.0% du montant brut</strong>
                        </div>
                    </div>
                </div>

                <!-- Bloc de contrôle du Choix des Places par les Spectateurs -->
                <div style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1.15rem; margin-bottom: 1.15rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1 1 300px;">
                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #FFF2ED; color: var(--dash-primary); display: grid; place-items: center; font-size: 1rem; flex-shrink: 0;">
                            <i class="fa-solid fa-chair"></i>
                        </div>
                        <div>
                            <strong style="font-size: 0.85rem; color: #000000; display: block;">Gestion du Choix des Places par les Spectateurs</strong>
                            <span style="font-size: 0.77rem; color: #737373;">
                                Définissez pour chaque tarif combien de places les clients peuvent choisir individuellement (laissez à 0 pour un placement 100% libre).
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                        <button type="button" onclick="quickSetAllPlacesChoisies(true)" style="padding: 5px 12px; font-size: 0.75rem; font-weight: 700; border: 1px solid #E5E5E5; background: #F5F5F5; border-radius: 6px; cursor: pointer; color: #000000;" title="Permettre le choix de place sur toute la quantité">
                            <i class="fa-solid fa-check-double" style="color: var(--dash-primary);"></i> Toutes au choix
                        </button>
                        <button type="button" onclick="quickSetAllPlacesChoisies(false)" style="padding: 5px 12px; font-size: 0.75rem; font-weight: 700; border: 1px solid #E5E5E5; background: #F5F5F5; border-radius: 6px; cursor: pointer; color: #737373;" title="Placement 100% libre sans sélection de numéros">
                            <i class="fa-solid fa-ban"></i> 100% Libre (0)
                        </button>
                    </div>
                </div>

                <!-- En-tête Direct de la Grille des Billets & Bouton Ajouter un Tarif -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.35rem; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 800; color: var(--dash-text); display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-tags" style="color: var(--dash-primary);"></i> Vos Catégories de Billets & Tarifs
                        </h4>
                        <small style="color: #737373; font-size: 0.78rem;">Définissez vos prix, quotas et options de places ci-dessous</small>
                    </div>

                    <button type="button" onclick="addTicketRow()" class="dash-btn-action"
                        style="padding: 7px 15px; font-size: 0.84rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; border: none; box-shadow: 0 2px 6px rgba(255, 74, 13, 0.25);">
                        <i class="fa-solid fa-plus"></i> Ajouter un tarif
                    </button>
                </div>

                <div id="tickets-container">
                    <!-- Ligne de tarif 1 par défaut -->
                    <div class="dash-ticket-row">
                        <div class="ticket-col-name">
                            <label>Catégorie</label>
                            <input type="text" name="ticket_nom[]" required placeholder="Ex: STANDARD" value="STANDARD">
                        </div>
                        <div class="ticket-col-price">
                            <label>Prix unitaire (F)</label>
                            <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="5000"
                                value="5000" oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-col-qty">
                            <label>Places</label>
                            <input type="number" name="ticket_quantite[]" required min="1" placeholder="500" value="500"
                                oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-col-choix">
                            <label title="Nombre de places que les clients peuvent choisir individuellement">Places au choix</label>
                            <input type="number" name="ticket_places_choisies[]" min="0" placeholder="0" value="0"
                                title="Nombre de places que les spectateurs peuvent choisir (0 = placement 100% libre)"
                                oninput="validatePlacesChoisies(this); calculateEventSummary();">
                        </div>
                        <div class="ticket-col-fee">
                            <label>Frais choix (F)</label>
                            <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0"
                                title="Supplément pour place choisie" oninput="calculateEventSummary()">
                        </div>
                        <div class="ticket-delete-btn-box">
                            <button type="button" onclick="removeTicketRow(this)" title="Supprimer">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Synthèse Financière Estimée avec Taux Indexé sur l'Ampleur -->
                <div class="dash-summary-strip">
                    <div>
                        <small
                            style="color: #737373; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Capacité
                            Totale</small>
                        <strong id="summary-capacity" style="font-size: 1.15rem; color: #ffffff;">500 places</strong>
                        <span id="summary-tier-pill" style="display: block; font-size: 0.72rem; color: #FF4A0D; font-weight: 800; margin-top: 2px;">Standard</span>
                    </div>

                    <div>
                        <small
                            style="color: #737373; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Recette
                            Brute Max</small>
                        <strong id="summary-gross" style="font-size: 1.15rem; color: #ffffff;">2 500 000 F</strong>
                    </div>

                    <div>
                        <small
                            style="color: #737373; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Commission
                            (<span id="summary-rate-pct">5.0%</span>)</small>
                        <strong id="summary-commission" style="font-size: 1.15rem; color: #FF4A0D;">125 000 F</strong>
                    </div>

                    <div>
                        <small
                            style="color: #737373; display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 800; margin-bottom: 3px;">Votre
                            Gain Net Estimé</small>
                        <strong id="summary-net" style="font-size: 1.25rem; color: #FF4A0D;">2 375 000 FCFA</strong>
                    </div>
                </div>

                <input type="hidden" name="commission_rate" id="form_commission_rate" value="5.0">

                <!-- Bouton de Soumission -->
                <button type="submit" class="dash-btn-action btn-primary btn-submit-large">
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
                            <i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D;"></i>
                            Proposer une Campagne de Cotisation
                        </h3>
                        <div class="dash-card-subtitle">Financez votre projet ou événement : les visiteurs contribuent
                            directement par Mobile Money</div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="creer_campagne">

                    <div class="dash-form-group">
                        <label for="camp_titre"><i class="fa-solid fa-heading" style="color: #FF4A0D;"></i> Titre de la
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

                    <div class="form-row-2col">
                        <div class="dash-form-group">
                            <label for="camp_objectif"><i class="fa-solid fa-bullseye" style="color: #FF4A0D;"></i> Montant
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
                        <label for="camp_image"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Affiche / Image
                            illustrative de la campagne</label>
                        <input type="file" id="camp_image" name="image" accept="image/*" style="padding: 0.5rem 0.75rem;">
                    </div>

                    <button type="submit" class="dash-btn-action btn-primary btn-submit-large"
                        style="margin-top: 1rem;">
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
                <div class="vote-mode-buttons">
                    <button type="button" id="btn-mode-concours" onclick="switchVoteMode('concours')"
                        class="dash-chart-tab active"
                        style="padding: 0.65rem 1.35rem; font-size: 0.9rem; font-weight: 800; border-radius: 10px; border: 1px solid var(--dash-border); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i>
                        <span>1. Concours & Compétition (Plusieurs Candidats)</span>
                    </button>

                    <button type="button" id="btn-mode-realisation" onclick="switchVoteMode('realisation')"
                        class="dash-chart-tab"
                        style="padding: 0.65rem 1.35rem; font-size: 0.9rem; font-weight: 800; border-radius: 10px; border: 1px solid var(--dash-border); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-square-poll-vertical" style="color: #FF4A0D;"></i>
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
                            style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid #F5F5F5; padding-bottom: 1rem;">
                            <span
                                style="background: #FF4A0D; color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 0.9rem;">
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

                        <div class="dash-form-group">
                            <label for="concours_nom"><i class="fa-solid fa-heading"
                                    style="color: var(--dash-primary);"></i> Nom du concours / de la compétition *</label>
                            <input type="text" id="concours_nom" name="nom" required
                                placeholder="Ex: Miss Campus Côte d'Ivoire 2026, Tremplin Jeunes Talents...">
                        </div>

                        <div class="form-row-2col">
                            <div class="dash-form-group">
                                <label for="concours_prix_vote"><i class="fa-solid fa-coins" style="color: #FF4A0D;"></i>
                                    Prix unitaire par vote (FCFA) *</label>
                                <input type="number" id="concours_prix_vote" name="prix_vote" required min="0" step="50"
                                    placeholder="Ex: 200 (0 = vote gratuit)">
                            </div>

                            <div class="dash-form-group">
                                <label for="concours_image"><i class="fa-solid fa-image" style="color: #FF4A0D;"></i>
                                    Affiche / Logo officiel du concours</label>
                                <input type="file" id="concours_image" name="image" accept="image/*"
                                    style="padding: 0.5rem 0.75rem;">
                            </div>
                        </div>

                        <div class="form-row-2col datetime-row">
                            <div class="dash-form-group">
                                <label for="concours_date"><i class="fa-regular fa-calendar"
                                        style="color: var(--dash-primary);"></i> Date de clôture des votes *</label>
                                <input type="date" id="concours_date" name="date_evenement" required>
                            </div>

                            <div class="dash-form-group">
                                <label for="concours_heure"><i class="fa-regular fa-clock" style="color: #FF4A0D;"></i>
                                    Heure de fin des votes *</label>
                                <input type="time" id="concours_heure" name="heure" required value="23:59">
                            </div>
                        </div>

                        <div class="dash-form-group">
                            <label for="concours_desc"><i class="fa-solid fa-align-left"
                                    style="color: var(--dash-muted);"></i> Règlement & Présentation du concours</label>
                            <textarea id="concours_desc" name="description" rows="3"
                                placeholder="Critères d'évaluation, récompenses, déroulement des votes..."></textarea>
                        </div>

                        <!-- SOUS-SECTION : CANDIDATS DYNAMIQUES -->
                        <div
                            style="margin-top: 1.5rem; margin-bottom: 1.5rem; background: #ffffff; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.25rem;">
                            <div class="step-header-with-action">
                                <div>
                                    <h5 style="margin: 0; font-size: 0.95rem; color: var(--dash-text); font-weight: 800;">
                                        <i class="fa-solid fa-users" style="color: #FF4A0D;"></i> Candidats enregistrés
                                    </h5>
                                    <small style="color: var(--dash-muted); font-size: 0.78rem;">
                                        Ajoutez chaque candidat en spécifiant son nom complet, son talent et sa photo
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

                        <button type="submit" class="dash-btn-action btn-primary btn-submit-large">
                            <i class="fa-solid fa-paper-plane"></i> Soumettre la Demande de Concours avec ses Candidats
                        </button>
                    </form>
                </div>

                <!-- =====================================================================
                     PANNEAU B : VOTE POUR LA RÉALISATION D'UN ÉVÉNEMENT (INFORMATIONS NÉCESSAIRES)
                     ===================================================================== -->
                <div id="panel-vote-realisation" style="display: none;">
                    <form method="POST" enctype="multipart/form-data"
                        style="background: #ffffff; border: 1px solid #E5E5E5; border-radius: 14px; padding: 1.75rem; margin-bottom: 1.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                        <input type="hidden" name="action" value="proposer_vote_realisation">

                        <div
                            style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid #FFF2ED; padding-bottom: 1rem;">
                            <span
                                style="background: #FF4A0D; color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 0.9rem;">
                                <i class="fa-solid fa-square-poll-vertical"></i>
                            </span>
                            <div>
                                <h4 style="margin: 0; font-size: 1.1rem; color: #000000; font-weight: 800;">
                                    Vote pour la Réalisation d'un Événement (Plébiscite)
                                </h4>
                                <small style="color: #737373; font-size: 0.8rem;">
                                    Renseignez les informations de l'événement et la question soumise aux spectateurs (aucun
                                    candidat requis).
                                </small>
                            </div>
                        </div>

                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_nom" style="color: #000000; font-weight: 800;">
                                    <i class="fa-solid fa-bullhorn" style="color: #FF4A0D;"></i> Nom de l'événement / du
                                    projet envisagé *
                                </label>
                                <input type="text" id="realisation_nom" name="nom" required
                                    placeholder="Ex: Concert de Burna Boy à Abidjan, Festival Afro-Beat..."
                                    style="font-weight: 600;">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_image" style="color: #000000; font-weight: 800;">
                                    <i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Visuel / Affiche de
                                    l'événement à réaliser
                                </label>
                                <input type="file" id="realisation_image" name="image" accept="image/*"
                                    style="padding: 0.5rem; background: #F5F5F5; border-radius: 8px;">
                            </div>
                        </div>

                        <div class="dash-form-group" style="margin-bottom: 1.25rem;">
                            <label for="realisation_vote_question" style="color: #000000; font-weight: 800;">
                                <i class="fa-solid fa-circle-question" style="color: #FF4A0D;"></i> Question ou Proposition
                                soumise au public *
                            </label>
                            <input type="text" id="realisation_vote_question" name="vote_question" required
                                placeholder="Ex: Souhaitez-vous la tenue du spectacle de Burna Boy à Abidjan en Décembre ?"
                                style="background: #ffffff; border: 1.5px solid #FFF2ED;">
                            <small style="color: #737373; font-size: 0.75rem; margin-top: 3px; display: block;">
                                Cette question figurera en tête de vote pour inviter les spectateurs à exprimer leur accord
                                et soutien.
                            </small>
                        </div>

                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_date" style="color: #000000; font-weight: 800;"><i
                                        class="fa-solid fa-calendar" style="color: #FF4A0D;"></i> Période / Date envisagée
                                    *</label>
                                <input type="date" id="realisation_date" name="date_evenement" required
                                    value="<?php echo date('Y-m-d', strtotime('+2 months')); ?>">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_heure" style="color: #000000; font-weight: 800;"><i
                                        class="fa-solid fa-clock" style="color: #FF4A0D;"></i> Heure envisagée *</label>
                                <input type="time" id="realisation_heure" name="heure" required value="20:00">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_lieu" style="color: #000000; font-weight: 800;"><i
                                        class="fa-solid fa-location-dot" style="color: #000000;"></i> Lieu / Ville
                                    envisagée</label>
                                <input type="text" id="realisation_lieu" name="lieu"
                                    placeholder="Ex: Stade Félix Houphouët-Boigny, Abidjan" value="Abidjan">
                            </div>

                            <div class="dash-form-group" style="margin: 0;">
                                <label for="realisation_prix_vote" style="color: #000000; font-weight: 800;"><i
                                        class="fa-solid fa-coins" style="color: #FF4A0D;"></i> Prix du vote de soutien
                                    (FCFA)</label>
                                <input type="number" id="realisation_prix_vote" name="prix_vote" min="0" step="500"
                                    placeholder="0 = gratuit — Ex: 1000 pour 1 000 FCFA">
                            </div>
                        </div>

                        <div class="dash-form-group" style="margin-bottom: 1.35rem;">
                            <label for="realisation_desc" style="color: #000000; font-weight: 800;"><i
                                    class="fa-solid fa-align-left" style="color: var(--dash-muted);"></i> Présentation du
                                projet & Enjeux de la réalisation</label>
                            <textarea id="realisation_desc" name="description" rows="2"
                                placeholder="Expliquez pourquoi le public doit voter pour que ce projet voie le jour..."></textarea>
                        </div>

                        <div
                            style="background: #FFF2ED; border: 1px solid #FFD8CC; padding: 0.85rem 1.15rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.82rem; color: #FF4A0D;">
                            <i class="fa-solid fa-circle-info" style="margin-right: 5px;"></i>
                            <strong>Aucun candidat à enregistrer</strong> : En mode « Vote pour la réalisation », les
                            spectateurs votent pour valider ou encourager la tenue globale du projet.
                        </div>

                        <button type="submit" class="dash-btn-action btn-primary btn-submit-large">
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
                            style="background: #F5F5F5; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 2rem; text-align: center; color: var(--dash-muted); font-size: 0.88rem;">
                            <i class="fa-solid fa-user-xmark"
                                style="font-size: 1.8rem; color: #E5E5E5; display: block; margin-bottom: 0.5rem;"></i>
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
                                                style="color: #FF4A0D; font-weight: 700; display: block; font-size: 0.75rem; margin-top: 2px;">
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
                                        style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #F5F5F5; padding-top: 0.65rem; margin-top: auto;">
                                        <span
                                            style="background: #FFF2ED; color: #000000; border: 1px solid #FFF2ED; border-radius: 6px; padding: 3px 8px; font-size: 0.78rem; font-weight: 800;">
                                            <i class="fa-solid fa-vote-yea"></i> <?php echo (int) $cp['nb_votes']; ?>
                                            vote<?php echo (int) $cp['nb_votes'] > 1 ? 's' : ''; ?>
                                        </span>

                                        <form method="POST"
                                            onsubmit="return confirm('Confirmez-vous la suppression de ce participant ?');"
                                            style="margin: 0;">
                                            <input type="hidden" name="action" value="supprimer_candidat">
                                            <input type="hidden" name="candidat_id" value="<?php echo (int) $cp['id']; ?>">
                                            <button type="submit"
                                                style="background: transparent; border: 0; color: #000000; cursor: pointer; font-size: 0.85rem; padding: 4px;"
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
                        <i class="fa-solid fa-square-poll-vertical" style="color: #FF4A0D;"></i> Votes pour la Réalisation
                        d'Événements (<?php echo count($evts_realisation); ?>)
                    </h4>

                    <?php if (empty($evts_realisation)): ?>
                        <div
                            style="background: #F5F5F5; border: 1px dashed var(--dash-border); border-radius: 10px; padding: 1.5rem; text-align: center; color: var(--dash-muted); font-size: 0.85rem;">
                            Aucun événement actuellement configuré en vote de réalisation.
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <?php foreach ($evts_realisation as $er): ?>
                                <div
                                    style="background: #ffffff; border: 1px solid #E5E5E5; border-radius: 10px; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                    <div>
                                        <strong style="color: var(--dash-text); font-size: 0.95rem; display: block;">
                                            <?php echo htmlspecialchars($er['nom']); ?>
                                        </strong>
                                        <span
                                            style="color: #FF4A0D; font-size: 0.82rem; font-weight: 700; display: block; margin-top: 2px;">
                                            <i class="fa-solid fa-circle-question"></i>
                                            <?php echo htmlspecialchars($er['vote_question'] ?: 'Soutenez la tenue de cet événement'); ?>
                                        </span>
                                    </div>

                                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                                        <span
                                            style="background: #FFF2ED; color: #FF4A0D; border: 1px solid #E5E5E5; border-radius: 8px; padding: 4px 12px; font-size: 0.82rem; font-weight: 800;">
                                            <i class="fa-solid fa-check"></i> <?php echo (int) ($er['nb_votes_realisation'] ?? 0); ?>
                                            votes de soutien
                                        </span>
                                        <span
                                            style="background: #F5F5F5; color: var(--dash-muted); border-radius: 8px; padding: 4px 10px; font-size: 0.78rem;">
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
    // Gestion de la Catégorie Personnalisée
    function onCategorySelectChange(sel) {
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

    function toggleCustomCategoryInput() {
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

    // Liste officielle des salles actives chargée depuis le serveur
    const DB_SALLES = <?php echo json_encode($db_salles, JSON_UNESCAPED_UNICODE); ?>;

    // Gestion de la Ville (Liste déroulante) et Filtrage des Salles
    function onVilleChange(val) {
        const boxCustom = document.getElementById('box_ville_custom');
        const inputCustom = document.getElementById('input_ville_custom');
        const labelVilleActive = document.getElementById('label_ville_active');

        if (boxCustom) {
            boxCustom.style.display = (val === 'Autre') ? 'block' : 'none';
            if (inputCustom) inputCustom.required = (val === 'Autre');
        }

        if (labelVilleActive) {
            labelVilleActive.textContent = val;
        }

        // Filtre dynamiquement les salles répertoriées pour cette ville
        populateSallesForVille(val);
    }

    function populateSallesForVille(ville) {
        const sel = document.getElementById('salle_select');
        const noticeNoRooms = document.getElementById('salle_no_rooms_notice');
        const card = document.getElementById('salle_info_card');
        const inputSalleNom = document.getElementById('input_salle_nom');
        const hiddenId = document.getElementById('input_salle_id');
        if (!sel) return;

        sel.innerHTML = '';

        // Salles de la ville
        const filtered = (DB_SALLES || []).filter(function (s) {
            return s.ville && s.ville.trim().toLowerCase() === ville.trim().toLowerCase();
        });

        if (filtered.length > 0) {
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = `-- Salles répertoriées à ${ville} (${filtered.length} disponible${filtered.length > 1 ? 's' : ''}) --`;
            sel.appendChild(defaultOpt);

            filtered.forEach(function (s) {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.dataset.nom = s.nom;
                opt.dataset.capacite = s.capacite;
                opt.dataset.commune = s.commune || '';
                opt.dataset.ville = s.ville;
                opt.textContent = '🏛️ ' + s.nom + (s.commune ? ' (' + s.commune + ')' : '') + ' — ' + Number(s.capacite).toLocaleString('fr-FR') + ' places';
                sel.appendChild(opt);
            });

            const customOpt = document.createElement('option');
            customOpt.value = 'Autre';
            customOpt.textContent = '✏️ Autre salle ou lieu non listé...';
            sel.appendChild(customOpt);

            if (noticeNoRooms) noticeNoRooms.style.display = 'none';
            // Sélectionne la 1ère salle par défaut si disponible
            sel.selectedIndex = 1;
            onSalleSelectChange(sel.value);
        } else {
            const noOpt = document.createElement('option');
            noOpt.value = '';
            noOpt.textContent = `-- Aucune salle avec modélisation 3D pour ${ville} --`;
            sel.appendChild(noOpt);

            const customOpt = document.createElement('option');
            customOpt.value = 'Autre';
            customOpt.textContent = '✏️ Saisie libre du nom de salle...';
            customOpt.selected = true;
            sel.appendChild(customOpt);

            if (noticeNoRooms) noticeNoRooms.style.display = 'block';
            if (hiddenId) hiddenId.value = '';
            if (card) card.style.display = 'none';
        }
    }

    function setSalleConnue(isKnown) {
        const radOui = document.querySelector('input[name="salle_connue"][value="oui"]');
        const radNon = document.querySelector('input[name="salle_connue"][value="non"]');
        if (radOui) radOui.checked = isKnown;
        if (radNon) radNon.checked = !isKnown;

        const lblOui = document.getElementById('label_salle_connue');
        const lblNon = document.getElementById('label_salle_inconnue');
        const secOui = document.getElementById('section_salle_connue');
        const secNon = document.getElementById('section_salle_inconnue');
        const badge = document.getElementById('venue_type_status_badge');

        if (isKnown) {
            if (lblOui) {
                lblOui.style.border = '1.5px solid var(--dash-primary)';
                lblOui.style.background = '#FFF2ED';
            }
            if (lblNon) {
                lblNon.style.border = '1px solid #E5E5E5';
                lblNon.style.background = '#ffffff';
            }
            if (secOui) secOui.style.display = 'block';
            if (secNon) secNon.style.display = 'none';
            if (badge) {
                badge.innerHTML = '🏛️ Salle répertoriée (Vue 3D active)';
                badge.style.background = '#FFF2ED';
                badge.style.color = 'var(--dash-primary)';
                badge.style.borderColor = '#F0D9D0';
            }
            const sel = document.getElementById('salle_select');
            if (sel) onSalleSelectChange(sel.value);
        } else {
            if (lblNon) {
                lblNon.style.border = '1.5px solid #FF4A0D';
                lblNon.style.background = '#FFF2ED';
            }
            if (lblOui) {
                lblOui.style.border = '1px solid #E5E5E5';
                lblOui.style.background = '#ffffff';
            }
            if (secOui) secOui.style.display = 'none';
            if (secNon) secNon.style.display = 'block';
            if (badge) {
                badge.innerHTML = '📍 Espace non répertorié (Sans vue 3D)';
                badge.style.background = '#F5F5F5';
                badge.style.color = '#737373';
                badge.style.borderColor = '#E5E5E5';
            }
            // Pas de salle_id pour un espace non répertorié
            const hiddenId = document.getElementById('input_salle_id');
            if (hiddenId) hiddenId.value = '';
            const card = document.getElementById('salle_info_card');
            if (card) card.style.display = 'none';
        }
    }

    function onSalleSelectChange(val) {
        const inputSalle = document.getElementById('input_salle_nom');
        const hiddenId = document.getElementById('input_salle_id');
        const card = document.getElementById('salle_info_card');
        const sel = document.getElementById('salle_select');

        if (!val || val === 'Autre') {
            if (hiddenId) hiddenId.value = '';
            if (card) card.style.display = 'none';
            if (val === 'Autre' && inputSalle) {
                inputSalle.focus();
            }
            return;
        }

        const selectedOption = sel.options[sel.selectedIndex];
        if (!selectedOption) return;

        const sId = val;
        const sNom = selectedOption.dataset.nom || '';
        const sCap = selectedOption.dataset.capacite || 0;
        const sLoc = (selectedOption.dataset.commune ? selectedOption.dataset.commune + ', ' : '') + (selectedOption.dataset.ville || '');

        if (hiddenId) hiddenId.value = sId;
        if (inputSalle && sNom) inputSalle.value = sNom;

        if (card) {
            card.style.display = 'block';
            document.getElementById('salle_info_nom').textContent = sNom;
            document.getElementById('salle_info_loc').textContent = sLoc;
            document.getElementById('salle_info_cap').textContent = Number(sCap).toLocaleString('fr-FR') + ' places';
        }
    }

    // Validation du nombre de places que les clients peuvent choisir
    function validatePlacesChoisies(input) {
        if (!input) return;
        const row = input.closest('.dash-ticket-row');
        if (!row) return;
        const qtyInput = row.querySelector('input[name="ticket_quantite[]"]');
        const totalQty = Number(qtyInput ? qtyInput.value : 0) || 0;
        let chosen = Number(input.value) || 0;

        if (chosen < 0) {
            input.value = 0;
            chosen = 0;
        }
        if (chosen > totalQty) {
            input.value = totalQty;
            chosen = totalQty;
        }
    }

    // Raccourcis pour configurer rapidement les places au choix sur tous les tarifs
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

    // 1. Ajout dynamique d'une ligne de tarif
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
                <input type="number" name="ticket_frais[]" min="0" step="100" placeholder="0" value="0" title="Supplément facturé pour le choix de place" oninput="calculateEventSummary()">
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

    // 2. Barème Dégressif selon l'Ampleur de l'Événement (Capacité & Recette) ou Taux Négocié
    const PROMOTER_CUSTOM_RATE = <?php echo json_encode(isset($prom_info['commission_rate']) && abs((float)$prom_info['commission_rate'] - 5.00) > 0.001 ? (float)$prom_info['commission_rate'] : null); ?>;

    function getEventScale(capacity, gross) {
        if (PROMOTER_CUSTOM_RATE !== null) {
            return {
                tier: 'custom',
                name: 'Taux Négocié',
                rate: PROMOTER_CUSTOM_RATE / 100,
                percent: PROMOTER_CUSTOM_RATE,
                payout: (100 - PROMOTER_CUSTOM_RATE),
                range: 'Taux personnalisé accordé par l\'administrateur'
            };
        }
        if (capacity >= 5000 || gross >= 50000000) {
            return {
                tier: 'mega',
                name: 'Festival & Stade',
                rate: 0.03,
                percent: 3.0,
                payout: 97.0,
                range: '≥ 5 000 places'
            };
        } else if (capacity >= 1500 || gross >= 15000000) {
            return {
                tier: 'grand',
                name: 'Grand Événement',
                rate: 0.04,
                percent: 4.0,
                payout: 96.0,
                range: '1 500 – 4 999 places'
            };
        } else if (capacity >= 300 || gross >= 3000000) {
            return {
                tier: 'standard',
                name: 'Événement Standard',
                rate: 0.05,
                percent: 5.0,
                payout: 95.0,
                range: '300 – 1 499 places'
            };
        } else {
            return {
                tier: 'intimiste',
                name: 'Événement Intimiste',
                rate: 0.07,
                percent: 7.0,
                payout: 93.0,
                range: '< 300 places'
            };
        }
    }

    // 3. Calcul en temps réel de la capacité, du brut, du barème et du net
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

        // Détermination dynamique de l'ampleur et du taux
        const scale = getEventScale(totalCapacity, totalGross);
        const totalCommission = Math.round(totalGross * scale.rate);
        const totalNet = totalGross - totalCommission;

        // Mise à jour de la synthèse
        const capEl = document.getElementById('summary-capacity');
        const grossEl = document.getElementById('summary-gross');
        const commEl = document.getElementById('summary-commission');
        const netEl = document.getElementById('summary-net');
        const ratePctEl = document.getElementById('summary-rate-pct');
        const tierPillEl = document.getElementById('summary-tier-pill');
        const hiddenRateInput = document.getElementById('form_commission_rate');

        if (capEl) capEl.textContent = totalCapacity.toLocaleString('fr-FR') + ' places';
        if (grossEl) grossEl.textContent = totalGross.toLocaleString('fr-FR') + ' FCFA';
        if (commEl) commEl.textContent = totalCommission.toLocaleString('fr-FR') + ' FCFA';
        if (netEl) netEl.textContent = totalNet.toLocaleString('fr-FR') + ' FCFA';
        if (ratePctEl) ratePctEl.textContent = scale.percent.toFixed(1) + '%';
        if (tierPillEl) tierPillEl.textContent = scale.name + ' (' + scale.percent.toFixed(1) + '%)';
        if (hiddenRateInput) hiddenRateInput.value = scale.percent.toFixed(1);

        // Mise à jour visuelle des cartes de barème
        ['intimiste', 'standard', 'grand', 'mega'].forEach(function(t) {
            const card = document.getElementById('tier-card-' + t);
            if (card) {
                if (t === scale.tier) {
                    card.classList.add('is-active');
                } else {
                    card.classList.remove('is-active');
                }
            }
        });

        // Mise à jour du bandeau de statut live
        const liveName = document.getElementById('live-tier-name');
        const liveRate = document.getElementById('live-tier-rate');
        const livePayout = document.getElementById('live-tier-payout');

        if (liveName) liveName.textContent = scale.name + ' (' + totalCapacity.toLocaleString('fr-FR') + ' places)';
        if (liveRate) liveRate.textContent = scale.percent.toFixed(1) + '%';
        if (livePayout) livePayout.textContent = scale.payout.toFixed(1) + '% du montant brut';
    }

    // Initialisation
    calculateEventSummary();
    const currentVilleInput = document.getElementById('select_ville');
    if (currentVilleInput) {
        onVilleChange(currentVilleInput.value);
    }

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
        row.style.background = '#F5F5F5';
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

            <div class="cand-delete-box" style="text-align: right; padding-top: 1.1rem;">
                <button type="button" onclick="this.closest('.dash-cand-vote-row').remove()" style="background: #F5F5F5; color: #000000; border: 0; border-radius: 8px; padding: 0.55rem 0.75rem; cursor: pointer;" title="Supprimer ce candidat">
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