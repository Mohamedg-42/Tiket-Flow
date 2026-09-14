<?php
// ==============================================================================
// ENVOI DU CODE OTP PAR SMS (ajax/otp_send.php)
// Étape 2 du parcours d'achat sur un événement privé : le numéro éligible
// reçoit un code à 6 chiffres à saisir avant de débloquer le paiement.
// ==============================================================================

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/whitelist.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit();
}

$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$telephone = trim($_POST['telephone'] ?? '');

if (!$event_id || $telephone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Numéro de téléphone requis.']);
    exit();
}

$stmt = $pdo->prepare("SELECT id, visibilite FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event || $event['visibilite'] !== 'prive') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Événement introuvable ou non restreint.']);
    exit();
}

// Re-vérification serveur de l'éligibilité (ne jamais faire confiance au client)
$eligibility = checkWhitelistEligibility($pdo, $event_id, $telephone);
if (!$eligibility['eligible']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $eligibility['message']]);
    exit();
}

$result = createAndSendOtp($pdo, $event_id, $telephone);

if (!$result['success']) {
    http_response_code(429);
    echo json_encode($result);
    exit();
}

echo json_encode($result);
