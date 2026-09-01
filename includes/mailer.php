<?php
// ==============================================================================
// GESTIONNAIRE D'ENVOI D'EMAILS DE BILLETERIE (includes/mailer.php)
// Génère et transmet des e-mails HTML professionnels avec QR Codes et copies des billets
// ==============================================================================

/**
 * Envoie une copie officielle des billets achetés par email au client
 *
 * @param string $to_email      Adresse email du destinataire
 * @param string $to_name       Nom complet du titulaire
 * @param string $order_number  Numéro de commande (ex: CMD-20260827-XXXX)
 * @param array  $tickets       Tableau des billets générés avec codes et QR
 * @param int    $order_id      Identifiant de la commande
 * @return bool                 True si l'envoi a été tenté avec succès
 */
function sendTicketEmail(string $to_email, string $to_name, string $order_number, array $tickets, int $order_id = 0): bool
{
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = "🎟️ Vos Billets & QR Codes pour votre commande #" . $order_number . " - Ticket Flow";

    // Construction des cartes de billets en HTML dans l'email
    $tickets_html = "";
    foreach ($tickets as $index => $t) {
        $qr_url = htmlspecialchars($t['qr_code']);
        $code   = htmlspecialchars($t['code_unique']);
        $event  = htmlspecialchars($t['event_name']);
        $tier   = htmlspecialchars($t['type_ticket']);
        $prix   = number_format($t['prix'], 0, ',', ' ') . " FCFA";
        $date_e = date('d/m/Y', strtotime($t['date_ev']));
        $heure  = substr($t['heure'], 0, 5);
        $lieu   = htmlspecialchars($t['lieu']);

        $tickets_html .= "
        <div style='background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 20px; overflow: hidden; font-family: Helvetica, Arial, sans-serif;'>
            <div style='background: #0f172a; color: #ffffff; padding: 12px 20px; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between;'>
                <span>BILLET #" . ($index + 1) . " - " . strtoupper($tier) . "</span>
                <span style='background: #10b981; color: #ffffff; padding: 2px 8px; border-radius: 10px; font-size: 11px;'>VALIDE</span>
            </div>
            <div style='padding: 20px; text-align: center;'>
                <div style='margin-bottom: 12px;'>
                    <img src='{$qr_url}' alt='QR Code' style='width: 160px; height: 160px; display: inline-block; border: 1px solid #cbd5e1; border-radius: 8px; padding: 5px;'>
                </div>
                <div style='background: #f1f5f9; display: inline-block; padding: 6px 16px; border-radius: 6px; font-family: monospace; font-size: 16px; font-weight: bold; letter-spacing: 2px; color: #0f172a; margin-bottom: 15px;'>
                    {$code}
                </div>
                <h3 style='margin: 0 0 8px; font-size: 18px; color: #0f172a;'>{$event}</h3>
                <p style='color: #64748b; font-size: 14px; margin: 0 0 10px;'>
                    📅 <strong>{$date_e} à {$heure}</strong><br>
                    📍 <strong>{$lieu}</strong>
                </p>
                <div style='border-top: 1px solid #f1f5f9; padding-top: 10px; font-size: 13px; color: #64748b;'>
                    Prix payé : <strong style='color: #0d9488;'>{$prix}</strong>
                </div>
            </div>
        </div>";
    }

    $download_link = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/client/telecharger-ticket.php?order_id=" . $order_id;

    // Corps de l'email HTML
    $body_html = "
    <!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <title>Vos Billets Ticket Flow</title>
    </head>
    <body style='font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 30px 15px; color: #1e293b;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;'>
            
            <!-- En-tête de l'email -->
            <div style='background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #ffffff; padding: 30px; text-align: center;'>
                <h1 style='margin: 0 0 6px; font-size: 24px; letter-spacing: -0.5px;'>🎟️ TICKET FLOW</h1>
                <p style='margin: 0; color: #38bdf8; font-size: 14px; font-weight: bold;'>Confirmation de Réservation & Billets Officiels</p>
            </div>

            <!-- Message d'accueil -->
            <div style='padding: 30px 25px; background: #ffffff;'>
                <p style='font-size: 15px; line-height: 1.5; color: #334155; margin-top: 0;'>
                    Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
                    Nous avons le plaisir de vous confirmer le règlement de votre commande <strong>#" . htmlspecialchars($order_number) . "</strong>.<br>
                    Vous trouverez ci-dessous la copie officielle de vos billets d'accès avec leurs <strong>QR Codes</strong> pour le contrôle à l'entrée.
                </p>

                <!-- Bouton Téléchargement PDF -->
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='{$download_link}' target='_blank' style='background: #0d9488; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px; display: inline-block;'>
                        📥 Télécharger mes Billets en PDF
                    </a>
                </div>

                <div style='margin-top: 25px;'>
                    {$tickets_html}
                </div>

                <!-- Consignes importantes -->
                <div style='background: #f8fafc; border-left: 4px solid #0d9488; padding: 15px; border-radius: 0 8px 8px 0; margin-top: 20px; font-size: 13px; color: #475569;'>
                    <strong>💡 Consignes d'accès :</strong>
                    <ul style='margin: 6px 0 0; padding-left: 18px; line-height: 1.5;'>
                        <li>Présentez ce QR Code directement depuis votre smartphone ou en version imprimée.</li>
                        <li>Chaque QR Code est unique et sera invalidé dès son premier scan à l'entrée.</li>
                    </ul>
                </div>
            </div>

            <!-- Pied de page de l'email -->
            <div style='background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 5px;'>© " . date('Y') . " Ticket Flow - Billetterie 100% Sécurisée</p>
                <p style='margin: 0;'>Ceci est un e-mail automatique, merci de ne pas y répondre directement.</p>
            </div>
        </div>
    </body>
    </html>";

    // ---- Pièce jointe PDF : génération des billets en PDF natif ----
    require_once __DIR__ . '/pdf.php';
    $pdf_data = generateTicketsPdf($tickets, $order_number, $to_name);
    $pdf_filename = 'billets-' . preg_replace('/[^A-Za-z0-9\-]/', '', $order_number) . '.pdf';

    if ($pdf_data !== '') {
        // Message MIME multipart/mixed : corps HTML + PDF en pièce jointe
        $boundary = 'TFMIX' . md5(uniqid((string)$order_id, true));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: Ticket Flow <billetterie@ticketflow.com>\r\n";
        $headers .= "Reply-To: support@ticketflow.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

        $body  = "--" . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $body_html . "\r\n";
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Type: application/pdf; name=\"" . $pdf_filename . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"" . $pdf_filename . "\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdf_data)) . "\r\n";
        $body .= "--" . $boundary . "--\r\n";
    } else {
        // Repli : email HTML seul si le PDF n'a pas pu être généré
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Ticket Flow <billetterie@ticketflow.com>\r\n";
        $headers .= "Reply-To: support@ticketflow.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        $body = $body_html;
    }

    // Envoi effectif via le client SMTP configuré, ou mail() en dernier recours
    require_once __DIR__ . '/smtp.php';
    $res = smtp_send($to_email, $to_name, $subject, $headers, $body);
    if (!$res['ok']) {
        // L'envoi a échoué : on le signale sans bloquer la confirmation de paiement
        error_log('[TicketFlow] Échec envoi billets -> ' . $to_email . ' : ' . $res['error']);
    }
    return $res['ok'];
}
