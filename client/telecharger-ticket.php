<?php
// ==============================================================================
// TÉLÉCHARGEMENT & IMPRESSION DE BILLET OFFICIEL (client/telecharger-ticket.php)
// Format e-Ticket haute définition prêt pour impression papier ou export PDF
// ==============================================================================

require_once '../config/database.php';
session_start();

$code = trim($_GET['code'] ?? '');
$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$tickets = [];

if (!empty($code)) {
    // Recherche par code unique de billet
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        WHERE t.code_unique = ?
    ");
    $stmt->execute([$code]);
    $tickets = $stmt->fetchAll();

} elseif ($order_id) {
    // Recherche par commande complète
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        WHERE t.order_id = ?
        ORDER BY t.id ASC
    ");
    $stmt->execute([$order_id]);
    $tickets = $stmt->fetchAll();

} elseif ($ticket_id) {
    // Recherche par ID de billet
    $stmt = $pdo->prepare("
        SELECT t.*, e.nom AS event_name, e.description AS event_desc, e.date_evenement, e.heure, e.lieu, e.image AS event_image,
               p.nom_commercial AS promoter_name, p.telephone_contact AS promoter_phone
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN promoters p ON e.user_id = p.user_id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $tickets = $stmt->fetchAll();
}

if (empty($tickets)) {
    die("Billet introuvable ou référence invalide. <a href='accueil.php'>Retour à l'accueil</a>");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e-Ticket Officiel - Eventia</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', system-ui, sans-serif;
            background: #F5F5F5;
            color: #000000;
            padding: 2rem 1rem;
            min-height: 100vh;
        }

        .action-bar {
            max-width: 800px;
            margin: 0 auto 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.4rem;
            border-radius: 8px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #FF4A0D;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
        }

        .btn-primary:hover {
            background: #FF4A0D;
            transform: translateY(-2px);
        }

        .btn-back {
            background: #ffffff;
            color: #000000;
            border: 1px solid #E5E5E5;
        }

        .ticket-wrapper {
            max-width: 800px;
            margin: 0 auto;
            display: grid;
            gap: 2rem;
        }

        .ticket-card {
            background: #ffffff;
            border: 1.5px solid #000000;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            display: grid;
            grid-template-columns: 1fr 240px;
            position: relative;
        }

        .ticket-main {
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #F5F5F5;
        }

        .ticket-brand {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 1.2rem;
            color: #FF4A0D;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .ticket-badge {
            background: #FFF2ED;
            color: #000000;
            border: 1px solid #FFF2ED;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 5px 14px;
            border-radius: 20px;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 6px;
            line-height: 1;
        }

        .ticket-event-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            color: #000000;
            margin-bottom: 0.75rem;
            line-height: 1.2;
        }

        .ticket-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
        }

        .ticket-details-grid small {
            display: block;
            color: #737373;
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .ticket-details-grid strong {
            color: #000000;
            font-size: 0.95rem;
        }

        .ticket-buyer-bar {
            background: #F5F5F5;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.84rem;
        }

        .ticket-stub {
            background: #000000;
            color: #ffffff;
            padding: 1.75rem 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            border-left: 2px dashed #000000;
            position: relative;
        }

        .ticket-stub::before,
        .ticket-stub::after {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            background: #F5F5F5;
            border-radius: 50%;
            left: -12px;
        }

        .ticket-stub::before {
            top: -12px;
        }

        .ticket-stub::after {
            bottom: -12px;
        }

        .qr-box {
            background: #ffffff;
            padding: 8px;
            border-radius: 10px;
            margin-bottom: 0.85rem;
        }

        .qr-box img {
            width: 140px;
            height: 140px;
            display: block;
        }

        .code-display {
            font-family: 'Space Mono', monospace;
            font-size: 1.05rem;
            font-weight: 700;
            color: #FF4A0D;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }

        .stub-tier {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.1rem;
            color: #ffffff;
            text-transform: uppercase;
        }

        .stub-price {
            color: #737373;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Styles d'impression & export PDF optimisés */
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }

            .action-bar {
                display: none !important;
            }

            .ticket-card {
                box-shadow: none;
                border: 1.5px solid #000000 !important;
                border-radius: 20px !important;
                overflow: hidden !important;
                page-break-inside: avoid;
                margin-bottom: 2rem;
            }

            .ticket-stub {
                background: #ffffff !important;
                color: #000000 !important;
                border-left: 2px dashed #000000;
            }

            .ticket-stub::before,
            .ticket-stub::after {
                background: #ffffff;
            }

            .code-display {
                color: #000000 !important;
            }

            .stub-tier {
                color: #000000 !important;
            }

            .stub-price {
                color: #333333 !important;
            }
        }

        @media (max-width: 680px) {
            .ticket-card {
                grid-template-columns: 1fr;
            }

            .ticket-stub {
                border-left: none;
                border-top: 2px dashed #000000;
                padding: 2rem 1rem;
            }

            .ticket-stub::before {
                top: -12px;
                left: 50%;
                transform: translateX(-50%);
            }

            .ticket-stub::after {
                display: none;
            }
        }

        @media (max-width: 420px) {
            .ticket-details-grid {
                grid-template-columns: 1fr;
            }

            .ticket-buyer-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }
        }
    </style>
</head>

<body>

    <div class="action-bar">
        <a href="accueil.php" class="btn-action btn-back">
            <i class="fa-solid fa-arrow-left"></i> Retour au site
        </a>

        <div style="display: flex; gap: 0.75rem;">
            <?php
            // Construction du lien et fichier PDF du/des ticket(s)
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
            $public_link = $protocol . ($_SERVER['HTTP_HOST'] ?? '') . "/ticket-platform/client/telecharger-pdf.php";
            $pdf_query = "telecharger-pdf.php";

            if (!empty($code)) {
                $public_link .= "?code=" . urlencode($code);
                $pdf_query .= "?code=" . urlencode($code);
                $pdf_filename = "billet-" . preg_replace('/[^A-Za-z0-9\-]/', '', $code) . ".pdf";
            } elseif (!empty($order_id)) {
                $public_link .= "?order_id=" . $order_id;
                $pdf_query .= "?order_id=" . $order_id;
                $pdf_filename = "billets-commande-" . $order_id . ".pdf";
            } elseif (!empty($ticket_id)) {
                $public_link .= "?id=" . $ticket_id;
                $pdf_query .= "?id=" . $ticket_id;
                $pdf_filename = "billet-" . $ticket_id . ".pdf";
            } else {
                $pdf_filename = "billet-eventia.pdf";
            }

            $first_tk = $tickets[0] ?? [];
            $ev_name = $first_tk['event_name'] ?? '';
            $ev_date = !empty($first_tk['date_evenement']) ? date('d/m/Y', strtotime($first_tk['date_evenement'])) : '';
            $ev_time = !empty($first_tk['heure']) ? substr($first_tk['heure'], 0, 5) : '';
            $ev_lieu = $first_tk['lieu'] ?? '';
            $tk_type = $first_tk['type_ticket'] ?? '';
            $tk_code = $first_tk['code_unique'] ?? '';

            $wa_message = "🎟️ *Billet Officiel Eventia*\n"
                . "📌 *Événement :* " . $ev_name . "\n"
                . "🏷️ *Catégorie :* " . $tk_type . "\n"
                . "📅 *Date :* " . $ev_date . ($ev_time ? " à " . $ev_time : "") . "\n"
                . "📍 *Lieu :* " . $ev_lieu . "\n"
                . (!empty($tk_code) ? "🔑 *Code :* " . $tk_code . "\n" : "")
                . "📥 *Télécharger le PDF :* " . $public_link;
            ?>
            <button type="button" id="btn-whatsapp-share"
                data-pdf="<?php echo htmlspecialchars($pdf_query, ENT_QUOTES); ?>"
                data-filename="<?php echo htmlspecialchars($pdf_filename, ENT_QUOTES); ?>"
                data-message="<?php echo htmlspecialchars($wa_message, ENT_QUOTES); ?>"
                onclick="shareTicketPdfWhatsApp(this)" class="btn-action" style="background: #FF4A0D; color: white;"
                title="Envoyer le fichier PDF par WhatsApp">
                <i class="fa-brands fa-whatsapp" style="font-size: 1.1rem;"></i> Envoyer par WhatsApp (PDF)
            </button>

            <button onclick="window.print()" class="btn-action btn-primary">
                <i class="fa-solid fa-download"></i> Télécharger en PDF / Imprimer
            </button>
        </div>
    </div>

    <div class="ticket-wrapper">
        <?php foreach ($tickets as $index => $t): ?>
            <div class="ticket-card">
                <!-- Partie principale du billet -->
                <div class="ticket-main">
                    <div class="ticket-header">
                        <div class="ticket-brand">
                            <i class="fa-solid fa-ticket"></i> EVENTIA
                        </div>
                        <div class="ticket-badge">
                            <i class="fa-solid fa-circle-check"></i> <?php echo strtoupper($t['statut']); ?>
                        </div>
                    </div>

                    <h1 class="ticket-event-name"><?php echo htmlspecialchars($t['event_name']); ?></h1>

                    <div class="ticket-details-grid">
                        <div>
                            <small><i class="fa-regular fa-calendar"></i> Date & Heure</small>
                            <strong><?php echo date('d/m/Y', strtotime($t['date_evenement'])); ?> à
                                <?php echo substr($t['heure'], 0, 5); ?></strong>
                        </div>
                        <div>
                            <small><i class="fa-solid fa-location-dot"></i> Salle & Lieu</small>
                            <strong><?php echo htmlspecialchars($t['lieu']); ?></strong>
                        </div>
                        <div>
                            <small><i class="fa-solid fa-tag"></i> Catégorie de Billet</small>
                            <strong><?php echo htmlspecialchars($t['type_ticket']); ?></strong>
                        </div>
                        <?php if (!empty($t['place_numero'])): ?>
                            <div>
                                <small><i class="fa-solid fa-chair"></i> Place</small>
                                <strong><?php echo htmlspecialchars($t['place_numero']); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div>
                            <small><i class="fa-solid fa-coins"></i> Prix Payé</small>
                            <strong><?php echo number_format($t['prix'], 0, ',', ' '); ?> FCFA</strong>
                        </div>
                    </div>

                    <div class="ticket-buyer-bar">
                        <span>Titulaire :
                            <strong><?php echo htmlspecialchars($t['client_nom'] ?: 'Client'); ?></strong></span>
                        <span>Date d'achat : <?php echo date('d/m/Y H:i', strtotime($t['date_achat'])); ?></span>
                    </div>
                </div>

                <!-- Talon détachable avec QR Code pour le scan à l'entrée -->
                <div class="ticket-stub">
                    <div class="qr-box">
                        <img src="<?php echo htmlspecialchars($t['qr_code']); ?>" alt="QR Code d'accès">
                    </div>
                    <div class="code-display"><?php echo htmlspecialchars($t['code_unique']); ?></div>
                    <div class="stub-tier"><?php echo htmlspecialchars($t['type_ticket']); ?></div>
                    <div class="stub-price"><?php echo number_format($t['prix'], 0, ',', ' '); ?> F</div>
                    <small style="color: #737373; font-size: 0.68rem; margin-top: 0.5rem;">Présentez ce QR Code à l'agent de
                        contrôle</small>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="../js/share-ticket.js"></script>
</body>

</html>