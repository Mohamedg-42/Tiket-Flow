<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY CLIENT (client/paiement.php)
// Saisie, sélection d'opérateur & confirmation des informations de paiement
// Passerelle de paiement officielle : BICTORYS (Wave, Orange, MTN, Moov, Cartes)
// Style : Grille Modulaire Suisse Müller-Brockmann
// ==============================================================================

require_once '../config/database.php';
require_once '../config/bictorys.php';
session_start();

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$order_id) {
    header('Location: accueil.php');
    exit();
}

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
$client_phone = $order['client_telephone'] ?: ($_SESSION['user_phone'] ?? '');

// Traitement de l'initialisation du paiement Bictorys
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initier_paiement'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $callbackUrl = $baseUrl . '/callback.php?order_id=' . $order_id . '&methode=bictorys&pay_token=' . $pay_token;
    $errorUrl = $baseUrl . '/paiement.php?order_id=' . $order_id . '&error=1';

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
    if ($charge['success'] && !empty($charge['redirectUrl'])) {
        header('Location: ' . $charge['redirectUrl']);
        exit();
    } else {
        $error_msg = $charge['error'] ?? "Impossible d'initialiser la session de paiement sécurisée Bictorys.";
    }
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
        <form method="POST" action="paiement.php?order_id=<?php echo $order_id; ?>" id="bictorys-pay-form"
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

        if (form && submitBtn) {
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.75';
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Connexion sécurisée en cours...</span>';
            });
        }
    });
</script>

<?php include 'footer.php'; ?>