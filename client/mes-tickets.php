<?php
// ==============================================================================
// MES TICKETS CLIENT (client/mes-tickets.php)
// Affichage des billets achetés avec leur code unique et QR Code pour contrôle
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin('../connexion.php');

$page_title = "Mes Tickets - Tikéli";
$body_class = "client-page tickets-page";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// ===== Filtres des billets : par événement, par type et par statut =====
$filtre_event  = filter_input(INPUT_GET, 'event', FILTER_VALIDATE_INT) ?: 0;
$filtre_type   = trim($_GET['type'] ?? '');
$filtre_statut = trim($_GET['statut'] ?? '');
$filtres_statut_valides = ['vendu', 'utilise', 'annule'];
if (!in_array($filtre_statut, $filtres_statut_valides, true)) {
    $filtre_statut = '';
}

$client_email = trim($_SESSION['user_email'] ?? '');
$user_cond = !empty($client_email) ? "(t.user_id = ? OR (t.client_email IS NOT NULL AND t.client_email = ?))" : "t.user_id = ?";
$user_p = !empty($client_email) ? [$user_id, $client_email] : [$user_id];

// Liste des événements pour lesquels le client possède des billets
$stmt_liste_events = $pdo->prepare("
    SELECT DISTINCT e.id, e.nom
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE $user_cond
    ORDER BY e.nom ASC
");
$stmt_liste_events->execute($user_p);
$mes_evenements = $stmt_liste_events->fetchAll();

// Liste des types de billets possédés par le client
$stmt_liste_types = $pdo->prepare("SELECT DISTINCT type_ticket FROM tickets t WHERE $user_cond ORDER BY type_ticket ASC");
$stmt_liste_types->execute($user_p);
$types_billets = array_column($stmt_liste_types->fetchAll(), 'type_ticket');

// Le type filtré doit exister dans la liste (sécurité, évite toute injection)
if ($filtre_type !== '' && !in_array($filtre_type, $types_billets, true)) {
    $filtre_type = '';
}

// Construction sécurisée des conditions de filtrage (requêtes préparées)
$sql_filtres    = "";
$params_filtres = [];
if ($filtre_event > 0) {
    $sql_filtres .= " AND t.event_id = ?";
    $params_filtres[] = $filtre_event;
}
if ($filtre_type !== '') {
    $sql_filtres .= " AND t.type_ticket = ?";
    $params_filtres[] = $filtre_type;
}
if ($filtre_statut !== '') {
    $sql_filtres .= " AND t.statut = ?";
    $params_filtres[] = $filtre_statut;
}

// Récupération des tickets achetés par le client (avec filtres appliqués)
$sql = "
    SELECT t.*, e.nom AS event_name, e.date_evenement, e.heure, e.lieu, e.image AS event_image
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE $user_cond
    $sql_filtres
    ORDER BY t.date_achat DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($user_p, $params_filtres));
$tickets = $stmt->fetchAll();
?>

<main class="client-main swiss-spread">
    <div class="swiss-wrap">
        <!-- Calque de Grille Modulaire Müller-Brockmann -->
        <div class="guides" aria-hidden="true">
            <div class="cols"></div>
            <div class="rows"></div>
            <div class="mline l"></div>
            <div class="mline r"></div>
        </div>

        <div class="page-header" style="margin-bottom: 2rem;">
            <div class="page-heading">
                <span class="page-kicker"><i class="fa-solid fa-ticket"></i> Votre Espace Billetterie</span>
                <h1 class="swiss-headline">Mes Billets & QR Codes</h1>
                <p>Présentez ces QR codes à l'entrée de l'événement pour faire scanner votre entrée.</p>
            </div>
            <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.35rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-arrow-left"></i> Retour aux événements
            </a>
        </div>

    <!-- Filtres des billets : par événement, par type et par statut -->
    <?php if (count($mes_evenements) > 0): ?>
        <form method="GET" class="filter-toolbar">
            <span class="filter-toolbar-label">
                <i class="fa-solid fa-filter" style="color: var(--tikeli-orange);"></i> Filtrer :
            </span>

            <select name="event" onchange="this.form.submit()" class="filter-select">
                <option value="0">Tous les événements</option>
                <?php foreach ($mes_evenements as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($filtre_event === (int)$ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['nom']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" onchange="this.form.submit()" class="filter-select">
                <option value="">Tous les types</option>
                <?php foreach ($types_billets as $tb): ?>
                    <option value="<?php echo htmlspecialchars($tb); ?>" <?php echo ($filtre_type === $tb) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tb); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="statut" onchange="this.form.submit()" class="filter-select">
                <option value="">Tous les statuts</option>
                <option value="vendu" <?php echo ($filtre_statut === 'vendu') ? 'selected' : ''; ?>>Valide</option>
                <option value="utilise" <?php echo ($filtre_statut === 'utilise') ? 'selected' : ''; ?>>Utilisé</option>
                <option value="annule" <?php echo ($filtre_statut === 'annule') ? 'selected' : ''; ?>>Annulé</option>
            </select>

            <?php if ($filtre_event > 0 || $filtre_type !== '' || $filtre_statut !== ''): ?>
                <a href="mes-tickets.php" class="filter-reset-btn">
                    <i class="fa-solid fa-xmark"></i> Réinitialiser
                </a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if (count($tickets) > 0): ?>
        <div class="tickets-grid">
            <?php foreach ($tickets as $t): ?>
                <?php
                $is_used = ($t['statut'] === 'utilise');
                $is_cancelled = ($t['statut'] === 'annule');

                // Fichier et lien de partage WhatsApp du billet (PDF)
                $pdf_ticket_filename = "billet-" . preg_replace('/[^A-Za-z0-9\-]/', '', $t['code_unique']) . ".pdf";
                $ticket_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/telecharger-pdf.php?code=' . urlencode($t['code_unique']);
                $wa_text = "🎟️ Mon billet Tikéli (PDF)\nÉvénement : " . $t['event_name']
                    . "\nType : " . $t['type_ticket']
                    . (!empty($t['place_numero']) ? "\nPlace : " . $t['place_numero'] : '')
                    . "\nCode : " . $t['code_unique']
                    . "\nLien PDF : " . $ticket_url;
                ?>
                <div class="ticket-pass-card">
                    <!-- En-tête du billet -->
                    <div class="ticket-pass-header">
                        <span class="ticket-pass-type">
                            <i class="fa-solid fa-ticket" style="color: var(--tikeli-orange);"></i> <?php echo htmlspecialchars($t['type_ticket']); ?>
                        </span>
                        <span>
                            <?php if ($is_used): ?>
                                <span class="badge-status-used">
                                    <i class="fa-solid fa-clock-rotate-left"></i> Utilisé
                                </span>
                            <?php elseif ($is_cancelled): ?>
                                <span class="badge-status-failed">
                                    <i class="fa-solid fa-ban"></i> Annulé
                                </span>
                            <?php else: ?>
                                <span class="badge-status-paid">
                                    <i class="fa-solid fa-circle-check"></i> Valide
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Encoche / Ligne perforée de découpe style ticket suisse -->
                    <div class="ticket-perforation">
                        <div class="ticket-perforation-line"></div>
                    </div>

                    <div class="ticket-pass-body">
                        <!-- Cadre QR Code -->
                        <div class="ticket-qr-frame">
                            <img src="<?php echo htmlspecialchars($t['qr_code']); ?>" alt="QR Code Billet" style="opacity: <?php echo $is_used ? '0.35' : '1'; ?>;">
                        </div>

                        <!-- Code unique cliquable pour copier -->
                        <div class="ticket-code-pill" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($t['code_unique']); ?>'); const original = this.innerHTML; this.innerHTML = '<i class=\'fa-solid fa-check\' style=\'color: #059669;\'></i> Copié !'; setTimeout(() => this.innerHTML = original, 1800);" title="Cliquer pour copier le code">
                            <span><?php echo htmlspecialchars($t['code_unique']); ?></span>
                            <i class="fa-regular fa-copy" style="color: #737373; font-size: 0.8rem;"></i>
                        </div>

                        <!-- Détails de l'événement -->
                        <h3 class="ticket-event-name">
                            <?php echo htmlspecialchars($t['event_name']); ?>
                        </h3>

                        <div class="ticket-meta-box">
                            <div class="ticket-meta-row">
                                <i class="fa-regular fa-calendar" style="color: var(--tikeli-orange);"></i>
                                <span><?php echo date('d/m/Y', strtotime($t['date_evenement'])); ?> à <?php echo substr($t['heure'], 0, 5); ?></span>
                            </div>
                            <div class="ticket-meta-row">
                                <i class="fa-solid fa-location-dot" style="color: #000000;"></i>
                                <span><?php echo htmlspecialchars($t['lieu']); ?></span>
                            </div>
                            <?php if (!empty($t['place_numero'])): ?>
                                <div class="ticket-meta-row">
                                    <i class="fa-solid fa-chair" style="color: var(--tikeli-orange);"></i>
                                    <span>Place : <strong style="color: #000000;" id="ticket-seat-val-<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['place_numero']); ?></strong></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pied du billet : Prix & Actions -->
                    <div class="ticket-pass-footer">
                        <div class="ticket-price-row">
                            <span>Prix payé :</span>
                            <strong class="ticket-price-val"><?php echo number_format($t['prix'], 0, ',', ' '); ?> FCFA</strong>
                        </div>

                        <div class="ticket-btn-group">
                            <a href="telecharger-ticket.php?code=<?php echo urlencode($t['code_unique']); ?>" target="_blank" class="btn-ticket-pdf" title="Télécharger le billet PDF">
                                <i class="fa-solid fa-download"></i> PDF
                            </a>
                            <button type="button" 
                                id="btn-wa-<?php echo (int)$t['id']; ?>"
                                data-pdf="telecharger-pdf.php?code=<?php echo urlencode($t['code_unique']); ?>"
                                data-filename="<?php echo htmlspecialchars($pdf_ticket_filename, ENT_QUOTES); ?>"
                                data-message="<?php echo htmlspecialchars($wa_text, ENT_QUOTES); ?>"
                                onclick="shareTicketPdfWhatsApp(this)"
                                class="btn-ticket-wa" title="Envoyer le PDF du billet par WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp
                            </button>
                        </div>
                        <?php
                        $event_datetime = strtotime($t['date_evenement'] . ' ' . $t['heure']);
                        $tt_id = !empty($t['ticket_type_id']) ? (int)$t['ticket_type_id'] : 0;
                        if ($tt_id === 0 && !empty($t['event_id'])) {
                            // Résolution de fallback du type de ticket
                            $stmt_tt = $pdo->prepare("SELECT id FROM ticket_types WHERE event_id = ? AND (nom ILIKE ? OR prix = ?) LIMIT 1");
                            $stmt_tt->execute([(int)$t['event_id'], $t['type_ticket'], $t['prix']]);
                            $tt_found = $stmt_tt->fetch(PDO::FETCH_ASSOC);
                            if ($tt_found) {
                                $tt_id = (int)$tt_found['id'];
                            }
                        }
                        $can_change_seat = ($t['statut'] === 'vendu' && $event_datetime > time() && $tt_id > 0);
                        $has_seat = !empty($t['place_numero']);
                        $btn_seat_label = $has_seat ? "Changer ma place en 3D" : "Choisir ma place en 3D";
                        ?>
                        <?php if ($can_change_seat): ?>
                            <button type="button" 
                                class="btn-ticket-seat-change" 
                                onclick="openSeatChange3DModal(<?php echo (int)$t['id']; ?>, <?php echo (int)$t['event_id']; ?>, <?php echo $tt_id; ?>, '<?php echo htmlspecialchars(addslashes($t['event_name'])); ?>', '<?php echo htmlspecialchars(addslashes($t['lieu'])); ?>', '<?php echo htmlspecialchars(addslashes($t['type_ticket'])); ?>', '<?php echo htmlspecialchars(addslashes($t['place_numero'] ?? '')); ?>')"
                                title="<?php echo $btn_seat_label; ?> sur le rendu 3D">
                                <i class="fa-solid fa-cube"></i> <?php echo $btn_seat_label; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="eventia-empty-state" style="background: #ffffff; border: 1px solid var(--line); border-radius: 16px; padding: 3.5rem 1.5rem; text-align: center; margin-top: 1rem;">
            <div style="width: 64px; height: 64px; background: #F5F5F5; border-radius: 50%; display: grid; place-items: center; font-size: 1.8rem; color: #737373; margin: 0 auto 1.25rem;">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <h3 style="color: #000000; font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Vous n'avez aucun billet pour le moment</h3>
            <p style="color: #737373; font-size: 0.92rem; max-width: 440px; margin: 0 auto 1.5rem;">Réservez votre premier événement dès maintenant et retrouvez vos billets ici !</p>
            <a href="accueil.php" class="btn-submit" style="display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.65rem 1.4rem; width: auto;">
                <i class="fa-solid fa-compass"></i> Explorer les événements
            </a>
        </div>
    <?php endif; ?>
    </div> <!-- Fin de .swiss-wrap -->
</main>

<!-- ==============================================================================
     MODALE DE CHANGEMENT DE PLACE EN RENDU 3D IMMERSIF (EventiaVenue3D)
     ============================================================================== -->
<div id="client3DChangeSeatModal" class="s3d-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="c3dEventTitle">
    <div class="s3d-modal-window">
        <!-- Header 3D Client -->
        <div class="s3d-header">
            <div class="s3d-header-main">
                <div class="s3d-header-title-row">
                    <span class="s3d-badge-3d">
                        <i class="fa-solid fa-cube"></i> Rendu 3D
                    </span>
                    <h3 id="c3dEventTitle" class="s3d-event-title"></h3>
                </div>
                <div class="s3d-venue-subtitle" id="c3dVenueSubtitle">
                    Orientation interactive & choix de votre nouvelle place en 3D
                </div>
            </div>

            <!-- Contrôles Caméras 3D -->
            <div class="s3d-header-controls">
                <div class="s3d-cameras-group">
                    <div id="c3dCameras" class="s3d-cameras">
                        <button type="button" class="s3d-cam-btn" onclick="setChangeSeatCam('isometric')" title="Vue Isométrique 3D">
                            <i class="fa-solid fa-cubes"></i> <span>3D</span>
                        </button>
                        <button type="button" class="s3d-cam-btn" onclick="setChangeSeatCam('top')" title="Vue du Haut">
                            <i class="fa-solid fa-eye"></i> <span>Haut</span>
                        </button>
                        <button type="button" class="s3d-cam-btn" onclick="setChangeSeatCam('stage')" title="Vue Scène">
                            <i class="fa-solid fa-masks-theater"></i> <span>Scène</span>
                        </button>
                        <button type="button" class="s3d-cam-btn s3d-cam-reset" onclick="setChangeSeatCam('reset')" title="Recentrer">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </button>
                    </div>
                    <button type="button" class="s3d-close-btn" onclick="closeChangeSeat3DModal()" title="Fermer la vue 3D">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Corps 3D : Canvas + Sidebar -->
        <div class="s3d-body">
            <!-- Zone Canvas 3D (Rendu 3D exclusif) -->
            <div id="panelChangeSeat3D" class="s3d-canvas-panel" style="position: relative;">
                <div id="c3dLoadingSpinner" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #f8fafc; text-align: center; z-index: 10; pointer-events: none;">
                    <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2.5rem; color: #d97706; margin-bottom: 0.75rem;"></i>
                    <div style="font-size: 0.95rem; font-weight: 700; color: #e2e8f0; text-shadow: 0 2px 4px rgba(0,0,0,0.8);">Chargement du rendu 3D de la salle...</div>
                </div>
                <canvas id="canvasChangeSeat3D"></canvas>

                <!-- Indication tactile -->
                <div class="s3d-touch-hint">
                    <i class="fa-solid fa-hand-pointer"></i> Touchez un siège vert disponible · Glissez pour tourner la caméra · Pincez / Molette pour zoomer
                </div>

                <!-- Légende 3D -->
                <div class="s3d-legend">
                    <span class="s3d-legend-item"><span class="s3d-dot dot-libre"></span> Disponible</span>
                    <span class="s3d-legend-item"><span class="s3d-dot dot-current"></span> Votre Place Actuelle</span>
                    <span class="s3d-legend-item"><span class="s3d-dot dot-selected"></span> Nouveau Choix</span>
                    <span class="s3d-legend-item"><span class="s3d-dot dot-occupe"></span> Occupé</span>
                </div>
            </div>

            <!-- Sidebar latérale : Sélection & Validation -->
            <div class="s3d-sidebar" id="changeSeatSidebar">
                <div>
                    <div class="s3d-sidebar-header">
                        <div class="s3d-sidebar-title">
                            <i class="fa-solid fa-chair" style="color: #d97706;"></i> Re-sélection de place
                        </div>
                        <span id="c3dBadgeCategory" class="s3d-seats-badge">1 place max</span>
                    </div>

                    <!-- Récapitulatif Billet -->
                    <div style="background: #0f172a; border: 1px solid #1e293b; border-radius: 10px; padding: 12px; margin-bottom: 12px; font-size: 0.8rem; color: #94a3b8; line-height: 1.5;">
                        <div>Catégorie : <strong id="c3dTicketType" style="color: #f8fafc;">—</strong></div>
                        <div>Place actuelle : <strong id="c3dCurrentSeat" style="color: #38bdf8;">—</strong></div>
                        <div style="margin-top: 5px; font-size: 0.72rem; color: #64748b;">
                            Seuls les sièges de votre catégorie sont sélectionnables en 3D.
                        </div>
                    </div>

                    <!-- Distance & Visibilité Scène en direct -->
                    <div id="c3dSightlineBox" class="s3d-sightline-box" style="display: none;">
                        <div class="s3d-sightline-title"><i class="fa-solid fa-eye"></i> Visibilité Scène</div>
                        <div id="c3dSightlineDesc" class="s3d-sightline-desc"></div>
                    </div>

                    <!-- Carte du nouveau siège sélectionné -->
                    <div class="s3d-selected-list" id="c3dSelectedList">
                        <div class="s3d-empty-msg">
                            <i class="fa-solid fa-cube" style="margin-bottom: 6px; display: block; font-size: 1.3rem; color: #d97706;"></i>
                            Cliquez sur un siège vert disponible dans le rendu 3D pour le choisir.
                        </div>
                    </div>
                </div>

                <!-- Footer Sidebar -->
                <div class="s3d-sidebar-footer">
                    <div class="s3d-total-card">
                        <div>
                            <span class="s3d-total-label">Nouveau Choix</span>
                            <small id="c3dSubText" class="s3d-sub-count">Aucun siège choisi</small>
                        </div>
                        <strong id="c3dNewSeatCode" class="s3d-total-amount" style="font-size: 1.05rem; color: #d97706;">—</strong>
                    </div>

                    <button type="button" id="btnApply3DSeatChange" class="s3d-btn-validate" disabled onclick="apply3DSeatChange()">
                        <i class="fa-solid fa-check-circle"></i> Valider ce siège 3D
                    </button>
                    <button type="button" class="s3d-btn-cancel" onclick="closeChangeSeat3DModal()">
                        Annuler sans modifier
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notification Toast flottante -->
<div id="seatToast" style="position: fixed; bottom: 24px; right: 24px; z-index: 100000; display: none; background: #0f172a; color: #ffffff; padding: 14px 20px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); font-size: 0.88rem; align-items: center; gap: 10px; border-left: 4px solid #10b981; animation: seatSlideUp 0.25s ease;">
    <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.2rem;"></i>
    <span id="seatToastMsg">Action effectuée.</span>
</div>

<script src="../js/share-ticket.js"></script>
<script src="../js/venue-3d-engine.js"></script>
<script>
let seatChangeEngine = null;
let activeChangeTicketId = null;
let chosen3DSeat = null;

function showToast(message, isError = false) {
    const toast = document.getElementById('seatToast');
    const msg = document.getElementById('seatToastMsg');
    if (!toast || !msg) return;

    msg.textContent = message;
    toast.style.borderLeftColor = isError ? '#ef4444' : '#10b981';
    const icon = toast.querySelector('i');
    if (icon) {
        icon.className = isError ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check';
        icon.style.color = isError ? '#ef4444' : '#10b981';
    }
    toast.style.display = 'inline-flex';
    setTimeout(() => {
        toast.style.display = 'none';
    }, 4500);
}

async function openSeatChange3DModal(ticketId, eventId, ticketTypeId, eventName, venueName, ticketType, currentSeat) {
    activeChangeTicketId = ticketId;
    chosen3DSeat = null;

    const modal = document.getElementById('client3DChangeSeatModal');
    if (!modal) return;

    document.getElementById('c3dEventTitle').textContent = eventName;
    document.getElementById('c3dVenueSubtitle').textContent = 'Lieu : ' + venueName + ' · Vue de scène interactive & choix de place';
    document.getElementById('c3dTicketType').textContent = ticketType;
    document.getElementById('c3dCurrentSeat').textContent = currentSeat ? ('Place ' + currentSeat) : 'Non attribuée';
    document.getElementById('c3dSubText').textContent = 'Sélectionnez un siège 3D';
    document.getElementById('c3dNewSeatCode').textContent = '—';

    const btn = document.getElementById('btnApply3DSeatChange');
    btn.disabled = true;

    const listEl = document.getElementById('c3dSelectedList');
    listEl.innerHTML = `
        <div class="s3d-empty-msg">
            <i class="fa-solid fa-cube" style="margin-bottom: 6px; display: block; font-size: 1.3rem; color: #d97706;"></i>
            Cliquez sur un siège disponible dans la vue 3D.
        </div>
    `;

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    const spinner = document.getElementById('c3dLoadingSpinner');
    if (spinner) spinner.style.display = 'block';

    const canvas = document.getElementById('canvasChangeSeat3D');
    const EngineClass = window.TikéliVenue3D || window.TikeliVenue3D || window.EventiaVenue3D;

    if (!seatChangeEngine && EngineClass) {
        seatChangeEngine = new EngineClass(canvas, {
            readOnly: false,
            maxSeats: 1,
            onSeatSelect: (seat) => {
                handle3DSeatSelected(seat);
            },
            onSeatDeselect: () => {
                handle3DSeatDeselected();
            },
            onHoverSeat: (seat) => {
                handle3DSeatHover(seat);
            }
        });
    } else if (seatChangeEngine) {
        seatChangeEngine.clearSelection();
    }

    if (seatChangeEngine) {
        seatChangeEngine.resize();
    }

    // Charger les données 3D pour cet événement et exclure le billet actuel
    const url = '../ajax/salle_3d_data.php?event_id=' + eventId + '&exclude_ticket_id=' + ticketId;
    const data = await seatChangeEngine.loadFromEndpoint(url);

    if (spinner) spinner.style.display = 'none';

    if (data && seatChangeEngine) {
        // Filtrer la vue 3D directement sur la catégorie du billet
        seatChangeEngine.filterByTariff(ticketTypeId);
        setTimeout(() => {
            seatChangeEngine.resize();
            seatChangeEngine.render();
        }, 80);
    }
}

function handle3DSeatSelected(seat) {
    chosen3DSeat = seat;

    const subText = document.getElementById('c3dSubText');
    const codeEl = document.getElementById('c3dNewSeatCode');
    const btn = document.getElementById('btnApply3DSeatChange');

    subText.textContent = 'Place sélectionnée';
    codeEl.textContent = seat.code;
    btn.disabled = false;

    const listEl = document.getElementById('c3dSelectedList');
    listEl.innerHTML = `
        <div style="background: #0f172a; border: 1.5px solid #d97706; border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; animation: seatFadeIn 0.2s ease;">
            <div>
                <strong style="color: #f8fafc; font-size: 0.88rem;"><i class="fa-solid fa-chair" style="color: #d97706;"></i> Place ${seat.code}</strong>
                <div style="font-size: 0.74rem; color: #94a3b8;">${seat.zone_name} • Rang ${seat.row}</div>
                <div style="font-size: 0.72rem; color: #10b981; font-weight: 700; margin-top: 2px;">✓ Même catégorie tarifaire</div>
            </div>
            <div style="color: #d97706; font-size: 1.2rem;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
    `;
}

function handle3DSeatDeselected() {
    chosen3DSeat = null;
    document.getElementById('c3dSubText').textContent = 'Sélectionnez un siège 3D';
    document.getElementById('c3dNewSeatCode').textContent = '—';
    document.getElementById('btnApply3DSeatChange').disabled = true;

    const listEl = document.getElementById('c3dSelectedList');
    listEl.innerHTML = `
        <div class="s3d-empty-msg">
            <i class="fa-solid fa-cube" style="margin-bottom: 6px; display: block; font-size: 1.3rem; color: #d97706;"></i>
            Cliquez sur un siège disponible dans la vue 3D.
        </div>
    `;
}

function handle3DSeatHover(seat) {
    const sightBox = document.getElementById('c3dSightlineBox');
    const sightDesc = document.getElementById('c3dSightlineDesc');
    if (!sightBox || !sightDesc) return;

    if (seat) {
        sightBox.style.display = 'block';
        const dist = Math.max(6, Math.round(seat.z / 10));
        const isCur = (seat.statut === 'actuelle' || seat.is_current);
        const statusLabel = isCur ? 'Votre place actuelle' : (seat.statut === 'libre' ? '✓ Disponible' : '✗ Déjà réservée');
        const statusColor = isCur ? '#38bdf8' : (seat.statut === 'libre' ? '#10b981' : '#f87171');

        sightDesc.innerHTML = `<strong>Place ${seat.code}</strong> (${seat.zone_name})<br>
        Distance scène estimée : <strong>${dist} m</strong><br>
        Statut : <span style="color: ${statusColor}; font-weight: 700;">${statusLabel}</span>`;
    }
}

function closeChangeSeat3DModal() {
    const modal = document.getElementById('client3DChangeSeatModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    activeChangeTicketId = null;
    chosen3DSeat = null;
}

function setChangeSeatCam(view) {
    if (seatChangeEngine) {
        seatChangeEngine.setViewPreset(view);
    }
}

async function apply3DSeatChange() {
    if (!activeChangeTicketId || !chosen3DSeat) return;

    const btn = document.getElementById('btnApply3DSeatChange');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Validation du siège...';

    try {
        const formData = new FormData();
        formData.append('ticket_id', activeChangeTicketId);
        formData.append('nouveau_siege', chosen3DSeat.code);

        const res = await fetch('../ajax/changer_place.php', {
            method: 'POST',
            body: formData
        });

        const result = await res.json();

        if (result.success) {
            // Mettre à jour l'affichage sur la carte du billet
            const seatValEl = document.getElementById('ticket-seat-val-' + activeChangeTicketId);
            if (seatValEl) {
                seatValEl.textContent = result.nouveau_siege;
                seatValEl.style.color = '#d97706';
                setTimeout(() => { seatValEl.style.color = '#000000'; }, 3500);
            }

            // Mettre à jour WhatsApp
            const waBtn = document.getElementById('btn-wa-' + activeChangeTicketId);
            if (waBtn) {
                let msg = waBtn.dataset.message || '';
                msg = msg.replace(/Place : [^\n]+/, 'Place : ' + result.nouveau_siege);
                waBtn.dataset.message = msg;
            }

            closeChangeSeat3DModal();
            showToast(result.message || 'Votre nouvelle place 3D a été validée avec succès !');
        } else {
            alert(result.message || 'Erreur lors du changement de place.');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Erreur technique lors de la validation. Veuillez vérifier votre connexion.');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

window.addEventListener('resize', () => {
    const modal = document.getElementById('client3DChangeSeatModal');
    if (seatChangeEngine && modal && modal.style.display !== 'none') {
        setTimeout(() => {
            seatChangeEngine.resize();
            seatChangeEngine.render();
        }, 150);
    }
});
</script>
<?php include 'footer.php'; ?>

