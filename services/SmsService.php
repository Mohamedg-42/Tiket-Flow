<?php
/**
 * Service d'envoi et de validation des SMS / OTP
 * Supporte le mode local/simulation (log) et les passerelles SMS de production (HTTP REST/cURL)
 */

namespace Services;

class SmsService
{
    private static ?string $logFile = null;

    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            self::$logFile = $dir . DIRECTORY_SEPARATOR . 'sms_otp.log';
        }
        return self::$logFile;
    }

    /**
     * Normalise un numéro de téléphone pour comparaison et envoi fiables
     * Exemple : "07 01 02 03 04" -> "+2250701020304"
     */
    public static function normalizePhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }

        // Supprime tout sauf les chiffres et le '+' initial
        $clean = preg_replace('/[^\d+]/', '', trim($phone));

        // Remplace 00 par + au début
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }

        // Si format local 10 chiffres (Côte d'Ivoire), préfixer par +225
        if (preg_match('/^0[1-9]\d{8}$/', $clean)) {
            $clean = '+225' . $clean;
        } elseif (preg_match('/^[1-9]\d{9}$/', $clean) && !str_starts_with($clean, '+')) {
            $clean = '+' . $clean;
        }

        return $clean;
    }

    /**
     * Génère un code OTP aléatoire cryptographiquement sécurisé à 6 chiffres
     */
    public static function generateOtp(int $length = 6): string
    {
        $min = (int) pow(10, $length - 1);
        $max = (int) (pow(10, $length) - 1);
        return (string) random_int($min, $max);
    }

    /**
     * Génère un jeton secret de session / vérification (UUID/Hex 64 caractères)
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Envoie un code OTP par SMS
     * En développement ou absence de passerelle externe, écrit dans le journal logs/sms_otp.log
     */
    public static function sendOtp(string $telephone, string $code, string $eventName = 'Événement'): array
    {
        $phoneNorm = self::normalizePhone($telephone);
        if (empty($phoneNorm)) {
            return [
                'success' => false,
                'error' => 'Numéro de téléphone invalide.'
            ];
        }

        $message = "Votre code de confirmation Tike WA pour {$eventName} est : {$code}. Valable 5 minutes. Ne le partagez jamais.";

        // Vérifie si une passerelle SMS externe est configurée dans l'environnement
        $gatewayUrl = getenv('SMS_GATEWAY_URL');
        $apiKey     = getenv('SMS_API_KEY');

        $sentViaGateway = false;
        $gatewayResponse = null;

        if (!empty($gatewayUrl) && !empty($apiKey)) {
            try {
                $ch = curl_init($gatewayUrl);
                $payload = json_encode([
                    'to'      => $phoneNorm,
                    'message' => $message,
                    'sender'  => 'Tike WA'
                ]);

                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5
                ]);

                $gatewayResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    $sentViaGateway = true;
                }
            } catch (\Throwable $t) {
                // Fallback direct sur le journal local
                error_log("Erreur Gateway SMS: " . $t->getMessage());
            }
        }

        // Journalisation locale systématique (audit trail & environnement de dev)
        $logLine = sprintf(
            "[%s] OTP envoyé à %s | Code: %s | Événement: %s | Passerelle: %s\n",
            date('Y-m-d H:i:s'),
            $phoneNorm,
            $code,
            $eventName,
            $sentViaGateway ? 'Externe' : 'Simulation Log'
        );
        @file_put_contents(self::getLogFile(), $logLine, FILE_APPEND | LOCK_EX);

        return [
            'success'   => true,
            'telephone' => $phoneNorm,
            'code'      => (getenv('APP_ENV') === 'development' || empty($gatewayUrl)) ? $code : null, // Renvoyé en dev pour faciliter le test
            'mode'      => $sentViaGateway ? 'live' : 'simulation'
        ];
    }
}
