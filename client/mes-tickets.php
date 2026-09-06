<?php
// ==============================================================================
// MES TICKETS CLIENT (client/mes-tickets.php)
// Affichage des billets achetés avec leur code unique et QR Code pour contrôle
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin('../connexion.php');

$page_title = "Mes Tickets - Eventia";
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
                                    <span>Place : <strong style="color: #000000;"><?php echo htmlspecialchars($t['place_numero']); ?></strong></span>
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
                                data-pdf="telecharger-pdf.php?code=<?php echo urlencode($t['code_unique']); ?>"
                                data-filename="<?php echo htmlspecialchars($pdf_ticket_filename, ENT_QUOTES); ?>"
                                data-message="<?php echo htmlspecialchars($wa_text, ENT_QUOTES); ?>"
                                onclick="shareTicketPdfWhatsApp(this)"
                                class="btn-ticket-wa" title="Envoyer le PDF du billet par WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp
                            </button>
                        </div>
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

<script src="../js/share-ticket.js"></script>
<?php include 'footer.php'; ?>
