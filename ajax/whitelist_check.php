<?php
// ==============================================================================
// VÉRIFICATION D'ÉLIGIBILITÉ WHITELIST (ajax/whitelist_check.php)
// Étape 1 du parcours d'achat sur un événement privé : le numéro saisi
// doit correspondre à une entrée préchargée par l'organisateur.
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

if (!$event) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Événement introuvable.']);
    exit();
}

if ($event['visibilite'] !== 'prive') {
    // Événement public : aucune vérification whitelist nécessaire
    echo json_encode(['success' => true, 'eligible' => true, 'message' => 'Événement public.', 'requires_otp' => false]);
    exit();
}

$result = checkWhitelistEligibility($pdo, $event_id, $telephone);

echo json_encode([
    'success' => true,
    'eligible' => $result['eligible'],
    'message' => $result['message'],
    'remaining' => $result['remaining'] ?? null,
    'requires_otp' => true,
]);
