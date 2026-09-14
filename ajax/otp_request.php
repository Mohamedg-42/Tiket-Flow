<?php
/**
 * AJAX Endpoint : Génération et envoi du code OTP par SMS
 * ajax/otp_request.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SmsService.php';

use Services\SmsService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Méthode non autorisée.']);
    exit();
}

$event_id  = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$telephone = trim($_POST['telephone'] ?? '');
$token     = trim($_POST['token'] ?? '');

if (!$event_id || empty($telephone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Paramètres requis manquants.']);
    exit();
}

$phoneClean = SmsService::normalizePhone($telephone);
if (empty($phoneClean)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_PHONE', 'message' => 'Numéro de téléphone invalide.']);
    exit();
}

try {
    // 1. Contrôle événement
    $stmt = $pdo->prepare("SELECT id, nom, visibilite, access_token, requires_whitelist, statut FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event || $event['statut'] !== 'actif') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'EVENT_NOT_AVAILABLE', 'message' => 'Événement non disponible.']);
        exit();
    }

    if ($event['visibilite'] === 'prive') {
        if (empty($token) || $token !== $event['access_token']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Accès direct non autorisé.']);
            exit();
        }
    }

    // 2. Contrôle Whitelist si requise
    if (!empty($event['requires_whitelist'])) {
        $rawPhone = preg_replace('/[^\d]/', '', $telephone);
        $stmt_wl = $pdo->prepare("
            SELECT * FROM guest_whitelists 
            WHERE event_id = ? 
              AND (telephone = ? OR telephone = ? OR telephone LIKE ?)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_wl->execute([$event_id, $phoneClean, $telephone, '%' . substr($rawPhone, -8)]);
        $guest = $stmt_wl->fetch(PDO::FETCH_ASSOC);

        if (!$guest) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'NOT_WHITELISTED', 'message' => 'Ce numéro n\'est pas sur la liste des invités.']);
            exit();
        }

        if ((int)$guest['tickets_authorized'] <= (int)$guest['tickets_purchased']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'QUOTA_EXHAUSTED', 'message' => 'Quota de tickets épuisé.']);
            exit();
        }
    }

    // 3. Throttling anti-spam : vérifier si un code a été demandé il y a moins de 60 secondes
    $stmt_recent = $pdo->prepare("
        SELECT created_at 
        FROM otp_verifications 
        WHERE event_id = ? AND telephone = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_recent->execute([$event_id, $phoneClean]);
    $lastOtp = $stmt_recent->fetch(PDO::FETCH_ASSOC);

    if ($lastOtp) {
        $secondsAgo = time() - strtotime($lastOtp['created_at']);
        if ($secondsAgo < 60) {
            $waitTime = 60 - $secondsAgo;
            http_response_code(429);
            echo json_encode([
                'success'    => false,
                'error'      => 'RATE_LIMITED',
                'wait_time'  => $waitTime,
                'message'    => "Veuillez patienter {$waitTime} seconde(s) avant de demander un nouveau code."
            ]);
            exit();
        }
    }

    // 4. Génération du code OTP et hachage
    $otpCode = SmsService::generateOtp(6);
    $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Invalidation des anciens codes non vérifiés pour ce couple (event, phone)
    $pdo->prepare("DELETE FROM otp_verifications WHERE event_id = ? AND telephone = ? AND is_verified = FALSE")
        ->execute([$event_id, $phoneClean]);

    // Insertion du nouvel OTP (validité 5 minutes)
    $stmt_ins = $pdo->prepare("
        INSERT INTO otp_verifications (
            event_id, telephone, otp_code, otp_hash, expires_at, attempts, max_attempts, is_verified, ip_address
        ) VALUES (
            ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '5 minutes', 0, 3, FALSE, ?
        )
    ");
    $stmt_ins->execute([$event_id, $phoneClean, $otpCode, $otpHash, $ip]);

    // 5. Envoi par SMS
    $smsResult = SmsService::sendOtp($phoneClean, $otpCode, $event['nom']);

    echo json_encode([
        'success'   => true,
        'message'   => "Code de vérification envoyé par SMS au {$phoneClean}.",
        'telephone' => $phoneClean,
        'expires_in'=> 300, // 5 minutes
        'debug_otp' => $smsResult['code'] ?? null // Disponible uniquement en environnement local
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Erreur technique lors de l\'envoi de l\'OTP.']);
}
