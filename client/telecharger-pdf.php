<?php
require_once '../config/database.php';
require_once '../includes/pdf.php';
session_start();

$code = trim($_GET['code'] ?? '');
$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT) ?: filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$token = trim($_GET['token'] ?? $_GET['order_token'] ?? '');
$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';

$session_user_id = (int) ($_SESSION['user_id'] ?? 0);
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$tickets = [];

if (!empty($code)) {
    // 1. Recherche par jeton secret unique (QR code / email)
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement AS date_ev, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE t.code_unique = ?
    ");
    $stmt->execute([$code]);
    $tickets = $stmt->fetchAll();
} elseif ($order_id) {
    // 2. Recherche par commande : contrôle d'accès
    $stmt_ord = $pdo->prepare("SELECT id, user_id, statut, numero_commande, created_at FROM orders WHERE id = ?");
    $stmt_ord->execute([$order_id]);
    $order_info = $stmt_ord->fetch();

    if (!$order_info) {
        http_response_code(404);
        die("Commande introuvable.");
    }

    $is_owner = ($session_user_id > 0 && (int)$order_info['user_id'] === $session_user_id);
    $in_session = !empty($_SESSION['accessible_orders'][$order_id]);
    $is_paid = in_array(strtolower($order_info['statut']), ['paye', 'payee', 'confirme'], true);
    $expected_token = hash_hmac('sha256', $order_id . '|' . $order_info['created_at'], $pay_secret);
    $token_valid = (!empty($token) && hash_equals($expected_token, $token));

    if (!$is_admin && !$is_owner && !$in_session && !$token_valid && !$is_paid) {
        http_response_code(403);
        die("Accès refusé. Cette commande nécessite une connexion ou n'est pas encore validée.");
    }

    $sql = "
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement AS date_ev, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE t.order_id = ?
        ORDER BY t.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$order_id]);
    $tickets = $stmt->fetchAll();
} elseif ($ticket_id) {
    // 3. Recherche par ID de billet
    $sql = "
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement AS date_ev, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE t.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticket_id]);
    $tickets = $stmt->fetchAll();

    if (!empty($tickets)) {
        $first = $tickets[0];
        $is_owner = ($session_user_id > 0 && (int)$first['user_id'] === $session_user_id);
        $is_sold = ($first['statut'] === 'vendu');
        if (!$is_admin && !$is_owner && !$is_sold) {
            http_response_code(403);
            die("Accès refusé. Veuillez vous connecter pour accéder à ce billet.");
        }
    }
}

if (empty($tickets)) {
    http_response_code(404);
    die("Billet introuvable ou référence invalide.");
}

$order_number = $tickets[0]['numero_commande'] ?? 'CMD-' . time();
$client_name = $tickets[0]['client_nom'] ?: 'Client';

// Génération du PDF
$pdf_data = generateTicketsPdf($tickets, $order_number, $client_name);

if (empty($pdf_data)) {
    die("Erreur lors de la génération du PDF.");
}

$filename = 'billets-' . preg_replace('/[^A-Za-z0-9\-]/', '', $order_number) . '.pdf';

// Envoi des headers pour forcer l'affichage direct du PDF dans le navigateur (ou téléchargement)
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
echo $pdf_data;
exit;
