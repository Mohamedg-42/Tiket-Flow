<?php
// ==============================================================================
// MODIFICATION D'UN ÉVÉNEMENT ADMIN (admin/modifier-evenement.php)
// Design Dashboard Pro - Formulaire de mise à jour complète
// ==============================================================================

$admin_page_title = "Modifier l'Événement - Administration";
include 'header.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: evenements.php');
    exit();
}

$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: evenements.php');
    exit();
}

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Catégorie sélectionnée ou personnalisée
    $categorie = trim($_POST['categorie'] ?? 'Concert');
    $categorie_custom = trim($_POST['categorie_custom'] ?? '');
    if ($categorie === 'Autre' || !empty($categorie_custom)) {
        if (!empty($categorie_custom)) {
            $categorie = $categorie_custom;
        }
    }
    $date = $_POST['date'] ?? '';
    $heure = $_POST['heure'] ?? '';

    // Détermination de la Ville et de la Salle (Connue ou Pas)
    $ville = trim($_POST['ville'] ?? '');
    $ville_custom = trim($_POST['ville_custom'] ?? '');
    if ($ville === 'Autre' && !empty($ville_custom)) {
        $ville = $ville_custom;
    }
    if (empty($ville))
        $ville = 'Abidjan';

    $salle_connue = $_POST['salle_connue'] ?? 'oui';
    if ($salle_connue === 'non') {
        $statut_salle = trim($_POST['statut_salle_inconnue'] ?? 'Lieu à confirmer');
        if (empty($statut_salle))
            $statut_salle = 'Lieu à confirmer';
        $lieu = $statut_salle . ' (' . $ville . ')';
    } else {
        $salle_nom = trim($_POST['salle_nom'] ?? '');
        $salle_select = trim($_POST['salle_select'] ?? '');
        $nom_choisi = !empty($salle_nom) ? $salle_nom : (($salle_select !== 'Autre' && !empty($salle_select)) ? $salle_select : '');

        if (!empty($nom_choisi)) {
            $lieu = $nom_choisi . ', ' . $ville;
        } else {
            $lieu = !empty($_POST['lieu']) ? trim($_POST['lieu']) : $event['lieu'];
        }
    }

    $statut = $_POST['statut'] ?? 'actif';
    $type_vote = $_POST['type_vote'] ?? 'aucun';
    $vote_question = trim($_POST['vote_question'] ?? '');
    $prix_vote = (float) ($_POST['prix_vote'] ?? 0);
    $commission = (float) ($_POST['commission_rate'] ?? 5.0);

    // Image upload si nouvelle image
    $image = $event['image'];
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowed, true)) {
            $upload_events = '../uploads/events/';
            if (!is_dir($upload_events)) {
                mkdir($upload_events, 0777, true);
            }
            $new_img = 'event_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $new_img)) {
                $image = $new_img;
            }
        }
    }

    try {
        $sql = 'UPDATE events SET nom = ?, description = ?, categorie = ?, image = ?, date_evenement = ?, heure = ?, lieu = ?, type_vote = ?, vote_question = ?, prix_vote = ?, commission_rate = ?, statut = ? WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nom, $description, $categorie, $image, $date, $heure, $lieu, $type_vote, $vote_question ?: null, $prix_vote, $commission, $statut, $id]);

        $message = "L'événement a été mis à jour avec succès !";
        $msg_type = 'success';

        // Rechargement des infos actualisées
        $stmt_r = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt_r->execute([$id]);
        $event = $stmt_r->fetch();
    } catch (PDOException $e) {
        $message = 'Erreur lors de la modification : ' . $e->getMessage();
        $msg_type = 'error';
    }
}
?>

<div class="dash-container">
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Modifier l'Événement : <?php echo htmlspecialchars($event['nom']); ?>
            </h1>
            <p>Mettez à jour les caractéristiques, le statut ou la tarification de l'événement.</p>
        </div>

        <a href="evenements.php" class="dash-btn-action"
            style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Retour à la Liste
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <div class="dash-card">
        <form method="POST" enctype="multipart/form-data">
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                <div style="grid-column: 1 / -1;">
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Nom
                        de l'événement *</label>
                    <input type="text" name="nom" required value="<?php echo htmlspecialchars($event['nom']); ?>"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.9rem; box-sizing: border-box;">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Description
                        détaillée</label>
                    <textarea name="description" rows="4"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box;"><?php echo htmlspecialchars($event['description'] ?? ''); ?></textarea>
                </div>

                <div>
                    <?php
                    $cats = ['Concert', 'Festival', 'Théâtre', 'Humour', 'Sport', 'Conférence', 'Soirée', 'Autre'];
                    $is_custom = !in_array($event['categorie'], ['Concert', 'Festival', 'Théâtre', 'Humour', 'Sport', 'Conférence', 'Soirée'], true);
                    ?>
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <label
                            style="font-size: 0.82rem; font-weight: 700; margin: 0; color: var(--dash-text);">Catégorie
                            *</label>
                        <button type="button" onclick="toggleAdminCustomCat()"
                            style="background: none; border: none; font-size: 0.74rem; color: var(--dash-primary); font-weight: 700; cursor: pointer; text-decoration: underline; padding: 0;">
                            <i class="fa-solid fa-pen"></i> Personnaliser
                        </button>
                    </div>
                    <select id="select_categorie" name="categorie" onchange="onAdminCatChange(this)"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo (($event['categorie'] === $c) || ($is_custom && $c === 'Autre')) ? 'selected' : ''; ?>>
                                <?php echo ($c === 'Autre') ? '✏️ Autre / Catégorie personnalisée' : $c; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="box_custom_cat"
                        style="<?php echo $is_custom ? 'display: block;' : 'display: none;'; ?> margin-top: 6px;">
                        <input type="text" id="input_custom_cat" name="categorie_custom"
                            value="<?php echo $is_custom ? htmlspecialchars($event['categorie']) : ''; ?>"
                            placeholder="Tapez votre propre catégorie..."
                            style="width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #FF4A0D; background: #FFF2ED; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                    </div>
                </div>

                <?php
                $current_lieu_raw = $event['lieu'] ?? '';
                $is_lieu_inconnu = (stripos($current_lieu_raw, 'Lieu à confirmer') !== false || stripos($current_lieu_raw, 'Lieu secret') !== false || stripos($current_lieu_raw, 'Salle en cours') !== false);
                $villes_connues = ['Abidjan', 'Yamoussoukro', 'Bouaké', 'San-Pédro', 'Korhogo', 'Daloa', 'Grand-Bassam', 'Assinie', 'Man', 'Gagnoa', 'Soubré', 'En Ligne'];
                $detected_ville = 'Abidjan';
                foreach ($villes_connues as $vk) {
                    if (stripos($current_lieu_raw, $vk) !== false) {
                        $detected_ville = $vk;
                        break;
                    }
                }
                ?>
                <div
                    style="grid-column: 1 / -1; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1.15rem;">
                    <div
                        style="font-weight: 800; font-size: 0.88rem; color: var(--dash-text); margin-bottom: 0.85rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-location-dot" style="color: var(--dash-primary);"></i> Localisation &
                        Salle de la Manifestation
                    </div>

                    <!-- 1. Sélection de la VILLE (Liste Déroulante) -->
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="admin_select_ville"
                                style="font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; display: block; color: var(--dash-text);">
                                Ville / Localité *
                            </label>
                            <select id="admin_select_ville" name="ville" onchange="onAdminVilleChange(this.value)"
                                required
                                style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; background: #ffffff; color: var(--dash-text); box-sizing: border-box;">
                                <?php foreach ($villes_connues as $vk): ?>
                                    <option value="<?php echo $vk; ?>" <?php echo ($detected_ville === $vk) ? 'selected' : ''; ?>><?php echo $vk; ?></option>
                                <?php endforeach; ?>
                                <option value="Autre">✏️ Autre ville...</option>
                            </select>
                            <div id="box_admin_ville_custom" style="display: none; margin-top: 6px;">
                                <input type="text" name="ville_custom" id="input_admin_ville_custom"
                                    placeholder="Saisissez le nom de la ville..."
                                    style="width: 100%; padding: 0.55rem; border: 1px solid var(--dash-primary); background: #ffffff; border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                            </div>
                        </div>

                        <!-- 2. Choix : La salle est-elle connue ou pas ? -->
                        <div>
                            <label
                                style="font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; display: block; color: var(--dash-text);">
                                Choix de la salle / lieu précis *
                            </label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <label id="label_admin_salle_connue" onclick="setAdminSalleConnue(true)"
                                    style="display: flex; align-items: center; gap: 8px; padding: 0.58rem 0.8rem; border: <?php echo !$is_lieu_inconnu ? '1.5px solid var(--dash-primary)' : '1px solid #E5E5E5'; ?>; background: <?php echo !$is_lieu_inconnu ? '#FFF2ED' : '#ffffff'; ?>; border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                    <input type="radio" name="salle_connue" value="oui" <?php echo !$is_lieu_inconnu ? 'checked' : ''; ?> style="accent-color: var(--dash-primary); margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #000000;">
                                        <i class="fa-solid fa-check" style="color: var(--dash-primary);"></i> Salle
                                        connue
                                    </span>
                                </label>
                                <label id="label_admin_salle_inconnue" onclick="setAdminSalleConnue(false)"
                                    style="display: flex; align-items: center; gap: 8px; padding: 0.58rem 0.8rem; border: <?php echo $is_lieu_inconnu ? '1.5px solid #FF4A0D' : '1px solid #E5E5E5'; ?>; background: <?php echo $is_lieu_inconnu ? '#FFF2ED' : '#ffffff'; ?>; border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                    <input type="radio" name="salle_connue" value="non" <?php echo $is_lieu_inconnu ? 'checked' : ''; ?> style="accent-color: var(--dash-primary); margin: 0;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #737373;">
                                        <i class="fa-solid fa-clock" style="color: #FF4A0D;"></i> Pas encore connue
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 3.A : Si la salle EST CONNUE -->
                    <div id="section_admin_salle_connue"
                        style="<?php echo !$is_lieu_inconnu ? 'display: block;' : 'display: none;'; ?>">
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                            <div>
                                <label
                                    style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">
                                    Sélectionnez une salle réputée ou tapez son nom
                                </label>
                                <select id="admin_salle_select" name="salle_select"
                                    onchange="onAdminSalleSelectChange(this.value)"
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; background: #ffffff; color: var(--dash-text); box-sizing: border-box;">
                                    <option value="">-- Salles réputées & Complexes --</option>
                                    <?php
                                    try {
                                        $salles_stmt = $pdo->query("SELECT nom, ville, commune, capacite FROM salles WHERE statut = 'active' ORDER BY ville ASC, nom ASC");
                                        $salles_list = $salles_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        $salles_by_ville = [];
                                        foreach ($salles_list as $s) {
                                            $v = $s['ville'] ?? 'Abidjan';
                                            $salles_by_ville[$v][] = $s;
                                        }
                                        foreach ($salles_by_ville as $vName => $items):
                                    ?>
                                        <optgroup label="Salles (<?php echo htmlspecialchars($vName); ?>)">
                                            <?php foreach ($items as $sItem): ?>
                                                <option value="<?php echo htmlspecialchars($sItem['nom']); ?>" <?php echo (stripos($current_lieu_raw, $sItem['nom']) !== false) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sItem['nom']); ?><?php echo !empty($sItem['commune']) ? ' (' . htmlspecialchars($sItem['commune']) . ')' : ''; ?><?php echo !empty($sItem['capacite']) ? ' — ' . number_format($sItem['capacite'], 0, ',', ' ') . ' pl.' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php 
                                        endforeach;
                                    } catch (Exception $e) {}
                                    ?>
                                    <option value="Autre">✏️ Autre salle / Adresse personnalisée...</option>
                                </select>
                            </div>
                            <div>
                                <label
                                    style="display: block; font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">
                                    Précision / Nom exact ou Adresse
                                </label>
                                <input type="text" id="input_admin_salle_nom" name="salle_nom"
                                    value="<?php echo !$is_lieu_inconnu ? htmlspecialchars($current_lieu_raw) : ''; ?>"
                                    placeholder="Ex: Salle Lougah, Esplanade, Hall 1..."
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box; background: #ffffff;">
                            </div>
                        </div>
                    </div>

                    <!-- 3.B : Si la salle N'EST PAS ENCORE CONNUE -->
                    <div id="section_admin_salle_inconnue"
                        style="<?php echo $is_lieu_inconnu ? 'display: block;' : 'display: none;'; ?> background: #FFF2ED; border: 1px solid #E5E5E5; border-radius: 10px; padding: 0.85rem 1rem;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.4rem;">
                            <i class="fa-solid fa-circle-info" style="color: #FF4A0D; font-size: 0.95rem;"></i>
                            <strong style="color: #000000; font-size: 0.82rem;">La salle sera dévoilée
                                ultérieurement</strong>
                        </div>
                        <p style="margin: 0 0 0.5rem; font-size: 0.76rem; color: #000000; line-height: 1.4;">
                            Choisissez le libellé qui s'affichera sur la page de l'événement et sur les billets des
                            spectateurs :
                        </p>
                        <select name="statut_salle_inconnue"
                            style="width: 100%; max-width: 400px; padding: 0.5rem 0.75rem; border: 1px solid #FF4A0D; border-radius: 8px; font-size: 0.82rem; font-weight: 700; background: #ffffff; color: #000000;">
                            <option value="Lieu à confirmer" <?php echo (stripos($current_lieu_raw, 'Lieu à confirmer') !== false) ? 'selected' : ''; ?>>📍 Lieu à confirmer très prochainement</option>
                            <option value="Lieu secret (bientôt dévoilé)" <?php echo (stripos($current_lieu_raw, 'Lieu secret') !== false) ? 'selected' : ''; ?>>🤫 Lieu secret (dévoilé aux inscrits par
                                SMS/Email)</option>
                            <option value="Salle en cours de sélection" <?php echo (stripos($current_lieu_raw, 'Salle en cours') !== false) ? 'selected' : ''; ?>>🏢 Salle en cours de sélection</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Date
                        *</label>
                    <input type="date" name="date" required
                        value="<?php echo htmlspecialchars($event['date_evenement']); ?>"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Heure
                        *</label>
                    <input type="time" name="heure" required value="<?php echo htmlspecialchars($event['heure']); ?>"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Remplacer
                        l'affiche (Optionnel)</label>
                    <input type="file" name="image" accept="image/png, image/jpeg, image/webp"
                        style="width: 100%; padding: 0.45rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Statut</label>
                    <select name="statut"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="actif" <?php echo ($event['statut'] === 'actif') ? 'selected' : ''; ?>>Actif (En
                            ligne)</option>
                        <option value="termine" <?php echo ($event['statut'] === 'termine') ? 'selected' : ''; ?>>Terminé (Clos)</option>
                        <option value="annule" <?php echo ($event['statut'] === 'annule') ? 'selected' : ''; ?>>Annulé
                        </option>
                        <option value="en_attente" <?php echo ($event['statut'] === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                    </select>
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Système
                        de Vote</label>
                    <select name="type_vote"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-sizing: border-box; background: #ffffff;">
                        <option value="aucun" <?php echo ($event['type_vote'] === 'aucun' || empty($event['type_vote'])) ? 'selected' : ''; ?>>Aucun vote</option>
                        <option value="concours" <?php echo ($event['type_vote'] === 'concours') ? 'selected' : ''; ?>>Concours (candidats)</option>
                        <option value="realisation" <?php echo ($event['type_vote'] === 'realisation') ? 'selected' : ''; ?>>Réalisation directe</option>
                        <option value="ferme" <?php echo ($event['type_vote'] === 'ferme') ? 'selected' : ''; ?>>Vote clôturé</option>
                    </select>
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Question ou Intitulé du Vote / Concours</label>
                    <input type="text" name="vote_question"
                        value="<?php echo htmlspecialchars($event['vote_question'] ?? ''); ?>"
                        placeholder="Ex: Qui sera la révélation musicale de l'année ?"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Prix
                        du Vote (FCFA)</label>
                    <input type="number" name="prix_vote"
                        value="<?php echo htmlspecialchars($event['prix_vote'] ?? 0); ?>" min="0" step="50"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label
                        style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Commission
                        (%)</label>
                    <input type="number" name="commission_rate"
                        value="<?php echo htmlspecialchars($event['commission_rate'] ?? 5.0); ?>" min="0" max="30"
                        step="0.5"
                        style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>
            </div>

            <div style="border-top: 1px solid var(--dash-border); padding-top: 1.25rem; display: flex; gap: 0.75rem;">
                <button type="submit" class="dash-btn-action btn-primary"
                    style="padding: 0.75rem 1.8rem; font-size: 0.9rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer les Modifications
                </button>
                <a href="evenements.php" class="dash-btn-action"
                    style="text-decoration: none; padding: 0.75rem 1.2rem;">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    function onAdminCatChange(sel) {
        const box = document.getElementById('box_custom_cat');
        const input = document.getElementById('input_custom_cat');
        if (sel.value === 'Autre') {
            box.style.display = 'block';
            input.required = true;
            input.focus();
        } else {
            box.style.display = 'none';
            input.required = false;
        }
    }

    function toggleAdminCustomCat() {
        const sel = document.getElementById('select_categorie');
        const box = document.getElementById('box_custom_cat');
        const input = document.getElementById('input_custom_cat');
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

    // Gestion Ville et Salle (Connue ou Pas)
    function onAdminVilleChange(val) {
        const boxCustom = document.getElementById('box_admin_ville_custom');
        const inputCustom = document.getElementById('input_admin_ville_custom');
        const optAbidjan = document.getElementById('optgroup_admin_abidjan');

        if (boxCustom) {
            boxCustom.style.display = (val === 'Autre') ? 'block' : 'none';
            if (inputCustom) inputCustom.required = (val === 'Autre');
        }
        if (optAbidjan) {
            optAbidjan.style.display = (val === 'Abidjan') ? 'block' : 'none';
        }
    }

    function setAdminSalleConnue(isKnown) {
        const radOui = document.querySelector('input[name="salle_connue"][value="oui"]');
        const radNon = document.querySelector('input[name="salle_connue"][value="non"]');
        if (radOui) radOui.checked = isKnown;
        if (radNon) radNon.checked = !isKnown;

        const lblOui = document.getElementById('label_admin_salle_connue');
        const lblNon = document.getElementById('label_admin_salle_inconnue');
        const secOui = document.getElementById('section_admin_salle_connue');
        const secNon = document.getElementById('section_admin_salle_inconnue');

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
        }
    }

    function onAdminSalleSelectChange(val) {
        const inputSalle = document.getElementById('input_admin_salle_nom');
        if (val && val !== 'Autre') {
            if (inputSalle) inputSalle.value = val;
        } else if (val === 'Autre') {
            if (inputSalle) {
                inputSalle.value = '';
                inputSalle.focus();
            }
        }
    }
</script>

<?php include 'footer.php'; ?>