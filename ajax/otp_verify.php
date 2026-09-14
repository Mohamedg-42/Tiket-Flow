<?php
// ==============================================================================
// VÉRIFICATION DU CODE OTP (ajax/otp_verify.php)
// Étape 3 du parcours d'achat sur un événement privé : si le code est valide,
// le paiement est débloqué pour ce téléphone/événement (flag en session,
// valable le temps de la session d'achat).
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
$code = trim($_POST['code'] ?? '');

if (!$event_id || $telephone === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Numéro et code requis.']);
    exit();
}

$result = verifyOtp($pdo, $event_id, $telephone, $code);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit();
}

// Débloque l'achat pour ce téléphone sur cet événement, valable 30 minutes
if (!isset($_SESSION['whitelist_verified']) || !is_array($_SESSION['whitelist_verified'])) {
    $_SESSION['whitelist_verified'] = [];
}
$_SESSION['whitelist_verified'][$event_id] = [
    'telephone' => normalizePhone($telephone),
    'expires' => time() + (30 * 60),
];

echo json_encode(['success' => true, 'message' => 'Numéro vérifié. Vous pouvez finaliser votre achat.']);
