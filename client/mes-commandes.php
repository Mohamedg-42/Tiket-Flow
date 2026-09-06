<?php
// ==============================================================================
// HISTORIQUE DES COMMANDES CLIENT (client/mes-commandes.php)
// Affichage complet et explicite de l'événement, lieu, date et détails des billets
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin('../connexion.php');

$page_title = "Mes Commandes - Eventia";
$body_class = "client-page";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// ===== Filtres : par événement, par type de billet, par statut =====
$filtre_event  = filter_input(INPUT_GET, 'event', FILTER_VALIDATE_INT) ?: 0;
$filtre_type   = filter_input(INPUT_GET, 'type', FILTER_VALIDATE_INT) ?: 0;
$filtre_statut = trim($_GET['statut'] ?? '');
$filtres_statut_valides = ['payee', 'en_attente', 'echouee', 'annulee'];
if (!in_array($filtre_statut, $filtres_statut_valides, true)) {
    $filtre_statut = '';
}

// Construction sécurisée des conditions de filtrage (requêtes préparées)
$sql_filtres    = "";
$params_filtres = [];
if ($filtre_event > 0) {
    $sql_filtres .= " AND o.id IN (SELECT oi2.order_id FROM order_items oi2 JOIN ticket_types tt2 ON oi2.ticket_type_id = tt2.id WHERE tt2.event_id = ?)";
    $params_filtres[] = $filtre_event;
}
if ($filtre_type > 0) {
    $sql_filtres .= " AND o.id IN (SELECT oi3.order_id FROM order_items oi3 WHERE oi3.ticket_type_id = ?)";
    $params_filtres[] = $filtre_type;
}
if ($filtre_statut !== '') {
    $sql_filtres .= " AND o.statut = ?";
    $params_filtres[] = $filtre_statut;
}

// Listes des événements et types de billets présents dans les commandes du client
$stmt_liste_events = $pdo->prepare("
    SELECT DISTINCT e.id, e.nom
    FROM order_items oi
    JOIN ticket_types tt ON oi.ticket_type_id = tt.id
    JOIN events e ON tt.event_id = e.id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.user_id = ?
    ORDER BY e.nom ASC
");
$stmt_liste_events->execute([$user_id]);
$evenements_client = $stmt_liste_events->fetchAll();

$stmt_liste_types = $pdo->prepare("
    SELECT DISTINCT tt.id, tt.nom
    FROM order_items oi
    JOIN ticket_types tt ON oi.ticket_type_id = tt.id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.user_id = ?
    ORDER BY tt.nom ASC
");
$stmt_liste_types->execute([$user_id]);
$types_billets = $stmt_liste_types->fetchAll();

// Récupération des commandes du client avec les détails complets de l'événement
$sql = "
    SELECT o.*, 
           (SELECT COUNT(*) FROM tickets t WHERE t.order_id = o.id) AS total_billets,
           (SELECT STRING_AGG(CONCAT(oi.quantite, 'x ', tt.nom), ', ') 
            FROM order_items oi 
            JOIN ticket_types tt ON oi.ticket_type_id = tt.id 
            WHERE oi.order_id = o.id) AS details_articles,
           (SELECT e.nom 
            FROM order_items oi 
            JOIN ticket_types tt ON oi.ticket_type_id = tt.id 
            JOIN events e ON tt.event_id = e.id 
            WHERE oi.order_id = o.id LIMIT 1) AS event_nom,
           (SELECT e.date_evenement 
            FROM order_items oi 
            JOIN ticket_types tt ON oi.ticket_type_id = tt.id 
            JOIN events e ON tt.event_id = e.id 
            WHERE oi.order_id = o.id LIMIT 1) AS event_date,
           (SELECT e.heure 
            FROM order_items oi 
            JOIN ticket_types tt ON oi.ticket_type_id = tt.id 
            JOIN events e ON tt.event_id = e.id 
            WHERE oi.order_id = o.id LIMIT 1) AS event_heure,
           (SELECT e.lieu 
            FROM order_items oi 
            JOIN ticket_types tt ON oi.ticket_type_id = tt.id 
            JOIN events e ON tt.event_id = e.id 
            WHERE oi.order_id = o.id LIMIT 1) AS event_lieu
    FROM orders o 
    WHERE o.user_id = ?
    $sql_filtres
    ORDER BY o.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$user_id], $params_filtres));
$orders = $stmt->fetchAll();

// Cotisations & contributions du client (campagnes auxquelles il a participé)
// On rattache aussi les contributions invitées faites avec le même email
$mes_cotisations = [];
try {
    $stmt_cot = $pdo->prepare("
        SELECT ct.*, c.titre AS campagne_titre
        FROM cotisations ct
        LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
        WHERE ct.user_id = ? 
           OR (ct.email IS NOT NULL AND ct.email = ?)
        ORDER BY ct.created_at DESC
    ");
    $stmt_cot->execute([$user_id, $_SESSION['user_email'] ?? '']);
    $mes_cotisations = $stmt_cot->fetchAll();
} catch (PDOException $e) {
    $mes_cotisations = []; // Tables pas encore migrées
}

// ===== Statistiques du mini tableau de bord =====

// 1. Billets achetés (toutes commandes confondues)
$nb_billets = 0;
try {
    $stmt_billets = $pdo->prepare("
        SELECT COUNT(*) FROM tickets t
        JOIN orders o ON o.id = t.order_id
        WHERE o.user_id = ?
    ");
    $stmt_billets->execute([$user_id]);
    $nb_billets = (int)$stmt_billets->fetchColumn();
} catch (PDOException $e) {
    $nb_billets = 0;
}

// 2. Cotisations : nombre + total contribué (hors annulations)
$nb_cotisations = count($mes_cotisations);
$total_cotise   = 0;
foreach ($mes_cotisations as $c) {
    if ($c['statut'] !== 'annule') {
        $total_cotise += (float)$c['montant'];
    }
}

// 3. Événements auxquels le client a voté (liste + compteur)
$mes_votes = [];
try {
    $stmt_votes = $pdo->prepare("
        SELECT v.created_at AS date_vote,
               e.id AS event_id, e.nom, e.date_evenement, e.heure, e.lieu, e.categorie
        FROM event_votes v
        JOIN events e ON e.id = v.event_id
        WHERE v.user_id = ? OR v.visitor_id = ?
        ORDER BY v.created_at DESC
    ");
    $stmt_votes->execute([$user_id, session_id()]);
    $mes_votes = $stmt_votes->fetchAll();
} catch (PDOException $e) {
    $mes_votes = []; // Table event_votes pas encore migrée
}
$nb_votes = count($mes_votes);

// ===== Zone active du tableau de bord (commandes / cotisations / votes) =====
$zone = trim($_GET['zone'] ?? 'commandes');
if (!in_array($zone, ['commandes', 'cotisations', 'votes'], true)) {
    $zone = 'commandes';
}

// ===== Filtres de la section Cotisations =====
$filtre_c_campagne = filter_input(INPUT_GET, 'c_campagne', FILTER_VALIDATE_INT) ?: 0;
$filtre_c_statut   = trim($_GET['c_statut'] ?? '');
if (!in_array($filtre_c_statut, ['payee', 'en_attente', 'annule'], true)) {
    $filtre_c_statut = '';
}

// Listes pour les menus déroulants des filtres
$campagnes_client = [];
foreach ($mes_cotisations as $c) {
    if (!empty($c['campagne_titre']) && !isset($campagnes_client[(int)$c['campagne_id']])) {
        $campagnes_client[(int)$c['campagne_id']] = $c['campagne_titre'];
    }
}

// Application des filtres cotisations (sur les données déjà limitées au client)
$cotisations_affichees = $mes_cotisations;
if ($filtre_c_campagne > 0) {
    $cotisations_affichees = array_values(array_filter($cotisations_affichees, function ($c) use ($filtre_c_campagne) {
        return (int)$c['campagne_id'] === $filtre_c_campagne;
    }));
}
if ($filtre_c_statut !== '') {
    $cotisations_affichees = array_values(array_filter($cotisations_affichees, function ($c) use ($filtre_c_statut) {
        return $c['statut'] === $filtre_c_statut;
    }));
}

// ===== Filtres de la section Votes =====
$filtre_v_categorie = trim($_GET['v_categorie'] ?? '');
$cats_votees = [];
foreach ($mes_votes as $v) {
    if (!empty($v['categorie'])) {
        $cats_votees[$v['categorie']] = true;
    }
}

// Application du filtre votes
$votes_affiches = $mes_votes;
if ($filtre_v_categorie !== '') {
    $votes_affiches = array_values(array_filter($votes_affiches, function ($v) use ($filtre_v_categorie) {
        return $v['categorie'] === $filtre_v_categorie;
    }));
}
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

        <!-- En-tête de la page -->
        <div class="page-header">
            <div class="page-heading">
                <span class="page-kicker"><i class="fa-solid fa-bag-shopping"></i> Espace Billetterie</span>
                <h1 class="swiss-headline">Mes Commandes</h1>
                <p>Retrouvez l'historique de vos réservations avec le détail de chaque événement et accédez à vos billets.</p>
            </div>
            <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.35rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-plus"></i> Découvrir d'autres événements
            </a>
        </div>

        <!-- ===== Mini tableau de bord : accès rapide ===== -->
        <div class="kpi-cards-grid">
            <a href="#section-commandes" id="tile-commandes" onclick="showSection('commandes'); return false;" class="kpi-card <?php echo ($zone === 'commandes') ? 'active' : ''; ?>">
                <div class="kpi-icon-box kpi-icon-dark">
                    <i class="fa-solid fa-ticket"></i>
                </div>
                <div class="kpi-body">
                    <span class="kpi-label">Billets achetés</span>
                    <strong class="kpi-val"><?php echo $nb_billets; ?></strong>
                    <span class="kpi-link">Voir mes billets <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>

            <a href="#section-cotisations" id="tile-cotisations" onclick="showSection('cotisations'); return false;" class="kpi-card <?php echo ($zone === 'cotisations') ? 'active' : ''; ?>">
                <div class="kpi-icon-box kpi-icon-orange">
                    <i class="fa-solid fa-hand-holding-heart"></i>
                </div>
                <div class="kpi-body">
                    <span class="kpi-label">Mes Cotisations</span>
                    <strong class="kpi-val"><?php echo $nb_cotisations; ?> <span class="kpi-subval">(<?php echo number_format($total_cotise, 0, ',', ' '); ?> FCFA)</span></strong>
                    <span class="kpi-link kpi-link-orange">Voir mes contributions <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>

            <a href="#section-votes" id="tile-votes" onclick="showSection('votes'); return false;" class="kpi-card <?php echo ($zone === 'votes') ? 'active' : ''; ?>">
                <div class="kpi-icon-box kpi-icon-dark">
                    <i class="fa-solid fa-vote-yea"></i>
                </div>
                <div class="kpi-body">
                    <span class="kpi-label">Événements votés</span>
                    <strong class="kpi-val"><?php echo $nb_votes; ?></strong>
                    <span class="kpi-link">Voter maintenant <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>
        </div>

        <!-- Tableau / Contenu des commandes -->
        <div class="content-section" id="section-commandes" style="display: <?php echo ($zone === 'commandes') ? '' : 'none'; ?>;">
            <!-- Filtres : par événement, par type de billet, par statut -->
            <form method="GET" class="filter-toolbar">
                <input type="hidden" name="zone" value="commandes">
                <span class="filter-toolbar-label">
                    <i class="fa-solid fa-filter" style="color: var(--tikeli-orange);"></i> Filtrer :
                </span>

                <select name="event" onchange="this.form.submit()" class="filter-select">
                    <option value="0">Tous les événements</option>
                    <?php foreach ($evenements_client as $ev): ?>
                        <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($filtre_event === (int)$ev['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ev['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="type" onchange="this.form.submit()" class="filter-select">
                    <option value="0">Tous les types de billets</option>
                    <?php foreach ($types_billets as $tb): ?>
                        <option value="<?php echo (int)$tb['id']; ?>" <?php echo ($filtre_type === (int)$tb['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tb['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="statut" onchange="this.form.submit()" class="filter-select">
                    <option value="">Tous les statuts</option>
                    <option value="payee" <?php echo ($filtre_statut === 'payee') ? 'selected' : ''; ?>>Payées</option>
                    <option value="en_attente" <?php echo ($filtre_statut === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                    <option value="echouee" <?php echo ($filtre_statut === 'echouee') ? 'selected' : ''; ?>>Échouées</option>
                    <option value="annulee" <?php echo ($filtre_statut === 'annulee') ? 'selected' : ''; ?>>Annulées</option>
                </select>

                <?php if ($filtre_event > 0 || $filtre_type > 0 || $filtre_statut !== ''): ?>
                    <a href="mes-commandes.php" class="filter-reset-btn">
                        <i class="fa-solid fa-xmark"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </form>

            <div class="section-title">
                <i class="fa-solid fa-clock-rotate-left"></i> Historique de vos Commandes (<?php echo count($orders); ?>)
            </div>

            <div class="table-wrapper">
                <table class="events-table">
                    <thead>
                        <tr>
                            <th style="min-width: 170px;">N° Commande</th>
                            <th style="min-width: 290px;">Événement & Billets</th>
                            <th style="min-width: 140px;">Montant Total</th>
                            <th style="min-width: 130px;">Statut</th>
                            <th style="min-width: 140px;">Date d'Achat</th>
                            <th style="text-align: right; min-width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($orders) > 0): ?>
                            <?php foreach ($orders as $ord): ?>
                                <?php 
                                $is_paid = ($ord['statut'] === 'payee');
                                $is_pending = ($ord['statut'] === 'en_attente');
                                ?>
                                <tr>
                                    <!-- 1. Numéro & Icône Commande -->
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 38px; height: 38px; border-radius: 8px; background: #F5F5F5; display: grid; place-items: center; font-size: 1rem; flex-shrink: 0; border: 1px solid #E5E5E5;">
                                                <i class="fa-solid <?php echo $is_paid ? 'fa-circle-check' : 'fa-receipt'; ?>" style="color: <?php echo $is_paid ? '#10b981' : 'var(--tikeli-orange)'; ?>;"></i>
                                            </div>
                                            <div>
                                                <strong style="font-family: 'Space Mono', Consolas, monospace; font-size: 0.92rem; color: #000000; display: block; letter-spacing: 0.3px; font-weight: 700;">
                                                    <?php echo htmlspecialchars($ord['numero_commande']); ?>
                                                </strong>
                                                <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">Réf #<?php echo $ord['id']; ?></small>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 2. ÉVÉNEMENT & DÉTAILS DES BILLETS -->
                                    <td>
                                        <div style="margin-bottom: 5px;">
                                            <strong style="color: #000000; font-size: 0.98rem; display: block; font-weight: 700; line-height: 1.3;">
                                                <?php echo htmlspecialchars($ord['event_nom'] ?: 'Événement Spécial'); ?>
                                            </strong>
                                        </div>

                                        <?php if (!empty($ord['event_date'])): ?>
                                            <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.82rem; color: #737373; margin-bottom: 6px; flex-wrap: wrap;">
                                                <span><i class="fa-regular fa-calendar" style="color: var(--tikeli-orange);"></i> <?php echo date('d/m/Y', strtotime($ord['event_date'])); ?><?php if (!empty($ord['event_heure'])): ?> à <?php echo substr($ord['event_heure'], 0, 5); ?><?php endif; ?></span>
                                                <?php if (!empty($ord['event_lieu'])): ?>
                                                    <span><i class="fa-solid fa-location-dot" style="color: #000000;"></i> <?php echo htmlspecialchars($ord['event_lieu']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #F5F5F5; border: 1px solid #E5E5E5; border-radius: 6px; padding: 3px 8px; font-weight: 600; font-size: 0.78rem; color: #000000;">
                                                <i class="fa-solid fa-tag" style="color: var(--tikeli-orange);"></i> <?php echo htmlspecialchars($ord['details_articles'] ?: 'Billets'); ?>
                                            </span>
                                        </div>
                                    </td>

                                    <!-- 3. Montant Total -->
                                    <td>
                                        <strong style="color: #000000; font-size: 1.05rem; font-weight: 800; font-family: 'Outfit', sans-serif;">
                                            <?php echo number_format($ord['montant_total'], 0, ',', ' '); ?> FCFA
                                        </strong>
                                    </td>

                                    <!-- 4. Statut -->
                                    <td>
                                        <?php if ($is_paid): ?>
                                            <span class="badge-status-paid">
                                                <i class="fa-solid fa-circle-check"></i> Payée
                                            </span>
                                        <?php elseif ($is_pending): ?>
                                            <span class="badge-status-pending">
                                                <i class="fa-solid fa-clock"></i> En attente
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status-failed">
                                                <i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($ord['statut']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 5. Date d'achat -->
                                    <td>
                                        <span style="color: #000000; font-size: 0.86rem; font-weight: 600; display: block;">
                                            <i class="fa-regular fa-calendar" style="color: #737373; margin-right: 3px;"></i> <?php echo date('d/m/Y', strtotime($ord['created_at'])); ?>
                                        </span>
                                        <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i> <?php echo date('H:i', strtotime($ord['created_at'])); ?>
                                        </small>
                                    </td>

                                    <!-- 6. Boutons d'Action -->
                                    <td style="text-align: right;">
                                        <?php if ($is_paid): ?>
                                            <div style="display: inline-flex; gap: 0.5rem; justify-content: flex-end; align-items: center;">
                                                <a href="mes-tickets.php" class="btn-action-primary" title="Voir mes QR Codes">
                                                    <i class="fa-solid fa-qrcode"></i> Billets (<?php echo (int)$ord['total_billets']; ?>)
                                                </a>
                                                <a href="telecharger-ticket.php?order_id=<?php echo $ord['id']; ?>" target="_blank" class="btn-action-secondary" title="Télécharger mes billets en PDF">
                                                    <i class="fa-solid fa-file-pdf"></i> PDF
                                                </a>
                                            </div>
                                        <?php elseif ($is_pending): ?>
                                            <a href="paiement.php?order_id=<?php echo $ord['id']; ?>" class="btn-action-orange">
                                                <i class="fa-solid fa-credit-card"></i> Payer maintenant
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #737373; font-size: 0.85rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--muted); padding: 3.5rem 1rem;">
                                    <div style="width: 60px; height: 60px; background: #F5F5F5; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                        <i class="fa-solid fa-basket-shopping"></i>
                                    </div>
                                    <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.15rem;">Vous n'avez pas encore passé de commande</h3>
                                    <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                        Vos réservations et billets avec QR Code apparaîtront ici dès votre premier achat.
                                    </p>
                                    <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-ticket"></i> Découvrir les événements
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== Mes Cotisations & Contributions (affichée au clic sur la tuile) ===== -->
        <div class="content-section" id="section-cotisations" style="margin-top: 1.75rem; display: <?php echo ($zone === 'cotisations') ? '' : 'none'; ?>;">
            <div class="section-title">
                <i class="fa-solid fa-hand-holding-heart" style="color: var(--tikeli-orange);"></i> Mes Cotisations & Contributions (<?php echo count($cotisations_affichees); ?>)
            </div>

            <!-- Filtres : par campagne, par statut -->
            <form method="GET" class="filter-toolbar">
                <input type="hidden" name="zone" value="cotisations">
                <span class="filter-toolbar-label">
                    <i class="fa-solid fa-filter" style="color: var(--tikeli-orange);"></i> Filtrer :
                </span>

                <select name="c_campagne" onchange="this.form.submit()" class="filter-select" style="max-width: 260px;">
                    <option value="0">Toutes les campagnes</option>
                    <?php foreach ($campagnes_client as $camp_id => $camp_titre): ?>
                        <option value="<?php echo (int)$camp_id; ?>" <?php echo ($filtre_c_campagne === (int)$camp_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($camp_titre); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="c_statut" onchange="this.form.submit()" class="filter-select">
                    <option value="">Tous les statuts</option>
                    <option value="payee" <?php echo ($filtre_c_statut === 'payee') ? 'selected' : ''; ?>>Payées</option>
                    <option value="en_attente" <?php echo ($filtre_c_statut === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                    <option value="annule" <?php echo ($filtre_c_statut === 'annule') ? 'selected' : ''; ?>>Annulées</option>
                </select>

                <?php if ($filtre_c_campagne > 0 || $filtre_c_statut !== ''): ?>
                    <a href="mes-commandes.php?zone=cotisations" class="filter-reset-btn">
                        <i class="fa-solid fa-xmark"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </form>

            <div class="table-wrapper">
                <table class="events-table">
                    <thead>
                        <tr>
                            <th style="min-width: 280px;">Campagne</th>
                            <th style="min-width: 140px;">Montant</th>
                            <th style="min-width: 130px;">Statut</th>
                            <th style="min-width: 140px;">Date</th>
                            <th style="text-align: right; min-width: 180px;">Référence / Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($cotisations_affichees) > 0): ?>
                            <?php foreach ($cotisations_affichees as $cot): ?>
                                <?php
                                $cot_payee   = ($cot['statut'] === 'payee');
                                $cot_attente = ($cot['statut'] === 'en_attente');
                                ?>
                                <tr>
                                    <!-- 1. Campagne -->
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 38px; height: 38px; border-radius: 8px; background: #FFF2ED; color: var(--tikeli-orange); display: grid; place-items: center; font-size: 1rem; flex-shrink: 0; border: 1px solid #FFEDD5;">
                                                <i class="fa-solid fa-hand-holding-heart"></i>
                                            </div>
                                            <div>
                                                <strong style="color: #000000; font-size: 0.95rem; display: block; font-weight: 700;">
                                                    <?php echo !empty($cot['campagne_titre']) ? htmlspecialchars($cot['campagne_titre']) : 'Contribution générale'; ?>
                                                </strong>
                                                <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">
                                                    <?php echo $cot_attente ? 'Paiement à finaliser' : ($cot_payee ? 'Participation confirmée' : 'Participation annulée'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 2. Montant -->
                                    <td>
                                        <strong style="color: #000000; font-size: 1.05rem; font-weight: 800; font-family: 'Outfit', sans-serif;">
                                            <?php echo number_format((float)$cot['montant'], 0, ',', ' '); ?> FCFA
                                        </strong>
                                    </td>

                                    <!-- 3. Statut -->
                                    <td>
                                        <?php if ($cot_payee): ?>
                                            <span class="badge-status-paid">
                                                <i class="fa-solid fa-circle-check"></i> Payée
                                            </span>
                                        <?php elseif ($cot_attente): ?>
                                            <span class="badge-status-pending">
                                                <i class="fa-solid fa-clock"></i> En attente
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status-failed">
                                                <i class="fa-solid fa-ban"></i> Annulée
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 4. Date -->
                                    <td>
                                        <span style="font-size: 0.86rem; color: #000000; font-weight: 600; display: block;">
                                            <i class="fa-regular fa-calendar" style="color: #737373; margin-right: 3px;"></i> <?php echo date('d/m/Y', strtotime($cot['created_at'])); ?>
                                        </span>
                                        <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i> <?php echo date('H:i', strtotime($cot['created_at'])); ?>
                                        </small>
                                    </td>

                                    <!-- 5. Référence / Action -->
                                    <td style="text-align: right;">
                                        <?php if ($cot_payee): ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.82rem; color: #000000; font-weight: 700; background: #F5F5F5; padding: 4px 8px; border-radius: 6px; border: 1px solid #E5E5E5;" title="Référence de transaction">
                                                <?php echo htmlspecialchars($cot['reference'] ?? '—'); ?>
                                            </span>
                                        <?php elseif ($cot_attente): ?>
                                            <a href="paiement-cotisation.php?cotisation_id=<?php echo (int)$cot['id']; ?>" class="btn-action-orange">
                                                <i class="fa-solid fa-credit-card"></i> Payer maintenant
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #737373; font-size: 0.85rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                                    <div style="width: 60px; height: 60px; background: #F5F5F5; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                        <i class="fa-solid fa-hand-holding-heart"></i>
                                    </div>
                                    <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.1rem;">Aucune participation à une cotisation</h3>
                                    <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                        Vos contributions aux campagnes de financement apparaîtront ici.
                                    </p>
                                    <a href="accueil.php?onglet=cotisations" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-hand-holding-heart"></i> Découvrir les campagnes
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== Événements votés (affichée au clic sur la tuile) ===== -->
        <div class="content-section" id="section-votes" style="margin-top: 1.75rem; display: <?php echo ($zone === 'votes') ? '' : 'none'; ?>;">
            <div class="section-title">
                <i class="fa-solid fa-vote-yea" style="color: #000000;"></i> Événements auxquels vous avez voté (<?php echo count($votes_affiches); ?>)
            </div>

            <!-- Filtre : par catégorie -->
            <form method="GET" class="filter-toolbar">
                <input type="hidden" name="zone" value="votes">
                <span class="filter-toolbar-label">
                    <i class="fa-solid fa-filter" style="color: var(--tikeli-orange);"></i> Filtrer :
                </span>

                <select name="v_categorie" onchange="this.form.submit()" class="filter-select" style="max-width: 260px;">
                    <option value="">Toutes les catégories</option>
                    <?php foreach (array_keys($cats_votees) as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filtre_v_categorie === $cat) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($filtre_v_categorie !== ''): ?>
                    <a href="mes-commandes.php?zone=votes" class="filter-reset-btn">
                        <i class="fa-solid fa-xmark"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </form>

            <div class="table-wrapper">
                <table class="events-table">
                    <thead>
                        <tr>
                            <th style="min-width: 280px;">Événement</th>
                            <th style="min-width: 140px;">Catégorie</th>
                            <th style="min-width: 160px;">Date de l'événement</th>
                            <th style="min-width: 150px;">Date de votre vote</th>
                            <th style="text-align: right; min-width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($votes_affiches) > 0): ?>
                            <?php foreach ($votes_affiches as $vt): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 38px; height: 38px; border-radius: 8px; background: #F5F5F5; color: #000000; display: grid; place-items: center; font-size: 1rem; flex-shrink: 0; border: 1px solid #E5E5E5;">
                                                <i class="fa-solid fa-vote-yea"></i>
                                            </div>
                                            <div>
                                                <strong style="color: #000000; font-size: 0.95rem; font-weight: 700;"><?php echo htmlspecialchars($vt['nom']); ?></strong>
                                                <small style="color: #737373; font-size: 0.76rem; display: block; font-weight: 500;">
                                                    <i class="fa-solid fa-location-dot" style="color: #000000;"></i> <?php echo htmlspecialchars($vt['lieu']); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="background: #F5F5F5; color: #000000; border: 1px solid #E5E5E5; padding: 0.25rem 0.7rem; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                                            <?php echo htmlspecialchars($vt['categorie']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.86rem; color: #000000; font-weight: 600; display: block;">
                                            <i class="fa-regular fa-calendar" style="color: #737373; margin-right: 3px;"></i> <?php echo date('d/m/Y', strtotime($vt['date_evenement'])); ?>
                                        </span>
                                        <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i> <?php echo substr($vt['heure'], 0, 5); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.86rem; color: #000000; font-weight: 600; display: block;">
                                            <i class="fa-regular fa-calendar" style="color: #737373; margin-right: 3px;"></i> <?php echo date('d/m/Y', strtotime($vt['date_vote'])); ?>
                                        </span>
                                        <small style="color: #737373; font-size: 0.76rem; font-weight: 500;">
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i> <?php echo date('H:i', strtotime($vt['date_vote'])); ?>
                                        </small>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="accueil.php?onglet=voter" class="btn-action-primary">
                                            <i class="fa-solid fa-chart-simple"></i> Voir les votes
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                                    <div style="width: 60px; height: 60px; background: #F5F5F5; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                        <i class="fa-solid fa-vote-yea"></i>
                                    </div>
                                    <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.1rem;">Aucun vote enregistré</h3>
                                    <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                        Votez pour les événements que vous aimeriez voir organisés : vos votes apparaîtront ici.
                                    </p>
                                    <a href="accueil.php?onglet=voter" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-vote-yea"></i> Voter maintenant
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> <!-- Fin de .swiss-wrap -->
</main>

<script>
    /* ===== Mini tableau de bord : active et affiche la zone correspondante ===== */
    const dashboardZones = {
        commandes:   document.getElementById('section-commandes'),
        cotisations: document.getElementById('section-cotisations'),
        votes:       document.getElementById('section-votes')
    };
    const dashboardTiles = {
        commandes:   document.getElementById('tile-commandes'),
        cotisations: document.getElementById('tile-cotisations'),
        votes:       document.getElementById('tile-votes')
    };

    function showSection(section) {
        Object.keys(dashboardZones).forEach(function (key) {
            if (dashboardZones[key]) dashboardZones[key].style.display = 'none';
            if (dashboardTiles[key]) dashboardTiles[key].classList.remove('active');
        });

        if (dashboardZones[section]) {
            dashboardZones[section].style.display = '';
            dashboardZones[section].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (dashboardTiles[section]) {
            dashboardTiles[section].classList.add('active');
        }
    }
</script>

<?php include 'footer.php'; ?>
