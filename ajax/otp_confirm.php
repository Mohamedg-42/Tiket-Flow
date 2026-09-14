<?php
/**
 * AJAX Endpoint : Validation du code OTP soumis par l'acheteur
 * ajax/otp_confirm.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SmsService.php';

use Services\SmsService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Méthode non autorisée.']);
    exit();
}

$event_id  = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$telephone = trim($_POST['telephone'] ?? '');
$otpCode   = trim($_POST['otp_code'] ?? '');

if (!$event_id || empty($telephone) || empty($otpCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Code OTP ou numéro manquant.']);
    exit();
}

$phoneClean = SmsService::normalizePhone($telephone);

try {
    // 1. Recherche du dernier enregistrement OTP actif pour ce couple
    $stmt = $pdo->prepare("
        SELECT id, otp_code, otp_hash, expires_at, attempts, max_attempts, is_verified 
        FROM otp_verifications 
        WHERE event_id = ? AND telephone = ? AND is_verified = FALSE
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$event_id, $phoneClean]);
    $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRecord) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'NO_ACTIVE_OTP',
            'message' => 'Aucun code de vérification actif trouvé. Veuillez demander un code.'
        ]);
        exit();
    }

    // 2. Vérification des tentatives
    $attempts    = (int) $otpRecord['attempts'];
    $maxAttempts = (int) $otpRecord['max_attempts'];

    if ($attempts >= $maxAttempts) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'TOO_MANY_ATTEMPTS',
            'message' => 'Nombre maximal de tentatives infructueuses atteint. Veuillez générer un nouveau code.'
        ]);
        exit();
    }

    // 3. Vérification de l'expiration
    $isExpired = strtotime($otpRecord['expires_at']) < time();
    if ($isExpired) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'EXPIRED_OTP',
            'message' => 'Ce code de vérification a expiré. Veuillez en redemander un.'
        ]);
        exit();
    }

    // 4. Comparaison du code (par hachage bCrypt et fallback direct)
    $isValid = password_verify($otpCode, $otpRecord['otp_hash']) || hash_equals((string)$otpRecord['otp_code'], (string)$otpCode);

    if (!$isValid) {
        $attempts++;
        $pdo->prepare("UPDATE otp_verifications SET attempts = ? WHERE id = ?")
            ->execute([$attempts, $otpRecord['id']]);

        $left = max(0, $maxAttempts - $attempts);
        http_response_code(400);
        echo json_encode([
            'success'       => false,
            'error'         => 'INVALID_OTP',
            'attempts_left' => $left,
            'message'       => $left > 0 
                ? "Code incorrect. Il vous reste {$left} tentative(s)."
                : "Code incorrect. Nombre maximal de tentatives atteint."
        ]);
        exit();
    }

    // 5. Code valide : génération du jeton de vérification
    $verificationToken = SmsService::generateToken();
    $stmt_success = $pdo->prepare("
        UPDATE otp_verifications 
        SET is_verified = TRUE, 
            verified_at = CURRENT_TIMESTAMP, 
            verification_token = ? 
        WHERE id = ?
    ");
    $stmt_success->execute([$verificationToken, $otpRecord['id']]);

    // Enregistrement de la preuve de vérification en session
    if (!isset($_SESSION['verified_otp'])) {
        $_SESSION['verified_otp'] = [];
    }
    $_SESSION['verified_otp'][$event_id] = [
        'token'       => $verificationToken,
        'telephone'   => $phoneClean,
        'verified_at' => time()
    ];

    echo json_encode([
        'success'            => true,
        'verified'           => true,
        'verification_token' => $verificationToken,
        'telephone'          => $phoneClean,
        'message'            => 'Identité vérifiée avec succès ! Vous pouvez maintenant choisir vos billets.'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Erreur technique lors de la validation.']);
}
