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
           (SELECT GROUP_CONCAT(CONCAT(oi.quantite, 'x ', tt.nom) SEPARATOR ', ') 
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

<main class="client-main" style="max-width: 1080px; margin: 0 auto; padding: clamp(1rem, 2.5vw, 2rem) clamp(0.75rem, 2vw, 1.5rem);">
    <!-- En-tête de la page -->
    <div class="page-header">
        <div class="page-heading">
            <span class="page-kicker"><i class="fa-solid fa-bag-shopping"></i> Espace Billetterie</span>
            <h1>Mes Commandes</h1>
            <p>Retrouvez l'historique de vos réservations avec le détail de chaque événement et accédez à vos billets.</p>
        </div>
        <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.35rem;">
            <i class="fa-solid fa-plus"></i> Découvrir d'autres événements
        </a>
    </div>

    <!-- ===== Mini tableau de bord : accès rapide ===== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: clamp(0.75rem, 2vw, 1rem); margin-bottom: 1.75rem;">
        <a href="#section-commandes" id="tile-commandes" onclick="showSection('commandes'); return false;" style="background: #ffffff; border: 1px solid var(--line); border-left: 4px solid var(--primary); border-radius: var(--radius-md); padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.9rem; text-decoration: none; transition: all 0.2s ease; cursor: pointer;"
           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.08)';"
           onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-sm); background: #f0fdfa; color: var(--primary); display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0;">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <div>
                <strong style="color: var(--navy); font-size: 1.3rem; display: block; line-height: 1.2;"><?php echo $nb_billets; ?></strong>
                <small style="color: var(--muted); font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Billets achetés</small>
                <span style="color: var(--primary); font-size: 0.75rem; font-weight: 700; display: block; margin-top: 2px;">
                    Voir mes billets <i class="fa-solid fa-arrow-right"></i>
                </span>
            </div>
        </a>

        <a href="#section-cotisations" id="tile-cotisations" onclick="showSection('cotisations'); return false;" style="background: #ffffff; border: 1px solid var(--line); border-left: 4px solid #16a34a; border-radius: var(--radius-md); padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.9rem; text-decoration: none; transition: all 0.2s ease; cursor: pointer;"
           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.08)';"
           onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-sm); background: #f0fdf4; color: #16a34a; display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0;">
                <i class="fa-solid fa-hand-holding-heart"></i>
            </div>
            <div>
                <strong style="color: var(--navy); font-size: 1.3rem; display: block; line-height: 1.2;"><?php echo $nb_cotisations; ?> <span style="font-size: 0.85rem; color: var(--muted);">· <?php echo number_format($total_cotise, 0, ',', ' '); ?> F</span></strong>
                <small style="color: var(--muted); font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Mes Cotisations</small>
                <span style="color: #16a34a; font-size: 0.75rem; font-weight: 700; display: block; margin-top: 2px;">
                    Voir mes contributions <i class="fa-solid fa-arrow-right"></i>
                </span>
            </div>
        </a>

        <a href="#section-votes" id="tile-votes" onclick="showSection('votes'); return false;" style="background: #ffffff; border: 1px solid var(--line); border-left: 4px solid #f59e0b; border-radius: var(--radius-md); padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.9rem; text-decoration: none; transition: all 0.2s ease; cursor: pointer;"
           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.08)';"
           onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-sm); background: #fffbeb; color: #b45309; display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0;">
                <i class="fa-solid fa-vote-yea"></i>
            </div>
            <div>
                <strong style="color: var(--navy); font-size: 1.3rem; display: block; line-height: 1.2;"><?php echo $nb_votes; ?></strong>
                <small style="color: var(--muted); font-weight: 700; font-size: 0.78rem; text-transform: uppercase;">Événements votés</small>
                <span style="color: #b45309; font-size: 0.75rem; font-weight: 700; display: block; margin-top: 2px;">
                    Voter maintenant <i class="fa-solid fa-arrow-right"></i>
                </span>
            </div>
        </a>
    </div>

    <!-- Tableau / Contenu des commandes -->
    <div class="content-section" id="section-commandes" style="display: <?php echo ($zone === 'commandes') ? '' : 'none'; ?>;">
        <!-- Filtres : par événement, par type de billet, par statut -->
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
            <input type="hidden" name="zone" value="commandes">
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy); display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-filter" style="color: var(--primary);"></i> Filtrer :
            </span>

            <select name="event" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 260px;">
                <option value="0">Tous les événements</option>
                <?php foreach ($evenements_client as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($filtre_event === (int)$ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['nom']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 220px;">
                <option value="0">Tous les types de billets</option>
                <?php foreach ($types_billets as $tb): ?>
                    <option value="<?php echo (int)$tb['id']; ?>" <?php echo ($filtre_type === (int)$tb['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tb['nom']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="statut" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer;">
                <option value="">Tous les statuts</option>
                <option value="payee" <?php echo ($filtre_statut === 'payee') ? 'selected' : ''; ?>>Payées</option>
                <option value="en_attente" <?php echo ($filtre_statut === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                <option value="echouee" <?php echo ($filtre_statut === 'echouee') ? 'selected' : ''; ?>>Échouées</option>
                <option value="annulee" <?php echo ($filtre_statut === 'annulee') ? 'selected' : ''; ?>>Annulées</option>
            </select>

            <?php if ($filtre_event > 0 || $filtre_type > 0 || $filtre_statut !== ''): ?>
                <a href="mes-commandes.php" style="font-size: 0.82rem; font-weight: 700; color: var(--danger); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
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
                        <th style="min-width: 160px;">N° Commande</th>
                        <th style="min-width: 280px;">Événement & Billets</th>
                        <th>Montant Total</th>
                        <th>Statut</th>
                        <th>Date d'Achat</th>
                        <th style="text-align: right; min-width: 170px;">Actions</th>
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
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #f0fdfa; color: var(--primary); display: grid; place-items: center; font-size: 1rem; flex-shrink: 0;">
                                            <i class="fa-solid <?php echo $is_paid ? 'fa-circle-check' : 'fa-receipt'; ?>"></i>
                                        </div>
                                        <div>
                                            <strong style="font-family: monospace; font-size: 0.95rem; color: var(--navy); display: block; letter-spacing: 0.5px;">
                                                <?php echo htmlspecialchars($ord['numero_commande']); ?>
                                            </strong>
                                            <small style="color: var(--muted); font-size: 0.76rem;">Réf #<?php echo $ord['id']; ?></small>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. ÉVÉNEMENT & DÉTAILS DES BILLETS (CLAIREMENT PRÉCISÉS) -->
                                <td>
                                    <div style="margin-bottom: 4px;">
                                        <strong style="color: var(--navy); font-size: 1rem; display: block;">
                                            <i class="fa-solid fa-masks-theater" style="color: var(--primary); margin-right: 4px;"></i>
                                            <?php echo htmlspecialchars($ord['event_nom'] ?: 'Événement Spécial'); ?>
                                        </strong>
                                    </div>

                                    <?php if (!empty($ord['event_date'])): ?>
                                        <div style="font-size: 0.82rem; color: var(--muted); margin-bottom: 4px;">
                                            <span><i class="fa-regular fa-calendar" style="color: var(--primary);"></i> <?php echo date('d/m/Y', strtotime($ord['event_date'])); ?><?php if (!empty($ord['event_heure'])): ?> à <?php echo substr($ord['event_heure'], 0, 5); ?><?php endif; ?></span>
                                            <?php if (!empty($ord['event_lieu'])): ?>
                                                <span style="margin-left: 6px;"><i class="fa-solid fa-location-dot" style="color: #ef4444;"></i> <?php echo htmlspecialchars($ord['event_lieu']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div style="font-size: 0.84rem; color: var(--ink);">
                                        <span style="background: #f1f5f9; border: 1px solid var(--line); border-radius: 4px; padding: 2px 6px; font-weight: 700; color: var(--primary-dark);">
                                            <i class="fa-solid fa-tags"></i> <?php echo htmlspecialchars($ord['details_articles'] ?: 'Billets'); ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- 3. Montant Total -->
                                <td>
                                    <strong style="color: var(--primary-dark); font-size: 1.05rem; font-family: 'Outfit', sans-serif;">
                                        <?php echo number_format($ord['montant_total'], 0, ',', ' '); ?> FCFA
                                    </strong>
                                </td>

                                <!-- 4. Statut -->
                                <td>
                                    <?php if ($is_paid): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 800; text-transform: uppercase;">
                                            <i class="fa-solid fa-circle-check"></i> Payée
                                        </span>
                                    <?php elseif ($is_pending): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 800; text-transform: uppercase;">
                                            <i class="fa-solid fa-clock"></i> En attente
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 800; text-transform: uppercase;">
                                            <i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($ord['statut']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. Date d'achat -->
                                <td>
                                    <span style="color: var(--muted); font-size: 0.86rem;">
                                        <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ord['created_at'])); ?><br>
                                        <small><i class="fa-regular fa-clock"></i> <?php echo date('H:i', strtotime($ord['created_at'])); ?></small>
                                    </span>
                                </td>

                                <!-- 6. Boutons d'Action -->
                                <td style="text-align: right;">
                                    <?php if ($is_paid): ?>
                                        <div style="display: inline-flex; gap: 0.4rem; justify-content: flex-end;">
                                            <a href="mes-tickets.php" class="btn-submit" style="width: auto; padding: 0.45rem 0.85rem; font-size: 0.82rem; text-decoration: none;" title="Voir mes QR Codes">
                                                <i class="fa-solid fa-qrcode"></i> Billets (<?php echo (int)$ord['total_billets']; ?>)
                                            </a>
                                            <a href="telecharger-ticket.php?order_id=<?php echo $ord['id']; ?>" target="_blank" class="btn-submit" style="width: auto; padding: 0.45rem 0.85rem; font-size: 0.82rem; background: #0f172a; text-decoration: none;" title="Télécharger mes billets en PDF">
                                                <i class="fa-solid fa-download"></i> PDF
                                            </a>
                                        </div>
                                    <?php elseif ($is_pending): ?>
                                        <a href="paiement.php?order_id=<?php echo $ord['id']; ?>" class="btn-submit" style="display: inline-flex; align-items: center; gap: 0.4rem; width: auto; padding: 0.45rem 0.95rem; font-size: 0.82rem; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); text-decoration: none;">
                                            <i class="fa-solid fa-credit-card"></i> Payer maintenant
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 0.85rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--muted); padding: 3.5rem 1rem;">
                                <div style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                    <i class="fa-solid fa-basket-shopping"></i>
                                </div>
                                <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.15rem;">Vous n'avez pas encore passé de commande</h3>
                                <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                    Vos réservations et billets avec QR Code apparaîtront ici dès votre premier achat.
                                </p>
                                <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex;">
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
            <i class="fa-solid fa-hand-holding-heart" style="color: var(--primary);"></i> Mes Cotisations & Contributions (<?php echo count($cotisations_affichees); ?>)
        </div>

        <!-- Filtres : par campagne, par statut -->
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
            <input type="hidden" name="zone" value="cotisations">
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy); display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-filter" style="color: var(--primary);"></i> Filtrer :
            </span>

            <select name="c_campagne" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 260px;">
                <option value="0">Toutes les campagnes</option>
                <?php foreach ($campagnes_client as $camp_id => $camp_titre): ?>
                    <option value="<?php echo (int)$camp_id; ?>" <?php echo ($filtre_c_campagne === (int)$camp_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($camp_titre); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="c_statut" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer;">
                <option value="">Tous les statuts</option>
                <option value="payee" <?php echo ($filtre_c_statut === 'payee') ? 'selected' : ''; ?>>Payées</option>
                <option value="en_attente" <?php echo ($filtre_c_statut === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                <option value="annule" <?php echo ($filtre_c_statut === 'annule') ? 'selected' : ''; ?>>Annulées</option>
            </select>

            <?php if ($filtre_c_campagne > 0 || $filtre_c_statut !== ''): ?>
                <a href="mes-commandes.php?zone=cotisations" style="font-size: 0.82rem; font-weight: 700; color: var(--danger); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <i class="fa-solid fa-xmark"></i> Réinitialiser
                </a>
            <?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table class="events-table">
                <thead>
                    <tr>
                        <th style="min-width: 280px;">Campagne</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th>Date</th>
                        <th style="text-align: right; min-width: 170px;">Référence / Action</th>
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
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #f0fdfa; color: var(--primary); display: grid; place-items: center; font-size: 1rem; flex-shrink: 0;">
                                            <i class="fa-solid fa-hand-holding-heart"></i>
                                        </div>
                                        <div>
                                            <strong style="color: var(--navy); font-size: 0.95rem; display: block;">
                                                <?php echo !empty($cot['campagne_titre']) ? htmlspecialchars($cot['campagne_titre']) : 'Contribution générale'; ?>
                                            </strong>
                                            <small style="color: var(--muted); font-size: 0.76rem;">
                                                <?php echo $cot_attente ? 'Paiement à finaliser' : ($cot_payee ? 'Participation confirmée' : 'Participation annulée'); ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. Montant -->
                                <td>
                                    <strong style="color: var(--primary); font-size: 1.05rem;">
                                        <?php echo number_format((float)$cot['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>

                                <!-- 3. Statut -->
                                <td>
                                    <?php if ($cot_payee): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 800; display: inline-flex; align-items: center; gap: 0.3rem;">
                                            <i class="fa-solid fa-circle-check"></i> Payée
                                        </span>
                                    <?php elseif ($cot_attente): ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 800; display: inline-flex; align-items: center; gap: 0.3rem;">
                                            <i class="fa-regular fa-clock"></i> En attente
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #b91c1c; padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 800; display: inline-flex; align-items: center; gap: 0.3rem;">
                                            <i class="fa-solid fa-ban"></i> Annulée
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. Date -->
                                <td>
                                    <span style="font-size: 0.88rem; color: var(--ink); font-weight: 600; display: block;">
                                        <?php echo date('d/m/Y', strtotime($cot['created_at'])); ?>
                                    </span>
                                    <small><i class="fa-regular fa-clock"></i> <?php echo date('H:i', strtotime($cot['created_at'])); ?></small>
                                </td>

                                <!-- 5. Référence / Action -->
                                <td style="text-align: right;">
                                    <?php if ($cot_payee): ?>
                                        <span style="font-family: monospace; font-size: 0.82rem; color: var(--navy); font-weight: 700;" title="Référence de transaction">
                                            <?php echo htmlspecialchars($cot['reference'] ?? '—'); ?>
                                        </span>
                                    <?php elseif ($cot_attente): ?>
                                        <a href="paiement-cotisation.php?cotisation_id=<?php echo (int)$cot['id']; ?>" class="btn-submit" style="display: inline-flex; align-items: center; gap: 0.4rem; width: auto; padding: 0.45rem 0.95rem; font-size: 0.82rem; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); text-decoration: none;">
                                            <i class="fa-solid fa-credit-card"></i> Payer maintenant
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 0.85rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                                <div style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                    <i class="fa-solid fa-hand-holding-heart"></i>
                                </div>
                                <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.1rem;">Aucune participation à une cotisation</h3>
                                <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                    Vos contributions aux campagnes de financement apparaîtront ici.
                                </p>
                                <a href="accueil.php?onglet=cotisations" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex;">
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
            <i class="fa-solid fa-vote-yea" style="color: #b45309;"></i> Événements auxquels vous avez voté (<?php echo count($votes_affiches); ?>)
        </div>

        <!-- Filtre : par catégorie -->
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
            <input type="hidden" name="zone" value="votes">
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy); display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-filter" style="color: #b45309;"></i> Filtrer :
            </span>

            <select name="v_categorie" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 260px;">
                <option value="">Toutes les catégories</option>
                <?php foreach (array_keys($cats_votees) as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filtre_v_categorie === $cat) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($filtre_v_categorie !== ''): ?>
                <a href="mes-commandes.php?zone=votes" style="font-size: 0.82rem; font-weight: 700; color: var(--danger); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <i class="fa-solid fa-xmark"></i> Réinitialiser
                </a>
            <?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table class="events-table">
                <thead>
                    <tr>
                        <th style="min-width: 280px;">Événement</th>
                        <th>Catégorie</th>
                        <th>Date de l'événement</th>
                        <th>Date de votre vote</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($votes_affiches) > 0): ?>
                        <?php foreach ($votes_affiches as $vt): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #fffbeb; color: #b45309; display: grid; place-items: center; font-size: 1rem; flex-shrink: 0;">
                                            <i class="fa-solid fa-vote-yea"></i>
                                        </div>
                                        <div>
                                            <strong style="color: var(--navy); font-size: 0.95rem;"><?php echo htmlspecialchars($vt['nom']); ?></strong>
                                            <small style="color: var(--muted); font-size: 0.76rem; display: block;">
                                                <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($vt['lieu']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="background: #f1f5f9; color: var(--navy); padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700;"><?php echo htmlspecialchars($vt['categorie']); ?></span></td>
                                <td>
                                    <span style="font-size: 0.88rem; color: var(--ink); font-weight: 600; display: block;">
                                        <?php echo date('d/m/Y', strtotime($vt['date_evenement'])); ?>
                                    </span>
                                    <small><i class="fa-regular fa-clock"></i> <?php echo substr($vt['heure'], 0, 5); ?></small>
                                </td>
                                <td>
                                    <span style="font-size: 0.88rem; color: var(--ink); font-weight: 600; display: block;">
                                        <?php echo date('d/m/Y', strtotime($vt['date_vote'])); ?>
                                    </span>
                                    <small><i class="fa-regular fa-clock"></i> <?php echo date('H:i', strtotime($vt['date_vote'])); ?></small>
                                </td>
                                <td style="text-align: right;">
                                    <a href="accueil.php?onglet=voter" class="btn-submit" style="width: auto; padding: 0.45rem 0.85rem; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;">
                                        <i class="fa-solid fa-chart-simple"></i> Voir les votes
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                                <div style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; color: var(--muted); margin: 0 auto 1rem;">
                                    <i class="fa-solid fa-vote-yea"></i>
                                </div>
                                <h3 style="color: var(--navy); margin-bottom: 0.35rem; font-size: 1.1rem;">Aucun vote enregistré</h3>
                                <p style="color: var(--muted); font-size: 0.9rem; max-width: 450px; margin: 0 auto 1.25rem;">
                                    Votez pour les événements que vous aimeriez voir organisés : vos votes apparaîtront ici.
                                </p>
                                <a href="accueil.php?onglet=voter" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.4rem; display: inline-flex;">
                                    <i class="fa-solid fa-vote-yea"></i> Voter maintenant
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    /* ===== Mini tableau de bord : affiche uniquement la zone correspondant à la tuile cliquée ===== */
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
        // Masque toutes les zones et atténue toutes les tuiles
        Object.keys(dashboardZones).forEach(function (key) {
            if (dashboardZones[key]) dashboardZones[key].style.display = 'none';
            if (dashboardTiles[key]) dashboardTiles[key].style.opacity = '0.6';
        });

        // Affiche la zone demandée et met en valeur sa tuile
        if (dashboardZones[section]) {
            dashboardZones[section].style.display = '';
            dashboardZones[section].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (dashboardTiles[section]) dashboardTiles[section].style.opacity = '1';
    }

    // État initial : atténuer les tuiles dont la zone est masquée
    Object.keys(dashboardZones).forEach(function (key) {
        if (dashboardZones[key] && dashboardZones[key].style.display === 'none' && dashboardTiles[key]) {
            dashboardTiles[key].style.opacity = '0.6';
        }
    });
</script>

<?php include 'footer.php'; ?>
