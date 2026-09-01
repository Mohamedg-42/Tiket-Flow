<?php
// ==============================================================================
// CLIENT SMTP NATIF PHP (includes/smtp.php)
// Envoie des emails via une connexion SMTP directe (stream_socket_client),
// avec support AUTH LOGIN, STARTTLS et SSL. Aucune dépendance externe.
// ==============================================================================

if (!function_exists('smtp_send')) {

    /**
     * Envoie un email via SMTP (config/smtp.php) ou via mail() en dernier recours.
     *
     * @param string $to_email  Destinataire
     * @param string $to_name   Nom du destinataire
     * @param string $subject   Sujet
     * @param string $headers   En-têtes complets (MIME inclus) séparés par \r\n
     * @param string $body      Corps du message (MIME : texte + pièces jointes)
     * @return array{ok:bool,error:string} Résultat exploitable + message
     */
    function smtp_send(string $to_email, string $to_name, string $subject, string $headers, string $body): array
    {
        require_once __DIR__ . '/../config/smtp.php';

        // Chemin de journalisation
        $log_dir  = dirname(__DIR__) . '/logs';
        $log_file = $log_dir . '/mail.log';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0775, true);
        }

        $log = function (string $msg) use ($log_file) {
            @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
        };

        // -- Voie 1 : SMTP configuré --
        if (SMTP_HOST !== '') {
            try {
                $res = smtp_via_connect($to_email, $to_name, $subject, $headers, $body);
                $log('SMTP envoi -> ' . ($res['ok'] ? 'OK vers ' . $to_email : 'ECHEC : ' . $res['error']));
                return $res;
            } catch (Throwable $e) {
                $log('SMTP exception : ' . $e->getMessage());
                return ['ok' => false, 'error' => 'SMTP exception : ' . $e->getMessage()];
            }
        }

        // -- Voie 2 : fallback mail() --
        $result = @mail($to_email, $subject, $body, $headers);
        $err = error_get_last();
        $log($result
            ? 'mail() OK vers ' . $to_email
            : 'mail() ECHEC (' . ($err['message'] ?? 'aucune info') . ')');
        return ['ok' => (bool)$result, 'error' => $result ? '' : ($err['message'] ?? 'mail() a échoué')];
    }
/**
     * Connexion SMTP réelle (avec TLS/SSL) + AUTH + DATA.
     */
    function smtp_via_connect(string $to_email, string $to_name, string $subject, string $headers, string $body): array
    {
        $host   = SMTP_HOST;
        $port   = SMTP_PORT;
        $secure = SMTP_SECURE;

        // Encodage du nom dans l'en-tête To (évite tout caractère parasite)
        $to_name_safe = preg_replace('/[^A-Za-z0-9À-ÿ _.\-\']/', '', $to_name);

        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $conn = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($conn === false) {
            return ['ok' => false, 'error' => "Connexion SMTP impossible ($host:$port) : $errstr ($errno)"];
        }
        stream_set_timeout($conn, 30);
        // Attente de la bannière (220)
        $resp = fgets($conn);
        if (strpos((string)$resp, '220') !== 0) {
            fclose($conn);
            return ['ok' => false, 'error' => 'Réponse initiale SMTP inattendue : ' . trim((string)$resp)];
        }

        $cmd = function (string $c) use ($conn, &$ok, &$errline) {
            fwrite($conn, $c . "\r\n");
            $r = '';
            while ($line = fgets($conn)) {
                $r .= $line;
                if (isset($line[3]) && $line[3] === ' ') { break; }
            }
            $ok = (int)substr($r, 0, 3) >= 200 && (int)substr($r, 0, 3) < 400;
            $errline = trim($r);
            return $r;
        };

        $cmd('EHLO ticketflow.local');
        if (!$ok) {
            fclose($conn);
            return ['ok' => false, 'error' => 'EHLO refusé : ' . $errline];
        }

        // STARTTLS si 'tls'
        if ($secure === 'tls') {
            $cmd('STARTTLS');
            if (!$ok) {
                fclose($conn);
                return ['ok' => false, 'error' => 'STARTTLS refusé : ' . $errline];
            }
            $r = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($r !== true) {
                fclose($conn);
                return ['ok' => false, 'error' => 'Échec du handshake STARTTLS'];
            }
            $cmd('EHLO ticketflow.local');
            if (!$ok) {
                fclose($conn);
                return ['ok' => false, 'error' => 'EHLO après STARTTLS refusé : ' . $errline];
            }
        }

        // AUTH LOGIN
        if (SMTP_USER !== '') {
            $cmd('AUTH LOGIN');
            if (!$ok) {
                fclose($conn);
                return ['ok' => false, 'error' => 'AUTH LOGIN refusé : ' . $errline];
            }
            fwrite($conn, base64_encode(SMTP_USER) . "\r\n");
            $r = fgets($conn);
            if (strpos((string)$r, '334') !== 0) {
                fclose($conn);
                return ['ok' => false, 'error' => 'Nom d\'utilisateur SMTP refusé : ' . trim((string)$r)];
            }
            fwrite($conn, base64_encode(SMTP_PASS) . "\r\n");
            $r = fgets($conn);
            if (strpos((string)$r, '235') !== 0) {
                fclose($conn);
                return ['ok' => false, 'error' => 'Authentification SMTP échouée : ' . trim((string)$r)];
            }
        }
// MAIL FROM / RCPT TO
        $cmd('MAIL FROM:<' . SMTP_FROM . '>');
        if (!$ok) {
            fclose($conn);
            return ['ok' => false, 'error' => 'MAIL FROM refusé : ' . $errline];
        }
        $cmd('RCPT TO:<' . $to_email . '>');
        if (!$ok) {
            fclose($conn);
            return ['ok' => false, 'error' => 'RCPT TO refusé : ' . $errline];
        }

        // DATA
        $cmd('DATA');
        if (!$ok) {
            fclose($conn);
            return ['ok' => false, 'error' => 'DATA refusé : ' . $errline];
        }

        // Reconstruction des en-têtes avec le bon From/To
        $headers_full = "To: " . $to_name_safe . " <" . $to_email . ">\r\n"
            . "Subject: " . $subject . "\r\n"
            . "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n"
            . $headers;

        $data = $headers_full . "\r\n" . $body;
        // Éliminer toute ligne commençant par un point (RFC 5321 data transparency)
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($conn, $data . "\r\n.\r\n");
        $r = '';
        while ($line = fgets($conn)) {
            $r .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        $accepted = strpos($r, '250') === 0;
        fwrite($conn, "QUIT\r\n");
        fclose($conn);

        return ['ok' => (bool)$accepted, 'error' => $accepted ? '' : 'Message refusé après DATA : ' . trim($r)];
    }
}