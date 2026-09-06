<?php
// ==============================================================================
// MODULE D'EXPORT EXCEL / CSV POUR LE PROMOTEUR (promoteur/export.php)
// Téléchargement sécurisé et instantané des données (Ventes, Retraits, Agents, etc.)
// Encodage UTF-8 avec BOM compatible Microsoft Excel, LibreOffice & Google Sheets
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Authentification & contrôle d'accès
checkRole(['promoteur', 'admin'], '../connexion.php');

$user_id = (int) $_SESSION['user_id'];

// Récupération des infos du promoteur
$promoter_name = 'Promoteur';
try {
    $stmt_pro = $pdo->prepare("SELECT p.*, u.nom, u.email FROM promoters p JOIN users u ON p.user_id = u.id WHERE p.user_id = ?");
    $stmt_pro->execute([$user_id]);
    $promoter = $stmt_pro->fetch(PDO::FETCH_ASSOC);
    if ($promoter) {
        $promoter_name = $promoter['nom_commercial'] ?: ($promoter['nom'] ?: 'Promoteur');
    }
} catch (Exception $e) {
    $promoter_name = 'Promoteur';
}

$type = trim($_GET['type'] ?? 'ventes');
$date_now = date('Y-m-d_H-i');

// Fonction helper pour échapper les valeurs CSV avec séparateur point-virgule (standard Excel FR)
function output_csv_row($file_handle, $fields) {
    fputcsv($file_handle, $fields, ';');
}

// ------------------------------------------------------------------------------
// 1. EXPORT DES VENTES & BILLETS
// ------------------------------------------------------------------------------
if ($type === 'ventes') {
    $filename = "Export_Ventes_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 pour ouverture directe et propre dans Excel (reconnaissance immédiate des accents)
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Filtres
    $q             = trim($_GET['q'] ?? '');
    $filter_event  = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
    $filter_status = trim($_GET['statut'] ?? '');
    $filter_type   = trim($_GET['type_nom'] ?? ($_GET['type'] ?? ''));
    $filter_period = trim($_GET['period'] ?? '');

    $sql = "
        SELECT t.id, t.code_unique, t.prix, t.statut, t.date_achat, t.date_utilisation,
               e.nom AS event_nom, e.date_evenement, e.lieu AS event_lieu,
               COALESCE(tt.nom, t.type_ticket) AS type_nom,
               COALESCE(t.client_nom, u_client.nom, 'Client Web') AS client_nom,
               COALESCE(t.client_email, u_client.email, '-') AS client_email,
               COALESCE(t.client_telephone, u_client.telephone, '-') AS client_tel,
               u_agent.nom AS agent_nom,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
        LEFT JOIN orders o ON t.order_id = o.id
        LEFT JOIN users u_client ON t.user_id = u_client.id
        LEFT JOIN users u_agent ON t.validated_by = u_agent.id
        WHERE e.user_id = ?
    ";
    $params = [$user_id];

    if ($filter_event) {
        $sql .= " AND e.id = ?";
        $params[] = $filter_event;
    }
    if (!empty($filter_status)) {
        $sql .= " AND t.statut = ?";
        $params[] = $filter_status;
    }
    if (!empty($filter_type)) {
        $sql .= " AND (tt.nom = ? OR t.type_ticket = ?)";
        $params[] = $filter_type;
        $params[] = $filter_type;
    }
    if (!empty($filter_period)) {
        switch ($filter_period) {
            case 'today':
                $sql .= " AND DATE(t.date_achat) = CURDATE()";
                break;
            case '7d':
                $sql .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30d':
                $sql .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'this_month':
                $sql .= " AND MONTH(t.date_achat) = MONTH(CURRENT_DATE()) AND YEAR(t.date_achat) = YEAR(CURRENT_DATE())";
                break;
        }
    }
    if ($q !== '') {
        $sql .= " AND (t.code_unique LIKE ? OR t.client_nom LIKE ? OR u_client.nom LIKE ? OR t.client_email LIKE ? OR u_client.email LIKE ? OR t.client_telephone LIKE ? OR u_client.telephone LIKE ? OR e.nom LIKE ?)";
        $wildcard = "%{$q}%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard]);
    }

    $sql .= " ORDER BY t.date_achat DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // En-têtes du fichier Excel
    output_csv_row($output, [
        'N° Billet (Code)',
        'Événement',
        'Date Événement',
        'Lieu',
        'Catégorie / Type',
        'Nom de l\'Acheteur',
        'Email Client',
        'Téléphone Client',
        'Prix Unitaire (FCFA)',
        'Date & Heure d\'Achat',
        'Statut du Billet',
        'Date & Heure de Scan',
        'Agent de Contrôle',
        'N° Commande'
    ]);

    $total_recettes = 0;
    $count = 0;

    foreach ($rows as $r) {
        $total_recettes += (float)$r['prix'];
        $count++;

        $statut_label = 'Valide (Non scanné)';
        if ($r['statut'] === 'utilise') $statut_label = 'Utilisé / Entré';
        elseif ($r['statut'] === 'annule') $statut_label = 'Annulé / Remboursé';

        output_csv_row($output, [
            $r['code_unique'],
            $r['event_nom'],
            date('d/m/Y', strtotime($r['date_evenement'])),
            $r['event_lieu'],
            $r['type_nom'],
            $r['client_nom'] ?: 'Client Direct',
            $r['client_email'] ?: '-',
            $r['client_tel'] ?: '-',
            number_format((float)$r['prix'], 0, ',', ' '),
            date('d/m/Y H:i', strtotime($r['date_achat'])),
            $statut_label,
            $r['date_utilisation'] ? date('d/m/Y H:i', strtotime($r['date_utilisation'])) : 'Non scanné',
            $r['agent_nom'] ?: '-',
            $r['numero_commande'] ? '#' . $r['numero_commande'] : 'Vente directe'
        ]);
    }

    // Ligne de synthèse
    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL BILLETS',
        $count,
        '',
        '',
        '',
        '',
        '',
        'TOTAL RECETTES (FCFA)',
        number_format($total_recettes, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 2. EXPORT DE L'HISTORIQUE DES RETRAITS & VIREMENTS
// ------------------------------------------------------------------------------
if ($type === 'retraits') {
    $filename = "Export_Virements_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $periode = trim($_GET['periode'] ?? 'toutes');
    $filter_methode = trim($_GET['methode'] ?? 'toutes');
    $search_q = trim($_GET['q'] ?? '');

    $sql = "SELECT * FROM withdrawals WHERE user_id = ?";
    $params = [$user_id];

    if ($filter_methode !== 'toutes' && in_array($filter_methode, ['wave', 'orange_money', 'mtn_money', 'moov_money'], true)) {
        $sql .= " AND methode = ?";
        $params[] = $filter_methode;
    }
    if ($periode !== 'toutes') {
        switch ($periode) {
            case '7_jours':
                $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30_jours':
                $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'ce_mois':
                $sql .= " AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())";
                break;
            case 'cette_annee':
                $sql .= " AND YEAR(created_at) = YEAR(CURRENT_DATE())";
                break;
        }
    }
    if ($search_q !== '') {
        $sql .= " AND (numero_telephone LIKE ? OR commentaire_admin LIKE ? OR id LIKE ?)";
        $wildcard = "%{$search_q}%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard]);
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Virement',
        'Référence Transaction',
        'Date & Heure de Demande',
        'Montant (FCFA)',
        'Opérateur Mobile Money',
        'Numéro Téléphone Récepteur',
        'Statut du Virement',
        'Date d\'Exécution',
        'Commentaire'
    ]);

    $total_vire = 0;
    foreach ($rows as $r) {
        if ($r['statut'] === 'paye') {
            $total_vire += (float)$r['montant'];
        }

        $op_name = 'Autre';
        switch ($r['methode']) {
            case 'wave': $op_name = 'Wave'; break;
            case 'orange_money': $op_name = 'Orange Money'; break;
            case 'mtn_money': $op_name = 'MTN MoMo'; break;
            case 'moov_money': $op_name = 'Moov Money'; break;
        }

        $statut_label = 'En attente';
        if ($r['statut'] === 'paye') $statut_label = 'Effectué / Payé';
        elseif ($r['statut'] === 'refuse') $statut_label = 'Refusé';

        $ref = 'PAY-FEEXPAY-' . str_pad($r['id'], 6, '0', STR_PAD_LEFT);

        output_csv_row($output, [
            '#VIR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $ref,
            date('d/m/Y H:i', strtotime($r['created_at'])),
            number_format((float)$r['montant'], 0, ',', ' '),
            $op_name,
            $r['numero_telephone'],
            $statut_label,
            $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : 'Instantané',
            $r['commentaire_admin'] ?: 'Transfert direct vers compte Mobile Money'
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL VIRÉ PAYÉ',
        '',
        '',
        number_format($total_vire, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 3. EXPORT DES AGENTS DE SCAN
// ------------------------------------------------------------------------------
if ($type === 'agents') {
    $filename = "Export_Agents_Controle_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
    $search_q     = trim($_GET['q'] ?? '');

    $rows = [];
    try {
        $sql = "
            SELECT aa.id AS assign_id, aa.created_at AS assign_date,
                   u.nom AS agent_nom, u.email AS agent_email, u.telephone AS agent_tel,
                   e.nom AS event_nom, e.date_evenement, e.lieu, e.statut AS event_statut,
                   (SELECT COUNT(*) FROM tickets t WHERE t.validated_by = u.id AND t.event_id = e.id AND t.statut = 'utilise') AS nb_scans,
                   (SELECT MAX(t.date_utilisation) FROM tickets t WHERE t.validated_by = u.id AND t.event_id = e.id AND t.statut = 'utilise') AS dernier_scan
            FROM agent_assignments aa
            JOIN users u ON aa.agent_id = u.id
            JOIN events e ON aa.event_id = e.id
            WHERE aa.promoter_user_id = ?
        ";
        $params = [$user_id];

        if ($filter_event) {
            $sql .= " AND e.id = ?";
            $params[] = $filter_event;
        }
        if ($search_q !== '') {
            $sql .= " AND (u.nom LIKE ? OR u.email LIKE ? OR u.telephone LIKE ? OR e.nom LIKE ?)";
            $wildcard = "%{$search_q}%";
            $params = array_merge($params, [$wildcard, $wildcard, $wildcard, $wildcard]);
        }

        $sql .= " ORDER BY aa.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Affectation',
        'Nom de l\'Agent',
        'Email Agent',
        'Téléphone Agent',
        'Événement Affecté',
        'Date Événement',
        'Lieu',
        'Statut Événement',
        'Date d\'Affectation',
        'Billets Scannés',
        'Dernier Scan Enregistré'
    ]);

    $total_scans = 0;
    foreach ($rows as $r) {
        $total_scans += (int)$r['nb_scans'];
        output_csv_row($output, [
            '#AFF-' . str_pad($r['assign_id'], 4, '0', STR_PAD_LEFT),
            $r['agent_nom'],
            $r['agent_email'],
            $r['agent_tel'],
            $r['event_nom'],
            date('d/m/Y', strtotime($r['date_evenement'])),
            $r['lieu'],
            ucfirst($r['event_statut']),
            date('d/m/Y H:i', strtotime($r['assign_date'])),
            $r['nb_scans'],
            $r['dernier_scan'] ? date('d/m/Y H:i:s', strtotime($r['dernier_scan'])) : 'Aucun scan'
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL AGENTS',
        count($rows),
        '',
        '',
        '',
        '',
        '',
        '',
        'TOTAL BILLETS SCANNÉS',
        $total_scans
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 4. EXPORT DES ÉVÉNEMENTS & SYNTHÈSE DES REVENUS
// ------------------------------------------------------------------------------
if ($type === 'evenements') {
    $filename = "Export_Evenements_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $sql = "
        SELECT e.*,
               COALESCE((SELECT SUM(quantite) FROM ticket_types WHERE event_id = e.id), 0) AS total_places,
               COALESCE((SELECT SUM(quantite_vendue) FROM ticket_types WHERE event_id = e.id), 0) AS billets_vendus,
               COALESCE((SELECT SUM(quantite_vendue * prix) FROM ticket_types WHERE event_id = e.id), 0) AS recettes_brutes
        FROM events e
        WHERE e.user_id = ?
        ORDER BY e.date_evenement DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Événement',
        'Titre de l\'Événement',
        'Catégorie',
        'Date',
        'Heure',
        'Lieu',
        'Statut',
        'Quota Places',
        'Billets Vendus',
        'Places Restantes',
        'Taux de Remplissage (%)',
        'Recettes Brutes (FCFA)',
        'Commission Plateforme (%)',
        'Commission Déduite (FCFA)',
        'Net Promoteur (FCFA)'
    ]);

    $tot_recettes = 0;
    $tot_comm = 0;
    $tot_net = 0;

    foreach ($rows as $r) {
        $brut = (float)$r['recettes_brutes'];
        $rate = (float)($r['commission_rate'] ?? 5.00);
        $comm = round($brut * ($rate / 100));
        $net = $brut - $comm;

        $places = (int)$r['total_places'];
        $vendus = (int)$r['billets_vendus'];
        $restants = max(0, $places - $vendus);
        $taux = $places > 0 ? round(($vendus / $places) * 100, 1) : 0;

        $tot_recettes += $brut;
        $tot_comm += $comm;
        $tot_net += $net;

        output_csv_row($output, [
            '#EV-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
            $r['nom'],
            $r['categorie'],
            date('d/m/Y', strtotime($r['date_evenement'])),
            date('H:i', strtotime($r['heure'])),
            $r['lieu'],
            ucfirst($r['statut']),
            $places,
            $vendus,
            $restants,
            $taux . '%',
            number_format($brut, 0, ',', ' '),
            $rate . '%',
            number_format($comm, 0, ',', ' '),
            number_format($net, 0, ',', ' ')
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL TOUS ÉVÉNEMENTS',
        count($rows) . ' événement(s)',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'TOTAUX :',
        number_format($tot_recettes, 0, ',', ' ') . ' FCFA',
        '',
        number_format($tot_comm, 0, ',', ' ') . ' FCFA',
        number_format($tot_net, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 5. EXPORT DES COTISATIONS & CAGNOTTES DU PROMOTEUR
// ------------------------------------------------------------------------------
if ($type === 'cotisations') {
    $filename = "Export_Cotisations_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $rows = [];
    try {
        $sql = "
            SELECT ct.id, ct.montant, ct.statut, ct.created_at, ct.nom AS nom_donateur, ct.email AS email_donateur, ct.telephone AS telephone_donateur,
                   c.titre AS campagne_titre, c.montant_objectif, c.date_fin AS date_limite
            FROM cotisations ct
            JOIN cotisation_campagnes c ON ct.campagne_id = c.id
            WHERE c.user_id = ?
            ORDER BY ct.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Cotisation',
        'Campagne / Projet',
        'Nom du Contributeur',
        'Email',
        'Téléphone',
        'Montant (FCFA)',
        'Statut du Paiement',
        'Date & Heure',
        'Objectif Campagne',
        'Date Limite'
    ]);

    $total_cotisations = 0;
    foreach ($rows as $r) {
        $total_cotisations += (float)$r['montant'];
        output_csv_row($output, [
            '#COT-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['campagne_titre'],
            $r['nom_donateur'] ?: 'Anonyme',
            $r['email_donateur'] ?: '-',
            $r['telephone_donateur'] ?: '-',
            number_format((float)$r['montant'], 0, ',', ' '),
            strtoupper($r['statut']),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            number_format((float)$r['montant_objectif'], 0, ',', ' ') . ' FCFA',
            !empty($r['date_limite']) ? date('d/m/Y', strtotime($r['date_limite'])) : 'Sans limite'
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL COTISATIONS REÇUES',
        count($rows) . ' contribution(s)',
        '',
        '',
        '',
        number_format($total_cotisations, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 6. EXPORT DES VOTES DU PROMOTEUR
// ------------------------------------------------------------------------------
if ($type === 'votes') {
    $filename = "Export_Votes_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $rows = [];
    try {
        $sql = "
            SELECT ev.id, ev.created_at, ev.montant, ev.statut,
                   e.nom AS event_nom,
                   ec.nom AS candidat_nom,
                   u.nom AS votant_nom, u.email AS votant_email, u.telephone AS votant_tel
            FROM event_votes ev
            JOIN events e ON ev.event_id = e.id
            LEFT JOIN event_candidats ec ON ev.candidat_id = ec.id
            LEFT JOIN users u ON ev.user_id = u.id
            WHERE e.user_id = ?
            ORDER BY ev.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Vote',
        'Événement',
        'Candidat Sélectionné',
        'Nom du Votant',
        'Email Votant',
        'Téléphone',
        'Montant Payé (FCFA)',
        'Statut',
        'Date & Heure'
    ]);

    $total_votes_montant = 0;
    foreach ($rows as $r) {
        $total_votes_montant += (float)$r['montant'];
        output_csv_row($output, [
            '#VOTE-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['event_nom'],
            $r['candidat_nom'] ?: 'Événement Global',
            $r['votant_nom'] ?: 'Visiteur',
            $r['votant_email'] ?: '-',
            $r['votant_tel'] ?: '-',
            number_format((float)$r['montant'], 0, ',', ' '),
            strtoupper($r['statut'] ?: 'VALIDE'),
            date('d/m/Y H:i', strtotime($r['created_at']))
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL VOTES ENREGISTRÉS',
        count($rows),
        '',
        '',
        '',
        '',
        number_format($total_votes_montant, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 7. EXPORT DES DEMANDES D'ÉVÉNEMENTS
// ------------------------------------------------------------------------------
if ($type === 'demandes') {
    $filename = "Export_Demandes_Evenements_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $sql = "
        SELECT er.*, e.nom AS event_nom, e.statut AS event_statut
        FROM event_requests er
        LEFT JOIN events e ON er.id = e.id
        WHERE er.user_id = ?
        ORDER BY er.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Demande',
        'Titre Soumis',
        'Date Événement Souhaitée',
        'Lieu',
        'Statut de la Demande',
        'Date de Soumission',
        'Date de Réponse',
        'Motif / Commentaire Admin'
    ]);

    foreach ($rows as $r) {
        output_csv_row($output, [
            '#DEM-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['nom'] ?: ($r['event_nom'] ?: '-'),
            $r['date_evenement'] ? date('d/m/Y', strtotime($r['date_evenement'])) : '-',
            $r['lieu'] ?: '-',
            strtoupper($r['statut']),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : 'En attente',
            $r['commentaire_admin'] ?: 'En cours d\'examen'
        ]);
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 8. EXPORT DES RÉCLAMATIONS & TICKETS SUPPORT DU PROMOTEUR
// ------------------------------------------------------------------------------
if ($type === 'reclamations') {
    $filename = "Export_Reclamations_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter_name) . "_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $sql = "SELECT * FROM claims WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Ticket',
        'Sujet / Motif',
        'Message',
        'Statut du Ticket',
        'Date d\'Ouverture',
        'Date de Mise à Jour',
        'Réponse de l\'Administration'
    ]);

    foreach ($rows as $r) {
        $statut_label = 'En attente';
        if ($r['statut'] === 'en_cours') $statut_label = 'En cours';
        elseif ($r['statut'] === 'resolue') $statut_label = 'Résolue';
        elseif ($r['statut'] === 'fermee') $statut_label = 'Fermée';

        output_csv_row($output, [
            '#REC-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['sujet'],
            $r['message'],
            $statut_label,
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['updated_at'] ? date('d/m/Y H:i', strtotime($r['updated_at'])) : '-',
            $r['reponse_admin'] ?: 'En attente de traitement'
        ]);
    }

    fclose($output);
    exit();
}

// Type inconnu
header("Location: mes-ventes.php");
exit();
