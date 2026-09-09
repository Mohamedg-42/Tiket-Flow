<?php
// ==============================================================================
// CONFIGURATION KADEV PAY (config/kadevpay.php)
// Passerelle de paiement Mobile Money & Cartes (Côte d'Ivoire & UEMOA)
// Documentation : https://pay.kadev.ci
// ==============================================================================

if (!defined('KADEVPAY_PUBLIC_KEY')) {
    define('KADEVPAY_PUBLIC_KEY', getenv('KADEVPAY_PUBLIC_KEY') ?: 'kdvp_test_427cfdc9ffc45dcd05a0294f61f79de0');
}

if (!defined('KADEVPAY_SECRET_KEY')) {
    define('KADEVPAY_SECRET_KEY', getenv('KADEVPAY_SECRET_KEY') ?: 'kdvs_test_c7d8e5efe248e8d18b2654f3a0dce44f285b53e59cd1c082');
}

if (!defined('KADEVPAY_WEBHOOK_SECRET')) {
    define('KADEVPAY_WEBHOOK_SECRET', getenv('KADEVPAY_WEBHOOK_SECRET') ?: '');
}

if (!defined('KADEVPAY_ENV')) {
    define('KADEVPAY_ENV', 'test'); // 'test' ou 'live'
}

if (!defined('KADEVPAY_API_URL')) {
    define('KADEVPAY_API_URL', 'https://pay.kadev.ci/api/v1');
}

if (!defined('KADEVPAY_SDK_URL')) {
    define('KADEVPAY_SDK_URL', 'https://pay.kadev.ci/js/v1/kadev-pay.js');
}

/**
 * Vérifier le statut d'une transaction auprès de l'API Kadev Pay
 *
 * @param string $reference Référence unique ou transaction ID Kadev Pay
 * @return array|null Tableau de réponse ou null en cas d'erreur de communication
 */
function kadevpay_verify_transaction(string $reference): ?array {
    $reference = trim($reference);
    if (empty($reference)) {
        return null;
    }

    $secretKey = KADEVPAY_SECRET_KEY;
    $url = rtrim(KADEVPAY_API_URL, '/') . '/transactions/verify/' . rawurlencode($reference);

    if (!function_exists('curl_init')) {
        error_log("KadevPay: cURL non disponible sur ce serveur.");
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false // Tolérance locale de certificat si nécessaire
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("KadevPay cURL Error: " . $curlErr);
        return null;
    }

    if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            return $data;
        }
    }

    error_log("KadevPay verification failed HTTP $httpCode: " . $response);
    return null;
}
