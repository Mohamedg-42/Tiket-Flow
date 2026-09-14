<?php
// ==============================================================================
// LOGIQUE MÉTIER — Liste d'invités (whitelist) & vérification OTP
// pour les événements privés/restreints (includes/whitelist.php)
// ==============================================================================

require_once __DIR__ . '/sms.php';

const OTP_VALIDITY_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN_SECONDS = 60;

/**
 * Vérifie qu'un numéro de téléphone figure dans la liste d'invités autorisés
 * d'un événement privé et qu'il lui reste des places disponibles.
 *
 * @return array{eligible:bool, message:string, remaining?:int, whitelist_id?:int}
 */
function checkWhitelistEligibility(PDO $pdo, int $event_id, string $telephone): array {
    $telephone = normalizePhone($telephone);
    if ($telephone === '') {
        return ['eligible' => false, 'message' => 'Numéro de téléphone invalide.'];
    }

    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, tickets_autorises, tickets_utilises
        FROM event_guest_whitelist
        WHERE event_id = ? AND telephone = ?
    ");
    $stmt->execute([$event_id, $telephone]);
    $guest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$guest) {
        return [
            'eligible' => false,
            'message' => "Ce numéro ne figure pas sur la liste des invités autorisés pour cet événement.",
        ];
    }

    $remaining = (int) $guest['tickets_autorises'] - (int) $guest['tickets_utilises'];
    if ($remaining <= 0) {
        return [
            'eligible' => false,
            'message' => "Vous avez déjà utilisé la totalité de vos billets autorisés pour cet événement.",
        ];
    }

    return [
        'eligible' => true,
        'message' => 'Numéro éligible.',
        'remaining' => $remaining,
        'whitelist_id' => (int) $guest['id'],
    ];
}

/**
 * Génère un code OTP à 6 chiffres, l'enregistre (en invalidant les codes
 * précédents non utilisés pour ce couple téléphone/événement) et l'envoie par SMS.
 *
 * @return array{success:bool, message:string}
 */
function createAndSendOtp(PDO $pdo, int $event_id, string $telephone, string $purpose = 'whitelist_achat'): array {
    $telephone = normalizePhone($telephone);
    if ($telephone === '') {
        return ['success' => false, 'message' => 'Numéro de téléphone invalide.'];
    }

    // Anti-spam : empêcher de redemander un code trop fréquemment
    $stmt_last = $pdo->prepare("
        SELECT created_at FROM otp_verifications
        WHERE telephone = ? AND event_id = ? AND purpose = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_last->execute([$telephone, $event_id, $purpose]);
    $last = $stmt_last->fetchColumn();
    if ($last && (time() - strtotime($last)) < OTP_RESEND_COOLDOWN_SECONDS) {
        $wait = OTP_RESEND_COOLDOWN_SECONDS - (time() - strtotime($last));
        return ['success' => false, 'message' => "Veuillez patienter encore {$wait}s avant de redemander un code."];
    }

    $pdo->prepare("UPDATE otp_verifications SET used = 1 WHERE telephone = ? AND event_id = ? AND purpose = ? AND used = 0")
        ->execute([$telephone, $event_id, $purpose]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $stmt_ins = $pdo->prepare("
        INSERT INTO otp_verifications (telephone, event_id, code, purpose, expires_at)
        VALUES (?, ?, ?, ?, NOW() + INTERVAL '" . ((int) OTP_VALIDITY_MINUTES) . " minutes')
    ");
    $stmt_ins->execute([$telephone, $event_id, $code, $purpose]);

    $message = "Tike WA : votre code de vérification est {$code}. Il expire dans " . OTP_VALIDITY_MINUTES . " minutes. Ne le partagez avec personne.";
    $sent = sendSms($telephone, $message);

    if (!$sent) {
        return ['success' => false, 'message' => "Impossible d'envoyer le SMS pour le moment. Réessayez."];
    }

    return ['success' => true, 'message' => 'Code envoyé par SMS.'];
}

/**
 * Vérifie le code OTP saisi par l'acheteur.
 *
 * @return array{success:bool, message:string}
 */
function verifyOtp(PDO $pdo, int $event_id, string $telephone, string $code, string $purpose = 'whitelist_achat'): array {
    $telephone = normalizePhone($telephone);
    $code = trim($code);

    if ($telephone === '' || $code === '') {
        return ['success' => false, 'message' => 'Numéro ou code invalide.'];
    }

    $stmt = $pdo->prepare("
        SELECT * FROM otp_verifications
        WHERE telephone = ? AND event_id = ? AND purpose = ? AND used = 0
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$telephone, $event_id, $purpose]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otp) {
        return ['success' => false, 'message' => "Aucun code en attente pour ce numéro. Demandez un nouveau code."];
    }

    if (strtotime($otp['expires_at']) < time()) {
        return ['success' => false, 'message' => 'Ce code a expiré. Demandez un nouveau code.'];
    }

    if ((int) $otp['attempts'] >= OTP_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE otp_verifications SET used = 1 WHERE id = ?")->execute([$otp['id']]);
        return ['success' => false, 'message' => 'Trop de tentatives. Demandez un nouveau code.'];
    }

    if (!hash_equals($otp['code'], $code)) {
        $pdo->prepare("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?")->execute([$otp['id']]);
        $remaining_attempts = OTP_MAX_ATTEMPTS - ((int) $otp['attempts'] + 1);
        return ['success' => false, 'message' => "Code incorrect. Tentative(s) restante(s) : {$remaining_attempts}."];
    }

    $pdo->prepare("UPDATE otp_verifications SET used = 1 WHERE id = ?")->execute([$otp['id']]);

    return ['success' => true, 'message' => 'Numéro vérifié avec succès.'];
}
