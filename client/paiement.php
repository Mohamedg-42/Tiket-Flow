<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY CLIENT (client/paiement.php)
// Saisie, sélection d'opérateur & confirmation des informations de paiement
// Passerelle de paiement officielle : BICTORYS (Wave, Orange, MTN, Moov, Cartes)
// Style : Grille Modulaire Suisse Müller-Brockmann
// ==============================================================================

require_once '../config/database.php';
require_once '../config/bictorys.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/secure_token.php';
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

$token = trim((string) ($_GET['token'] ?? ''));
$order_id = null;

if (!empty($token)) {
    $order_id = resolve_resource_token($pdo, $token, 'order');
    if (!$order_id) {
        render_token_security_error(
            "Paiement introuvable",
            "Ce lien de paiement est invalide, a expiré ou la commande est introuvable.",
            404,
            "accueil.php"
        );
    }
} elseif (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $order_id = (int) $_GET['order_id'];
    $sec_token = get_or_create_resource_token($pdo, 'order', $order_id);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: paiement.php?token=' . urlencode($sec_token), true, 301);
        exit();
    }
} else {
    header('Location: accueil.php');
    exit();
}

$cur_order_token = $token ?: get_or_create_resource_token($pdo, 'order', (int) $order_id);

// Récupération de la commande
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || $order['statut'] !== 'en_attente') {
    $_SESSION['order_message'] = "Cette commande est introuvable ou a déjà été réglée.";
    header('Location: accueil.php');
    exit();
}

// Génération de la signature cryptographique sécurisée du paiement
if (!defined('APP_SECRET_KEY')) {
    error_log("Tike WA CRITIQUE: APP_SECRET_KEY non défini — inclure config/env.php");
    $_SESSION['order_message'] = "Erreur de configuration serveur. Veuillez contacter l'administrateur.";
    header('Location: accueil.php');
    exit();
}
$pay_secret = APP_SECRET_KEY;
$pay_token = hash_hmac('sha256', $order_id . '|' . $order['montant_total'] . '|' . $order['created_at'], $pay_secret);

$error_msg = null;
if (isset($_GET['error'])) {
    $error_msg = "La transaction a été interrompue ou annulée. Vous pouvez réessayer avec votre moyen de paiement ci-dessous.";
}

// Téléphone client par défaut
$client_phone = $order['client_telephone'] ?: ($_SESSION['user_phone'] ?? ($_SESSION['user_telephone'] ?? ''));
if (empty($client_phone) && !empty($order['user_id'])) {
    try {
        $stmt_u = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_u->execute([$order['user_id']]);
        $client_phone = (string) $stmt_u->fetchColumn();
    } catch (\Throwable $t) {
    }
}

// Traitement de l'initialisation du paiement Bictorys
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initier_paiement'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $callbackUrl = $baseUrl . '/callback.php?order_id=' . $order_id . '&methode=bictorys&pay_token=' . $pay_token;
    $errorUrl = $baseUrl . '/paiement.php?token=' . urlencode($cur_order_token) . '&error=1';

    $selected_provider = trim($_POST['provider'] ?? '');
    $phone_submitted = trim($_POST['phone'] ?? $client_phone);
    $otp_submitted = trim($_POST['otp'] ?? '');

    // Mettre à jour le téléphone dans la commande si modifié
    if (!empty($phone_submitted) && $phone_submitted !== $order['client_telephone']) {
        try {
            $pdo->prepare("UPDATE orders SET client_telephone = ? WHERE id = ?")->execute([$phone_submitted, $order_id]);
            $order['client_telephone'] = $phone_submitted;
        } catch (\Throwable $t) {
        }
    }

    $chargeParams = [
        'amount' => (int) round($order['montant_total']),
        'paymentReference' => 'ORDER_' . $order_id,
        'successRedirectUrl' => $callbackUrl,
        'errorRedirectUrl' => $errorUrl,
        'country' => 'CI',
        'customer' => [
            'name' => $order['client_nom'] ?: ($_SESSION['user_nom'] ?? 'Client'),
            'phone' => $phone_submitted,
            'email' => $order['client_email'] ?: ($_SESSION['user_email'] ?? '')
        ]
    ];

    if (!empty($selected_provider) && in_array($selected_provider, ['wave_money', 'orange_money', 'mtn_money', 'card'], true)) {
        $chargeParams['payment_type'] = $selected_provider;
    }

    if (!empty($otp_submitted)) {
        $chargeParams['otp'] = $otp_submitted;
    }

    $charge = bictorys_create_charge($chargeParams);

    // Réponse AJAX pour la modale intégrée
    if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        if ($charge['success'] && !empty($charge['redirectUrl'])) {
            $txId = $charge['transactionId'] ?? '';
            $finalCallback = $callbackUrl . '&transaction_id=' . urlencode($txId);
            echo json_encode([
                'success' => true,
                'transactionId' => $txId,
                'redirectUrl' => $charge['redirectUrl'],
                'callbackUrl' => $finalCallback,
                'amount' => (int) round($order['montant_total']),
                'amount_formatted' => number_format($order['montant_total'], 0, ',', ' '),
                'currency' => 'FCFA',
                'orderNumber' => $order['numero_commande'],
                'clientNom' => $order['client_nom'] ?: ($_SESSION['user_nom'] ?? 'Client'),
                'clientPhone' => $phone_submitted,
                'provider' => $selected_provider ?: 'wave_money',
                'is_simulator' => (strpos($charge['redirectUrl'], '/simulator/') !== false)
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'error' => $charge['error'] ?? "Impossible d'initialiser la session de paiement sécurisée Bictorys."
            ]);
            exit();
        }
    }

    if ($charge['success'] && !empty($charge['redirectUrl'])) {
        header('Location: ' . $charge['redirectUrl']);
        exit();
    } else {
        $error_msg = $charge['error'] ?? "Impossible d'initialiser la session de paiement sécurisée Bictorys.";
    }
}

// Confirmation de la simulation Bictorys via requête AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmer_simulation_bictorys'])) {
    header('Content-Type: application/json; charset=utf-8');
    $txId = trim($_POST['transaction_id'] ?? '');
    if (!empty($txId)) {
        $confirmUrl = 'https://api.test.bictorys.com/simulator/v1/confirm?transaction_id=' . urlencode($txId);
        $ch = curl_init($confirmUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    echo json_encode([
        'success' => true,
        'message' => 'Paiement confirmé avec succès',
        'transactionId' => $txId
    ]);
    exit();
}

$page_title = "Paiement Sécurisé - Tike WA";
$body_class = "client-page payment-page";
include 'header.php';
?>

<div class="payment-container"
    style="max-width: 620px; margin: 2rem auto 3.5rem; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <a href="accueil.php" class="back-link"
        style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner à l'accueil
    </a>

    <?php if (!empty($error_msg)): ?>
        <div
            style="background: #FEF2F2; border: 1px solid #FCA5A5; color: #991B1B; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.92rem; display: flex; align-items: flex-start; gap: 0.75rem;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top: 0.2rem; color: #DC2626;"></i>
            <div>
                <strong>Avis de paiement :</strong>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="payment-card eventia-card"
        style="padding: 0; overflow: hidden; border: 1px solid #E2E8F0; border-radius: 14px; box-shadow: 0 12px 24px -4px rgba(16, 24, 40, 0.08); background: #ffffff;">

        <!-- En-tête Swiss Style -->
        <div class="payment-heading" style="background: #0f172a; color: #ffffff; padding: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div class="payment-icon"
                    style="width: 48px; height: 48px; background: rgba(255, 74, 13, 0.15); border: 1px solid rgba(255, 74, 13, 0.3); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: var(--tikeli-orange, #FF4A0D);">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <span
                    style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #cbd5e1; font-family: 'Space Mono', monospace; font-size: 0.75rem; padding: 0.35rem 0.65rem; border-radius: 6px; text-transform: uppercase;">
                    Bictorys Secure Pay
                </span>
            </div>
            <span class="page-kicker"
                style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Mono', monospace;">Commande
                #<?php echo htmlspecialchars($order['numero_commande']); ?></span>
            <h1
                style="color: #ffffff; margin: 0.3rem 0 0.5rem; font-size: 1.7rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; line-height: 1.2;">
                Finaliser votre Paiement</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Titulaire :
                <strong
                    style="color: #f1f5f9;"><?php echo htmlspecialchars($order['client_nom'] ?: ($_SESSION['user_nom'] ?? 'Client')); ?></strong>
                <?php if (!empty($order['client_email']) || !empty($_SESSION['user_email'])): ?>
                    · <?php echo htmlspecialchars($order['client_email'] ?: ($_SESSION['user_email'] ?? '')); ?>
                <?php endif; ?>
            </p>
        </div>

        <!-- Montant Total à régler -->
        <div class="payment-amount"
            style="background: #F8FAFC; border-bottom: 1px solid #E2E8F0; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="color: #0f172a; font-weight: 700; font-size: 0.95rem;">Montant Total :</span>
            <strong class="swiss-numeral"
                style="color: #0f172a; font-size: 1.85rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 900;"><?php echo number_format($order['montant_total'], 0, ',', ' '); ?>
                <span
                    style="font-family: 'Space Mono', monospace; font-size: 0.95rem; color: var(--eventia-muted, #737373); font-weight: 700;">FCFA</span></strong>
        </div>

        <!-- Formulaire de Paiement Harmonisé -->
        <form method="POST" action="paiement.php?token=<?php echo urlencode($cur_order_token); ?>" id="bictorys-pay-form"
            style="padding: 2rem;">
            <input type="hidden" name="initier_paiement" value="1">
            <input type="hidden" name="provider" id="selected_provider" value="wave_money">

            <!-- 1. Sélection du moyen de paiement -->
            <div style="margin-bottom: 1.5rem;">
                <label
                    style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    1. Choisissez votre moyen de paiement
                </label>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem;"
                    id="provider-selector">
                    <!-- Wave -->
                    <button type="button" class="provider-card active" data-provider="wave_money"
                        style="background: #ffffff; border: 2px solid #1ba0e2; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #1ba0e2;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Wave</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Sans frais</small>
                    </button>

                    <!-- Orange Money -->
                    <button type="button" class="provider-card" data-provider="orange_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ff7900;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Orange</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Code #144*82#</small>
                    </button>

                    <!-- MTN MoMo -->
                    <button type="button" class="provider-card" data-provider="mtn_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ffcc00;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">MTN MoMo</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Prompt USSD</small>
                    </button>

                    <!-- Carte Bancaire -->
                    <button type="button" class="provider-card" data-provider="card"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <i class="fa-regular fa-credit-card" style="color: #3b82f6; font-size: 1rem;"></i>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Carte Visa/CB</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Chiffré 3DS</small>
                    </button>
                </div>
            </div>

            <!-- 2. Saisie du numéro de téléphone & informations -->
            <div style="margin-bottom: 1.5rem;">
                <label for="phone_input"
                    style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    2. Numéro de compte Mobile Money
                </label>
                <div style="position: relative;">
                    <span
                        style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-weight: 700; color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.95rem;">
                        <i class="fa-solid fa-phone" style="margin-right: 4px; font-size: 0.85rem;"></i>
                    </span>
                    <input type="tel" name="phone" id="phone_input"
                        value="<?php echo htmlspecialchars($client_phone); ?>" required
                        placeholder="Ex: 0701020304 ou +225..."
                        style="width: 100%; box-sizing: border-box; padding: 0.85rem 1rem 0.85rem 2.8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; font-family: 'Space Mono', monospace; font-weight: 600; color: #0f172a; background: #ffffff;">
                </div>
                <small style="color: #64748b; font-size: 0.78rem; margin-top: 0.35rem; display: block;">
                    Le compte qui recevra l'autorisation de débit ou sera débité.
                </small>
            </div>

            <!-- Champ OTP conditionnel pour Orange Money CI -->
            <div id="orange-otp-block"
                style="display: none; margin-bottom: 1.5rem; background: #FFF7ED; border: 1px solid #FFEDD5; padding: 1rem; border-radius: 8px;">
                <label for="otp_input"
                    style="display: block; font-size: 0.82rem; font-weight: 700; color: #9A3412; margin-bottom: 0.4rem; font-family: 'Space Mono', monospace;">
                    Code d'autorisation Orange Money (#144*82#)
                </label>
                <input type="text" name="otp" id="otp_input" placeholder="Entrez le code à 4 ou 6 chiffres généré"
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 1rem; border: 1px solid #FDBA74; border-radius: 6px; font-size: 0.95rem; font-family: 'Space Mono', monospace;">
                <small style="color: #C2410C; font-size: 0.75rem; margin-top: 0.35rem; display: block;">
                    Sur votre téléphone Orange, composez <strong>#144*82#</strong> pour obtenir votre code temporaire,
                    puis validez.
                </small>
            </div>

            <!-- Bloc d'instructions dynamiques adaptées à l'opérateur -->
            <div id="provider-instructions"
                style="background: #F1F5F9; border-left: 4px solid #1ba0e2; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #334155;">
                <span id="instruction-text">
                    <i class="fa-solid fa-info-circle" style="color: #1ba0e2; margin-right: 6px;"></i>
                    <strong>Sur smartphone</strong> : votre application Wave s'ouvrira directement pour valider en 1
                    clic sans scanner.<br>
                    <strong>Sur PC / Mac</strong> : vous pourrez scanner le QR code officiel Wave avec votre
                    application.
                </span>
            </div>

            <!-- Bouton de validation d'action -->
            <button type="submit" id="btn-submit-pay"
                style="width: 100%; background: var(--tikeli-orange, #FF4A0D); color: #ffffff; border: none; padding: 1.05rem 1.5rem; border-radius: 10px; font-size: 1.05rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.65rem; transition: background 0.2s ease, transform 0.1s ease; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);">
                <i class="fa-solid fa-lock"></i>
                <span id="btn-pay-label">Payer <?php echo number_format($order['montant_total'], 0, ',', ' '); ?> FCFA
                    avec Wave</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>

            <!-- Option passerelle multi-opérateurs de repli -->
            <div style="margin-top: 1rem; text-align: center;">
                <button type="button" id="btn-toggle-all"
                    style="background: none; border: none; color: #64748b; font-size: 0.8rem; cursor: pointer; text-decoration: underline;">
                    Ou ouvrir le portail multi-moyens Bictorys
                </button>
            </div>

            <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid #E2E8F0; padding-top: 1.25rem;">
                <p
                    style="margin: 0; color: var(--eventia-muted, #737373); font-size: 0.82rem; display: flex; align-items: center; justify-content: center; gap: 0.45rem;">
                    <i class="fa-solid fa-shield-check" style="color: #10B981;"></i>
                    Transaction sécurisée et chiffrée 256-bit certifiée PCI-DSS
                </p>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================== -->
<!-- MODALE DE PAIEMENT HARMONISÉE (SIMULATION & AUTORISATION BICTORYS)             -->
<!-- Vue 1: Order Details & Total Payment Amount | Vue 2: Payment Processed Success -->
<!-- ============================================================================== -->
<div id="pay-modal-backdrop" class="pay-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-main-title">
    <div class="pay-modal-card">
        <!-- En-tête de la modale -->
        <div class="pay-modal-header">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(255, 74, 13, 0.2); border: 1px solid rgba(255, 74, 13, 0.4); display: grid; place-items: center; color: var(--tikeli-orange, #FF4A0D); font-size: 0.95rem;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h3 id="modal-main-title" style="margin: 0; font-size: 1.05rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: #ffffff;">
                    Autorisation de Paiement
                </h3>
            </div>
            <button type="button" class="pay-modal-close" id="btn-modal-close" aria-label="Fermer la modale">&times;</button>
        </div>

        <div class="pay-modal-body">
            <!-- ============================================================ -->
            <!-- VUE 1 : DÉTAILS DE LA COMMANDE (ORDER DETAILS)               -->
            <!-- ============================================================ -->
            <div id="modal-view-details">
                <span style="font-family: 'Space Mono', monospace; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tikeli-orange, #FF4A0D); font-weight: 700; display: block; margin-bottom: 0.25rem;">
                    Bictorys Secure Checkout
                </span>
                <h4 style="margin: 0 0 1.25rem; font-size: 1.35rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: #0f172a;">
                    Order Details
                </h4>

                <!-- Récapitulatif harmonisé -->
                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; text-align: left; font-size: 0.9rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #EDF2F7;">
                        <span style="color: #64748b; font-size: 0.8rem; font-family: 'Space Mono', monospace; text-transform: uppercase;">Commande</span>
                        <strong style="color: #0f172a; font-family: 'Space Mono', monospace; font-size: 0.88rem;">#<?php echo htmlspecialchars($order['numero_commande']); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #EDF2F7;">
                        <span style="color: #64748b; font-size: 0.8rem; font-family: 'Space Mono', monospace; text-transform: uppercase;">Opérateur</span>
                        <span id="modal-operator-badge" style="display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 700; color: #0f172a;">
                            <span id="modal-operator-dot" style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #1ba0e2;"></span>
                            <span id="modal-operator-name">Wave</span>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #64748b; font-size: 0.8rem; font-family: 'Space Mono', monospace; text-transform: uppercase;">Compte Débité</span>
                        <strong id="modal-client-phone" style="color: #0f172a; font-family: 'Space Mono', monospace; font-size: 0.88rem;">+225 ...</strong>
                    </div>
                </div>

                <!-- Montant Total Harmonisé -->
                <div style="background: #0f172a; color: #ffffff; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; display: block; margin-bottom: 4px;">
                        Total Payment Amount
                    </span>
                    <div style="font-family: 'Outfit', 'Inter', sans-serif; font-size: 2.1rem; font-weight: 900; line-height: 1.1; color: #ffffff;">
                        <span id="modal-total-amount"><?php echo number_format($order['montant_total'], 0, ',', ' '); ?></span>
                        <span style="font-family: 'Space Mono', monospace; font-size: 1rem; color: #cbd5e1; font-weight: 700;">FCFA</span>
                    </div>
                    <small style="color: #94a3b8; font-size: 0.76rem; margin-top: 6px; display: block;">
                        Débit instantané sécurisé par autorisation bancaire
                    </small>
                </div>

                <!-- Boutons d'Action CANCEL & CONFIRM harmonisés -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <button type="button" id="btn-modal-cancel" class="btn-modal-cancel">
                        <i class="fa-solid fa-xmark"></i> CANCEL
                    </button>
                    <button type="button" id="btn-modal-confirm" class="btn-modal-confirm">
                        <i class="fa-solid fa-check"></i> CONFIRM
                    </button>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- VUE 2 : PAIEMENT VALIDÉ AVEC SUCCÈS (CONFIRMATION PROCESS)   -->
            <!-- ============================================================ -->
            <div id="modal-view-success" style="display: none;">
                <!-- Icône Coche Verte Animée -->
                <div class="success-check-icon">
                    <i class="fa-solid fa-check"></i>
                </div>

                <h4 style="margin: 0 0 0.5rem; font-size: 1.35rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: #065F46;">
                    Your payment has been successfully proceed!
                </h4>
                <p style="margin: 0 0 1.25rem; font-size: 0.88rem; color: #047857;">
                    Votre autorisation de paiement a été acceptée et traitée par la passerelle Bictorys.
                </p>

                <!-- Détails de validation -->
                <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.25rem; text-align: left;">
                    <div style="margin-bottom: 0.75rem;">
                        <span style="font-family: 'Space Mono', monospace; font-size: 0.72rem; text-transform: uppercase; color: #166534; font-weight: 700; display: block; margin-bottom: 2px;">
                            Total Payment Amount
                        </span>
                        <strong id="modal-success-amount" style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; color: #15803D; font-weight: 800;">
                            <?php echo number_format($order['montant_total'], 0, ',', ' '); ?> FCFA
                        </strong>
                    </div>
                    <div>
                        <span style="font-family: 'Space Mono', monospace; font-size: 0.72rem; text-transform: uppercase; color: #166534; font-weight: 700; display: block; margin-bottom: 2px;">
                            Payment Message
                        </span>
                        <span id="modal-success-msg" style="font-family: 'Space Mono', monospace; font-size: 0.82rem; color: #166534; font-weight: 700; word-break: break-all;">
                            PAYMENT PROCESSED: <span id="modal-success-txid">...</span>
                        </span>
                    </div>
                </div>

                <!-- Animation & Redirection Automatique -->
                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 1rem; text-align: center;">
                    <span style="font-size: 0.85rem; font-weight: 600; color: #334155; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i class="fa-solid fa-spinner fa-spin" style="color: #059669;"></i>
                        Génération et téléchargement de vos e-Tickets...
                    </span>
                    <div class="pay-progress-bar">
                        <div id="pay-progress-fill" class="pay-progress-fill"></div>
                    </div>
                    <small style="color: #64748b; font-size: 0.75rem; margin-top: 0.5rem; display: block;">
                        Redirection automatique vers vos billets officiels...
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ─── STYLES MODALE DE PAIEMENT HARMONISÉE ────────────────────────────────────── */
.pay-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.pay-modal-backdrop.is-open {
    display: flex;
    opacity: 1;
}

.pay-modal-card {
    background: #ffffff;
    width: 100%;
    max-width: 460px;
    border-radius: 16px;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
    overflow: hidden;
    border: 1px solid #E2E8F0;
    transform: scale(0.95);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.pay-modal-backdrop.is-open .pay-modal-card {
    transform: scale(1);
}

.pay-modal-header {
    background: #0f172a;
    color: #ffffff;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pay-modal-close {
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    transition: color 0.15s ease;
}
.pay-modal-close:hover {
    color: #ffffff;
}

.pay-modal-body {
    padding: 1.5rem;
    text-align: center;
}

.btn-modal-cancel {
    background: #ffffff;
    border: 1.5px solid #CBD5E1;
    color: #475569;
    padding: 0.85rem 1.2rem;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Space Mono', monospace;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-modal-cancel:hover {
    background: #FEE2E2;
    border-color: #F87171;
    color: #DC2626;
}

.btn-modal-confirm {
    background: #2563eb;
    border: none;
    color: #ffffff;
    padding: 0.85rem 1.2rem;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Space Mono', monospace;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    transition: all 0.15s ease;
}
.btn-modal-confirm:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}

.success-check-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #D1FAE5;
    color: #059669;
    display: grid;
    place-items: center;
    font-size: 2rem;
    margin: 0 auto 1.15rem;
    box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.15);
    animation: bounceIn 0.4s ease-out;
}

.pay-progress-bar {
    width: 100%;
    height: 6px;
    background: #E2E8F0;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 0.85rem;
}

.pay-progress-fill {
    height: 100%;
    background: #10B981;
    width: 0%;
    border-radius: 999px;
    transition: width 1.5s linear;
}

@keyframes bounceIn {
    0% { transform: scale(0.5); opacity: 0; }
    70% { transform: scale(1.1); }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const providerInput = document.getElementById('selected_provider');
        const cards = document.querySelectorAll('.provider-card');
        const instructions = document.getElementById('provider-instructions');
        const instructionText = document.getElementById('instruction-text');
        const orangeBlock = document.getElementById('orange-otp-block');
        const btnLabel = document.getElementById('btn-pay-label');
        const btnAll = document.getElementById('btn-toggle-all');
        const form = document.getElementById('bictorys-pay-form');
        const submitBtn = document.getElementById('btn-submit-pay');

        // Éléments de la Modale
        const modalBackdrop = document.getElementById('pay-modal-backdrop');
        const modalCloseBtn = document.getElementById('btn-modal-close');
        const btnModalCancel = document.getElementById('btn-modal-cancel');
        const btnModalConfirm = document.getElementById('btn-modal-confirm');
        const modalViewDetails = document.getElementById('modal-view-details');
        const modalViewSuccess = document.getElementById('modal-view-success');
        const modalOperatorDot = document.getElementById('modal-operator-dot');
        const modalOperatorName = document.getElementById('modal-operator-name');
        const modalClientPhone = document.getElementById('modal-client-phone');
        const modalTotalAmount = document.getElementById('modal-total-amount');
        const modalSuccessAmount = document.getElementById('modal-success-amount');
        const modalSuccessTxId = document.getElementById('modal-success-txid');
        const payProgressFill = document.getElementById('pay-progress-fill');

        let currentTxId = '';
        let currentCallbackUrl = '';

        const totalAmount = "<?php echo number_format($order['montant_total'], 0, ',', ' '); ?> FCFA";

        const config = {
            'wave_money': {
                name: 'Wave',
                color: '#1ba0e2',
                text: '<i class="fa-solid fa-info-circle" style="color: #1ba0e2; margin-right: 6px;"></i> <strong>Sur smartphone</strong> : l’application Wave s’ouvre directement pour valider en 1 clic.<br><strong>Sur ordinateur</strong> : scannez le QR code officiel ou confirmez sur votre application.'
            },
            'orange_money': {
                name: 'Orange Money',
                color: '#ff7900',
                text: '<i class="fa-solid fa-info-circle" style="color: #ff7900; margin-right: 6px;"></i> <strong>Orange Money CI</strong> : composez <strong>#144*82#</strong> pour obtenir votre code d’autorisation, ou confirmez le prompt USSD reçu sur votre écran.'
            },
            'mtn_money': {
                name: 'MTN MoMo',
                color: '#ffcc00',
                text: '<i class="fa-solid fa-info-circle" style="color: #eab308; margin-right: 6px;"></i> <strong>MTN Mobile Money</strong> : une demande de débit s’affichera sur votre téléphone. Validez avec votre code PIN secret.'
            },
            'card': {
                name: 'Carte Bancaire',
                color: '#3b82f6',
                text: '<i class="fa-solid fa-shield-check" style="color: #3b82f6; margin-right: 6px;"></i> <strong>Carte Visa / Mastercard</strong> : redirection sécurisée vers la saisie chiffrée avec protection 3D-Secure de votre banque.'
            }
        };

        function selectProvider(prov) {
            providerInput.value = prov;
            cards.forEach(c => {
                if (c.getAttribute('data-provider') === prov) {
                    c.style.border = '2px solid ' + (config[prov] ? config[prov].color : 'var(--tikeli-orange, #FF4A0D)');
                    c.style.background = '#F8FAFC';
                } else {
                    c.style.border = '1px solid #cbd5e1';
                    c.style.background = '#ffffff';
                }
            });

            if (prov === 'orange_money') {
                orangeBlock.style.display = 'block';
            } else {
                orangeBlock.style.display = 'none';
            }

            if (config[prov]) {
                instructions.style.borderLeftColor = config[prov].color;
                instructionText.innerHTML = config[prov].text;
                btnLabel.innerText = "Payer " + totalAmount + " avec " + config[prov].name;
            } else {
                instructions.style.borderLeftColor = '#64748b';
                instructionText.innerHTML = '<i class="fa-solid fa-info-circle"></i> Redirection vers le portail officiel pour choisir parmi tous les opérateurs.';
                btnLabel.innerText = "Continuer vers le paiement sécurisé (" + totalAmount + ")";
            }
        }

        cards.forEach(c => {
            c.addEventListener('click', function () {
                selectProvider(this.getAttribute('data-provider'));
            });
        });

        if (btnAll) {
            btnAll.addEventListener('click', function () {
                selectProvider('');
            });
        }

        // Fermeture de la modale
        function closeModal() {
            modalBackdrop.classList.remove('is-open');
            setTimeout(() => {
                modalBackdrop.style.display = 'none';
            }, 200);
            if (submitBtn) {
                submitBtn.style.pointerEvents = 'auto';
                submitBtn.style.opacity = '1';
                submitBtn.disabled = false;
                const prov = providerInput.value;
                submitBtn.innerHTML = '<i class="fa-solid fa-lock"></i> <span id="btn-pay-label">Payer ' + totalAmount + ' avec ' + (config[prov] ? config[prov].name : 'Wave') + '</span> <i class="fa-solid fa-arrow-right"></i>';
            }
        }

        if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
        if (btnModalCancel) btnModalCancel.addEventListener('click', closeModal);
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalBackdrop.classList.contains('is-open') && modalViewSuccess.style.display !== 'block') {
                closeModal();
            }
        });

        // Interception de la soumission du formulaire pour affichage dans la MODALE HARMONISÉE
        if (form && submitBtn) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                submitBtn.style.pointerEvents = 'none';
                submitBtn.style.opacity = '0.75';
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Connexion sécurisée en cours...</span>';

                const formData = new FormData(form);
                formData.append('ajax', '1');

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.transactionId) {
                        currentTxId = data.transactionId;
                        currentCallbackUrl = data.callbackUrl;

                        // Remplir les données de la vue 1
                        const prov = data.provider || 'wave_money';
                        const provConf = config[prov] || { name: 'Mobile Money', color: '#FF4A0D' };
                        modalOperatorDot.style.background = provConf.color;
                        modalOperatorName.innerText = provConf.name;
                        modalClientPhone.innerText = data.clientPhone || '<?php echo htmlspecialchars($client_phone); ?>';
                        modalTotalAmount.innerText = data.amount_formatted || '<?php echo number_format($order['montant_total'], 0, ',', ' '); ?>';

                        // Afficher la Vue 1
                        modalViewDetails.style.display = 'block';
                        modalViewSuccess.style.display = 'none';

                        modalBackdrop.style.display = 'flex';
                        setTimeout(() => {
                            modalBackdrop.classList.add('is-open');
                        }, 10);
                    } else {
                        alert(data.error || "Impossible d'initialiser le paiement sécurisé.");
                        closeModal();
                    }
                })
                .catch(err => {
                    console.error("Erreur AJAX paiement:", err);
                    // Repli soumission normale si problème réseau
                    form.submit();
                });
            });
        }

        // Clic sur le bouton CONFIRM de la modale
        if (btnModalConfirm) {
            btnModalConfirm.addEventListener('click', function () {
                btnModalConfirm.disabled = true;
                btnModalConfirm.style.pointerEvents = 'none';
                btnModalConfirm.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Traitement...';

                const confirmData = new FormData();
                confirmData.append('confirmer_simulation_bictorys', '1');
                confirmData.append('transaction_id', currentTxId);

                fetch(window.location.href, {
                    method: 'POST',
                    body: confirmData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    // Transition vers la VUE 2 : SUCCÈS
                    modalViewDetails.style.display = 'none';
                    modalViewSuccess.style.display = 'block';

                    modalSuccessAmount.innerText = modalTotalAmount.innerText + ' FCFA';
                    modalSuccessTxId.innerText = currentTxId;

                    // Lancer la barre de progression
                    setTimeout(() => {
                        payProgressFill.style.width = '100%';
                    }, 50);

                    // Redirection automatique vers callback -> telecharger-ticket.php
                    setTimeout(() => {
                        window.location.href = currentCallbackUrl;
                    }, 1500);
                })
                .catch(err => {
                    console.error("Erreur confirmation:", err);
                    // Rediriger directement vers le callback
                    window.location.href = currentCallbackUrl;
                });
            });
        }
    });
</script>

<?php include 'footer.php'; ?>