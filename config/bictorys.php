<?php
// ==============================================================================
// CONFIGURATION & CLIENT API BICTORYS (config/bictorys.php)
// Passerelle de paiement Mobile Money (Wave, Orange, MTN, Moov) & Cartes Bancaires
// Documentation officielle : https://docs.bictorys.com/docs/integration
// ==============================================================================

require_once __DIR__ . '/env.php';

if (!defined('BICTORYS_ENV')) {
    define('BICTORYS_ENV', getenv('BICTORYS_ENV') ?: 'test');
}

if (!defined('BICTORYS_API_URL')) {
    $default_url = (BICTORYS_ENV === 'live' || BICTORYS_ENV === 'production')
        ? 'https://api.bictorys.com'
        : 'https://api.test.bictorys.com';
    define('BICTORYS_API_URL', rtrim(getenv('BICTORYS_API_URL') ?: $default_url, '/'));
}

if (!defined('BICTORYS_API_KEY')) {
    define('BICTORYS_API_KEY', getenv('BICTORYS_API_KEY') ?: 'test_public-1f408b1c-a65b-421a-bd8b-703b7cdfca1e.IbcwtwZHxjD0DzWDsS5Oc9idxdq3lruXFzl0JXCM4dlW3VfDa1j6kv2GS8S4wFrB');
}

if (!defined('BICTORYS_PRIVATE_KEY')) {
    define('BICTORYS_PRIVATE_KEY', getenv('BICTORYS_PRIVATE_KEY') ?: '');
}

if (!defined('BICTORYS_WEBHOOK_SECRET')) {
    define('BICTORYS_WEBHOOK_SECRET', getenv('BICTORYS_WEBHOOK_SECRET') ?: '');
}

if (!defined('BICTORYS_COUNTRY')) {
    define('BICTORYS_COUNTRY', getenv('BICTORYS_COUNTRY') ?: 'CI');
}

/**
 * Normalise un numéro de téléphone au format strict requis par Bictorys (+INDICATIFNUMERO sans espaces)
 * Exemples : 
 *   "0701234567" -> "+2250701234567"
 *   "225 07 01 23 45 67" -> "+2250701234567"
 *   "+225 0701234567" -> "+2250701234567"
 *
 * @param string $phone Numéro de téléphone brut
 * @param string $country Code pays ISO (CI, SN, BF, ML, TG, BJ)
 * @return string Numéro formaté
 */
function bictorys_format_phone(string $phone, string $country = 'CI'): string
{
    $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
    if (empty($cleaned)) {
        return '';
    }

    $indicatifs = [
        'CI' => '225',
        'SN' => '221',
        'BF' => '226',
        'ML' => '223',
        'TG' => '228',
        'BJ' => '229'
    ];
    $ind = $indicatifs[strtoupper($country)] ?? '225';

    if (strpos($cleaned, '+') === 0) {
        return $cleaned;
    }

    if (strpos($cleaned, $ind) === 0) {
        return '+' . $cleaned;
    }

    return '+' . $ind . $cleaned;
}

/**
 * Initie une session de paiement (Charge) auprès de l'API Bictorys
 *
 * @param array $params Paramètres de la charge :
 *   - amount (int, requis) : Montant en FCFA
 *   - paymentReference (string, requis) : Référence unique (ex: ORDER_123)
 *   - successRedirectUrl (string, requis) : URL de retour après paiement
 *   - errorRedirectUrl (string, requis) : URL de retour après échec
 *   - country (string, optionnel) : Code pays (par défaut BICTORYS_COUNTRY)
 *   - customer (array, optionnel) : ['name', 'phone', 'email']
 *   - payment_type (string, optionnel) : 'wave_money', 'orange_money', 'card', etc.
 *   - otp (string, optionnel) : Code OTP pour Orange Money CI direct
 * @return array ['success' => bool, 'transactionId' => string, 'redirectUrl' => string, 'link' => string, 'error' => string]
 */
function bictorys_create_charge(array $params): array
{
    $apiUrl = BICTORYS_API_URL;
    $apiKey = BICTORYS_API_KEY;

    if (empty($apiKey)) {
        return [
            'success' => false,
            'error' => "Clé API Bictorys non configurée (BICTORYS_API_KEY manquante)."
        ];
    }

    $country = strtoupper($params['country'] ?? BICTORYS_COUNTRY);
    $amount = (int) round($params['amount'] ?? 0);

    // Endpoint : avec ou sans payment_type
    $endpoint = $apiUrl . '/pay/v1/charges';
    if (!empty($params['payment_type'])) {
        $pt = $params['payment_type'];
        $query = ($pt === 'card')
            ? 'payment_type=card&payment_category=card'
            : 'payment_type=' . urlencode($pt);
        $endpoint .= '?' . $query;
    }

    // Préparation du corps JSON
    $body = [
        'amount' => $amount,
        'currency' => 'XOF',
        'country' => $country,
        'paymentReference' => (string) ($params['paymentReference'] ?? ('REF-' . uniqid())),
        'successRedirectUrl' => (string) ($params['successRedirectUrl'] ?? ''),
        'ErrorRedirectUrl' => (string) ($params['errorRedirectUrl'] ?? $params['successRedirectUrl'] ?? '') // E majuscule exigé par Bictorys
    ];

    // Objet client
    if (!empty($params['customer']) && is_array($params['customer'])) {
        $custPhone = bictorys_format_phone($params['customer']['phone'] ?? '', $country);
        $body['customerObject'] = array_filter([
            'name' => trim($params['customer']['name'] ?? 'Client'),
            'phone' => $custPhone,
            'email' => trim($params['customer']['email'] ?? ''),
            'country' => $country
        ]);
    }

    // OTP pour Orange Money CI si renseigné
    if (!empty($params['otp'])) {
        $body['otp'] = trim($params['otp']);
    }

    $jsonPayload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Exécution de la requête HTTP cURL avec gestion de retries (WAF 403)
    $maxRetries = 2;
    $lastHttpCode = 0;
    $lastResponse = '';

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        if ($attempt > 0) {
            usleep((int) (pow(2, $attempt) * 500000)); // 1s, 2s
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $lastResponse = curl_exec($ch);
        $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Bictorys cURL error (tentative $attempt): " . $curlErr);
            continue;
        }

        // Si WAF Cloudflare / AWS 403 Forbidden temporaire, réessayer
        if ($lastHttpCode === 403 && stripos($lastResponse, 'Forbidden') !== false && $attempt < $maxRetries) {
            continue;
        }

        break;
    }

    $data = json_decode($lastResponse, true);

    if ($lastHttpCode >= 200 && $lastHttpCode < 300 && is_array($data)) {
        $txId = $data['transactionId'] ?? $data['chargeId'] ?? $data['id'] ?? '';
        $redirectUrl = $data['redirectUrl'] ?? $data['link'] ?? '';
        return [
            'success' => true,
            'transactionId' => $txId,
            'chargeId' => $data['chargeId'] ?? $txId,
            'redirectUrl' => $redirectUrl,
            'link' => $data['link'] ?? $redirectUrl,
            'opToken' => $data['opToken'] ?? '',
            'qrCode' => $data['qrCode'] ?? null,
            'message' => $data['message'] ?? null,
            'raw' => $data
        ];
    }

    $errMsg = "Erreur Bictorys ($lastHttpCode)";
    if (is_array($data) && !empty($data['message'])) {
        $errMsg = $data['message'];
    } elseif (!empty($lastResponse)) {
        $errMsg .= " : " . substr(strip_tags($lastResponse), 0, 150);
    }

    error_log("Bictorys create_charge failed: " . $errMsg . " | Payload: " . $jsonPayload);

    return [
        'success' => false,
        'httpCode' => $lastHttpCode,
        'error' => $errMsg,
        'raw' => $data
    ];
}

/**
 * Vérifie le statut d'une transaction ou charge auprès de l'API Bictorys
 * Endpoints : 
 *   - GET /pay/v1/transactions/{id}/status
 *   - Fallback GET /pay/v1/charges/{id}
 *
 * @param string $transactionId UUID de la transaction ou chargeId Bictorys
 * @return array|null Données du statut ou null si erreur
 */
function bictorys_verify_transaction(string $transactionId): ?array
{
    $transactionId = trim($transactionId);
    if (empty($transactionId)) {
        return null;
    }

    $apiUrl = BICTORYS_API_URL;
    $apiKey = BICTORYS_API_KEY;

    $endpoints = [
        $apiUrl . '/pay/v1/transactions/' . rawurlencode($transactionId) . '/status',
        $apiUrl . '/pay/v1/charges/' . rawurlencode($transactionId)
    ];

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Bictorys status check cURL Error ($url): " . $curlErr);
            continue;
        }

        if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    error_log("Bictorys status check failed for ID: " . $transactionId);
    return null;
}

/**
 * Vérifie l'authenticité d'un webhook entrant provenant de Bictorys
 *
 * @param string $rawBody Corps brut de la requête webhook
 * @param array $headers En-têtes HTTP reçus (clés en minuscules)
 * @return bool True si la signature ou la clé secrète est valide
 */
function bictorys_verify_webhook(string $rawBody, array $headers): bool
{
    $webhookSecret = BICTORYS_WEBHOOK_SECRET;

    // Si aucun secret n'est configuré en environnement de test, tolérance contrôlée
    if (empty($webhookSecret)) {
        if (BICTORYS_ENV === 'test') {
            error_log("⚠️ Bictorys Webhook: BICTORYS_WEBHOOK_SECRET non configuré en mode TEST - acceptation conditionnelle.");
            return true;
        }
        return false;
    }

    $sigHeader = $headers['x-webhook-signature'] ?? $headers['X-Webhook-Signature'] ?? null;
    $tsHeader = $headers['x-webhook-timestamp'] ?? $headers['X-Webhook-Timestamp'] ?? null;
    $keyHeader = $headers['x-secret-key'] ?? $headers['X-Secret-Key'] ?? null;

    // Méthode 1 : Signature HMAC-SHA256
    if (!empty($sigHeader) && !empty($tsHeader)) {
        $ts = (int) $tsHeader;
        // Protection anti-rejeu (replay attack) : 5 minutes
        $now = time();
        $tsSeconds = ($ts > 9999999999) ? (int) ($ts / 1000) : $ts;
        if (abs($now - $tsSeconds) > 300) {
            error_log("Bictorys Webhook: Replay attack protection déclenchée (timestamp trop ancien ou dans le futur).");
            return false;
        }

        $expectedSig = hash_hmac('sha256', $tsHeader . '.' . $rawBody, $webhookSecret);
        return hash_equals($expectedSig, $sigHeader);
    }

    // Méthode 2 : Clé secrète statique X-Secret-Key
    if (!empty($keyHeader)) {
        return hash_equals($webhookSecret, $keyHeader);
    }

    return false;
}
