<?php
// ==============================================================================
// GESTIONNAIRE CENTRAL DE SMS (includes/sms.php)
// Envoi de SMS transactionnels (codes OTP) via une passerelle HTTP générique.
// Configurable via .env : SMS_API_URL, SMS_API_KEY, SMS_SENDER
// Sans fournisseur configuré : le SMS est journalisé dans logs/sms.log
// (utile en développement / tant qu'aucun compte SMS n'est branché).
// ==============================================================================

/**
 * Normalise un numéro de téléphone pour comparaison/stockage fiable :
 * conserve uniquement les chiffres, retire les zéros de tête après l'indicatif
 * pays si présent, et force le préfixe indicatif Côte d'Ivoire (+225) par défaut
 * si aucun indicatif international n'est fourni.
 */
function normalizePhone(string $telephone): string {
    $digits = preg_replace('/\D+/', '', $telephone);
    if ($digits === '') {
        return '';
    }
    // Déjà préfixé par un indicatif international plausible (10-15 chiffres avec 225/autre)
    if (strlen($digits) >= 12 && strpos($digits, '225') === 0) {
        return '+' . $digits;
    }
    if (strlen($digits) === 10) {
        // Numéro local ivoirien à 10 chiffres (ex: 0748365690) -> +225 748365690
        $digits = ltrim($digits, '0');
        return '+225' . $digits;
    }
    if (strlen($digits) >= 8 && strlen($digits) <= 9) {
        return '+225' . $digits;
    }
    return '+' . ltrim($digits, '+');
}

/**
 * Envoie un SMS via la passerelle configurée dans .env.
 * Retourne true si l'envoi a réussi (ou a été journalisé faute de fournisseur configuré).
 */
function sendSms(string $telephone, string $message): bool {
    $telephone = normalizePhone($telephone);
    if ($telephone === '') {
        return false;
    }

    $api_url = getenv('SMS_API_URL') ?: '';
    $api_key = getenv('SMS_API_KEY') ?: '';
    $sender  = getenv('SMS_SENDER') ?: 'TikeWA';

    // Aucun fournisseur SMS configuré : on journalise pour ne pas bloquer les tests
    // (le code OTP reste consultable dans logs/sms.log tant qu'un vrai fournisseur
    // n'est pas branché en production).
    if (empty($api_url)) {
        return logSmsFallback($telephone, $message);
    }

    try {
        $payload = json_encode([
            'to' => $telephone,
            'sender' => $sender,
            'message' => $message,
        ]);

        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            error_log("Tike WA SMS: erreur cURL vers la passerelle — $curl_error");
            return logSmsFallback($telephone, $message);
        }

        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }

        error_log("Tike WA SMS: la passerelle a répondu HTTP $http_code — $response");
        return logSmsFallback($telephone, $message);
    } catch (\Throwable $e) {
        error_log("Tike WA SMS: exception lors de l'envoi — " . $e->getMessage());
        return logSmsFallback($telephone, $message);
    }
}

/**
 * Journalise le SMS dans logs/sms.log lorsqu'aucun fournisseur n'est configuré
 * ou que l'envoi réel a échoué. Permet de continuer à tester le parcours OTP
 * sans compte SMS actif.
 */
function logSmsFallback(string $telephone, string $message): bool {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $line = sprintf("[%s] TO=%s MESSAGE=%s\n", date('Y-m-d H:i:s'), $telephone, $message);
    @file_put_contents($logDir . '/sms.log', $line, FILE_APPEND);
    return true;
}
