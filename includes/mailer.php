<?php
// ==============================================================================
// GESTIONNAIRE CENTRAL D'EMAILS (includes/mailer.php)
// Envoi d'emails transactionnels professionnels : Billetterie, Comptes & Notifications
// ==============================================================================

require_once __DIR__ . '/../config/smtp.php';
require_once __DIR__ . '/smtp.php';

/**
 * Fonction générique pour expédier un email au format HTML via SMTP Tikéli
 *
 * @param string $to_email       Adresse email du destinataire
 * @param string $to_name        Nom du destinataire
 * @param string $subject        Sujet du message
 * @param string $body_html      Contenu HTML
 * @param string|null $attachment_data Données binaires du fichier joint (ex: PDF)
 * @param string|null $attachment_filename Nom du fichier joint
 * @return bool True si expédié avec succès
 */
function sendTikéliEmail(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_html,
    ?string $attachment_data = null,
    ?string $attachment_filename = null
): bool {
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $from_email = defined('SMTP_FROM') && !empty(SMTP_FROM) ? SMTP_FROM : 'no-reply@tikeli.ci';
    $from_name = defined('SMTP_FROM_NAME') && !empty(SMTP_FROM_NAME) ? SMTP_FROM_NAME : 'Tikéli';

    if (!empty($attachment_data) && !empty($attachment_filename)) {
        // Message MIME multipart/mixed (HTML + Pièce jointe)
        $boundary = 'TIKÉLI_' . md5(uniqid((string) time(), true));

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_email}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $body_html . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$attachment_filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$attachment_filename}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($attachment_data)) . "\r\n";
        $body .= "--{$boundary}--\r\n";
    } else {
        // Message MIME standard text/html
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_email}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $body = $body_html;
    }

    $res = smtp_send($to_email, $to_name, $subject, $headers, $body);

    if (!$res['ok']) {
        error_log("[Tikéli Mailer] Échec envoi à {$to_email} : " . $res['error']);
    }

    return (bool) $res['ok'];
}

/**
 * Enveloppe HTML standardisée aux couleurs Tikéli (Navy & Ambre)
 */
function wrapTikéliTemplate(string $title, string $content_html): string
{
    $site_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform";
    $year = date('Y');

    return "
    <!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>" . htmlspecialchars($title) . "</title>
    </head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 30px 15px; color: #0f172a;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0;'>
            
            <!-- En-tête de marque Tikéli -->
            <div style='background: #000000; color: #ffffff; padding: 28px 24px; text-align: center; border-bottom: 3px solid #FF4A0D;'>
                <div style='font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-bottom: 4px; display: inline-flex; align-items: center; gap: 8px;'>
                    TIKÉLI
                </div>
                <div style='color: #a3a3a3; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;'>
                    Plateforme Officielle de Billetterie
                </div>
            </div>

            <!-- Contenu principal -->
            <div style='padding: 30px 24px; background: #ffffff;'>
                {$content_html}
            </div>

            <!-- Pied de page -->
            <div style='background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 6px;'>© {$year} Tikéli. Tous droits réservés.</p>
                <p style='margin: 0;'>Paiements sécurisés par Mobile Money (Wave, Orange, MTN, Moov).</p>
                <div style='margin-top: 10px;'>
                    <a href='{$site_url}/client/accueil.php' style='color: #16233f; text-decoration: none; font-weight: 700; margin: 0 8px;'>Accueil</a> ·
                    <a href='{$site_url}/connexion.php' style='color: #FF4A0D; text-decoration: none; font-weight: 700; margin: 0 8px;'>Connexion</a>
                </div>
            </div>

        </div>
    </body>
    </html>";
}

/**
 * 1. ENVOI DES BILLETS APRÈS ACHAT (sendTicketEmail)
 * Transmet les billets officiels avec QR Codes et pièce jointe PDF
 */
function sendTicketEmail(string $to_email, string $to_name, string $order_number, array $tickets, int $order_id = 0): bool
{
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = "Vos Billets & QR Codes — Commande #" . $order_number . " - Tikéli";

    // Cartes individuelles des billets
    $tickets_html = "";
    foreach ($tickets as $index => $t) {
        $qr_url = htmlspecialchars($t['qr_code']);
        $code = htmlspecialchars($t['code_unique']);
        $event = htmlspecialchars($t['event_name']);
        $tier = htmlspecialchars($t['type_ticket']);
        $prix = number_format($t['prix'], 0, ',', ' ') . " FCFA";
        $date_e = date('d/m/Y', strtotime($t['date_ev']));
        $heure = substr($t['heure'], 0, 5);
        $place_num = !empty($t['place']) ? $t['place'] : (!empty($t['place_numero']) ? $t['place_numero'] : '');
        $place = !empty($place_num) ? ("Place : <strong>" . htmlspecialchars($place_num) . "</strong>") : "";
        $lieu = htmlspecialchars($t['lieu'] ?? $t['event_lieu'] ?? $t['location'] ?? '');

        $tickets_html .= "
        <div style='background: #ffffff; border: 1.5px solid #0f172a; border-radius: 20px; margin-bottom: 22px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);'>
            <div style='padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;'>
                <span style='font-size: 15px; font-weight: 800; color: #0d9488; letter-spacing: 0.5px;'>TIKÉLI</span>
                <span style='background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: bold;'>✔ VENDU</span>
            </div>
            <div style='padding: 20px; text-align: left;'>
                <h3 style='margin: 0 0 14px; font-size: 20px; font-weight: 900; color: #0f172a; letter-spacing: -0.3px;'>{$event}</h3>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; margin-bottom: 16px;'>
                    <div>
                        <span style='color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; display: block;'>DATE & HEURE</span>
                        <strong style='color: #0f172a;'>{$date_e} à {$heure}</strong>
                    </div>
                    <div>
                        <span style='color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; display: block;'>SALLE & LIEU</span>
                        <strong style='color: #0f172a;'>{$lieu}</strong>
                    </div>
                    <div>
                        <span style='color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; display: block;'>CATÉGORIE DE BILLET</span>
                        <strong style='color: #0f172a;'>" . strtoupper($tier) . "</strong>
                    </div>
                    <div>
                        " . ($place_num ? "<span style='color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; display: block;'>PLACE</span><strong style='color: #0f172a;'>" . htmlspecialchars($place_num) . "</strong>" : "<span style='color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; display: block;'>PRIX PAYÉ</span><strong style='color: #0d9488;'>{$prix}</strong>") . "
                    </div>
                </div>
                
                <div style='border-top: 1.5px dashed #0f172a; padding-top: 16px; margin-top: 14px; text-align: center;'>
                    <div style='margin-bottom: 10px;'>
                        <img src='{$qr_url}' alt='QR Code' style='width: 140px; height: 140px; display: inline-block; border-radius: 8px;'>
                    </div>
                    <div style='font-family: monospace; font-size: 15px; font-weight: bold; letter-spacing: 2px; color: #0f172a; margin-bottom: 4px;'>
                        {$code}
                    </div>
                    <div style='font-weight: 800; font-size: 14px; color: #0f172a; text-transform: uppercase;'>
                        " . strtoupper($tier) . " — <span style='color: #475569;'>{$prix}</span>
                    </div>
                    <div style='font-size: 11px; color: #64748b; margin-top: 6px;'>
                        Présentez ce QR Code à l'agent de contrôle
                    </div>
                </div>
            </div>
        </div>";
    }

    $pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
    $order_token = '';
    try {
        global $pdo;
        if (!isset($pdo) || !$pdo) {
            require_once __DIR__ . '/../config/database.php';
        }
        if ($pdo && $order_id > 0) {
            $stmt_created = $pdo->prepare("SELECT created_at FROM orders WHERE id = ?");
            $stmt_created->execute([$order_id]);
            $created_at = $stmt_created->fetchColumn();
            if ($created_at) {
                $order_token = hash_hmac('sha256', $order_id . '|' . $created_at, $pay_secret);
            }
        }
    } catch (Throwable $e) {
        error_log("[Tikéli Mailer] Erreur génération token commande: " . $e->getMessage());
    }

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '') ? 'https://' : 'http://';
    $base_host = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $download_link = $base_host . "/ticket-platform/client/telecharger-ticket.php?order_id=" . $order_id . ($order_token ? "&token=" . $order_token : "");

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Félicitations pour votre réservation !</h2>
        <p style='font-size: 15px; line-height: 1.5; color: #334155; margin: 0 0 18px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Le paiement de votre commande <strong>#" . htmlspecialchars($order_number) . "</strong> a été validé avec succès.
            Vous trouverez ci-dessous vos billets électroniques ainsi que le fichier PDF officiel joint à cet e-mail.
        </p>

        <div style='text-align: center; margin: 20px 0;'>
            <a href='{$download_link}' target='_blank' style='background: #d97706; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);'>
                Télécharger tous mes Billets en PDF
            </a>
        </div>

        <div style='margin-top: 20px;'>
            {$tickets_html}
        </div>

        <div style='background: #fffbeb; border-left: 4px solid #d97706; padding: 14px; border-radius: 0 8px 8px 0; margin-top: 20px; font-size: 13px; color: #92400e; line-height: 1.5;'>
            <strong>Consignes importantes d'accès :</strong>
            <ul style='margin: 6px 0 0; padding-left: 18px;'>
                <li>Présentez directement ce QR Code sur votre smartphone à l'entrée ou imprimez votre billet.</li>
                <li>Chaque QR code est unique et est immédiatement invalidé au premier scan.</li>
            </ul>
        </div>
    ";

    $body_html = wrapTikéliTemplate("Vos Billets Tikéli #" . $order_number, $content);

    // Pièce jointe PDF
    require_once __DIR__ . '/pdf.php';
    $pdf_data = generateTicketsPdf($tickets, $order_number, $to_name);
    $pdf_filename = 'billets-' . preg_replace('/[^A-Za-z0-9\-]/', '', $order_number) . '.pdf';

    return sendTikéliEmail($to_email, $to_name, $subject, $body_html, $pdf_data ?: null, $pdf_filename);
}

/**
 * 2. EMAIL DE BIENVENUE CLIENT (sendWelcomeClientEmail)
 * Envoyé lors de la création d'un compte client sur inscription.php
 */
function sendWelcomeClientEmail(string $to_email, string $to_name): bool
{
    $subject = "Bienvenue sur Tikéli, " . $to_name . " !";
    $site_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Bienvenue dans la communauté Tikéli !</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Votre compte client a été créé avec succès sur <strong>Tikéli</strong>. Vous pouvez désormais réserver vos places de concert, festivals, spectacles et conférences en quelques clics par Mobile Money.
        </p>

        <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin: 20px 0;'>
            <strong style='color: #16233f; font-size: 14px; display: block; margin-bottom: 8px;'>Ce que vous pouvez faire dès maintenant :</strong>
            <ul style='margin: 0; padding-left: 20px; color: #475569; font-size: 13.5px; line-height: 1.6;'>
                <li>Explorer les événements à l'affiche et filtrer par ville ou catégorie.</li>
                <li>Choisir précisément votre place sur le plan de salle interactif.</li>
                <li>Retrouver l'historique de vos commandes et télécharger vos billets dans votre espace personnel.</li>
                <li>Participer aux campagnes de cotisation et voter pour vos candidats préférés.</li>
            </ul>
        </div>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$site_url}/client/accueil.php' style='background: #d97706; color: #ffffff; padding: 12px 26px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block;'>
                Découvrir les Événements à l'Affiche
            </a>
        </div>

        <p style='color: #64748b; font-size: 13px; line-height: 1.5; margin: 0;'>
            Votre adresse de connexion : <strong>" . htmlspecialchars($to_email) . "</strong>
        </p>
    ";

    $body_html = wrapTikéliTemplate("Bienvenue sur Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 3. EMAIL DE CONFIRMATION DOSSIER PROMOTEUR (sendPromoterRegistrationEmail)
 * Envoyé lors de l'inscription d'un organisateur / promoteur
 */
function sendPromoterRegistrationEmail(string $to_email, string $to_name, string $activite = ''): bool
{
    $subject = "Dossier Promoteur bien reçu — En cours d'examen - Tikéli";
    $site_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Votre dossier promoteur a été enregistré</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Nous vous confirmons la bonne réception de votre demande d'ouverture de compte <strong>Organisateur / Promoteur</strong> sur Tikéli.
        </p>

        <div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 16px; margin: 18px 0; color: #166534; font-size: 13.5px; line-height: 1.5;'>
            <strong>Prochaine étape : Validation administrative</strong><br>
            Notre équipe de conformité examine les informations et la pièce d'identité que vous avez transmises.
            Vous recevrez une notification par email dès que votre compte aura été validé.
        </div>

        <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; font-size: 13px; color: #475569; margin-bottom: 20px;'>
            <strong>Récapitulatif de votre dossier :</strong>
            <ul style='margin: 6px 0 0; padding-left: 18px;'>
                <li>Nom : <strong>" . htmlspecialchars($to_name) . "</strong></li>
                <li>Email de contact : <strong>" . htmlspecialchars($to_email) . "</strong></li>
                " . ($activite ? "<li>Activité déclarée : <strong>" . htmlspecialchars($activite) . "</strong></li>" : "") . "
                <li>Statut : <span style='color: #d97706; font-weight: bold;'>En attente de revue</span></li>
            </ul>
        </div>

        <p style='color: #64748b; font-size: 13px; line-height: 1.5; margin: 0;'>
            Si vous avez des questions complémentaires, notre équipe de support reste à votre écoute.
        </p>
    ";

    $body_html = wrapTikéliTemplate("Dossier Promoteur Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 4. EMAIL DE CRÉATION DE COMPTE PAR L'ADMINISTRATEUR (sendAdminCreatedAccountEmail)
 * Envoie la confirmation de création de compte avec liens sécurisés de connexion et de réinitialisation
 */
function sendAdminCreatedAccountEmail(
    string $to_email,
    string $to_name,
    string $role,
    string $password = '',
    string $profile_nom = '',
    ?string $custom_reset_url = null
): bool {
    global $pdo;
    if (!isset($pdo)) {
        @require_once __DIR__ . '/../config/database.php';
    }

    $subject = "Votre compte d'accès à la plateforme Tikéli a été créé";
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $site_url = "{$scheme}://{$host}/ticket-platform";
    $login_url = "{$site_url}/connexion.php";

    // Génération automatique d'un jeton sécurisé de réinitialisation (valide 24h)
    $reset_url = $custom_reset_url;
    if (empty($reset_url) && !empty($pdo)) {
        try {
            $stmt_inv = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
            $stmt_inv->execute([$to_email]);

            $token = bin2hex(random_bytes(32));
            $stmt_ins = $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at, used) 
                VALUES (?, ?, NOW() + INTERVAL '24 hours', 0)
            ");
            $stmt_ins->execute([$to_email, $token]);
            $reset_url = "{$site_url}/reinitialiser-mot-de-passe.php?token=" . urlencode($token) . "&email=" . urlencode($to_email);
        } catch (\Throwable $e) {
            error_log("[sendAdminCreatedAccountEmail Token Error] " . $e->getMessage());
            $reset_url = "{$site_url}/mot-de-passe-oublie.php";
        }
    }
    if (empty($reset_url)) {
        $reset_url = "{$site_url}/mot-de-passe-oublie.php";
    }

    $role_labels = [
        'admin' => 'Administrateur',
        'agent' => 'Agent de Contrôle (Scanners & Entrées)',
        'promoteur' => 'Promoteur Événements',
        'client' => 'Client'
    ];
    $role_label = $role_labels[$role] ?? ucfirst($role);

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Votre compte Tikéli a été créé</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Un administrateur de la plateforme <strong>Tikéli</strong> vient de vous ouvrir un compte d'accès officiel. Pour des raisons strictes de sécurité et de confidentialité, votre mot de passe n'est pas transmis en clair dans cet e-mail.
        </p>

        <!-- Boîte des identifiants sécurisée -->
        <div style='background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; margin: 20px 0;'>
            <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 8px 0; color: #64748b; width: 150px;'>Identifiant / Email :</td>
                    <td style='padding: 8px 0; color: #000000; font-weight: bold;'>" . htmlspecialchars($to_email) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Rôle d'accès :</td>
                    <td style='padding: 8px 0; color: #000000; font-weight: 600;'>{$role_label}" . (!empty($profile_nom) && strcasecmp($profile_nom, $role_label) !== 0 ? " — <span style='color: #64748b; font-weight: normal;'>(Profil : " . htmlspecialchars($profile_nom) . ")</span>" : "") . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Mot de passe :</td>
                    <td style='padding: 8px 0;'><span style='background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-style: italic;'>Configuré par l'administrateur (protégé)</span></td>
                </tr>
            </table>
        </div>

        <!-- Boutons d'action : Connexion & Réinitialisation -->
        <div style='text-align: center; margin: 28px 0;'>
            <table align='center' border='0' cellpadding='0' cellspacing='0' style='margin: 0 auto;'>
                <tr>
                    <td align='center' style='padding: 6px;'>
                        <a href='{$login_url}' style='background: #FF4A0D; color: #ffffff; padding: 13px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);'>
                            Me Connecter à Tikéli
                        </a>
                    </td>
                    <td align='center' style='padding: 6px;'>
                        <a href='{$reset_url}' style='background: #ffffff; color: #0f172a; border: 2px solid #cbd5e1; padding: 11px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block;'>
                            Réinitialiser mon mot de passe
                        </a>
                    </td>
                </tr>
            </table>
        </div>

        <div style='background: #f0fdf4; border-left: 4px solid #16a34a; padding: 14px 16px; border-radius: 0 8px 8px 0; font-size: 13px; color: #166534; margin-top: 15px; line-height: 1.5;'>
            <strong>Sécurité & Confidentialité :</strong> Conformément aux normes de protection des données, votre mot de passe n'est jamais transmis par e-mail. Vous pouvez vous connecter avec le mot de passe convenu avec votre administrateur ou utiliser le bouton <strong>« Réinitialiser mon mot de passe »</strong> pour en définir un nouveau immédiatement (lien valable 24 heures).
        </div>
    ";

    $body_html = wrapTikéliTemplate("Votre Compte Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 5. EMAIL DE SUSPENSION / RÉACTIVATION DE COMPTE (sendAccountStatusNotificationEmail)
 */
function sendAccountStatusNotificationEmail(
    string $to_email,
    string $to_name,
    string $type,
    string $motif = '',
    ?string $date_fin = null
): bool {
    $is_reactivation = ($type === 'reactivation' || $type === 'actif');
    $subject = $is_reactivation
        ? "Votre compte Tikéli a été réactivé"
        : "Notification concernant l'état de votre compte Tikéli";

    if ($is_reactivation) {
        $content = "
            <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Votre compte est de nouveau actif</h2>
            <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
                Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
                Nous vous informons que la suspension temporaire de votre compte Tikéli a été levée. Vous pouvez à présent vous reconnecter et accéder à l'ensemble de vos services.
            </p>
        ";
    } else {
        $fin_str = !empty($date_fin) ? (" jusqu'au <strong>" . date('d/m/Y', strtotime($date_fin)) . "</strong>") : " indéterminée";
        $content = "
            <h2 style='margin: 0 0 12px; color: #dc2626; font-size: 20px;'>Suspension de votre compte</h2>
            <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
                Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
                L'administration de Tikéli vous informe que votre compte fait l'objet d'une suspension" . $fin_str . ".
            </p>
            " . (!empty($motif) ? "
            <div style='background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 13.5px; color: #991b1b; margin: 15px 0;'>
                <strong>Motif :</strong> " . htmlspecialchars($motif) . "
            </div>" : "") . "
            <p style='color: #64748b; font-size: 13px; line-height: 1.5;'>
                Pendant cette période, les connexions à votre compte sont bloquées. Toutes vos données et billets restent sauvegardés. Pour toute réclamation, veuillez contacter le support.
            </p>
        ";
    }

    $body_html = wrapTikéliTemplate($subject, $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 6. EMAIL D'APPROBATION DE COMPTE PROMOTEUR (sendPromoterApprovalEmail)
 * Prévient le promoteur que son dossier est validé et qu'il peut dès lors se connecter
 */
function sendPromoterApprovalEmail(string $to_email, string $to_name, string $structure_name = ''): bool
{
    $subject = "Félicitations ! Votre compte Promoteur Tikéli est activé";
    $login_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/connexion.php";

    $nom_aff = !empty($structure_name) ? $structure_name : $to_name;

    $content = "
        <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Votre compte Promoteur est validé !</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            L'administration d'<strong>Tikéli</strong> a le plaisir de vous informer que votre dossier d'éligibilité pour <strong>" . htmlspecialchars($nom_aff) . "</strong> a été <strong>validé avec succès</strong>.
        </p>

        <div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 18px; margin: 20px 0;'>
            <strong style='color: #166534; font-size: 14.5px; display: block; margin-bottom: 8px;'>Votre Espace Promoteur est désormais 100% opérationnel :</strong>
            <ul style='margin: 0; padding-left: 20px; color: #166534; font-size: 13.5px; line-height: 1.6;'>
                <li>Publiez et configurez vos événements officiels.</li>
                <li>Gérez vos tarifs, billets et plans de salle interactifs.</li>
                <li>Suivez vos ventes et statistiques de billetterie en direct.</li>
                <li>Effectuez des retraits instantanés de vos fonds par Mobile Money.</li>
            </ul>
        </div>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$login_url}' style='background: #d97706; color: #ffffff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.25);'>
                Me Connecter à mon Espace Promoteur
            </a>
        </div>

        <p style='color: #64748b; font-size: 13px; line-height: 1.5; margin: 0;'>
            Identifiant : <strong>" . htmlspecialchars($to_email) . "</strong><br>
            Utilisez le mot de passe que vous avez défini lors de votre demande.
        </p>
    ";

    $body_html = wrapTikéliTemplate("Compte Promoteur Activé - Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 7. EMAIL DE REJET DE DOSSIER PROMOTEUR (sendPromoterRejectionEmail)
 */
function sendPromoterRejectionEmail(string $to_email, string $to_name, string $motif = ''): bool
{
    $subject = "Notification concernant votre candidature promoteur Tikéli";

    $content = "
        <h2 style='margin: 0 0 12px; color: #dc2626; font-size: 20px;'>Dossier Promoteur Non Validé</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Après examen de vos documents par notre équipe de conformité, nous ne sommes pas en mesure de valider votre demande d'ouverture de compte promoteur pour le moment.
        </p>

        " . (!empty($motif) ? "
        <div style='background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 13.5px; color: #991b1b; margin: 15px 0;'>
            <strong>Motif :</strong> " . htmlspecialchars($motif) . "
        </div>" : "") . "

        <p style='color: #64748b; font-size: 13px; line-height: 1.5;'>
            Vous pouvez mettre à jour vos pièces justificatives ou contacter le service support pour toute assistance complémentaire.
        </p>
    ";

    $body_html = wrapTikéliTemplate("Candidature Promoteur - Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 8. EMAIL DE RÉINITIALISATION DE MOT DE PASSE (sendPasswordResetEmail)
 * Envoie un lien sécurisé à usage unique valable 60 minutes
 */
function sendPasswordResetEmail(
    string $to_email,
    string $to_name,
    string $reset_url,
    int $expiry_minutes = 60
): bool {
    $subject = "Réinitialisation de votre mot de passe Tikéli";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Demande de réinitialisation de mot de passe</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Nous avons reçu une demande de réinitialisation du mot de passe associé à votre adresse email <strong>" . htmlspecialchars($to_email) . "</strong>.
        </p>

        <p style='font-size: 14.5px; line-height: 1.55; color: #334155; margin: 0 0 20px;'>
            Pour choisir un nouveau mot de passe, cliquez sur le bouton ci-dessous. Ce lien est sécurisé, strictement personnel et expirera dans <strong>{$expiry_minutes} minutes</strong> :
        </p>

        <!-- Bouton CTA Principal -->
        <div style='text-align: center; margin: 28px 0;'>
            <a href='{$reset_url}' style='background: #d97706; color: #ffffff; padding: 14px 32px; border-radius: 10px; text-decoration: none; font-weight: 800; font-size: 15px; display: inline-block; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);'>
                Réinitialiser mon mot de passe
            </a>
        </div>

        <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin: 20px 0; font-size: 13px; color: #64748b; line-height: 1.5; word-break: break-all;'>
            <span style='font-weight: 700; color: #334155; display: block; margin-bottom: 4px;'>Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</span>
            <a href='{$reset_url}' style='color: #0284c7; text-decoration: underline;'>{$reset_url}</a>
        </div>

        <div style='background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #92400e; margin-top: 20px;'>
            <strong>Vous n'êtes pas à l'origine de cette demande ?</strong><br>
            Ignorez simplement cet email. Votre mot de passe actuel restera inchangé et votre compte demeure sécurisé.
        </div>
    ";

    $body_html = wrapTikéliTemplate("Réinitialisation Mot de Passe - Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 9. EMAIL DE CONFIRMATION DE CHANGEMENT DE MOT DE PASSE (sendPasswordChangedConfirmationEmail)
 */
function sendPasswordChangedConfirmationEmail(string $to_email, string $to_name): bool
{
    $subject = "Votre mot de passe Tikéli a été modifié";
    $login_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/connexion.php";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Mot de passe mis à jour avec succès</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Le mot de passe de votre compte <strong>Tikéli</strong> a été modifié avec succès le <strong>" . date('d/m/Y à H:i') . "</strong>.
        </p>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$login_url}' style='background: #16233f; color: #ffffff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block; box-shadow: 0 4px 12px rgba(22, 35, 63, 0.25);'>
                Accéder à mon compte
            </a>
        </div>

        <div style='background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #991b1b; margin-top: 15px;'>
            <strong>Alerte Sécurité :</strong> Si vous n'avez pas effectué ce changement, veuillez contacter immédiatement l'assistance Tikéli pour sécuriser votre compte.
        </div>
    ";

    $body_html = wrapTikéliTemplate("Mot de Passe Modifié - Tikéli", $content);
    return sendTikéliEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 10. EMAIL DE CONFIRMATION DE CHANGEMENT DE PLACE (sendSeatChangedConfirmationEmail)
 * Notifie le client que son siège a été modifié avec succès et joint le nouveau PDF
 */
function sendSeatChangedConfirmationEmail(
    string $to_email,
    string $to_name,
    array $ticket,
    string $ancienne_place,
    string $nouvelle_place
): bool {
    $subject = "Votre place a été modifiée : " . $nouvelle_place . " — " . ($ticket['event_name'] ?? 'Tikéli');
    $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ticket-platform';

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Votre place a bien été mise à jour !</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Le changement de place pour votre billet de l'événement <strong>" . htmlspecialchars($ticket['event_name'] ?? '') . "</strong> a été validé avec succès.
        </p>

        <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin: 20px 0;'>
            <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Événement :</td>
                    <td style='padding: 8px 0; font-weight: 700; color: #16233f; text-align: right;'>" . htmlspecialchars($ticket['event_name'] ?? '') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Date & Heure :</td>
                    <td style='padding: 8px 0; font-weight: 700; color: #16233f; text-align: right;'>" . htmlspecialchars($ticket['date_evenement'] ?? '') . " à " . htmlspecialchars($ticket['heure'] ?? '') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Lieu :</td>
                    <td style='padding: 8px 0; font-weight: 700; color: #16233f; text-align: right;'>" . htmlspecialchars($ticket['lieu'] ?? '') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Catégorie :</td>
                    <td style='padding: 8px 0; font-weight: 700; color: #16233f; text-align: right;'>" . htmlspecialchars($ticket['type_ticket'] ?? '') . "</td>
                </tr>
                <tr style='border-top: 1px dashed #cbd5e1;'>
                    <td style='padding: 10px 0; color: #94a3b8;'>Ancienne place :</td>
                    <td style='padding: 10px 0; color: #94a3b8; text-decoration: line-through; text-align: right;'>" . htmlspecialchars($ancienne_place ?: 'Non spécifiée') . "</td>
                </tr>
                <tr style='background: #fffbeb;'>
                    <td style='padding: 10px 8px; color: #b45309; font-weight: bold;'>Nouvelle place :</td>
                    <td style='padding: 10px 8px; color: #d97706; font-weight: 900; font-size: 16px; text-align: right;'>Place " . htmlspecialchars($nouvelle_place) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'>Code Billet :</td>
                    <td style='padding: 8px 0; font-family: monospace; font-weight: 700; color: #16233f; text-align: right;'>" . htmlspecialchars($ticket['code_unique'] ?? '') . "</td>
                </tr>
            </table>
        </div>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$site_url}/client/mes-tickets.php' style='background: #d97706; color: #ffffff; padding: 12px 26px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block;'>
                Voir mon billet actualisé
            </a>
        </div>

        <p style='color: #64748b; font-size: 13px; line-height: 1.5; margin: 0;'>
            Votre QR code reste identique et valide pour l'accès. Vous trouverez votre billet actualisé au format PDF en pièce jointe de cet email.
        </p>
    ";

    $body_html = wrapTikéliTemplate("Changement de Place Confirmé - Tikéli", $content);

    // Génération du PDF actualisé en pièce jointe
    $pdf_data = null;
    $pdf_filename = null;
    try {
        require_once __DIR__ . '/pdf.php';
        $pdf_ticket_item = [
            'code_unique' => $ticket['code_unique'] ?? '',
            'qr_code' => $ticket['qr_code'] ?? '',
            'event_name' => $ticket['event_name'] ?? '',
            'type_ticket' => $ticket['type_ticket'] ?? '',
            'place' => $nouvelle_place,
            'prix' => $ticket['prix'] ?? 0,
            'date_ev' => $ticket['date_evenement'] ?? '',
            'heure' => $ticket['heure'] ?? '',
            'lieu' => $ticket['lieu'] ?? '',
            'date_achat' => date('Y-m-d H:i:s')
        ];
        $order_num = !empty($ticket['order_id']) ? "CMD-" . $ticket['order_id'] : $ticket['code_unique'];
        $pdf_data = generateTicketsPdf([$pdf_ticket_item], $order_num, $to_name);
        $pdf_filename = 'billet-' . preg_replace('/[^A-Za-z0-9\-]/', '', $ticket['code_unique']) . '.pdf';
    } catch (Throwable $e) {
        error_log("[Tikéli Mailer] Erreur génération PDF changement place : " . $e->getMessage());
    }

    return sendTikéliEmail($to_email, $to_name, $subject, $body_html, $pdf_data ?: null, $pdf_filename);
}



