<?php
require_once '../config/database.php';
require_once '../includes/pdf.php';
session_start();

$code = trim($_GET['code'] ?? '');
$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$tickets = [];

if (!empty($code)) {
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
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement AS date_ev, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE t.order_id = ?
        ORDER BY t.id ASC
    ");
    $stmt->execute([$order_id]);
    $tickets = $stmt->fetchAll();
} elseif ($ticket_id) {
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement AS date_ev, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone,
               o.numero_commande
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $tickets = $stmt->fetchAll();
}

if (empty($tickets)) {
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
