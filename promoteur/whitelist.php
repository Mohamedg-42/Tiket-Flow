<?php
// ==============================================================================
// GESTION DES ÉVÉNEMENTS PRIVÉS & WHITELIST INVITÉS (promoteur/whitelist.php)
// Design Dashboard Pro & Standard Typographique Suisse Müller-Brockmann
// ==============================================================================

$page_title = "Invités & Événements Privés (Whitelist) - Espace Promoteur";
include 'header.php';

$user_id = (int) $_SESSION['user_id'];

// Récupération de tous les événements du promoteur
$stmt_events = $pdo->prepare("
    SELECT id, nom, date_evenement, heure, lieu, visibilite, access_token, requires_whitelist, statut 
    FROM events 
    WHERE user_id = ? 
    ORDER BY date_evenement DESC, id DESC
");
$stmt_events->execute([$user_id]);
$my_events = $stmt_events->fetchAll(PDO::FETCH_ASSOC);

// Événement sélectionné (par défaut le premier ou celui passé en GET)
$selected_event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
if (!$selected_event_id && !empty($my_events)) {
    $selected_event_id = (int) $my_events[0]['id'];
}

$active_event = null;
foreach ($my_events as $ev) {
    if ((int) $ev['id'] === $selected_event_id) {
        $active_event = $ev;
        break;
    }
}

// Récupération de la liste des invités pour l'événement sélectionné
$guests = [];
$stats = [
    'total_guests'    => 0,
    'total_allocated' => 0,
    'total_purchased' => 0,
    'total_remaining' => 0
];

if ($active_event) {
    $stmt_guests = $pdo->prepare("
        SELECT id, nom, prenom, telephone, email, tickets_authorized, tickets_purchased, statut, imported_at 
        FROM guest_whitelists 
        WHERE event_id = ? 
        ORDER BY id DESC
    ");
    $stmt_guests->execute([$selected_event_id]);
    $guests = $stmt_guests->fetchAll(PDO::FETCH_ASSOC);

    foreach ($guests as $g) {
        $stats['total_guests']++;
        $auth = (int) $g['tickets_authorized'];
        $bought = (int) $g['tickets_purchased'];
        $stats['total_allocated'] += $auth;
        $stats['total_purchased'] += $bought;
    }
    $stats['total_remaining'] = max(0, $stats['total_allocated'] - $stats['total_purchased']);
}

// Construction de l'URL directe privée sécurisée
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF']));
$direct_link = '';
if ($active_event) {
    $token_param = !empty($active_event['access_token']) ? '&token=' . urlencode($active_event['access_token']) : '';
    $direct_link = rtrim($base_url, '/') . '/client/evenement.php?id=' . $active_event['id'] . $token_param;
}
?>

<div class="dash-container">
    <!-- En-tête de page & Titre -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-user-shield" style="color: var(--tikeli-orange, #FF4A0D); font-size: 1.6rem;"></i>
                Événements Privés & Whitelist d'Invités
            </h1>
            <p>Gérez la confidentialité, restreignez l'accès par token secret et préchargez la liste des bénéficiaires autorisés vérifiés par SMS OTP.</p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <?php if ($active_event): ?>
                <a href="<?php echo htmlspecialchars($direct_link); ?>" target="_blank" class="dash-btn-action"
                   style="background: #0F172A; color: #FFFFFF; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Tester le Lien Direct
                </a>
            <?php endif; ?>
            <a href="mes-evenements" class="dash-btn-action"
               style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-left"></i> Mes Événements
            </a>
        </div>
    </div>

    <?php if (empty($my_events)): ?>
        <div class="dash-card" style="text-align: center; padding: 4rem 1.5rem;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; color: #CBD5E1; margin-bottom: 1rem; display: block;"></i>
            <h3 style="margin: 0 0 0.5rem; color: #0F172A; font-weight: 800;">Aucun événement actif</h3>
            <p style="color: #64748B; max-width: 420px; margin: 0 auto 1.5rem;">Vous devez créer ou faire approuver au moins un événement avant de configurer une liste d'invités privée.</p>
            <a href="demande-evenement" class="dash-btn-action" style="background: var(--tikeli-orange, #FF4A0D); color: #fff; text-decoration: none;">
                <i class="fa-solid fa-plus-circle"></i> Créer un Événement
            </a>
        </div>
    <?php else: ?>

        <!-- Sélecteur d'événement -->
        <div class="dash-card" style="margin-bottom: 1.5rem; padding: 1.25rem 1.5rem;">
            <form method="GET" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <label for="event_id" style="font-weight: 700; font-size: 0.9rem; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-calendar-check" style="color: var(--tikeli-orange, #FF4A0D);"></i> Événement à gérer :
                </label>
                <select name="event_id" id="event_id" onchange="this.form.submit()" 
                        style="flex: 1; min-width: 260px; padding: 0.65rem 1rem; border-radius: 8px; border: 1px solid #CBD5E1; font-weight: 600; font-size: 0.9rem;">
                    <?php foreach ($my_events as $ev): ?>
                        <option value="<?php echo (int) $ev['id']; ?>" <?php echo ((int)$ev['id'] === $selected_event_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ev['nom']); ?> — <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?> (<?php echo strtoupper($ev['visibilite'] ?? 'PUBLIC'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Panneau de Contrôle Confidentialité & Lien Secret -->
        <div class="dash-card" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div>
                    <h3 style="margin: 0 0 0.35rem; font-size: 1.15rem; font-weight: 800; color: #0F172A;">
                        <i class="fa-solid fa-sliders" style="color: var(--tikeli-orange, #FF4A0D);"></i> Paramètres de Confidentialité & Accès
                    </h3>
                    <p style="margin: 0; font-size: 0.86rem; color: #64748B;">
                        Définissez si l'événement doit être masqué du grand public et s'il requiert une invitation validée par code OTP SMS.
                    </p>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="visibiliteBadge" class="dash-badge <?php echo (($active_event['visibilite'] ?? 'public') === 'prive') ? 'badge-danger' : 'badge-success'; ?>"
                          style="font-family: 'Space Mono', monospace; font-weight: 700; font-size: 0.78rem; padding: 4px 10px; border-radius: 6px;">
                        <i class="fa-solid <?php echo (($active_event['visibilite'] ?? 'public') === 'prive') ? 'fa-lock' : 'fa-globe'; ?>"></i>
                        <?php echo (($active_event['visibilite'] ?? 'public') === 'prive') ? 'MODE PRIVÉ (MASQUÉ)' : 'MODE PUBLIC'; ?>
                    </span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem;">
                <!-- Option 1 : Mode Privé -->
                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; user-select: none;">
                    <input type="checkbox" id="chkIsPrivate" <?php echo (($active_event['visibilite'] ?? 'public') === 'prive') ? 'checked' : ''; ?>
                           style="width: 20px; height: 20px; margin-top: 3px; accent-color: var(--tikeli-orange, #FF4A0D); cursor: pointer;"
                           onchange="updateEventVisibilitySettings()">
                    <div>
                        <strong style="display: block; font-size: 0.95rem; color: #0F172A;">Événement Privé / Masqué</strong>
                        <small style="display: block; font-size: 0.8rem; color: #64748B; line-height: 1.4; margin-top: 3px;">
                            Strictement exclu du catalogue public, de la recherche et des flux. Accessible uniquement avec le jeton secret.
                        </small>
                    </div>
                </label>

                <!-- Option 2 : Restreint à la Whitelist -->
                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; user-select: none;">
                    <input type="checkbox" id="chkRequiresWhitelist" <?php echo !empty($active_event['requires_whitelist']) ? 'checked' : ''; ?>
                           style="width: 20px; height: 20px; margin-top: 3px; accent-color: var(--tikeli-orange, #FF4A0D); cursor: pointer;"
                           onchange="updateEventVisibilitySettings()">
                    <div>
                        <strong style="display: block; font-size: 0.95rem; color: #0F172A;">Accès Restreint par Whitelist & OTP</strong>
                        <small style="display: block; font-size: 0.8rem; color: #64748B; line-height: 1.4; margin-top: 3px;">
                            Seuls les numéros préchargés dans la liste ci-dessous pourront acheter. Chaque acheteur devra valider un code SMS.
                        </small>
                    </div>
                </label>
            </div>

            <!-- Boîte de lien direct unique -->
            <div id="privateUrlSection" style="<?php echo (($active_event['visibilite'] ?? 'public') === 'prive') ? 'display: block;' : 'display: none;'; ?>">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748B; letter-spacing: 0.5px; margin-bottom: 6px;">
                    <i class="fa-solid fa-link" style="color: var(--tikeli-orange, #FF4A0D);"></i> URL Directe Sécurisée pour les Invités :
                </label>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <input type="text" id="directShareUrl" readonly value="<?php echo htmlspecialchars($direct_link); ?>"
                           style="flex: 1; min-width: 260px; font-family: 'Space Mono', monospace; font-size: 0.85rem; padding: 0.75rem 1rem; border: 1.5px solid #CBD5E1; border-radius: 8px; background: #FFFFFF; color: #0F172A;">
                    <button type="button" onclick="copyDirectUrl()" class="dash-btn-action"
                            style="background: #0F172A; color: #FFFFFF; border: none; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-copy"></i> <span id="copyBtnText">Copier le Lien</span>
                    </button>
                </div>
                <small style="display: block; color: #64748B; font-size: 0.78rem; margin-top: 6px;">
                    Partagez ce lien uniquement avec vos invités par Email, WhatsApp ou SMS. Sans le paramètre de sécurité, la page sera bloquée.
                </small>
            </div>
        </div>

        <!-- Statistiques Clés de la Whitelist -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="dash-card" style="padding: 1.25rem;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748B; display: block; margin-bottom: 4px;">Invités Inscrits</span>
                <strong style="font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 800; color: #0F172A;"><?php echo $stats['total_guests']; ?></strong>
            </div>
            <div class="dash-card" style="padding: 1.25rem;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748B; display: block; margin-bottom: 4px;">Billets Alloués</span>
                <strong style="font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 800; color: var(--tikeli-orange, #FF4A0D);"><?php echo $stats['total_allocated']; ?></strong>
            </div>
            <div class="dash-card" style="padding: 1.25rem;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748B; display: block; margin-bottom: 4px;">Billets Commandés</span>
                <strong style="font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 800; color: #059669;"><?php echo $stats['total_purchased']; ?></strong>
            </div>
            <div class="dash-card" style="padding: 1.25rem;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748B; display: block; margin-bottom: 4px;">Billets Restants</span>
                <strong style="font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 800; color: #3B82F6;"><?php echo $stats['total_remaining']; ?></strong>
            </div>
        </div>

        <!-- Grille : Module d'importation CSV & Ajout Manuel -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- 1. Importation Fichier CSV/Excel -->
            <div class="dash-card">
                <h3 style="margin: 0 0 0.5rem; font-size: 1.1rem; font-weight: 800; color: #0F172A;">
                    <i class="fa-solid fa-file-csv" style="color: #059669;"></i> Importer un Fichier CSV
                </h3>
                <p style="margin: 0 0 1rem; font-size: 0.82rem; color: #64748B; line-height: 1.45;">
                    Téléversez un fichier CSV comportant les colonnes : <code>Nom</code>, <code>Prénom</code>, <code>Téléphone</code>, <code>Email</code>, <code>Quota</code>.
                </p>

                <form id="csvImportForm" onsubmit="handleCsvImport(event)">
                    <div style="border: 2px dashed #CBD5E1; border-radius: 10px; padding: 1.5rem; text-align: center; margin-bottom: 1rem; background: #F8FAFC;">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size: 2rem; color: #94A3B8; margin-bottom: 0.5rem; display: block;"></i>
                        <input type="file" id="csvFileInput" name="csv_file" accept=".csv,text/csv" required
                               style="display: block; width: 100%; font-size: 0.85rem; color: #64748B;">
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <a href="data:text/csv;charset=utf-8,Nom,Prenom,Telephone,Email,Quota%0AKouame,Jean,0701020304,jean@example.com,2%0ATraore,Fatou,0501020305,fatou@example.com,1" 
                           download="modele_invites_tikeli.csv" 
                           style="font-size: 0.8rem; color: var(--tikeli-orange, #FF4A0D); text-decoration: underline; font-weight: 600;">
                            <i class="fa-solid fa-download"></i> Télécharger le modèle CSV
                        </a>
                        <button type="submit" id="btnUploadCsv" class="dash-btn-action"
                                style="background: #059669; color: #FFFFFF; border: none; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-upload"></i> Lancer l'Import
                        </button>
                    </div>
                    <div id="csvFeedback" style="margin-top: 10px; font-size: 0.84rem; font-weight: 600; display: none;"></div>
                </form>
            </div>

            <!-- 2. Ajout Manuel d'un Invité -->
            <div class="dash-card">
                <h3 style="margin: 0 0 0.5rem; font-size: 1.1rem; font-weight: 800; color: #0F172A;">
                    <i class="fa-solid fa-user-plus" style="color: var(--tikeli-orange, #FF4A0D);"></i> Ajouter un Invité Manuellement
                </h3>
                <p style="margin: 0 0 1rem; font-size: 0.82rem; color: #64748B;">
                    Inscrivez directement une personne bénéficiaire en renseignant son numéro et son quota de tickets.
                </p>

                <form id="singleGuestForm" onsubmit="handleAddSingleGuest(event)">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Nom *</label>
                            <input type="text" id="guest_nom" required placeholder="Nom de famille"
                                   style="width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Prénom</label>
                            <input type="text" id="guest_prenom" placeholder="Prénoms"
                                   style="width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Téléphone *</label>
                            <input type="tel" id="guest_tel" required placeholder="Ex: 0701020304"
                                   style="width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; font-family: 'Space Mono', monospace; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Quota Billets *</label>
                            <input type="number" id="guest_quota" required min="1" max="100" value="1"
                                   style="width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; font-family: 'Space Mono', monospace; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Email (Facultatif)</label>
                        <input type="email" id="guest_email" placeholder="invite@domaine.com"
                               style="width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
                    </div>

                    <button type="submit" id="btnAddSingleSubmit" class="dash-btn-action"
                            style="width: 100%; background: #0F172A; color: #FFFFFF; border: none; font-size: 0.9rem; font-weight: 700; padding: 0.75rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fa-solid fa-plus"></i> Enregistrer l'Invité
                    </button>
                    <div id="singleGuestFeedback" style="margin-top: 8px; font-size: 0.84rem; font-weight: 600; display: none;"></div>
                </form>
            </div>
        </div>

        <!-- Tableau dynamique des invités enregistrés -->
        <div class="dash-card">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div>
                    <h3 style="margin: 0 0 0.25rem; font-size: 1.15rem; font-weight: 800; color: #0F172A;">
                        <i class="fa-solid fa-users-viewfinder" style="color: var(--tikeli-orange, #FF4A0D);"></i> Liste des Bénéficiaires Autorisés (<?php echo count($guests); ?>)
                    </h3>
                    <small style="color: #64748B;">Recherchez ou filtrez la liste pour surveiller l'état des réservations en direct.</small>
                </div>

                <div style="position: relative; min-width: 240px;">
                    <input type="text" id="filterGuestInput" onkeyup="filterGuestsTable()" placeholder="Filtrer nom ou téléphone..."
                           style="width: 100%; padding: 0.55rem 0.9rem 0.55rem 2.2rem; border-radius: 8px; border: 1px solid #CBD5E1; font-size: 0.85rem; box-sizing: border-box;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: 0.85rem;"></i>
                </div>
            </div>

            <?php if (empty($guests)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: #64748B;">
                    <i class="fa-solid fa-address-book" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 0.75rem; display: block;"></i>
                    <h4 style="margin: 0 0 0.35rem; color: #0F172A; font-weight: 800;">Aucun invité préchargé</h4>
                    <p style="margin: 0; font-size: 0.85rem;">Utilisez le formulaire d'ajout manuel ou importez un fichier CSV ci-dessus pour peupler la liste.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dash-table" id="guestsTable" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 1.5px solid #E2E8F0; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B;">
                                <th style="padding: 0.75rem 1rem;">Invité</th>
                                <th style="padding: 0.75rem 1rem;">Numéro Téléphone</th>
                                <th style="padding: 0.75rem 1rem;">Email</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Quota Alloué</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Commandés</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Statut</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($guests as $g): 
                                $is_exhausted = ((int)$g['tickets_purchased'] >= (int)$g['tickets_authorized']);
                                ?>
                                <tr id="guest-row-<?php echo (int) $g['id']; ?>" style="border-bottom: 1px solid #F1F5F9; font-size: 0.88rem;">
                                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #0F172A;">
                                        <?php echo htmlspecialchars($g['nom'] . ' ' . ($g['prenom'] ?? '')); ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; font-family: 'Space Mono', monospace; font-size: 0.82rem; color: #0F172A;">
                                        <?php echo htmlspecialchars($g['telephone']); ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; font-size: 0.82rem; color: #64748B;">
                                        <?php echo !empty($g['email']) ? htmlspecialchars($g['email']) : '<em style="color:#CBD5E1;">Non renseigné</em>'; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; text-align: center; font-family: 'Space Mono', monospace; font-weight: 700;">
                                        <?php echo (int) $g['tickets_authorized']; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; text-align: center; font-family: 'Space Mono', monospace; font-weight: 700; color: <?php echo ((int)$g['tickets_purchased'] > 0) ? '#059669' : '#64748B'; ?>;">
                                        <?php echo (int) $g['tickets_purchased']; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; text-align: center;">
                                        <?php if ($is_exhausted): ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.7rem; font-weight: 700; background: #FEE2E2; color: #DC2626; padding: 2px 7px; border-radius: 4px;">
                                                ÉPUISÉ
                                            </span>
                                        <?php else: ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.7rem; font-weight: 700; background: #DCFCE7; color: #15803D; padding: 2px 7px; border-radius: 4px;">
                                                DISPONIBLE
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem; text-align: right;">
                                        <button type="button" onclick="deleteGuest(<?php echo (int) $g['id']; ?>)" 
                                                title="Supprimer cet invité"
                                                style="background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; padding: 5px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
const currentEventId = <?php echo (int) ($selected_event_id ?? 0); ?>;

// 1. Mise à jour de la visibilité et du mode Whitelist
async function updateEventVisibilitySettings() {
    const isPrivate = document.getElementById('chkIsPrivate').checked;
    const reqWhitelist = document.getElementById('chkRequiresWhitelist').checked;
    const urlSection = document.getElementById('privateUrlSection');
    const badge = document.getElementById('visibiliteBadge');

    const formData = new FormData();
    formData.append('action', 'toggle_visibility');
    formData.append('event_id', currentEventId);
    formData.append('is_private', isPrivate ? '1' : '0');
    formData.append('requires_whitelist', reqWhitelist ? '1' : '0');

    try {
        const res = await fetch('../ajax/whitelist_manage.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            if (isPrivate) {
                if (urlSection) urlSection.style.display = 'block';
                if (badge) {
                    badge.className = 'dash-badge badge-danger';
                    badge.innerHTML = '<i class="fa-solid fa-lock"></i> MODE PRIVÉ (MASQUÉ)';
                }
            } else {
                if (urlSection) urlSection.style.display = 'none';
                if (badge) {
                    badge.className = 'dash-badge badge-success';
                    badge.innerHTML = '<i class="fa-solid fa-globe"></i> MODE PUBLIC';
                }
            }
        } else {
            alert(data.message || 'Erreur lors de la mise à jour.');
        }
    } catch (e) {
        alert('Erreur technique.');
    }
}

// 2. Copier l'URL directe
function copyDirectUrl() {
    const inp = document.getElementById('directShareUrl');
    const btnTxt = document.getElementById('copyBtnText');
    if (!inp) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        if (btnTxt) btnTxt.innerText = 'Copié !';
        setTimeout(() => { if (btnTxt) btnTxt.innerText = 'Copier le Lien'; }, 2000);
    });
}

// 3. Ajout Manuel d'un invité
async function handleAddSingleGuest(e) {
    e.preventDefault();
    const nom = document.getElementById('guest_nom').value.trim();
    const prenom = document.getElementById('guest_prenom').value.trim();
    const tel = document.getElementById('guest_tel').value.trim();
    const quota = document.getElementById('guest_quota').value;
    const email = document.getElementById('guest_email').value.trim();
    const feedback = document.getElementById('singleGuestFeedback');
    const btn = document.getElementById('btnAddSingleSubmit');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enregistrement...';

    const formData = new FormData();
    formData.append('action', 'add_guest');
    formData.append('event_id', currentEventId);
    formData.append('nom', nom);
    formData.append('prenom', prenom);
    formData.append('telephone', tel);
    formData.append('tickets_authorized', quota);
    formData.append('email', email);

    try {
        const res = await fetch('../ajax/whitelist_manage.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            feedback.style.display = 'block';
            feedback.style.color = '#059669';
            feedback.innerText = data.message;
            setTimeout(() => { window.location.reload(); }, 1200);
        } else {
            feedback.style.display = 'block';
            feedback.style.color = '#DC2626';
            feedback.innerText = data.message || "Erreur d'ajout.";
        }
    } catch (err) {
        feedback.style.display = 'block';
        feedback.style.color = '#DC2626';
        feedback.innerText = 'Erreur technique de communication.';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Enregistrer l\'Invité';
    }
}

// 4. Importation CSV
async function handleCsvImport(e) {
    e.preventDefault();
    const fileInput = document.getElementById('csvFileInput');
    const feedback = document.getElementById('csvFeedback');
    const btn = document.getElementById('btnUploadCsv');

    if (!fileInput.files.length) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importation...';

    const formData = new FormData();
    formData.append('action', 'import_csv');
    formData.append('event_id', currentEventId);
    formData.append('csv_file', fileInput.files[0]);

    try {
        const res = await fetch('../ajax/whitelist_manage.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            feedback.style.display = 'block';
            feedback.style.color = '#059669';
            feedback.innerText = data.message;
            setTimeout(() => { window.location.reload(); }, 1500);
        } else {
            feedback.style.display = 'block';
            feedback.style.color = '#DC2626';
            feedback.innerText = data.message || "Erreur d'importation.";
        }
    } catch (err) {
        feedback.style.display = 'block';
        feedback.style.color = '#DC2626';
        feedback.innerText = 'Erreur lors de la lecture du fichier.';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-upload"></i> Lancer l\'Import';
    }
}

// 5. Supprimer un invité
async function deleteGuest(guestId) {
    if (!confirm("Êtes-vous certain de vouloir retirer cet invité de la liste ?")) return;

    const formData = new FormData();
    formData.append('action', 'delete_guest');
    formData.append('event_id', currentEventId);
    formData.append('guest_id', guestId);

    try {
        const res = await fetch('../ajax/whitelist_manage.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('guest-row-' + guestId);
            if (row) row.remove();
        } else {
            alert(data.message || "Erreur lors de la suppression.");
        }
    } catch (e) {
        alert("Erreur de connexion.");
    }
}

// 6. Filtrage dynamique du tableau des invités
function filterGuestsTable() {
    const q = document.getElementById('filterGuestInput').value.toLowerCase();
    const rows = document.querySelectorAll('#guestsTable tbody tr');
    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>
