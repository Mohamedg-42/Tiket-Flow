<?php
// ==============================================================================
// GESTIONNAIRE CENTRAL D'EMAILS (includes/mailer.php)
// Envoi d'emails transactionnels professionnels : Billetterie, Comptes & Notifications
// ==============================================================================

require_once __DIR__ . '/../config/smtp.php';
require_once __DIR__ . '/smtp.php';

/**
 * Fonction générique pour expédier un email au format HTML via SMTP Eventia
 *
 * @param string $to_email       Adresse email du destinataire
 * @param string $to_name        Nom du destinataire
 * @param string $subject        Sujet du message
 * @param string $body_html      Contenu HTML
 * @param string|null $attachment_data Données binaires du fichier joint (ex: PDF)
 * @param string|null $attachment_filename Nom du fichier joint
 * @return bool True si expédié avec succès
 */
function sendEventiaEmail(
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

    $from_email = defined('SMTP_FROM') && !empty(SMTP_FROM) ? SMTP_FROM : 'no-reply@eventia.ci';
    $from_name  = defined('SMTP_FROM_NAME') && !empty(SMTP_FROM_NAME) ? SMTP_FROM_NAME : 'Eventia';

    if (!empty($attachment_data) && !empty($attachment_filename)) {
        // Message MIME multipart/mixed (HTML + Pièce jointe)
        $boundary = 'EVENTIA_' . md5(uniqid((string)time(), true));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_email}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body  = "--{$boundary}\r\n";
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
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_email}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $body = $body_html;
    }

    $res = smtp_send($to_email, $to_name, $subject, $headers, $body);

    if (!$res['ok']) {
        error_log("[Eventia Mailer] Échec envoi à {$to_email} : " . $res['error']);
    }

    return (bool)$res['ok'];
}

/**
 * Enveloppe HTML standardisée aux couleurs Eventia (Navy & Ambre)
 */
function wrapEventiaTemplate(string $title, string $content_html): string {
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
            
            <!-- En-tête de marque Eventia -->
            <div style='background: #16233f; color: #ffffff; padding: 28px 24px; text-align: center; border-bottom: 3px solid #d97706;'>
                <div style='font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-bottom: 4px; display: inline-flex; align-items: center; gap: 8px;'>
                    EVENTIA
                </div>
                <div style='color: #94a3b8; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;'>
                    Plateforme Officielle de Billetterie
                </div>
            </div>

            <!-- Contenu principal -->
            <div style='padding: 30px 24px; background: #ffffff;'>
                {$content_html}
            </div>

            <!-- Pied de page -->
            <div style='background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 6px;'>© {$year} Eventia. Tous droits réservés.</p>
                <p style='margin: 0;'>Paiements sécurisés par Mobile Money (Wave, Orange, MTN, Moov).</p>
                <div style='margin-top: 10px;'>
                    <a href='{$site_url}/client/accueil.php' style='color: #16233f; text-decoration: none; font-weight: 700; margin: 0 8px;'>Accueil</a> ·
                    <a href='{$site_url}/connexion.php' style='color: #16233f; text-decoration: none; font-weight: 700; margin: 0 8px;'>Espace Client</a>
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
function sendTicketEmail(string $to_email, string $to_name, string $order_number, array $tickets, int $order_id = 0): bool {
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = "Vos Billets & QR Codes — Commande #" . $order_number . " - Eventia";

    // Cartes individuelles des billets
    $tickets_html = "";
    foreach ($tickets as $index => $t) {
        $qr_url = htmlspecialchars($t['qr_code']);
        $code   = htmlspecialchars($t['code_unique']);
        $event  = htmlspecialchars($t['event_name']);
        $tier   = htmlspecialchars($t['type_ticket']);
        $prix   = number_format($t['prix'], 0, ',', ' ') . " FCFA";
        $date_e = date('d/m/Y', strtotime($t['date_ev']));
        $heure  = substr($t['heure'], 0, 5);
        $place_num = !empty($t['place']) ? $t['place'] : (!empty($t['place_numero']) ? $t['place_numero'] : '');
        $place     = !empty($place_num) ? ("Place : <strong>" . htmlspecialchars($place_num) . "</strong>") : "";
        $lieu      = htmlspecialchars($t['lieu'] ?? $t['event_lieu'] ?? $t['location'] ?? '');

        $tickets_html .= "
        <div style='background: #ffffff; border: 1.5px solid #0f172a; border-radius: 20px; margin-bottom: 22px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);'>
            <div style='padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;'>
                <span style='font-size: 15px; font-weight: 800; color: #0d9488; letter-spacing: 0.5px;'>EVENTIA</span>
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

    $download_link = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/client/telecharger-ticket.php?order_id=" . $order_id;

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

    $body_html = wrapEventiaTemplate("Vos Billets Eventia #" . $order_number, $content);

    // Pièce jointe PDF
    require_once __DIR__ . '/pdf.php';
    $pdf_data = generateTicketsPdf($tickets, $order_number, $to_name);
    $pdf_filename = 'billets-' . preg_replace('/[^A-Za-z0-9\-]/', '', $order_number) . '.pdf';

    return sendEventiaEmail($to_email, $to_name, $subject, $body_html, $pdf_data ?: null, $pdf_filename);
}

/**
 * 2. EMAIL DE BIENVENUE CLIENT (sendWelcomeClientEmail)
 * Envoyé lors de la création d'un compte client sur inscription.php
 */
function sendWelcomeClientEmail(string $to_email, string $to_name): bool {
    $subject = "Bienvenue sur Eventia, " . $to_name . " !";
    $site_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Bienvenue dans la communauté Eventia !</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Votre compte client a été créé avec succès sur <strong>Eventia</strong>. Vous pouvez désormais réserver vos places de concert, festivals, spectacles et conférences en quelques clics par Mobile Money.
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

    $body_html = wrapEventiaTemplate("Bienvenue sur Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 3. EMAIL DE CONFIRMATION DOSSIER PROMOTEUR (sendPromoterRegistrationEmail)
 * Envoyé lors de l'inscription d'un organisateur / promoteur
 */
function sendPromoterRegistrationEmail(string $to_email, string $to_name, string $activite = ''): bool {
    $subject = "Dossier Promoteur bien reçu — En cours d'examen - Eventia";
    $site_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Votre dossier promoteur a été enregistré</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Nous vous confirmons la bonne réception de votre demande d'ouverture de compte <strong>Organisateur / Promoteur</strong> sur Eventia.
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

    $body_html = wrapEventiaTemplate("Dossier Promoteur Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 4. EMAIL DE CRÉATION DE COMPTE PAR L'ADMINISTRATEUR (sendAdminCreatedAccountEmail)
 * Envoie les identifiants d'accès au collaborateur / utilisateur
 */
function sendAdminCreatedAccountEmail(
    string $to_email, 
    string $to_name, 
    string $role, 
    string $password, 
    string $profile_nom = ''
): bool {
    $subject = "Vos identifiants d'accès à la plateforme Eventia";
    $login_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/connexion.php";

    $role_labels = [
        'admin'     => 'Administrateur',
        'agent'     => 'Agent de Contrôle (Scanners & Entrées)',
        'promoteur' => 'Promoteur Événements',
        'client'    => 'Client'
    ];
    $role_label = $role_labels[$role] ?? ucfirst($role);

    $content = "
        <h2 style='margin: 0 0 12px; color: #16233f; font-size: 20px;'>Votre compte Eventia a été créé</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Un administrateur de la plateforme <strong>Eventia</strong> vient de vous ouvrir un compte d'accès. Voici vos identifiants pour vous connecter :
        </p>

        <!-- Boîte des identifiants -->
        <div style='background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; margin: 20px 0;'>
            <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 6px 0; color: #64748b; width: 140px;'>Identifiant / Email :</td>
                    <td style='padding: 6px 0; color: #16233f; font-weight: bold;'>" . htmlspecialchars($to_email) . "</td>
                </tr>
                <tr>
                    <td style='padding: 6px 0; color: #64748b;'>Mot de passe initial :</td>
                    <td style='padding: 6px 0;'><code style='background: #eef1f6; padding: 4px 10px; border-radius: 6px; font-weight: bold; color: #d97706; font-size: 15px; letter-spacing: 1px;'>" . htmlspecialchars($password) . "</code></td>
                </tr>
                <tr>
                    <td style='padding: 6px 0; color: #64748b;'>Rôle système :</td>
                    <td style='padding: 6px 0; color: #16233f; font-weight: 600;'>{$role_label}</td>
                </tr>
                " . (!empty($profile_nom) ? "
                <tr>
                    <td style='padding: 6px 0; color: #64748b;'>Profil métier :</td>
                    <td style='padding: 6px 0; color: #16233f; font-weight: 600;'>" . htmlspecialchars($profile_nom) . "</td>
                </tr>" : "") . "
            </table>
        </div>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$login_url}' style='background: #16233f; color: #ffffff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block; box-shadow: 0 4px 12px rgba(22, 35, 63, 0.25);'>
                Me Connecter à Eventia
            </a>
        </div>

        <div style='background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #92400e; margin-top: 15px;'>
            <strong>Sécurité :</strong> Pour votre sécurité, nous vous recommandons de modifier votre mot de passe dès votre première connexion.
        </div>
    ";

    $body_html = wrapEventiaTemplate("Vos Identifiants Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
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
        ? "Votre compte Eventia a été réactivé" 
        : "Notification concernant l'état de votre compte Eventia";

    if ($is_reactivation) {
        $content = "
            <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Votre compte est de nouveau actif</h2>
            <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
                Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
                Nous vous informons que la suspension temporaire de votre compte Eventia a été levée. Vous pouvez à présent vous reconnecter et accéder à l'ensemble de vos services.
            </p>
        ";
    } else {
        $fin_str = !empty($date_fin) ? (" jusqu'au <strong>" . date('d/m/Y', strtotime($date_fin)) . "</strong>") : " indéterminée";
        $content = "
            <h2 style='margin: 0 0 12px; color: #dc2626; font-size: 20px;'>Suspension de votre compte</h2>
            <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
                Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
                L'administration d'Eventia vous informe que votre compte fait l'objet d'une suspension" . $fin_str . ".
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

    $body_html = wrapEventiaTemplate($subject, $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 6. EMAIL D'APPROBATION DE COMPTE PROMOTEUR (sendPromoterApprovalEmail)
 * Prévient le promoteur que son dossier est validé et qu'il peut dès lors se connecter
 */
function sendPromoterApprovalEmail(string $to_email, string $to_name, string $structure_name = ''): bool {
    $subject = "Félicitations ! Votre compte Promoteur Eventia est activé";
    $login_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/connexion.php";

    $nom_aff = !empty($structure_name) ? $structure_name : $to_name;

    $content = "
        <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Votre compte Promoteur est validé !</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            L'administration d'<strong>Eventia</strong> a le plaisir de vous informer que votre dossier d'éligibilité pour <strong>" . htmlspecialchars($nom_aff) . "</strong> a été <strong>validé avec succès</strong>.
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

    $body_html = wrapEventiaTemplate("Compte Promoteur Activé - Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 7. EMAIL DE REJET DE DOSSIER PROMOTEUR (sendPromoterRejectionEmail)
 */
function sendPromoterRejectionEmail(string $to_email, string $to_name, string $motif = ''): bool {
    $subject = "Notification concernant votre candidature promoteur Eventia";

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

    $body_html = wrapEventiaTemplate("Candidature Promoteur - Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
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
    $subject = "Réinitialisation de votre mot de passe Eventia";

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

    $body_html = wrapEventiaTemplate("Réinitialisation Mot de Passe - Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

/**
 * 9. EMAIL DE CONFIRMATION DE CHANGEMENT DE MOT DE PASSE (sendPasswordChangedConfirmationEmail)
 */
function sendPasswordChangedConfirmationEmail(string $to_email, string $to_name): bool {
    $subject = "Votre mot de passe Eventia a été modifié";
    $login_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/ticket-platform/connexion.php";

    $content = "
        <h2 style='margin: 0 0 12px; color: #16a34a; font-size: 20px;'>Mot de passe mis à jour avec succès</h2>
        <p style='font-size: 15px; line-height: 1.55; color: #334155; margin: 0 0 16px;'>
            Bonjour <strong>" . htmlspecialchars($to_name) . "</strong>,<br><br>
            Le mot de passe de votre compte <strong>Eventia</strong> a été modifié avec succès le <strong>" . date('d/m/Y à H:i') . "</strong>.
        </p>

        <div style='text-align: center; margin: 24px 0;'>
            <a href='{$login_url}' style='background: #16233f; color: #ffffff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14.5px; display: inline-block; box-shadow: 0 4px 12px rgba(22, 35, 63, 0.25);'>
                Accéder à mon compte
            </a>
        </div>

        <div style='background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #991b1b; margin-top: 15px;'>
            <strong>Alerte Sécurité :</strong> Si vous n'avez pas effectué ce changement, veuillez contacter immédiatement l'assistance Eventia pour sécuriser votre compte.
        </div>
    ";

    $body_html = wrapEventiaTemplate("Mot de Passe Modifié - Eventia", $content);
    return sendEventiaEmail($to_email, $to_name, $subject, $body_html);
}

