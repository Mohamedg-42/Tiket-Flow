<?php
// ==============================================================================
// MODULE D'EXPORT EXCEL / CSV POUR L'ADMINISTRATEUR (admin/export.php)
// Téléchargement complet et sécurisé de toutes les données de la plateforme
// Encodage UTF-8 avec BOM compatible Microsoft Excel, LibreOffice & Google Sheets
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Authentification & contrôle d'accès strict (Admin uniquement)
checkRole(['admin'], '../connexion.php');

$type = trim($_GET['type'] ?? 'tickets');
$date_now = date('Y-m-d_H-i');

function output_csv_row($file_handle, $fields) {
    fputcsv($file_handle, $fields, ';');
}

// ------------------------------------------------------------------------------
// 1. EXPORT DE TOUS LES BILLETS & VENTES GLOBALES
// ------------------------------------------------------------------------------
if ($type === 'tickets' || $type === 'ventes') {
    $filename = "Export_Admin_Billets_Ventes_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
    $statut   = trim($_GET['statut'] ?? '');
    $q        = trim($_GET['q'] ?? '');

    $sql = "
        SELECT t.id, t.code_unique, t.prix, t.statut, t.date_achat, t.date_utilisation,
               e.nom AS event_nom, e.date_evenement, e.lieu AS event_lieu,
               COALESCE(tt.nom, t.type_ticket) AS type_nom,
               u_pro.nom AS promoteur_nom, u_pro.email AS promoteur_email, p.nom_commercial,
               COALESCE(t.client_nom, u_cli.nom, 'Client Direct') AS client_nom,
               COALESCE(t.client_email, u_cli.email, '-') AS client_email,
               COALESCE(t.client_telephone, u_cli.telephone, '-') AS client_tel,
               u_ag.nom AS agent_nom,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
        LEFT JOIN users u_pro ON e.user_id = u_pro.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        LEFT JOIN users u_cli ON t.user_id = u_cli.id
        LEFT JOIN users u_ag ON t.validated_by = u_ag.id
        WHERE 1=1
    ";
    $params = [];

    if ($event_id) {
        $sql .= " AND e.id = ?";
        $params[] = $event_id;
    }
    if ($statut !== '') {
        $sql .= " AND t.statut = ?";
        $params[] = $statut;
    }
    if ($q !== '') {
        $sql .= " AND (t.code_unique LIKE ? OR t.client_nom LIKE ? OR u_cli.nom LIKE ? OR t.client_email LIKE ? OR u_cli.email LIKE ? OR t.client_telephone LIKE ? OR u_cli.telephone LIKE ? OR e.nom LIKE ? OR p.nom_commercial LIKE ?)";
        $wildcard = "%{$q}%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard]);
    }

    $sql .= " ORDER BY t.date_achat DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Billet',
        'Code Unique Billet',
        'Événement',
        'Date Événement',
        'Lieu Événement',
        'Promoteur / Organisateur',
        'Catégorie de Billet',
        'Prix d\'Achat (FCFA)',
        'Nom de l\'Acheteur',
        'Email Client',
        'Téléphone Client',
        'Date & Heure d\'Achat',
        'Statut du Billet',
        'Date de Scan / Validation',
        'Agent de Contrôle',
        'N° Commande'
    ]);

    $total_montant = 0;
    foreach ($rows as $r) {
        $total_montant += (float)$r['prix'];
        $statut_label = 'Valide (Non scanné)';
        if ($r['statut'] === 'utilise') $statut_label = 'Entré (Scanné)';
        elseif ($r['statut'] === 'annule') $statut_label = 'Annulé';

        $nom_pro = $r['nom_commercial'] ?: ($r['promoteur_nom'] ?: 'Événement Officiel');

        output_csv_row($output, [
            $r['id'],
            $r['code_unique'],
            $r['event_nom'],
            date('d/m/Y', strtotime($r['date_evenement'])),
            $r['event_lieu'],
            $nom_pro,
            $r['type_nom'],
            number_format((float)$r['prix'], 0, ',', ' '),
            $r['client_nom'],
            $r['client_email'],
            $r['client_tel'],
            date('d/m/Y H:i', strtotime($r['date_achat'])),
            $statut_label,
            $r['date_utilisation'] ? date('d/m/Y H:i', strtotime($r['date_utilisation'])) : 'Non scanné',
            $r['agent_nom'] ?: '-',
            $r['numero_commande'] ? '#' . $r['numero_commande'] : 'Guichet'
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL BILLETS',
        count($rows),
        '',
        '',
        '',
        '',
        'TOTAL GLOBAL (FCFA)',
        number_format($total_montant, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 2. EXPORT DES DEMANDES DE RETRAIT & VIREMENTS
// ------------------------------------------------------------------------------
if ($type === 'retraits') {
    $filename = "Export_Admin_Retraits_Virements_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $tab = $_GET['tab'] ?? 'tous';

    $sql = "
        SELECT w.*, u.nom AS promoteur_nom, u.email AS promoteur_email, u.telephone AS promoteur_tel,
               p.nom_commercial, p.solde AS solde_actuel
        FROM withdrawals w
        JOIN users u ON w.user_id = u.id
        LEFT JOIN promoters p ON w.user_id = p.user_id
    ";
    $params = [];

    if ($tab !== 'tous' && in_array($tab, ['en_attente', 'paye', 'refuse'], true)) {
        $sql .= " WHERE w.statut = ?";
        $params[] = $tab;
    }

    $sql .= " ORDER BY w.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Retrait',
        'Date de Demande',
        'Nom Promoteur',
        'Nom Commercial / Organisation',
        'Email',
        'Téléphone Promoteur',
        'Montant du Virement (FCFA)',
        'Opérateur Mobile Money',
        'Numéro Téléphone Récepteur',
        'Statut de la Demande',
        'Date de Traitement',
        'Commentaire Admin'
    ]);

    $total_paye = 0;
    $total_en_attente = 0;

    foreach ($rows as $r) {
        $m = (float)$r['montant'];
        if ($r['statut'] === 'paye') $total_paye += $m;
        elseif ($r['statut'] === 'en_attente') $total_en_attente += $m;

        $statut_label = 'En attente';
        if ($r['statut'] === 'paye') $statut_label = 'Payé / Effectué';
        elseif ($r['statut'] === 'refuse') $statut_label = 'Refusé';

        $op = 'Autre';
        switch ($r['methode']) {
            case 'wave': $op = 'Wave'; break;
            case 'orange_money': $op = 'Orange Money'; break;
            case 'mtn_money': $op = 'MTN MoMo'; break;
            case 'moov_money': $op = 'Moov Money'; break;
        }

        output_csv_row($output, [
            '#RET-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['promoteur_nom'],
            $r['nom_commercial'] ?: '-',
            $r['promoteur_email'],
            $r['promoteur_tel'],
            number_format($m, 0, ',', ' '),
            $op,
            $r['numero_telephone'],
            $statut_label,
            $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : '-',
            $r['commentaire_admin'] ?: '-'
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL PAYÉ :',
        number_format($total_paye, 0, ',', ' ') . ' FCFA',
        'TOTAL EN ATTENTE :',
        number_format($total_en_attente, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 3. EXPORT DE TOUS LES ÉVÉNEMENTS
// ------------------------------------------------------------------------------
if ($type === 'evenements') {
    $filename = "Export_Admin_Evenements_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $sql = "
        SELECT e.*, u.nom AS promoteur_nom, u.email AS promoteur_email, p.nom_commercial,
               COALESCE((SELECT SUM(quantite) FROM ticket_types WHERE event_id = e.id), 0) AS total_places,
               COALESCE((SELECT SUM(quantite_vendue) FROM ticket_types WHERE event_id = e.id), 0) AS billets_vendus,
               COALESCE((SELECT SUM(quantite_vendue * prix) FROM ticket_types WHERE event_id = e.id), 0) AS recettes_brutes
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        ORDER BY e.date_evenement DESC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Événement',
        'Titre de l\'Événement',
        'Promoteur Organisateur',
        'Email Contact',
        'Catégorie',
        'Date Événement',
        'Heure',
        'Lieu',
        'Taux Commission (%)',
        'Statut',
        'Quota Places',
        'Billets Vendus',
        'Places Restantes',
        'Taux Remplissage (%)',
        'Recettes Brutes (FCFA)',
        'Commission Plateforme (FCFA)',
        'Net Promoteur (FCFA)'
    ]);

    $tot_recettes = 0;
    $tot_comm = 0;

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

        $nom_pro = $r['nom_commercial'] ?: ($r['promoteur_nom'] ?: 'Plateforme Tikéli');

        output_csv_row($output, [
            '#EV-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
            $r['nom'],
            $nom_pro,
            $r['promoteur_email'] ?: '-',
            $r['categorie'],
            date('d/m/Y', strtotime($r['date_evenement'])),
            date('H:i', strtotime($r['heure'])),
            $r['lieu'],
            $rate . '%',
            ucfirst($r['statut']),
            $places,
            $vendus,
            $restants,
            $taux . '%',
            number_format($brut, 0, ',', ' '),
            number_format($comm, 0, ',', ' '),
            number_format($net, 0, ',', ' ')
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL ÉVÉNEMENTS',
        count($rows),
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'TOTAL RECETTES :',
        number_format($tot_recettes, 0, ',', ' ') . ' FCFA',
        number_format($tot_comm, 0, ',', ' ') . ' FCFA',
        number_format($tot_recettes - $tot_comm, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 4. EXPORT DES UTILISATEURS & PROMOTEURS
// ------------------------------------------------------------------------------
if ($type === 'utilisateurs' || $type === 'promoteurs') {
    $filename = "Export_Admin_Utilisateurs_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $role_filter = trim($_GET['role'] ?? '');

    $sql = "
        SELECT u.id, u.nom, u.email, u.telephone, u.role, u.est_verifie, u.created_at,
               p.nom_commercial, p.solde, p.statut AS promoter_statut
        FROM users u
        LEFT JOIN promoters p ON u.id = p.user_id
    ";
    $params = [];

    if ($role_filter !== '') {
        $sql .= " WHERE u.role = ?";
        $params[] = $role_filter;
    }

    $sql .= " ORDER BY u.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Utilisateur',
        'Nom & Prénoms',
        'Email',
        'Téléphone',
        'Rôle',
        'Structure / Nom Commercial',
        'Solde Promoteur (FCFA)',
        'Compte Vérifié',
        'Date d\'Inscription'
    ]);

    foreach ($rows as $r) {
        output_csv_row($output, [
            '#USR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['nom'],
            $r['email'],
            $r['telephone'],
            strtoupper($r['role']),
            $r['nom_commercial'] ?: '-',
            $r['solde'] !== null ? number_format((float)$r['solde'], 0, ',', ' ') : '-',
            $r['est_verifie'] ? 'Oui (Vérifié)' : 'Non',
            date('d/m/Y H:i', strtotime($r['created_at']))
        ]);
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 5. EXPORT DES COMMANDES & TRANSACTIONS
// ------------------------------------------------------------------------------
if ($type === 'commandes') {
    $filename = "Export_Admin_Commandes_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $sql = "
        SELECT o.*,
               COALESCE(o.client_nom, u.nom, 'Client Direct') AS final_nom,
               COALESCE(o.client_email, u.email, '-') AS final_email,
               COALESCE(o.client_telephone, u.telephone, '-') AS final_tel,
               (SELECT COUNT(*) FROM tickets WHERE order_id = o.id) AS nb_billets
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'N° Commande',
        'Date & Heure',
        'Client',
        'Email Client',
        'Téléphone Client',
        'Nombre de Billets',
        'Montant Total (FCFA)',
        'Statut de la Commande'
    ]);

    $total_cmds = 0;
    foreach ($rows as $r) {
        $total_cmds += (float)$r['montant_total'];
        output_csv_row($output, [
            '#' . ($r['numero_commande'] ?: str_pad($r['id'], 6, '0', STR_PAD_LEFT)),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['final_nom'],
            $r['final_email'],
            $r['final_tel'],
            $r['nb_billets'],
            number_format((float)$r['montant_total'], 0, ',', ' '),
            strtoupper($r['statut'])
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL COMMANDES',
        count($rows),
        '',
        '',
        '',
        '',
        'TOTAL VENTES (FCFA)',
        number_format($total_cmds, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 6. EXPORT DE TOUTES LES COTISATIONS & CAGNOTTES
// ------------------------------------------------------------------------------
if ($type === 'cotisations') {
    $filename = "Export_Admin_Cotisations_{$date_now}.csv";
    
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
                   c.titre AS campagne_titre, c.montant_objectif, c.date_fin AS date_limite,
                   u.nom AS promoteur_nom, u.email AS promoteur_email
            FROM cotisations ct
            JOIN cotisation_campagnes c ON ct.campagne_id = c.id
            LEFT JOIN users u ON c.user_id = u.id
            ORDER BY ct.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Cotisation',
        'Campagne / Projet',
        'Promoteur / Organisateur',
        'Nom du Donateur',
        'Email Donateur',
        'Téléphone Donateur',
        'Montant (FCFA)',
        'Statut du Paiement',
        'Date & Heure',
        'Objectif Campagne',
        'Date Limite'
    ]);

    $total_montant = 0;
    foreach ($rows as $r) {
        $total_montant += (float)$r['montant'];
        output_csv_row($output, [
            '#COT-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['campagne_titre'],
            $r['promoteur_nom'] ?: 'Plateforme Tikéli',
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
        'TOTAL COTISATIONS',
        count($rows),
        '',
        '',
        '',
        '',
        'TOTAL COLLECTÉ (FCFA)',
        number_format($total_montant, 0, ',', ' ') . ' FCFA'
    ]);

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 7. EXPORT DE TOUS LES VOTES & CANDIDATS
// ------------------------------------------------------------------------------
if ($type === 'votes') {
    $filename = "Export_Admin_Votes_{$date_now}.csv";
    
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
                   u.nom AS votant_nom, u.email AS votant_email, u.telephone AS votant_tel,
                   u_pro.nom AS promoteur_nom
            FROM event_votes ev
            JOIN events e ON ev.event_id = e.id
            LEFT JOIN event_candidats ec ON ev.candidat_id = ec.id
            LEFT JOIN users u ON ev.user_id = u.id
            LEFT JOIN users u_pro ON e.user_id = u_pro.id
            ORDER BY ev.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Vote',
        'Événement',
        'Promoteur',
        'Candidat Sélectionné',
        'Nom du Votant',
        'Email Votant',
        'Téléphone Votant',
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
            $r['promoteur_nom'] ?: 'Tikéli',
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
        'TOTAL VOTES',
        count($rows),
        '',
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
// 8. EXPORT DES DEMANDES (ÉVÉNEMENTS & PROMOTEURS)
// ------------------------------------------------------------------------------
if ($type === 'demandes') {
    $filename = "Export_Admin_Demandes_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $tab = $_GET['tab'] ?? 'evenements';

    if ($tab === 'promoteurs') {
        $sql = "
            SELECT pr.*, u.nom AS demandeur_nom, u.email AS demandeur_email, u.telephone AS demandeur_tel
            FROM promoter_requests pr
            LEFT JOIN users u ON pr.user_id = u.id
            ORDER BY pr.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        output_csv_row($output, [
            'ID Demande',
            'Demandeur',
            'Email',
            'Téléphone',
            'Activité Déclarée',
            'Statut Demande',
            'Date de Soumission',
            'Date de Traitement',
            'Commentaire Admin'
        ]);

        foreach ($rows as $r) {
            output_csv_row($output, [
                '#DEM-PRO-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
                $r['nom_complet'] ?: ($r['demandeur_nom'] ?: '-'),
                $r['email'] ?: ($r['demandeur_email'] ?: '-'),
                $r['telephone'] ?: ($r['demandeur_tel'] ?: '-'),
                $r['activite'] ?: '-',
                strtoupper($r['statut']),
                date('d/m/Y H:i', strtotime($r['created_at'])),
                $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : '-',
                $r['commentaire_admin'] ?: '-'
            ]);
        }
    } else {
        $sql = "
            SELECT er.*, u.nom AS demandeur_nom, u.email AS demandeur_email, u.telephone AS demandeur_tel
            FROM event_requests er
            LEFT JOIN users u ON er.user_id = u.id
            ORDER BY er.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        output_csv_row($output, [
            'ID Demande',
            'Demandeur',
            'Email',
            'Téléphone',
            'Titre Événement Soumis',
            'Date Souhaitée',
            'Lieu',
            'Statut Demande',
            'Date de Soumission',
            'Date de Traitement',
            'Commentaire Admin'
        ]);

        foreach ($rows as $r) {
            output_csv_row($output, [
                '#DEM-EV-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
                $r['demandeur_nom'] ?: '-',
                $r['demandeur_email'] ?: '-',
                $r['demandeur_tel'] ?: '-',
                $r['nom'] ?: '-',
                $r['date_evenement'] ? date('d/m/Y', strtotime($r['date_evenement'])) : '-',
                $r['lieu'] ?: '-',
                strtoupper($r['statut']),
                date('d/m/Y H:i', strtotime($r['created_at'])),
                $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : '-',
                $r['commentaire_admin'] ?: '-'
            ]);
        }
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 9. EXPORT DES RÉCLAMATIONS & SUPPORT (TABLE claims)
// ------------------------------------------------------------------------------
if ($type === 'reclamations') {
    $filename = "Export_Admin_Reclamations_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $rows = [];
    try {
        $sql = "
            SELECT r.*, u.nom AS user_nom, u.email AS user_email, u.telephone AS user_tel, u.role AS user_role
            FROM claims r
            LEFT JOIN users u ON r.user_id = u.id
            ORDER BY r.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Ticket',
        'Utilisateur',
        'Email',
        'Téléphone',
        'Rôle',
        'Sujet / Motif',
        'Message',
        'Statut Ticket',
        'Date Création',
        'Date Clôture / Réponse',
        'Réponse Admin'
    ]);

    foreach ($rows as $r) {
        output_csv_row($output, [
            '#REC-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['user_nom'] ?: '-',
            $r['user_email'] ?: '-',
            $r['user_tel'] ?: '-',
            strtoupper($r['user_role'] ?: '-'),
            $r['sujet'],
            $r['message'],
            strtoupper($r['statut']),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['updated_at'] ? date('d/m/Y H:i', strtotime($r['updated_at'])) : '-',
            $r['reponse_admin'] ?: 'Non traitée'
        ]);
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 10. EXPORT DU JOURNAL D'ACTIVITÉ & SÉCURITÉ
// ------------------------------------------------------------------------------
if ($type === 'activite' || $type === 'audit') {
    $filename = "Export_Admin_Journal_Activite_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $rows = [];
    try {
        $sql = "
            SELECT a.*, u.nom AS user_nom, u.email AS user_email, u.role AS user_role
            FROM activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 2000
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Log',
        'Date & Heure',
        'Action / Événement',
        'Type Entité',
        'ID Entité',
        'Utilisateur',
        'Email',
        'Rôle',
        'Description Détaillée'
    ]);

    foreach ($rows as $r) {
        output_csv_row($output, [
            '#LOG-' . str_pad($r['id'], 6, '0', STR_PAD_LEFT),
            date('d/m/Y H:i:s', strtotime($r['created_at'])),
            $r['action'],
            $r['entity_type'] ?: '-',
            $r['entity_id'] ?: '-',
            $r['user_nom'] ?: 'Système / Invité',
            $r['user_email'] ?: '-',
            strtoupper($r['user_role'] ?: '-'),
            $r['description'] ?? ($r['details'] ?? '-')
        ]);
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 11. EXPORT DES TÂCHES COLLABORATIVES
// ------------------------------------------------------------------------------
if ($type === 'taches') {
    $filename = "Export_Admin_Taches_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $rows = [];
    try {
        $sql = "
            SELECT t.*, u.nom AS assignee_nom, u.prenom AS assignee_prenom, u.email AS assignee_email
            FROM tasks t
            LEFT JOIN users u ON t.user_id = u.id
            ORDER BY t.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    output_csv_row($output, [
        'ID Tâche',
        'Titre de la Tâche',
        'Assigné à',
        'Email Assigné',
        'Priorité',
        'Statut',
        'Date de Début',
        'Date Limite (Échéance)',
        'Date de Création'
    ]);

    foreach ($rows as $r) {
        $nom_assigne = trim(($r['assignee_prenom'] ?? '') . ' ' . ($r['assignee_nom'] ?? ''));
        output_csv_row($output, [
            '#TASK-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['titre'],
            $nom_assigne ?: 'Non assigné',
            $r['assignee_email'] ?: '-',
            strtoupper($r['priorite'] ?: 'NORMALE'),
            strtoupper($r['statut'] ?: 'A_FAIRE'),
            !empty($r['date_debut']) ? date('d/m/Y', strtotime($r['date_debut'])) : '-',
            !empty($r['date_limite']) ? date('d/m/Y', strtotime($r['date_limite'])) : 'Sans échéance',
            date('d/m/Y H:i', strtotime($r['created_at']))
        ]);
    }

    fclose($output);
    exit();
}

// ------------------------------------------------------------------------------
// 12. EXPORT DES SALLES & LIEUX DE SPECTACLE
// ------------------------------------------------------------------------------
if ($type === 'salles') {
    $filename = "Export_Admin_Salles_Lieux_{$date_now}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    $statut_f = $_GET['statut'] ?? 'tous';
    $ville_f  = $_GET['ville'] ?? 'toutes';
    $search   = trim($_GET['q'] ?? '');

    $sql = "SELECT * FROM salles WHERE 1=1";
    $params = [];

    if ($statut_f !== 'tous' && in_array($statut_f, ['active', 'maintenance', 'inactive'], true)) {
        $sql .= " AND statut = ?";
        $params[] = $statut_f;
    }
    if ($ville_f !== 'toutes' && !empty($ville_f)) {
        $sql .= " AND ville = ?";
        $params[] = $ville_f;
    }
    if (!empty($search)) {
        $sql .= " AND (nom LIKE ? OR commune LIKE ? OR adresse LIKE ?)";
        $wildcard = "%{$search}%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard]);
    }

    $sql .= " ORDER BY capacite DESC, nom ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    output_csv_row($output, [
        'ID Salle',
        'Nom de la Salle / Complexe',
        'Ville',
        'Commune',
        'Adresse',
        'Capacité Maximale (Places)',
        'Type d\'Infrastructure',
        'Configuration des Sièges',
        'Statut',
        'Contact Responsable',
        'Téléphone Responsable',
        'Équipements Disponibles',
        'Date d\'Enregistrement'
    ]);

    $tot_cap = 0;
    foreach ($rows as $r) {
        $tot_cap += (int)$r['capacite'];
        output_csv_row($output, [
            '#SALLE-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
            $r['nom'],
            $r['ville'],
            $r['commune'] ?: '-',
            $r['adresse'] ?: '-',
            number_format((int)$r['capacite'], 0, ',', ' '),
            strtoupper($r['type_salle']),
            strtoupper($r['configuration']),
            strtoupper($r['statut']),
            $r['contact_responsable'] ?: '-',
            $r['telephone_responsable'] ?: '-',
            $r['equipements'] ?: '-',
            date('d/m/Y H:i', strtotime($r['created_at']))
        ]);
    }

    output_csv_row($output, []);
    output_csv_row($output, [
        'TOTAL SALLES :',
        count($rows),
        '',
        '',
        'CAPACITÉ TOTALE CUMULÉE :',
        number_format($tot_cap, 0, ',', ' ') . ' places'
    ]);

    fclose($output);
    exit();
}

// Redirection par défaut
header("Location: tickets.php");
exit();
