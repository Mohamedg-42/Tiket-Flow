<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY DES CONTRIBUTIONS (client/paiement-cotisation.php)
// Passerelle de paiement officielle : BICTORYS (Wave, Orange, MTN, Moov, Cartes)
// ==============================================================================

require_once '../config/database.php';
require_once '../config/bictorys.php';
session_start();

require_once '../includes/secure_token.php';
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

$token = trim((string) ($_GET['token'] ?? ''));
$cotisation_id = null;

if (!empty($token)) {
    $cotisation_id = resolve_resource_token($pdo, $token, 'cotisation_payment');
    if (!$cotisation_id) {
        render_token_security_error(
            "Contribution introuvable",
            "Ce lien de paiement de cotisation est invalide, a expiré ou a été désactivé.",
            404,
            "accueil.php?onglet=cotisations"
        );
    }
} elseif (isset($_GET['cotisation_id']) && is_numeric($_GET['cotisation_id'])) {
    $cotisation_id = (int) $_GET['cotisation_id'];
    $sec_token = get_or_create_resource_token($pdo, 'cotisation_payment', $cotisation_id);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: paiement-cotisation.php?token=' . urlencode($sec_token), true, 301);
        exit();
    }
} else {
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// Récupération de la contribution en attente
$stmt = $pdo->prepare('
    SELECT ct.*, c.titre AS campagne_titre, c.visibilite, c.access_token
    FROM cotisations ct
    LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
    WHERE ct.id = ?
');
$stmt->execute([$cotisation_id]);
$cotisation = $stmt->fetch();

if (!$cotisation || $cotisation['statut'] !== 'en_attente') {
    $_SESSION['cotisation_message'] = "Cette contribution est introuvable ou a déjà été réglée.";
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$is_private_campagne = ($cotisation['visibilite'] ?? 'public') === 'prive';
$campagne_token = (string) ($_GET['campagne_token'] ?? '');
if ($is_private_campagne) {
    $expected_token = (string) ($cotisation['access_token'] ?? '');
    if ($expected_token === '' || !hash_equals($expected_token, $campagne_token)) {
        http_response_code(403);
        header('Location: accueil.php?onglet=cotisations');
        exit();
    }
}

$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
$cotisation_token = hash_hmac('sha256', $cotisation['id'] . '|' . $cotisation['montant'] . '|' . $cotisation['created_at'], $pay_secret);
$cur_cotisation_token = get_or_create_resource_token($pdo, 'cotisation_payment', (int) $cotisation['id']);

$error_msg = null;
if (isset($_GET['error'])) {
    $error_msg = "Le don a été interrompu. Vous pouvez relancer le paiement sécurisé ci-dessous.";
}

// Initialisation Bictorys
$cot_phone = $cotisation['telephone'] ?: ($_SESSION['user_phone'] ?? ($_SESSION['user_telephone'] ?? ''));
if (empty($cot_phone) && !empty($cotisation['user_id'])) {
    try {
        $stmt_u = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_u->execute([$cotisation['user_id']]);
        $cot_phone = (string) $stmt_u->fetchColumn();
    } catch (\Throwable $t) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initier_paiement_cotisation'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $callbackUrl = $baseUrl . '/callback-cotisation.php?cotisation_id=' . $cotisation_id . '&methode=bictorys&cotisation_token=' . $cotisation_token . ($is_private_campagne ? ('&token=' . urlencode($campagne_token)) : '');
    $errorUrl = $baseUrl . '/paiement-cotisation.php?token=' . urlencode($cur_cotisation_token) . '&error=1';

    $selected_provider = trim($_POST['provider'] ?? '');
    $phone_submitted   = trim($_POST['phone'] ?? $cot_phone);
    $otp_submitted     = trim($_POST['otp'] ?? '');

    if (!empty($phone_submitted) && $phone_submitted !== $cotisation['telephone']) {
        try {
            $pdo->prepare("UPDATE cotisations SET telephone = ? WHERE id = ?")->execute([$phone_submitted, $cotisation_id]);
            $cotisation['telephone'] = $phone_submitted;
        } catch (\Throwable $t) {}
    }

    $chargeParams = [
        'amount'             => (int) round($cotisation['montant']),
        'paymentReference'   => 'COT_' . $cotisation_id,
        'successRedirectUrl' => $callbackUrl,
        'errorRedirectUrl'   => $errorUrl,
        'country'            => 'CI',
        'customer'           => [
            'name'  => $cotisation['nom'] ?: ($_SESSION['user_nom'] ?? 'Donateur'),
            'phone' => $phone_submitted,
            'email' => $cotisation['email'] ?: ($_SESSION['user_email'] ?? '')
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
        $error_msg = $charge['error'] ?? "Impossible d'initialiser la session de paiement de contribution via Bictorys.";
    }
}

$page_title = "Paiement de votre Contribution - Tike WA";
$body_class = "client-page payment-page";
include 'header.php';
?>

<div class="payment-container" style="max-width: 620px; margin: 2rem auto 3.5rem; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <?php $back_url = !empty($cotisation['campagne_id']) ? ('cotisation.php?id=' . (int)$cotisation['campagne_id'] . ($is_private_campagne ? '&token=' . urlencode($campagne_token) : '')) : 'accueil.php?onglet=cotisations'; ?>
    <a href="<?php echo $back_url; ?>" class="back-link"
        style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600; font-size: 0.88rem;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner à la campagne
    </a>

    <?php if (!empty($error_msg)): ?>
        <div style="background: #FEF2F2; border: 1px solid #FCA5A5; color: #991B1B; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.92rem; display: flex; align-items: flex-start; gap: 0.75rem;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top: 0.2rem; color: #DC2626;"></i>
            <div>
                <strong>Avis de paiement :</strong>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="payment-card eventia-card"
        style="padding: 0; overflow: hidden; border: 1px solid #E2E8F0; border-radius: 14px; box-shadow: 0 12px 24px -4px rgba(16, 24, 40, 0.08); background: #ffffff;">
        <div class="payment-heading"
            style="background: #0f172a; color: #ffffff; padding: 1.75rem 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                <div class="payment-icon"
                    style="width: 44px; height: 44px; background: rgba(255, 74, 13, 0.18); border: 1px solid rgba(255, 74, 13, 0.35); border-radius: 10px; display: grid; place-items: center; font-size: 1.2rem; color: var(--tikeli-orange, #FF4A0D); flex-shrink: 0;">
                    <i class="fa-solid fa-hand-holding-heart"></i>
                </div>
                <span style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #cbd5e1; font-family: 'Space Mono', monospace; font-size: 0.75rem; padding: 0.35rem 0.65rem; border-radius: 6px; text-transform: uppercase;">
                    Bictorys Secure Pay
                </span>
            </div>
            <span class="page-kicker"
                style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Mono', monospace;">Contribution #<?php echo (int) $cotisation['id']; ?></span>
            <h1 style="color: #ffffff; margin: 0.2rem 0 0.4rem; font-size: 1.55rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; line-height: 1.2;">
                Finaliser votre Don
            </h1>
            <p style="color: #94a3b8; font-size: 0.88rem; margin: 0;">
                Donateur : <strong style="color: #f1f5f9;"><?php echo htmlspecialchars($cotisation['nom'] ?: ($_SESSION['user_nom'] ?? 'Client')); ?></strong>
                <?php if (!empty($cotisation['campagne_titre'])): ?>
                    · Campagne : <strong style="color: #f1f5f9;"><?php echo htmlspecialchars($cotisation['campagne_titre']); ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="payment-amount"
            style="background: #F8FAFC; border-bottom: 1px solid #E2E8F0; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="color: #0f172a; font-weight: 700; font-size: 0.95rem;">Montant du don :</span>
            <strong class="swiss-numeral"
                style="color: var(--tikeli-orange, #FF4A0D); font-size: 1.75rem; font-family: 'Space Mono', monospace; font-weight: 900;">
                <?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?>
                <span style="font-family: inherit; font-size: 0.88rem; color: var(--eventia-muted, #737373); font-weight: 700;">FCFA</span>
            </strong>
        </div>

        <form method="POST" action="paiement-cotisation.php?token=<?php echo urlencode($cur_cotisation_token); ?>" id="bictorys-cot-form" style="padding: 2rem;">
            <input type="hidden" name="initier_paiement_cotisation" value="1">
            <input type="hidden" name="provider" id="selected_provider_cot" value="wave_money">

            <!-- 1. Sélection opérateur -->
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    1. Choisissez votre moyen de paiement
                </label>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem;">
                    <button type="button" class="provider-cot-card active" data-provider="wave_money"
                        style="background: #ffffff; border: 2px solid #1ba0e2; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #1ba0e2;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Wave</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Sans frais</small>
                    </button>
                    <button type="button" class="provider-cot-card" data-provider="orange_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ff7900;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Orange</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Code #144*82#</small>
                    </button>
                    <button type="button" class="provider-cot-card" data-provider="mtn_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ffcc00;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">MTN MoMo</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Prompt USSD</small>
                    </button>
                    <button type="button" class="provider-cot-card" data-provider="card"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <i class="fa-regular fa-credit-card" style="color: #3b82f6; font-size: 1rem;"></i>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Carte Visa/CB</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Chiffré 3DS</small>
                    </button>
                </div>
            </div>

            <!-- 2. Saisie téléphone -->
            <div style="margin-bottom: 1.5rem;">
                <label for="phone_input_cot" style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    2. Numéro de compte Mobile Money
                </label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-weight: 700; color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.95rem;">
                        <i class="fa-solid fa-phone" style="margin-right: 4px; font-size: 0.85rem;"></i>
                    </span>
                    <input type="tel" name="phone" id="phone_input_cot" value="<?php echo htmlspecialchars($cot_phone); ?>" required
                        placeholder="Ex: 0701020304 ou +225..."
                        style="width: 100%; box-sizing: border-box; padding: 0.85rem 1rem 0.85rem 2.8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; font-family: 'Space Mono', monospace; font-weight: 600; color: #0f172a; background: #ffffff;">
                </div>
                <small style="color: #64748b; font-size: 0.78rem; margin-top: 0.35rem; display: block;">
                    Le compte débité pour verser votre don.
                </small>
            </div>

            <!-- Champ OTP conditionnel pour Orange Money CI -->
            <div id="orange-otp-block-cot" style="display: none; margin-bottom: 1.5rem; background: #FFF7ED; border: 1px solid #FFEDD5; padding: 1rem; border-radius: 8px;">
                <label for="otp_input_cot" style="display: block; font-size: 0.82rem; font-weight: 700; color: #9A3412; margin-bottom: 0.4rem; font-family: 'Space Mono', monospace;">
                    Code d'autorisation Orange Money (#144*82#)
                </label>
                <input type="text" name="otp" id="otp_input_cot" placeholder="Entrez le code à 4 ou 6 chiffres généré"
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 1rem; border: 1px solid #FDBA74; border-radius: 6px; font-size: 0.95rem; font-family: 'Space Mono', monospace;">
                <small style="color: #C2410C; font-size: 0.75rem; margin-top: 0.35rem; display: block;">
                    Composez <strong>#144*82#</strong> pour obtenir votre code temporaire, puis validez.
                </small>
            </div>

            <!-- Instructions dynamiques -->
            <div id="provider-instructions-cot" style="background: #F1F5F9; border-left: 4px solid #1ba0e2; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #334155;">
                <span id="instruction-text-cot">
                    <i class="fa-solid fa-info-circle" style="color: #1ba0e2; margin-right: 6px;"></i>
                    <strong>Sur smartphone</strong> : votre application Wave s'ouvrira directement pour valider en 1 clic sans scanner.<br>
                    <strong>Sur PC / Mac</strong> : vous pourrez scanner le QR code officiel Wave avec votre application.
                </span>
            </div>

            <button type="submit" id="btn-submit-cot"
                style="width: 100%; background: var(--tikeli-orange, #FF4A0D); color: #ffffff; border: none; padding: 1.05rem 1.5rem; border-radius: 10px; font-size: 1.05rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.65rem; transition: background 0.2s ease, transform 0.1s ease; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);">
                <i class="fa-solid fa-lock"></i>
                <span id="btn-cot-label">Verser mon don de <?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?> FCFA avec Wave</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>

            <div style="margin-top: 1rem; text-align: center;">
                <button type="button" id="btn-toggle-all-cot"
                    style="background: none; border: none; color: #64748b; font-size: 0.8rem; cursor: pointer; text-decoration: underline;">
                    Ou ouvrir le portail multi-moyens Bictorys
                </button>
            </div>

            <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid #E2E8F0; padding-top: 1.25rem;">
                <p style="margin: 0; color: var(--eventia-muted, #737373); font-size: 0.82rem; display: flex; align-items: center; justify-content: center; gap: 0.45rem;">
                    <i class="fa-solid fa-shield-check" style="color: #10B981;"></i>
                    Paiement sécurisé et chiffré via Bictorys
                </p>
                <small style="color: #94a3b8; font-size: 0.75rem; margin-top: 0.3rem; display: block;">
                    La campagne sera créditée immédiatement après la confirmation du paiement.
                </small>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const providerInput = document.getElementById('selected_provider_cot');
    const cards = document.querySelectorAll('.provider-cot-card');
    const instructions = document.getElementById('provider-instructions-cot');
    const instructionText = document.getElementById('instruction-text-cot');
    const orangeBlock = document.getElementById('orange-otp-block-cot');
    const btnLabel = document.getElementById('btn-cot-label');
    const btnAll = document.getElementById('btn-toggle-all-cot');
    const form = document.getElementById('bictorys-cot-form');
    const submitBtn = document.getElementById('btn-submit-cot');

    const totalAmount = "<?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?> FCFA";

    const config = {
        'wave_money': {
            name: 'Wave',
            color: '#1ba0e2',
            text: '<i class="fa-solid fa-info-circle" style="color: #1ba0e2; margin-right: 6px;"></i> <strong>Sur smartphone</strong> : l’application Wave s’ouvre directement pour valider en 1 clic.<br><strong>Sur PC</strong> : scannez le QR code officiel ou confirmez sur votre application.'
        },
        'orange_money': {
            name: 'Orange Money',
            color: '#ff7900',
            text: '<i class="fa-solid fa-info-circle" style="color: #ff7900; margin-right: 6px;"></i> <strong>Orange Money CI</strong> : composez <strong>#144*82#</strong> pour générer votre code d’autorisation temporaire.'
        },
        'mtn_money': {
            name: 'MTN MoMo',
            color: '#ffcc00',
            text: '<i class="fa-solid fa-info-circle" style="color: #eab308; margin-right: 6px;"></i> <strong>MTN Mobile Money</strong> : une invite USSD s’affichera sur votre écran. Validez avec votre code PIN.'
        },
        'card': {
            name: 'Carte Bancaire',
            color: '#3b82f6',
            text: '<i class="fa-solid fa-shield-check" style="color: #3b82f6; margin-right: 6px;"></i> <strong>Carte Visa / Mastercard</strong> : redirection chiffrée avec protection 3D-Secure.'
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
            btnLabel.innerText = "Verser mon don de " + totalAmount + " avec " + config[prov].name;
        } else {
            instructions.style.borderLeftColor = '#64748b';
            instructionText.innerHTML = '<i class="fa-solid fa-info-circle"></i> Redirection vers le portail officiel Bictorys.';
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
            submitBtn.style.pointerEvents = 'none';
            submitBtn.style.opacity = '0.75';
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Connexion sécurisée en cours...</span>';
            setTimeout(function () {
                submitBtn.disabled = true;
            }, 50);
        });
    }
});
</script>

<?php include 'footer.php'; ?>