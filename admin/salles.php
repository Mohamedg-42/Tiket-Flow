<?php
// ==============================================================================
// GESTION DES SALLES & LIEUX DE SPECTACLE (admin/salles.php)
// Administration complète des infrastructures, capacités, zones et équipements
// Style Dashboard Pro & Swiss Grid System Eventia
// ==============================================================================

$admin_page_title = "Gestion des Salles & Lieux - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. TRAITEMENT : CRÉATION D'UNE NOUVELLE SALLE
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_salle'])) {
    $nom           = trim($_POST['nom'] ?? '');
    $ville         = trim($_POST['ville'] ?? 'Abidjan');
    $commune       = trim($_POST['commune'] ?? '');
    $adresse       = trim($_POST['adresse'] ?? '');
    $capacite      = (int)($_POST['capacite'] ?? 0);
    $type_salle    = trim($_POST['type_salle'] ?? 'polyvalente');
    $configuration = trim($_POST['configuration'] ?? 'placement_libre');
    $description   = trim($_POST['description'] ?? '');
    $contact_resp  = trim($_POST['contact_responsable'] ?? '');
    $tel_resp      = trim($_POST['telephone_responsable'] ?? '');
    $prix_loc      = (float)($_POST['prix_location_indicatif'] ?? 0);
    $statut        = trim($_POST['statut'] ?? 'active');

    $modele_3d     = trim($_POST['modele_3d'] ?? 'theatre_italien');
    $type_rendu_3d = trim($_POST['type_rendu_3d'] ?? 'generateur_3d');

    // Équipements cochés
    $equipements_arr = $_POST['equipements'] ?? [];
    $equipements = is_array($equipements_arr) ? implode(', ', array_map('trim', $equipements_arr)) : '';
    if (!empty($_POST['equipements_autres'])) {
        $equipements .= ($equipements ? ', ' : '') . trim($_POST['equipements_autres']);
    }

    // Traitement des uploads médias
    $upload_dir = '../uploads/salles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $image_principale = 'salle_default.jpg';
    if (isset($_FILES['image_principale']) && $_FILES['image_principale']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image_principale']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $image_principale = 'salle_main_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image_principale']['tmp_name'], $upload_dir . $image_principale);
        }
    }

    $plan_image = null;
    if (isset($_FILES['plan_image']) && $_FILES['plan_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['plan_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'pdf'], true)) {
            $plan_image = 'plan_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['plan_image']['tmp_name'], $upload_dir . $plan_image);
        }
    }

    $fichier_3d = null;
    if (isset($_FILES['fichier_3d']) && $_FILES['fichier_3d']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['fichier_3d']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['glb', 'gltf', 'obj', 'json', 'zip'], true)) {
            $fichier_3d = 'model3d_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['fichier_3d']['tmp_name'], $upload_dir . $fichier_3d);
        }
    }

    // Galerie photos multiples
    $galerie_photos_arr = [];
    if (!empty($_FILES['galerie_photos']['name'][0])) {
        foreach ($_FILES['galerie_photos']['name'] as $idx => $fName) {
            if ($_FILES['galerie_photos']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $gName = 'galerie_' . uniqid() . '_' . $idx . '.' . $ext;
                    if (move_uploaded_file($_FILES['galerie_photos']['tmp_name'][$idx], $upload_dir . $gName)) {
                        $galerie_photos_arr[] = $gName;
                    }
                }
            }
        }
    }
    $galerie_photos = !empty($galerie_photos_arr) ? json_encode($galerie_photos_arr) : null;

    if (empty($nom) || $capacite <= 0) {
        $message = "Le nom de la salle et une capacité supérieure à 0 sont obligatoires.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO salles (nom, ville, commune, adresse, capacite, type_salle, configuration, modele_3d, type_rendu_3d, image_principale, galerie_photos, plan_image, fichier_3d, description, contact_responsable, telephone_responsable, prix_location_indicatif, equipements, statut)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nom, $ville, $commune, $adresse, $capacite, $type_salle, $configuration, $modele_3d, $type_rendu_3d, $image_principale, $galerie_photos, $plan_image, $fichier_3d, $description, $contact_resp, $tel_resp, $prix_loc, $equipements, $statut]);
            $new_salle_id = (int)$pdo->lastInsertId();

            // Création automatique de zones par défaut avec élévations 3D
            if ($configuration === 'mixte' || $configuration === 'places_numerotees') {
                $vip_cap = round($capacite * 0.15);
                $std_cap = $capacite - $vip_cap;
                $pdo->prepare("INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur, elevation_3d, position_3d, tarif_indicatif) VALUES (?, 'Zone VIP / Carré Or', ?, '#FF4A0D', 0, 'vip_avant', 25000)")->execute([$new_salle_id, $vip_cap]);
                $pdo->prepare("INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur, elevation_3d, position_3d, tarif_indicatif) VALUES (?, 'Parterre Standard / Gradins', ?, '#FF4A0D', 1, 'centre', 10000)")->execute([$new_salle_id, $std_cap]);
            } else {
                $pdo->prepare("INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur, elevation_3d, position_3d, tarif_indicatif) VALUES (?, 'Espace Général / Libre', ?, '#FF4A0D', 0, 'centre', 5000)")->execute([$new_salle_id, $capacite]);
            }

            logActivity('salle.create', 'salle', $new_salle_id, "Création de la salle « $nom » (Capacité: $capacite places, Ville: $ville, Modèle 3D: $modele_3d)");
            $message = "La salle « " . htmlspecialchars($nom) . " », ses photos et ses plans 3D ont été enregistrés avec succès !";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la création de la salle : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 2. TRAITEMENT : MODIFICATION D'UNE SALLE
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_salle'])) {
    $salle_id      = (int)($_POST['salle_id'] ?? 0);
    $nom           = trim($_POST['nom'] ?? '');
    $ville         = trim($_POST['ville'] ?? 'Abidjan');
    $commune       = trim($_POST['commune'] ?? '');
    $adresse       = trim($_POST['adresse'] ?? '');
    $capacite      = (int)($_POST['capacite'] ?? 0);
    $type_salle    = trim($_POST['type_salle'] ?? 'polyvalente');
    $configuration = trim($_POST['configuration'] ?? 'placement_libre');
    $modele_3d     = trim($_POST['modele_3d'] ?? 'theatre_italien');
    $type_rendu_3d = trim($_POST['type_rendu_3d'] ?? 'generateur_3d');
    $description   = trim($_POST['description'] ?? '');
    $contact_resp  = trim($_POST['contact_responsable'] ?? '');
    $tel_resp      = trim($_POST['telephone_responsable'] ?? '');
    $prix_loc      = (float)($_POST['prix_location_indicatif'] ?? 0);
    $statut        = trim($_POST['statut'] ?? 'active');

    $equipements_arr = $_POST['equipements'] ?? [];
    $equipements = is_array($equipements_arr) ? implode(', ', array_map('trim', $equipements_arr)) : '';
    if (!empty($_POST['equipements_autres'])) {
        $equipements .= ($equipements ? ', ' : '') . trim($_POST['equipements_autres']);
    }

    $upload_dir = '../uploads/salles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Récupérer la salle existante pour conserver les anciens médias si non ré-uploadés
    $stmt_cur = $pdo->prepare("SELECT image_principale, galerie_photos, plan_image, fichier_3d FROM salles WHERE id = ?");
    $stmt_cur->execute([$salle_id]);
    $current_salle = $stmt_cur->fetch(PDO::FETCH_ASSOC);

    $image_principale = $current_salle['image_principale'] ?? 'salle_default.jpg';
    if (isset($_FILES['image_principale']) && $_FILES['image_principale']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image_principale']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $image_principale = 'salle_main_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image_principale']['tmp_name'], $upload_dir . $image_principale);
        }
    }

    $plan_image = $current_salle['plan_image'] ?? null;
    if (isset($_FILES['plan_image']) && $_FILES['plan_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['plan_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'pdf'], true)) {
            $plan_image = 'plan_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['plan_image']['tmp_name'], $upload_dir . $plan_image);
        }
    }

    $fichier_3d = $current_salle['fichier_3d'] ?? null;
    if (isset($_FILES['fichier_3d']) && $_FILES['fichier_3d']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['fichier_3d']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['glb', 'gltf', 'obj', 'json', 'zip'], true)) {
            $fichier_3d = 'model3d_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['fichier_3d']['tmp_name'], $upload_dir . $fichier_3d);
        }
    }

    // Galerie photos
    $galerie_photos = $current_salle['galerie_photos'] ?? null;
    if (!empty($_FILES['galerie_photos']['name'][0])) {
        $galerie_photos_arr = !empty($galerie_photos) ? json_decode($galerie_photos, true) : [];
        if (!is_array($galerie_photos_arr)) $galerie_photos_arr = [];

        foreach ($_FILES['galerie_photos']['name'] as $idx => $fName) {
            if ($_FILES['galerie_photos']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $gName = 'galerie_' . uniqid() . '_' . $idx . '.' . $ext;
                    if (move_uploaded_file($_FILES['galerie_photos']['tmp_name'][$idx], $upload_dir . $gName)) {
                        $galerie_photos_arr[] = $gName;
                    }
                }
            }
        }
        $galerie_photos = json_encode($galerie_photos_arr);
    }

    if (empty($nom) || $capacite <= 0 || $salle_id <= 0) {
        $message = "Veuillez renseigner tous les champs obligatoires.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE salles 
                SET nom = ?, ville = ?, commune = ?, adresse = ?, capacite = ?, type_salle = ?, configuration = ?, modele_3d = ?, type_rendu_3d = ?, image_principale = ?, galerie_photos = ?, plan_image = ?, fichier_3d = ?, description = ?, contact_responsable = ?, telephone_responsable = ?, prix_location_indicatif = ?, equipements = ?, statut = ?
                WHERE id = ?
            ");
            $stmt->execute([$nom, $ville, $commune, $adresse, $capacite, $type_salle, $configuration, $modele_3d, $type_rendu_3d, $image_principale, $galerie_photos, $plan_image, $fichier_3d, $description, $contact_resp, $tel_resp, $prix_loc, $equipements, $statut, $salle_id]);

            logActivity('salle.update', 'salle', $salle_id, "Modification des caractéristiques, photos et modèle 3D de la salle #$salle_id (« $nom »)");
            $message = "Les informations, photos et fichiers 3D de la salle ont été mis à jour avec succès.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur de mise à jour : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 3. TRAITEMENT : GESTION DES ZONES D'UNE SALLE
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_zone'])) {
    $s_id     = (int)($_POST['salle_id'] ?? 0);
    $nom_z    = trim($_POST['nom_zone'] ?? '');
    $cap_z    = (int)($_POST['capacite_zone'] ?? 0);
    $couleur  = trim($_POST['couleur_zone'] ?? '#FF4A0D');

    if ($s_id > 0 && !empty($nom_z) && $cap_z > 0) {
        $pdo->prepare("INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur) VALUES (?, ?, ?, ?)")->execute([$s_id, $nom_z, $cap_z, $couleur]);
        $message = "La zone « " . htmlspecialchars($nom_z) . " » a été ajoutée à la salle.";
        $msg_type = "success";
    } else {
        $message = "Veuillez saisir un nom et une capacité valides pour la zone.";
        $msg_type = "error";
    }
}

if (isset($_GET['delete_zone'])) {
    $del_z_id = (int)$_GET['delete_zone'];
    $pdo->prepare("DELETE FROM salle_zones WHERE id = ?")->execute([$del_z_id]);
    $message = "La zone a été supprimée.";
    $msg_type = "success";
}

// ------------------------------------------------------------------------------
// 4. TRAITEMENT : SUPPRESSION D'UNE SALLE
// ------------------------------------------------------------------------------
if (isset($_GET['delete_salle'])) {
    $del_s_id = (int)$_GET['delete_salle'];
    $stmt_s = $pdo->prepare("SELECT nom FROM salles WHERE id = ?");
    $stmt_s->execute([$del_s_id]);
    $s_to_del = $stmt_s->fetch();

    if ($s_to_del) {
        $pdo->prepare("DELETE FROM salles WHERE id = ?")->execute([$del_s_id]);
        logActivity('salle.delete', 'salle', $del_s_id, "Suppression de la salle #$del_s_id (« {$s_to_del['nom']} »)");
        $message = "La salle « " . htmlspecialchars($s_to_del['nom']) . " » a été supprimée.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 5. FILTRES & RECHERCHE
// ------------------------------------------------------------------------------
$statut_f = $_GET['statut'] ?? 'tous';
$ville_f  = $_GET['ville'] ?? 'toutes';
$type_f   = $_GET['type_salle'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

$sql = "SELECT s.*, (SELECT COUNT(*) FROM salle_zones sz WHERE sz.salle_id = s.id) AS nb_zones FROM salles s WHERE 1=1";
$params = [];

if ($statut_f !== 'tous' && in_array($statut_f, ['active', 'maintenance', 'inactive'], true)) {
    $sql .= " AND s.statut = ?";
    $params[] = $statut_f;
}

if ($ville_f !== 'toutes' && !empty($ville_f)) {
    $sql .= " AND s.ville = ?";
    $params[] = $ville_f;
}

if ($type_f !== 'tous' && !empty($type_f)) {
    $sql .= " AND s.type_salle = ?";
    $params[] = $type_f;
}

if (!empty($search)) {
    $sql .= " AND (s.nom LIKE ? OR s.commune LIKE ? OR s.adresse LIKE ? OR s.description LIKE ? OR s.contact_responsable LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$sql .= " ORDER BY (s.statut = 'active') DESC, s.capacite DESC, s.nom ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$salles_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération de toutes les zones groupées par salle
$all_zones_raw = $pdo->query("SELECT * FROM salle_zones ORDER BY capacite DESC")->fetchAll(PDO::FETCH_ASSOC);
$salle_zones_map = [];
foreach ($all_zones_raw as $z) {
    $salle_zones_map[$z['salle_id']][] = $z;
}

// Villes distinctes pour le sélecteur
$villes_disponibles = $pdo->query("SELECT DISTINCT ville FROM salles WHERE ville IS NOT NULL AND ville != '' ORDER BY ville ASC")->fetchAll(PDO::FETCH_COLUMN);

// Calculs KPI Globaux
$tot_salles      = (int)$pdo->query("SELECT COUNT(*) FROM salles")->fetchColumn();
$tot_capacite    = (int)$pdo->query("SELECT COALESCE(SUM(capacite), 0) FROM salles WHERE statut = 'active'")->fetchColumn();
$tot_actives     = (int)$pdo->query("SELECT COUNT(*) FROM salles WHERE statut = 'active'")->fetchColumn();
$tot_maintenance = (int)$pdo->query("SELECT COUNT(*) FROM salles WHERE statut = 'maintenance'")->fetchColumn();

function get_type_salle_label($type) {
    switch ($type) {
        case 'auditorium': return ['Auditorium / Théâtre', 'fa-masks-theater', '#FF4A0D'];
        case 'theatre':    return ['Salle de Théâtre', 'fa-landmark-dome', '#FF4A0D'];
        case 'stade':      return ['Stade / Arène', 'fa-futbol', '#FF4A0D'];
        case 'plein_air':  return ['Plein Air / Esplanade', 'fa-tree', '#FF4A0D'];
        case 'salle_fetes':return ['Salle Polyvalente / Fêtes', 'fa-champagne-glasses', '#FF4A0D'];
        default:           return ['Complexe Modulable', 'fa-building', '#737373'];
    }
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.salle-grid-card {
    background: #ffffff;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 14px;
    padding: 1.35rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: all 0.22s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.salle-grid-card:hover {
    transform: translateY(-3px);
    border-color: var(--dash-primary, #000000);
    box-shadow: 0 8px 24px rgba(0,0,0,0.07);
}
.zone-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.74rem;
    font-weight: 700;
    margin-right: 4px;
    margin-bottom: 4px;
}
.equip-tag {
    display: inline-block;
    background: #F5F5F5;
    border: 1px solid #E5E5E5;
    color: #737373;
    padding: 2px 7px;
    border-radius: 5px;
    font-size: 0.72rem;
    margin-right: 3px;
    margin-bottom: 3px;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-landmark" style="color: var(--eventia-amber, #FF4A0D); font-size: 1.55rem;"></i>
                Gestion des Salles & Lieux de Spectacle
            </h1>
            <p>Configurez les complexes d'accueil, définissez les capacités d'accueil, les zones de tarification et les équipements techniques.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; align-items: center; flex-wrap: wrap;">
            <a href="export.php?type=salles&statut=<?php echo urlencode($statut_f); ?>&ville=<?php echo urlencode($ville_f); ?>&q=<?php echo urlencode($search); ?>" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter le catalogue des salles sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <button type="button" onclick="openCreateSalleModal()" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <i class="fa-solid fa-plus"></i> Ajouter une Salle
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="eventia-alert eventia-alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="margin-bottom: 1.25rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. CARTES KPIS DE SYNTHÈSE
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total des Salles</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-building"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 900; color: var(--dash-text);"><?php echo $tot_salles; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Infrastructures répertoriées</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Capacité Totale</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chair"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 900; color: #FF4A0D;"><?php echo number_format($tot_capacite, 0, ',', ' '); ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Places disponibles en simultané</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Salles Actives</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 900; color: #FF4A0D;"><?php echo $tot_actives; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Opérationnelles pour réservations</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">En Maintenance</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-wrench"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 900; color: #FF4A0D;"><?php echo $tot_maintenance; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Travaux ou indisponibilités</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES DYNAMIQUES
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&ville=<?php echo urlencode($ville_f); ?>&type_salle=<?php echo urlencode($type_f); ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list"></i> Toutes (<?php echo $tot_salles; ?>)
            </a>

            <a href="?statut=active&ville=<?php echo urlencode($ville_f); ?>&type_salle=<?php echo urlencode($type_f); ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'active' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i> Actives (<?php echo $tot_actives; ?>)
            </a>

            <a href="?statut=maintenance&ville=<?php echo urlencode($ville_f); ?>&type_salle=<?php echo urlencode($type_f); ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'maintenance' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-wrench" style="color: #FF4A0D;"></i> En Maintenance
            </a>
        </div>

        <!-- À DROITE : FILTRE PAR VILLE & RECHERCHE -->
        <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">

            <div style="display: inline-flex; align-items: center; gap: 6px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; padding: 2px 8px;">
                <i class="fa-solid fa-location-dot" style="color: var(--dash-primary); font-size: 0.8rem;"></i>
                <select name="ville" onchange="this.form.submit()" style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.35rem 0.2rem; outline: none;">
                    <option value="toutes">Toutes les villes</option>
                    <?php foreach ($villes_disponibles as $v): ?>
                        <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $ville_f === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher une salle..." style="padding: 0.4rem 0.8rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; background: #ffffff; width: 180px; outline: none;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.75rem;"><i class="fa-solid fa-arrow-right"></i></button>

            <?php if ($search !== '' || $ville_f !== 'toutes' || $statut_f !== 'tous'): ?>
                <a href="salles.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. GRILLE DES SALLES & LIEUX
         ============================================================================== -->
    <?php if (empty($salles_list)): ?>
        <div style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 14px; padding: 3.5rem 1.5rem; text-align: center;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: #F5F5F5; color: var(--dash-muted); display: grid; place-items: center; font-size: 1.6rem; margin: 0 auto 1rem;">
                <i class="fa-solid fa-building-circle-xmark"></i>
            </div>
            <h3 style="margin: 0 0 0.5rem 0; color: var(--dash-text); font-weight: 800;">Aucune salle trouvée</h3>
            <p style="color: var(--dash-muted); margin: 0 0 1.25rem 0; font-size: 0.88rem;">Aucune salle ne correspond à vos critères de filtrage actuels.</p>
            <button type="button" onclick="openCreateSalleModal()" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-plus"></i> Ajouter une Nouvelle Salle
            </button>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
            <?php foreach ($salles_list as $s): ?>
                <?php
                    $type_info = get_type_salle_label($s['type_salle']);
                    $zones = $salle_zones_map[$s['id']] ?? [];
                    $statut_badge = ['Active', '#FFF2ED', '#000000'];
                    if ($s['statut'] === 'maintenance') $statut_badge = ['En Maintenance', '#FFF2ED', '#FF4A0D'];
                    elseif ($s['statut'] === 'inactive') $statut_badge = ['Inactive', '#F5F5F5', '#000000'];
                ?>
                <div class="salle-grid-card">
                    <div>
                        <!-- En-tête de la carte -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.6rem;">
                            <div>
                                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: <?php echo $type_info[2]; ?>; margin-bottom: 3px;">
                                    <i class="fa-solid <?php echo $type_info[1]; ?>"></i> <?php echo $type_info[0]; ?>
                                </span>
                                <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; line-height: 1.3;">
                                    <?php echo htmlspecialchars($s['nom']); ?>
                                </h3>
                            </div>
                            <span style="background: <?php echo $statut_badge[1]; ?>; color: <?php echo $statut_badge[2]; ?>; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; flex-shrink: 0;">
                                <?php echo $statut_badge[0]; ?>
                            </span>
                        </div>

                        <!-- Image / Vignette & Badges Médias -->
                        <?php 
                            $has_img = !empty($s['image_principale']) && $s['image_principale'] !== 'salle_default.jpg' && file_exists('../uploads/salles/' . $s['image_principale']);
                            $galerie_count = !empty($s['galerie_photos']) ? count(json_decode($s['galerie_photos'], true) ?: []) : 0;
                            $has_plan = !empty($s['plan_image']) && file_exists('../uploads/salles/' . $s['plan_image']);
                            $has_file_3d = !empty($s['fichier_3d']) && file_exists('../uploads/salles/' . $s['fichier_3d']);
                        ?>
                        <?php if ($has_img): ?>
                            <div style="height: 120px; border-radius: 10px; overflow: hidden; margin-bottom: 0.85rem; position: relative; background: #000000;">
                                <img src="../uploads/salles/<?php echo htmlspecialchars($s['image_principale']); ?>" alt="<?php echo htmlspecialchars($s['nom']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <div style="position: absolute; top: 8px; left: 8px; display: flex; gap: 4px;">
                                    <?php if ($galerie_count > 0): ?>
                                        <span style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); color: #fff; font-size: 0.68rem; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                            <i class="fa-solid fa-camera"></i> <?php echo $galerie_count; ?> photos
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($has_plan): ?>
                                        <span style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); color: #FF4A0D; font-size: 0.68rem; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                            <i class="fa-solid fa-map"></i> Plan
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; gap: 6px; margin-bottom: 0.65rem; flex-wrap: wrap;">
                                <span style="background: rgba(56, 189, 248, 0.12); color: #FF4A0D; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.7rem; font-weight: 700; padding: 2px 6px; border-radius: 6px;">
                                    <i class="fa-solid fa-cube"></i> Modèle <?php echo ucfirst(str_replace('_', ' ', $s['modele_3d'] ?? 'theatre')); ?>
                                </span>
                                <?php if ($galerie_count > 0): ?>
                                    <span style="background: #F5F5F5; color: #737373; font-size: 0.7rem; font-weight: 700; padding: 2px 6px; border-radius: 6px;">
                                        <i class="fa-solid fa-camera"></i> <?php echo $galerie_count; ?> photos
                                    </span>
                                <?php endif; ?>
                                <?php if ($has_plan): ?>
                                    <span style="background: #FFF2ED; color: #FF4A0D; font-size: 0.7rem; font-weight: 700; padding: 2px 6px; border-radius: 6px;">
                                        <i class="fa-solid fa-map"></i> Plan joint
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Localisation & Capacité -->
                        <div style="display: flex; align-items: center; justify-content: space-between; background: #F5F5F5; border: 1px solid #E5E5E5; border-radius: 10px; padding: 0.65rem 0.85rem; margin-bottom: 0.85rem;">
                            <div>
                                <div style="font-size: 0.78rem; color: var(--dash-muted);"><i class="fa-solid fa-location-dot" style="color: var(--dash-primary);"></i> Ville & Commune</div>
                                <strong style="font-size: 0.88rem; color: var(--dash-text);"><?php echo htmlspecialchars($s['ville'] . ($s['commune'] ? ' · ' . $s['commune'] : '')); ?></strong>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 0.78rem; color: var(--dash-muted);"><i class="fa-solid fa-chair" style="color: #FF4A0D;"></i> Capacité</div>
                                <strong style="font-size: 1.15rem; font-weight: 900; color: #FF4A0D;"><?php echo number_format((int)$s['capacite'], 0, ',', ' '); ?></strong>
                                <small style="font-size: 0.7rem; color: var(--dash-muted);">places</small>
                            </div>
                        </div>

                        <!-- Adresse & Configuration -->
                        <div style="font-size: 0.8rem; color: var(--dash-muted); margin-bottom: 0.75rem; line-height: 1.4;">
                            <?php if (!empty($s['adresse'])): ?>
                                <div style="margin-bottom: 3px;"><i class="fa-solid fa-map-pin" style="width: 14px;"></i> <?php echo htmlspecialchars($s['adresse']); ?></div>
                            <?php endif; ?>
                            <div>
                                <i class="fa-solid fa-sliders" style="width: 14px;"></i> Configuration : 
                                <strong style="color: var(--dash-text);">
                                    <?php 
                                        if ($s['configuration'] === 'places_numerotees') echo 'Sièges numérotés';
                                        elseif ($s['configuration'] === 'mixte') echo 'Mixte (Debout & Assis)';
                                        else echo 'Placement libre';
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <!-- Zones de tarification configurées -->
                        <div style="margin-bottom: 0.85rem;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase; margin-bottom: 4px; display: flex; justify-content: space-between;">
                                <span>Zones & Jauges (<?php echo count($zones); ?>)</span>
                                <a href="javascript:void(0)" onclick="openZonesModal(<?php echo $s['id']; ?>, '<?php echo addslashes($s['nom']); ?>')" style="color: var(--dash-primary); font-size: 0.74rem; text-decoration: none; font-weight: 800;">+ Gérer les zones</a>
                            </div>
                            <div>
                                <?php if (empty($zones)): ?>
                                    <span style="font-size: 0.75rem; color: var(--dash-muted); font-style: italic;">Aucune zone définie (jauge globale)</span>
                                <?php else: ?>
                                    <?php foreach ($zones as $z): ?>
                                        <span class="zone-pill" style="background: <?php echo htmlspecialchars($z['couleur']); ?>18; color: <?php echo htmlspecialchars($z['couleur']); ?>; border: 1px solid <?php echo htmlspecialchars($z['couleur']); ?>35;">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo htmlspecialchars($z['couleur']); ?>;"></span>
                                            <?php echo htmlspecialchars($z['nom_zone']); ?> : <strong><?php echo number_format($z['capacite'], 0, ',', ' '); ?></strong>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Équipements inclus -->
                        <?php if (!empty($s['equipements'])): ?>
                            <div style="margin-bottom: 0.85rem;">
                                <div style="font-size: 0.72rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase; margin-bottom: 4px;">Équipements & Prestations</div>
                                <div>
                                    <?php 
                                        $eq_list = explode(',', $s['equipements']);
                                        foreach ($eq_list as $eq): 
                                    ?>
                                        <span class="equip-tag"><i class="fa-solid fa-check" style="color: #FF4A0D; font-size: 0.65rem;"></i> <?php echo htmlspecialchars(trim($eq)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pied de carte : Responsable & Actions -->
                    <div style="border-top: 1px solid var(--dash-border); padding-top: 0.75rem; margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                        <div style="font-size: 0.76rem; color: var(--dash-muted);">
                            <?php if (!empty($s['contact_responsable']) || !empty($s['telephone_responsable'])): ?>
                                <i class="fa-solid fa-user-tie"></i> <?php echo htmlspecialchars($s['contact_responsable'] ?: 'Contact'); ?> (<?php echo htmlspecialchars($s['telephone_responsable']); ?>)
                            <?php else: ?>
                                <span style="font-style: italic;">Régie Eventia</span>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; gap: 5px; align-items: center;">
                            <button type="button" class="dash-btn-action" onclick="openStudio3D(<?php echo $s['id']; ?>, '<?php echo addslashes($s['nom']); ?>')" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; background: #000000; color: #FF4A0D; border: 1px solid #FF4A0D;" title="Ouvrir le Studio Rendu 3D">
                                <i class="fa-solid fa-cube"></i> Rendu 3D
                            </button>
                            <button type="button" class="dash-btn-action" onclick="openEditSalleModal(<?php echo htmlspecialchars(json_encode($s)); ?>)" style="padding: 0.35rem 0.65rem; font-size: 0.78rem;" title="Modifier les paramètres">
                                <i class="fa-solid fa-pen-to-square"></i> Modifier
                            </button>
                            <a href="?delete_salle=<?php echo $s['id']; ?>" onclick="return confirm('Confirmer la suppression définitive de cette salle ?');" class="dash-btn-action" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; color: #000000;" title="Supprimer la salle">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODAL A : CRÉER / MODIFIER UNE SALLE
     ============================================================================== -->
<div id="modalSalle" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; justify-content: center; align-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 1.5rem 1.75rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #E5E5E5; padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
            <h3 id="modalSalleTitle" style="margin: 0; font-size: 1.2rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-landmark" style="color: var(--eventia-amber, #FF4A0D);"></i> <span>Ajouter une Nouvelle Salle</span>
            </h3>
            <button type="button" onclick="closeModalSalle()" style="background: transparent; border: none; font-size: 1.25rem; color: var(--dash-muted); cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" id="formSalle" enctype="multipart/form-data">
            <input type="hidden" name="action_create_salle" id="actionSalleFlag" value="1">
            <input type="hidden" name="salle_id" id="salle_id_input" value="">

            <div style="display: grid; grid-template-columns: 1fr; gap: 0.85rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Nom complet de la salle / complexe *</label>
                    <input type="text" name="nom" id="salle_nom" required placeholder="Ex: Palais de la Culture - Salle Anoumabo" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Ville *</label>
                        <input type="text" name="ville" id="salle_ville" required value="Abidjan" placeholder="Ex: Abidjan, Bouaké..." style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Commune / Quartier</label>
                        <input type="text" name="commune" id="salle_commune" placeholder="Ex: Treichville, Cocody..." style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Adresse précise / Repère géographique</label>
                    <input type="text" name="adresse" id="salle_adresse" placeholder="Ex: Boulevard de Marseille, en bordure lagunaire" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Capacité maximale (places) *</label>
                        <input type="number" name="capacite" id="salle_capacite" required min="1" placeholder="Ex: 4000" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 800; color: #FF4A0D; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Type d'infrastructure *</label>
                        <select name="type_salle" id="salle_type" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box; background: #ffffff;">
                            <option value="auditorium">Auditorium / Salle de Spectacle</option>
                            <option value="theatre">Théâtre classique</option>
                            <option value="stade">Stade / Grande Arène couverte</option>
                            <option value="plein_air">Plein Air / Esplanade / Jardin</option>
                            <option value="salle_fetes">Salle Polyvalente / Fêtes & Banquets</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Configuration des places</label>
                        <select name="configuration" id="salle_configuration" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box; background: #ffffff;">
                            <option value="placement_libre">Placement Libre / Fosse</option>
                            <option value="places_numerotees">Places Assises Numérotées</option>
                            <option value="mixte">Mixte (VIP Numéroté + Standard Libre)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">
                            <i class="fa-solid fa-cube" style="color: #FF4A0D;"></i> Modèle Spatial 3D *
                        </label>
                        <select name="modele_3d" id="salle_modele_3d" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1.5px solid #FF4A0D; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #FFF2ED; color: #FF4A0D;">
                            <option value="theatre_italien">🎭 Théâtre à l'Italienne / Opéra</option>
                            <option value="auditorium">🏛️ Auditorium & Palais des Congrès</option>
                            <option value="arena">🏟️ Arena Circulaire & Dôme</option>
                            <option value="stade">⚽ Grand Stade & Pelouse</option>
                            <option value="plein_air">🌳 Espace Plein Air / Festival</option>
                        </select>
                    </div>
                </div>

                <!-- SECTION MÉDIAS, PHOTOS & FICHIERS DU PLAN 3D -->
                <div style="background: #F5F5F5; border: 1px solid #E5E5E5; border-radius: 12px; padding: 1rem; margin-top: 4px;">
                    <div style="font-weight: 800; font-size: 0.85rem; color: #000000; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-camera" style="color: #FF4A0D;"></i> Photos Réelles, Plan Visuel & Fichier 3D de la Salle
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">
                                <i class="fa-solid fa-image" style="color: #FF4A0D;"></i> Photo Principale / Façade
                            </label>
                            <input type="file" name="image_principale" accept="image/png, image/jpeg, image/webp" style="width: 100%; font-size: 0.78rem; padding: 0.35rem; border: 1px solid var(--dash-border); border-radius: 6px; background: #fff;">
                            <small style="color: var(--dash-muted); font-size: 0.7rem;">Formats : JPG, PNG, WEBP (Max 5 Mo)</small>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">
                                <i class="fa-solid fa-images" style="color: #FF4A0D;"></i> Galerie Photos Réelles (Scène, Gradins...)
                            </label>
                            <input type="file" name="galerie_photos[]" multiple accept="image/png, image/jpeg, image/webp" style="width: 100%; font-size: 0.78rem; padding: 0.35rem; border: 1px solid var(--dash-border); border-radius: 6px; background: #fff;">
                            <small style="color: var(--dash-muted); font-size: 0.7rem;">Sélectionnez plusieurs photos de la salle</small>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">
                                <i class="fa-solid fa-map-location-dot" style="color: #FF4A0D;"></i> Image du Plan Architectural (2D/3D)
                            </label>
                            <input type="file" name="plan_image" accept="image/png, image/jpeg, image/webp, image/svg+xml, application/pdf" style="width: 100%; font-size: 0.78rem; padding: 0.35rem; border: 1px solid var(--dash-border); border-radius: 6px; background: #fff;">
                            <small style="color: var(--dash-muted); font-size: 0.7rem;">Plan de masse, blueprint ou plan d'implantation</small>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">
                                <i class="fa-solid fa-cube" style="color: #FF4A0D;"></i> Fichier 3D dédié (Optionnel)
                            </label>
                            <input type="file" name="fichier_3d" accept=".glb, .gltf, .obj, .json, .zip" style="width: 100%; font-size: 0.78rem; padding: 0.35rem; border: 1px solid var(--dash-border); border-radius: 6px; background: #fff;">
                            <small style="color: var(--dash-muted); font-size: 0.7rem;">Format 3D : GLB, GLTF, OBJ, JSON spatial</small>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Prix Location Indicatif (FCFA)</label>
                        <input type="number" name="prix_location_indicatif" id="salle_prix_loc" min="0" step="50000" placeholder="Ex: 1500000" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Statut opérationnel</label>
                        <select name="statut" id="salle_statut" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box; background: #ffffff;">
                            <option value="active">Active & Réservable</option>
                            <option value="maintenance">En Maintenance / Travaux</option>
                            <option value="inactive">Inactive / Désactivée</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Contact Responsable / Régisseur</label>
                        <input type="text" name="contact_responsable" id="salle_contact" placeholder="Ex: M. Koffi / Direction Technique" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Téléphone Contact</label>
                        <input type="text" name="telephone_responsable" id="salle_tel" placeholder="Ex: +225 07 00 00 00 00" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Équipements & Prestations techniques (Cochez)</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 6px; background: #F5F5F5; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.8rem;">
                        <label><input type="checkbox" name="equipements[]" value="Sonorisation Pro"> Sonorisation Pro</label>
                        <label><input type="checkbox" name="equipements[]" value="Climatisation Intégrale"> Climatisation</label>
                        <label><input type="checkbox" name="equipements[]" value="Éclairage Scénique / DMX"> Éclairage Scénique</label>
                        <label><input type="checkbox" name="equipements[]" value="Loges VIP"> Loges VIP</label>
                        <label><input type="checkbox" name="equipements[]" value="Parking Sécurisé"> Parking Sécurisé</label>
                        <label><input type="checkbox" name="equipements[]" value="Écrans Géants LED"> Écrans Géants LED</label>
                        <label><input type="checkbox" name="equipements[]" value="Accès PMR"> Accès PMR</label>
                        <label><input type="checkbox" name="equipements[]" value="Groupe Électrogène"> Groupe Électrogène</label>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--dash-text); margin-bottom: 3px;">Description & Notes techniques</label>
                    <textarea name="description" id="salle_description" rows="3" placeholder="Dimensions de la scène, consignes de sécurité, acoustique..." style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; box-sizing: border-box; resize: vertical;"></textarea>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid #E5E5E5; padding-top: 1rem;">
                <button type="button" onclick="closeModalSalle()" class="dash-btn-action" style="padding: 0.6rem 1.25rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" id="btnSubmitSalle" style="padding: 0.6rem 1.5rem;">
                    <i class="fa-solid fa-check"></i> Enregistrer la Salle
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL B : GÉRER LES ZONES & JAUGES D'UNE SALLE
     ============================================================================== -->
<div id="modalZones" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; justify-content: center; align-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 550px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 1.5rem 1.75rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #E5E5E5; padding-bottom: 0.75rem; margin-bottom: 1rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.15rem; color: var(--dash-text); font-weight: 800;">Zones & Jauges Tarifaires</h3>
                <small id="zonesSalleNom" style="color: var(--dash-muted); font-weight: 700;"></small>
            </div>
            <button type="button" onclick="closeModalZones()" style="background: transparent; border: none; font-size: 1.25rem; color: var(--dash-muted); cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Formulaire d'ajout d'une zone -->
        <form method="POST" style="background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem;">
            <input type="hidden" name="action_add_zone" value="1">
            <input type="hidden" name="salle_id" id="zones_salle_id_input" value="">

            <div style="font-size: 0.82rem; font-weight: 800; color: var(--dash-text); margin-bottom: 0.6rem;">
                <i class="fa-solid fa-plus-circle" style="color: #FF4A0D;"></i> Ajouter une zone à cette salle
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr 60px; gap: 6px; align-items: flex-end;">
                <div>
                    <label style="display: block; font-size: 0.74rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Nom de la zone *</label>
                    <input type="text" name="nom_zone" required placeholder="Ex: Carré VIP, Fosse..." style="width: 100%; padding: 0.45rem 0.65rem; border-radius: 6px; border: 1px solid var(--dash-border); font-size: 0.82rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.74rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Capacité *</label>
                    <input type="number" name="capacite_zone" required min="1" placeholder="300" style="width: 100%; padding: 0.45rem 0.65rem; border-radius: 6px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.74rem; font-weight: 700; color: var(--dash-muted); margin-bottom: 2px;">Couleur</label>
                    <input type="color" name="couleur_zone" value="#FF4A0D" style="width: 100%; height: 32px; border: 1px solid var(--dash-border); border-radius: 6px; cursor: pointer; padding: 1px;">
                </div>
            </div>

            <div style="margin-top: 0.75rem; text-align: right;">
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.4rem 0.95rem; font-size: 0.8rem;">
                    <i class="fa-solid fa-plus"></i> Ajouter la zone
                </button>
            </div>
        </form>

        <div style="text-align: right;">
            <button type="button" onclick="closeModalZones()" class="dash-btn-action" style="padding: 0.5rem 1.25rem;">Fermer</button>
        </div>
    </div>
</div>

<script>
function openCreateSalleModal() {
    document.getElementById('modalSalleTitle').innerHTML = '<i class="fa-solid fa-landmark" style="color: var(--eventia-amber, #FF4A0D);"></i> <span>Ajouter une Nouvelle Salle</span>';
    document.getElementById('actionSalleFlag').name = 'action_create_salle';
    document.getElementById('btnSubmitSalle').innerHTML = '<i class="fa-solid fa-check"></i> Enregistrer la Salle';
    document.getElementById('formSalle').reset();
    document.getElementById('salle_id_input').value = '';
    document.getElementById('modalSalle').style.display = 'flex';
}

function openEditSalleModal(salle) {
    document.getElementById('modalSalleTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color: var(--eventia-amber, #FF4A0D);"></i> <span>Modifier la Salle</span>';
    document.getElementById('actionSalleFlag').name = 'action_update_salle';
    document.getElementById('btnSubmitSalle').innerHTML = '<i class="fa-solid fa-check"></i> Mettre à Jour la Salle';
    
    document.getElementById('salle_id_input').value = salle.id;
    document.getElementById('salle_nom').value = salle.nom || '';
    document.getElementById('salle_ville').value = salle.ville || 'Abidjan';
    document.getElementById('salle_commune').value = salle.commune || '';
    document.getElementById('salle_adresse').value = salle.adresse || '';
    document.getElementById('salle_capacite').value = salle.capacite || '';
    document.getElementById('salle_type').value = salle.type_salle || 'polyvalente';
    document.getElementById('salle_configuration').value = salle.configuration || 'placement_libre';
    document.getElementById('salle_statut').value = salle.statut || 'active';
    document.getElementById('salle_contact').value = salle.contact_responsable || '';
    document.getElementById('salle_tel').value = salle.telephone_responsable || '';
    document.getElementById('salle_description').value = salle.description || '';

    // Cocher les équipements
    const eqString = salle.equipements || '';
    document.querySelectorAll('input[name="equipements[]"]').forEach(cb => {
        cb.checked = eqString.includes(cb.value);
    });

    document.getElementById('salle_modele_3d').value = salle.modele_3d || 'theatre_italien';
    document.getElementById('salle_prix_loc').value = salle.prix_location_indicatif || '';

    document.getElementById('modalSalle').style.display = 'flex';
}

function closeModalSalle() {
    document.getElementById('modalSalle').style.display = 'none';
}

function openZonesModal(salleId, salleNom) {
    document.getElementById('zones_salle_id_input').value = salleId;
    document.getElementById('zonesSalleNom').innerText = salleNom;
    document.getElementById('modalZones').style.display = 'flex';
}

function closeModalZones() {
    document.getElementById('modalZones').style.display = 'none';
}

/* ==============================================================================
   STUDIO RENDU 3D TEMPS RÉEL (ADMIN)
   ============================================================================== */
let admin3DEngine = null;

async function openStudio3D(salleId, salleNom) {
    document.getElementById('studioSalleNom').textContent = salleNom;
    document.getElementById('modalStudio3D').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    const canvas = document.getElementById('studio3DCanvas');
    if (!admin3DEngine) {
        admin3DEngine = new EventiaVenue3D(canvas, {
            readOnly: false,
            onSeatSelect: (seat) => {
                document.getElementById('studioSeatDetails').innerHTML = `
                    <div style="background: #000000; color: #fff; padding: 0.75rem; border-radius: 8px; border-left: 3px solid #FF4A0D;">
                        <div style="font-weight: 800; font-size: 0.9rem; color: #FF4A0D;"><i class="fa-solid fa-chair"></i> Siège ${seat.code} (Rang ${seat.row})</div>
                        <div style="font-size: 0.8rem; margin-top: 3px; color: #737373;">Zone : <strong style="color: #fff;">${seat.zone_name}</strong></div>
                        <div style="font-size: 0.8rem; color: #737373;">Tarif indicatif : <strong style="color: #FF4A0D;">${Number(seat.prix).toLocaleString('fr-FR')} FCFA</strong></div>
                        <div style="font-size: 0.74rem; color: #FF4A0D; margin-top: 4px;">✓ Vue dégagée sur la scène (${Math.round(seat.z / 10)}m)</div>
                    </div>
                `;
            },
            onSeatDeselect: () => {
                document.getElementById('studioSeatDetails').innerHTML = '<span style="color: #737373; font-size: 0.8rem; font-style: italic;">Cliquez sur un siège 3D pour inspecter sa visibilité.</span>';
            }
        });
    }

    setTimeout(() => {
        if (admin3DEngine) {
            admin3DEngine.resize();
            admin3DEngine.render();
        }
    }, 60);

    // Chargement des données 3D depuis l'endpoint
    const data = await admin3DEngine.loadFromEndpoint('../ajax/salle_3d_data.php?salle_id=' + salleId);
    if (data) {
        document.getElementById('studioTotalSeats').textContent = data.seats ? data.seats.length : 0;
        document.getElementById('studioTotalZones').textContent = data.zones ? data.zones.length : 0;
        document.getElementById('studioModeleType').textContent = (data.salle.modele_3d || 'theatre_italien').toUpperCase();
        
        // Remplissage des filtres de zones/tarifs
        const filterBox = document.getElementById('studioTariffFilters');
        filterBox.innerHTML = '<button type="button" class="studio-filter-btn active" onclick="filterAdmin3D(this, \'all\')">Tous les Tarifs / Zones</button>';
        
        if (data.zones) {
            data.zones.forEach(z => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'studio-filter-btn';
                btn.innerHTML = `<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ${z.couleur || '#FF4A0D'}; margin-right: 4px;"></span> ${z.nom_zone} (${Number(z.tarif_indicatif || 10000).toLocaleString('fr-FR')} F)`;
                btn.onclick = () => filterAdmin3D(btn, z.id);
                filterBox.appendChild(btn);
            });
        }

        // Rendu du Plan Architectural (si disponible)
        const planContainer = document.getElementById('studioPlanContainer');
        if (data.salle && data.salle.plan_image) {
            planContainer.innerHTML = `
                <div style="background: #000000; border: 1px solid #000000; border-radius: 12px; padding: 1rem; max-width: 800px; width: 100%;">
                    <div style="font-weight: 800; color: #FF4A0D; font-size: 0.95rem; margin-bottom: 0.5rem; text-align: left;">
                        <i class="fa-solid fa-map-location-dot"></i> Plan d'Implantation Architectural
                    </div>
                    <img src="../uploads/salles/${data.salle.plan_image}" style="width: 100%; max-height: 60vh; object-fit: contain; border-radius: 8px; border: 1px solid #000000;">
                    <div style="margin-top: 0.75rem; font-size: 0.78rem; color: #737373; text-align: left;">
                        Fichier source : <code>${data.salle.plan_image}</code>
                    </div>
                </div>
            `;
        } else {
            planContainer.innerHTML = `
                <div style="color: #737373; padding: 3rem; text-align: center;">
                    <i class="fa-solid fa-map" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: #000000; display: block;"></i>
                    <strong style="color: #737373;">Aucun plan architectural joint pour cette salle.</strong>
                    <p style="font-size: 0.82rem; margin-top: 0.4rem;">Vous pouvez téléverser une image de blueprint ou de plan 2D/3D en modifiant la salle.</p>
                </div>
            `;
        }

        // Rendu de la Galerie Photos
        const photosGrid = document.getElementById('studioPhotosGrid');
        const photos = (data.salle && data.salle.galerie_photos) ? [...data.salle.galerie_photos] : [];
        if (data.salle && data.salle.image_principale && data.salle.image_principale !== 'salle_default.jpg') {
            if (!photos.includes(data.salle.image_principale)) photos.unshift(data.salle.image_principale);
        }

        if (photos.length > 0) {
            photosGrid.innerHTML = '';
            photos.forEach((ph, i) => {
                const card = document.createElement('div');
                card.style.cssText = 'background: #000000; border: 1px solid #000000; border-radius: 10px; overflow: hidden; height: 220px;';
                card.innerHTML = `
                    <img src="../uploads/salles/${ph}" style="width: 100%; height: 170px; object-fit: cover; cursor: pointer;" onclick="window.open(this.src)">
                    <div style="padding: 0.5rem 0.75rem; font-size: 0.74rem; color: #737373; display: flex; justify-content: space-between;">
                        <span>Photo #${i + 1}</span>
                        <a href="../uploads/salles/${ph}" target="_blank" style="color: #FF4A0D; text-decoration: none;">Agrandir <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                    </div>
                `;
                photosGrid.appendChild(card);
            });
        } else {
            photosGrid.innerHTML = `
                <div style="grid-column: 1 / -1; color: #737373; padding: 3rem; text-align: center;">
                    <i class="fa-solid fa-camera" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: #000000; display: block;"></i>
                    <strong style="color: #737373;">Aucune photo réelle enregistrée pour cette salle.</strong>
                    <p style="font-size: 0.82rem; margin-top: 0.4rem;">Ajoutez des photos de la scène, de la fosse et des tribunes lors de l'édition de la salle.</p>
                </div>
            `;
        }

        switchStudioTab('3d');
    }
}

function switchStudioTab(tab) {
    document.querySelectorAll('.studio-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('studioTabContent3D').style.display = (tab === '3d') ? 'flex' : 'none';
    document.getElementById('studioTabContentPlan').style.display = (tab === 'plan') ? 'flex' : 'none';
    document.getElementById('studioTabContentPhotos').style.display = (tab === 'photos') ? 'block' : 'none';
    document.getElementById('studioCamControls').style.display = (tab === '3d') ? 'flex' : 'none';

    if (tab === '3d') {
        const b = document.getElementById('tabBtn3D');
        if (b) b.classList.add('active');
        if (admin3DEngine) {
            admin3DEngine.resize();
            admin3DEngine.render();
        }
    } else if (tab === 'plan') {
        const b = document.getElementById('tabBtnPlan');
        if (b) b.classList.add('active');
    } else if (tab === 'photos') {
        const b = document.getElementById('tabBtnPhotos');
        if (b) b.classList.add('active');
    }
}

function filterAdmin3D(btn, filterId) {
    document.querySelectorAll('.studio-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (admin3DEngine) {
        admin3DEngine.filterByTariff(filterId);
    }
}

function setStudioView(view) {
    if (admin3DEngine) {
        admin3DEngine.setViewPreset(view);
    }
}

function closeStudio3D() {
    document.getElementById('modalStudio3D').style.display = 'none';
    document.body.style.overflow = '';
}
</script>

<style>
.studio-tab-btn {
    background: #000000;
    border: 1px solid #000000;
    color: #737373;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.studio-tab-btn:hover {
    color: #ffffff;
    border-color: #FF4A0D;
}
.studio-tab-btn.active {
    background: rgba(56, 189, 248, 0.15);
    border-color: #FF4A0D;
    color: #FF4A0D;
}

@media (max-width: 991px) {
    #modalStudio3D {
        padding: 0 !important;
    }
    #modalStudio3D > div {
        width: 100% !important;
        height: 100% !important;
        height: 100dvh !important;
        border-radius: 0 !important;
        border: none !important;
    }
    #modalStudio3D > div > div:nth-child(2) {
        flex-direction: column !important;
    }
    #modalStudio3D > div > div:nth-child(2) > div:last-child {
        width: 100% !important;
        border-left: none !important;
        border-top: 1px solid #000000 !important;
        max-height: 40vh !important;
    }
}
</style>

<!-- ==============================================================================
     MODAL C : STUDIO DE RENDU 3D TEMPS RÉEL DE LA SALLE
     ============================================================================== -->
<div id="modalStudio3D" style="display: none; position: fixed; inset: 0; background: rgba(3, 7, 18, 0.88); z-index: 99999; justify-content: center; align-items: center; padding: 1rem; backdrop-filter: blur(8px);">
    <div style="background: #000000; border: 1px solid #000000; border-radius: 20px; width: 100%; max-width: 1100px; height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);">
        
        <!-- Header du Studio 3D -->
        <div style="padding: 0.85rem 1.5rem; background: #000000; border-bottom: 1px solid #000000; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="background: rgba(56, 189, 248, 0.15); color: #FF4A0D; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase;">
                        <i class="fa-solid fa-cube"></i> Studio Rendu 3D & Médias
                    </span>
                    <h3 id="studioSalleNom" style="margin: 0; font-size: 1.2rem; color: #F5F5F5; font-weight: 800;"></h3>
                </div>
                <div style="display: flex; gap: 6px; margin-top: 6px;">
                    <button type="button" id="tabBtn3D" class="studio-tab-btn active" onclick="switchStudioTab('3d')">
                        <i class="fa-solid fa-cube"></i> Rendu 3D Immersif
                    </button>
                    <button type="button" id="tabBtnPlan" class="studio-tab-btn" onclick="switchStudioTab('plan')">
                        <i class="fa-solid fa-map-location-dot"></i> Plan Architectural (2D/3D)
                    </button>
                    <button type="button" id="tabBtnPhotos" class="studio-tab-btn" onclick="switchStudioTab('photos')">
                        <i class="fa-solid fa-camera"></i> Photos Réelles & Vues
                    </button>
                </div>
            </div>

            <!-- Boutons de caméras prédéfinies -->
            <div style="display: flex; gap: 6px; align-items: center;" id="studioCamControls">
                <button type="button" class="dash-btn-action" onclick="setStudioView('isometric')" style="background: #000000; color: #F5F5F5; border: 1px solid #000000; font-size: 0.78rem;">
                    <i class="fa-solid fa-cubes"></i> 3D
                </button>
                <button type="button" class="dash-btn-action" onclick="setStudioView('top')" style="background: #000000; color: #F5F5F5; border: 1px solid #000000; font-size: 0.78rem;">
                    <i class="fa-solid fa-eye"></i> Haut
                </button>
                <button type="button" class="dash-btn-action" onclick="setStudioView('stage')" style="background: #000000; color: #F5F5F5; border: 1px solid #000000; font-size: 0.78rem;">
                    <i class="fa-solid fa-masks-theater"></i> Scène
                </button>
                <button type="button" class="dash-btn-action" onclick="setStudioView('reset')" style="background: #000000; color: #FF4A0D; border: 1px solid #000000; font-size: 0.78rem;" title="Recentrer">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
                <button type="button" onclick="closeStudio3D()" style="background: rgba(239, 68, 68, 0.2); border: 1px solid #000000; color: #000000; border-radius: 8px; width: 34px; height: 34px; display: grid; place-items: center; cursor: pointer; margin-left: 6px;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Corps du Studio : Canvas 3D / Plan / Galerie + Panneau latéral -->
        <div style="display: flex; flex: 1; overflow: hidden; position: relative;">
            
            <!-- 1. Vue 3D Canvas -->
            <div id="studioTabContent3D" style="flex: 1; position: relative; background: #000000; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <canvas id="studio3DCanvas" style="width: 100%; height: 100%; display: block;"></canvas>

                <!-- Légende Flottante sur le Canvas -->
                <div style="position: absolute; bottom: 15px; left: 15px; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); border: 1px solid #000000; padding: 0.6rem 0.9rem; border-radius: 10px; display: flex; gap: 1rem; align-items: center; font-size: 0.75rem; color: #F5F5F5;">
                    <span style="display: flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; border-radius: 50%; background: #FF4A0D;"></span> Place Libre</span>
                    <span style="display: flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; border-radius: 50%; background: #FF4A0D; box-shadow: 0 0 6px #FF4A0D;"></span> Siège Sélectionné</span>
                    <span style="display: flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; border-radius: 50%; background: #000000;"></span> Déjà Réservé</span>
                </div>
            </div>

            <!-- 2. Vue Plan Architectural / Blueprint -->
            <div id="studioTabContentPlan" style="flex: 1; display: none; background: #000000; padding: 1.5rem; overflow-y: auto; text-align: center; align-items: center; justify-content: center; flex-direction: column;">
                <div id="studioPlanContainer" style="max-width: 100%; max-height: 100%; display: flex; flex-direction: column; align-items: center;">
                    <!-- Rempli en JS -->
                </div>
            </div>

            <!-- 3. Vue Galerie Photos Réelles -->
            <div id="studioTabContentPhotos" style="flex: 1; display: none; background: #000000; padding: 1.5rem; overflow-y: auto;">
                <div id="studioPhotosGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                    <!-- Rempli en JS -->
                </div>
            </div>

            <!-- Barre latérale : Métriques & Simulation Tarifs -->
            <div style="width: 320px; background: #000000; border-left: 1px solid #000000; padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; overflow-y: auto;">
                <div>
                    <h4 style="margin: 0 0 0.85rem; font-size: 0.88rem; color: #F5F5F5; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-sliders" style="color: #FF4A0D;"></i> Filtres & Tarifs 3D
                    </h4>

                    <!-- Boutons filtres zones/tarifs -->
                    <div id="studioTariffFilters" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 1.25rem;">
                        <!-- Rempli en JS -->
                    </div>

                    <!-- Détail du siège inspecté -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #737373; text-transform: uppercase; margin-bottom: 6px;">
                            Inspection Siège & Visibilité
                        </label>
                        <div id="studioSeatDetails">
                            <span style="color: #737373; font-size: 0.8rem; font-style: italic;">Cliquez sur un siège 3D pour inspecter sa visibilité.</span>
                        </div>
                    </div>
                </div>

                <!-- Métriques de la salle -->
                <div style="background: #000000; border: 1px solid #000000; border-radius: 12px; padding: 0.85rem; font-size: 0.78rem; color: #737373;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Modèle spatial :</span>
                        <strong id="studioModeleType" style="color: #FF4A0D;">THEATRE</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Sièges 3D modélisés :</span>
                        <strong id="studioTotalSeats" style="color: #F5F5F5;">0</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Zones configurées :</span>
                        <strong id="studioTotalZones" style="color: #FF4A0D;">0</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.studio-filter-btn {
    background: #000000;
    border: 1px solid #000000;
    color: #E5E5E5;
    padding: 0.55rem 0.8rem;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
}
.studio-filter-btn:hover {
    background: #000000;
    color: #ffffff;
    border-color: #FF4A0D;
}
.studio-filter-btn.active {
    background: rgba(56, 189, 248, 0.15);
    border-color: #FF4A0D;
    color: #FF4A0D;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
}
</style>

<script src="../js/venue-3d-engine.js"></script>

<?php include 'footer.php'; ?>
