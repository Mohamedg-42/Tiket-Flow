<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY D'UN VOTE PAYANT (client/paiement-vote.php)
// Passerelle de paiement officielle : BICTORYS (Wave, Orange, MTN, Moov, Cartes)
// ==============================================================================

require_once '../config/database.php';
require_once '../config/bictorys.php';
session_start();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: accueil.php');
    exit();
}

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Téléphone du client connecté (pré-rempli comme pour l'achat de billets)
$user_telephone = '';
if ($is_logged_in) {
    try {
        $stmt_utel = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_utel->execute([$_SESSION['user_id']]);
        $user_telephone = (string) $stmt_utel->fetchColumn();
    } catch (PDOException $e) {
        $user_telephone = '';
    }
}

// Récupération du paiement de vote en attente + infos de l'événement
$stmt = $pdo->prepare("
    SELECT vp.*, e.nom AS event_nom, e.visibilite AS event_visibilite, e.access_token AS event_access_token
    FROM vote_paiements vp
    JOIN events e ON e.id = vp.event_id
    WHERE vp.id = ?
");
$stmt->execute([$id]);
$vote_pay = $stmt->fetch();

if (!$vote_pay || $vote_pay['statut'] !== 'en_attente') {
    $_SESSION['vote_message'] = "Ce paiement de vote est introuvable ou a déjà été réglé.";
    header('Location: accueil.php?onglet=voter');
    exit();
}

$is_event_prive = ($vote_pay['event_visibilite'] ?? 'public') === 'prive';
$event_token = $vote_pay['event_access_token'] ?? '';
$back_url = $is_event_prive
    ? 'vote.php?id=' . (int) $vote_pay['event_id'] . (!empty($event_token) ? '&token=' . urlencode($event_token) : '')
    : 'accueil.php?onglet=voter';

$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
$vote_token = hash_hmac('sha256', $vote_pay['id'] . '|' . $vote_pay['montant'] . '|' . $vote_pay['created_at'], $pay_secret);

$error_msg = null;
if (isset($_GET['error'])) {
    $error_msg = "La transaction de vote a été interrompue. Vous pouvez la relancer ci-dessous.";
}

// Initialisation Bictorys
$vote_phone = $vote_pay['telephone'] ?: ($user_telephone ?: ($_SESSION['user_phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initier_paiement_vote'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $callbackUrl = $baseUrl . '/callback-vote.php?vote_paiement_id=' . $vote_pay['id'] . '&methode=bictorys&vote_token=' . $vote_token . ($is_event_prive && !empty($event_token) ? ('&token=' . urlencode($event_token)) : '');
    $errorUrl = $baseUrl . '/paiement-vote.php?id=' . $vote_pay['id'] . '&error=1';

    $selected_provider = trim($_POST['provider'] ?? '');
    $phone_submitted = trim($_POST['phone'] ?? $vote_phone);
    $otp_submitted = trim($_POST['otp'] ?? '');

    if (!empty($phone_submitted) && $phone_submitted !== $vote_pay['telephone']) {
        try {
            $pdo->prepare("UPDATE vote_paiements SET telephone = ? WHERE id = ?")->execute([$phone_submitted, $vote_pay['id']]);
            $vote_pay['telephone'] = $phone_submitted;
        } catch (\Throwable $t) {
        }
    }

    $chargeParams = [
        'amount' => (int) round($vote_pay['montant']),
        'paymentReference' => 'VOTE_' . $vote_pay['id'],
        'successRedirectUrl' => $callbackUrl,
        'errorRedirectUrl' => $errorUrl,
        'country' => 'CI',
        'customer' => [
            'name' => $_SESSION['user_nom'] ?? 'Électeur',
            'phone' => $phone_submitted,
            'email' => $_SESSION['user_email'] ?? ''
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
        $error_msg = $charge['error'] ?? "Impossible d'initialiser la session de paiement de vote via Bictorys.";
    }
}

// Récupération des candidats sélectionnés avec leurs photos et descriptions
$candidats_choisis = [];
$cands_ids = [];
if (!empty($vote_pay['candidats_ids'])) {
    $cands_ids = json_decode($vote_pay['candidats_ids'], true);
} elseif (!empty($vote_pay['candidat_id'])) {
    $cands_ids = [(int) $vote_pay['candidat_id']];
}
if (!empty($cands_ids) && is_array($cands_ids)) {
    $in_sql = implode(',', array_map('intval', $cands_ids));
    if ($in_sql !== '') {
        $candidats_choisis = $pdo->query("SELECT * FROM event_candidats WHERE id IN ($in_sql)")->fetchAll();
    }
}

$page_title = "Paiement de votre Vote - Tike WA";
$body_class = "client-page payment-page";
include 'header.php';
?>
<div class="payment-container"
    style="max-width: 620px; margin: 2rem auto 3.5rem; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="back-link"
        style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
        <i class="fa-solid fa-arrow-left"></i>
        <?php echo $is_event_prive ? 'Annuler et retourner au scrutin privé' : 'Annuler et retourner au classement'; ?>
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
        <div class="payment-heading" style="background: #0f172a; color: #ffffff; padding: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div class="payment-icon"
                    style="width: 48px; height: 48px; background: rgba(255, 74, 13, 0.18); border: 1px solid rgba(255, 74, 13, 0.35); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: var(--tikeli-orange, #FF4A0D);">
                    <i class="fa-solid fa-check-to-slot"></i>
                </div>
                <span
                    style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #cbd5e1; font-family: 'Space Mono', monospace; font-size: 0.75rem; padding: 0.35rem 0.65rem; border-radius: 6px; text-transform: uppercase;">
                    Bictorys Secure Pay
                </span>
            </div>
            <span class="page-kicker"
                style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Mono', monospace;">Session
                de vote #<?php echo (int) $vote_pay['id']; ?></span>
            <h1
                style="color: #ffffff; margin: 0.3rem 0 0.5rem; font-size: 1.7rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; line-height: 1.2;">
                Régler vos Voix</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Compétition :
                <strong style="color: #f1f5f9;"><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong>
            </p>
        </div>

        <?php if (!empty($candidats_choisis)): ?>
            <div style="padding: 1.25rem 2rem; background: #fafafa; border-bottom: 1px solid #E2E8F0;">
                <span
                    style="display: block; font-size: 0.82rem; text-transform: uppercase; font-weight: 700; color: var(--eventia-muted, #737373); letter-spacing: 0.05em; margin-bottom: 0.75rem; font-family: 'Space Mono', monospace;">
                    Candidat(s) soutenu(s) (<?php echo count($candidats_choisis); ?>) :
                </span>
                <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                    <?php foreach ($candidats_choisis as $c): ?>
                        <div
                            style="display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid #E2E8F0;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?php if (!empty($c['photo'])): ?>
                                    <img src="../<?php echo htmlspecialchars($c['photo']); ?>" alt=""
                                        style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid #ddd;">
                                <?php else: ?>
                                    <div
                                        style="width: 36px; height: 36px; border-radius: 50%; background: #eee; display: grid; place-items: center; font-size: 0.8rem; color: #777;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <strong
                                        style="font-size: 0.92rem; color: var(--eventia-navy, #0f172a); display: block;"><?php echo htmlspecialchars($c['nom']); ?></strong>
                                    <?php if (!empty($c['numero_candidat'])): ?>
                                        <small
                                            style="color: var(--eventia-muted, #737373); font-size: 0.75rem; font-family: 'Space Mono', monospace;">N°
                                            <?php echo htmlspecialchars($c['numero_candidat']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span style="color: #10B981; font-size: 1.1rem;"><i class="fa-solid fa-circle-check"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="payment-amount"
            style="background: #F8FAFC; border-bottom: 1px solid #E2E8F0; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <span
                    style="color: var(--eventia-navy, #0f172a); font-weight: 700; font-size: 0.95rem; display: block;">Montant
                    total du vote :</span>
                <?php if (!empty($candidats_choisis)): ?>
                    <small
                        style="color: var(--eventia-muted, #737373); font-size: 0.8rem;"><?php echo count($candidats_choisis); ?>
                        choix ×
                        <?php echo number_format((float) ($vote_pay['montant'] / count($candidats_choisis)), 0, ',', ' '); ?>
                        FCFA</small>
                <?php endif; ?>
            </div>
            <strong class="swiss-numeral"
                style="color: var(--eventia-navy, #0f172a); font-size: 1.85rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 900;"><?php echo number_format((float) $vote_pay['montant'], 0, ',', ' '); ?>
                <span
                    style="font-family: 'Space Mono', monospace; font-size: 0.95rem; color: var(--eventia-muted, #737373); font-weight: 700;">FCFA</span></strong>
        </div>

        <form method="POST" action="paiement-vote.php?id=<?php echo $vote_pay['id']; ?>" id="bictorys-vote-form"
            style="padding: 2rem;">
            <input type="hidden" name="initier_paiement_vote" value="1">
            <input type="hidden" name="provider" id="selected_provider_vote" value="wave_money">

            <!-- 1. Sélection opérateur -->
            <div style="margin-bottom: 1.5rem;">
                <label
                    style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    1. Choisissez votre moyen de paiement
                </label>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem;">
                    <button type="button" class="provider-vote-card active" data-provider="wave_money"
                        style="background: #ffffff; border: 2px solid #1ba0e2; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #1ba0e2;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Wave</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Sans frais</small>
                    </button>
                    <button type="button" class="provider-vote-card" data-provider="orange_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ff7900;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Orange</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Code #144*82#</small>
                    </button>
                    <button type="button" class="provider-vote-card" data-provider="mtn_money"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <span
                            style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: #ffcc00;"></span>
                        <strong style="color: #0f172a; font-size: 0.95rem;">MTN MoMo</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Prompt USSD</small>
                    </button>
                    <button type="button" class="provider-vote-card" data-provider="card"
                        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0.85rem 0.5rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.2s ease;">
                        <i class="fa-regular fa-credit-card" style="color: #3b82f6; font-size: 1rem;"></i>
                        <strong style="color: #0f172a; font-size: 0.95rem;">Carte Visa/CB</strong>
                        <small style="color: #64748b; font-size: 0.72rem;">Chiffré 3DS</small>
                    </button>
                </div>
            </div>

            <!-- 2. Saisie téléphone -->
            <div style="margin-bottom: 1.5rem;">
                <label for="phone_input_vote"
                    style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;">
                    2. Numéro de compte Mobile Money
                </label>
                <div style="position: relative;">
                    <span
                        style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-weight: 700; color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.95rem;">
                        <i class="fa-solid fa-phone" style="margin-right: 4px; font-size: 0.85rem;"></i>
                    </span>
                    <input type="tel" name="phone" id="phone_input_vote"
                        value="<?php echo htmlspecialchars($vote_phone); ?>" required
                        placeholder="Ex: 0701020304 ou +225..."
                        style="width: 100%; box-sizing: border-box; padding: 0.85rem 1rem 0.85rem 2.8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; font-family: 'Space Mono', monospace; font-weight: 600; color: #0f172a; background: #ffffff;">
                </div>
                <small style="color: #64748b; font-size: 0.78rem; margin-top: 0.35rem; display: block;">
                    Le compte qui autorise le paiement de vos voix.
                </small>
            </div>

            <!-- Champ OTP conditionnel pour Orange Money CI -->
            <div id="orange-otp-block-vote"
                style="display: none; margin-bottom: 1.5rem; background: #FFF7ED; border: 1px solid #FFEDD5; padding: 1rem; border-radius: 8px;">
                <label for="otp_input_vote"
                    style="display: block; font-size: 0.82rem; font-weight: 700; color: #9A3412; margin-bottom: 0.4rem; font-family: 'Space Mono', monospace;">
                    Code d'autorisation Orange Money (#144*82#)
                </label>
                <input type="text" name="otp" id="otp_input_vote" placeholder="Entrez le code à 4 ou 6 chiffres généré"
                    style="width: 100%; box-sizing: border-box; padding: 0.75rem 1rem; border: 1px solid #FDBA74; border-radius: 6px; font-size: 0.95rem; font-family: 'Space Mono', monospace;">
                <small style="color: #C2410C; font-size: 0.75rem; margin-top: 0.35rem; display: block;">
                    Composez <strong>#144*82#</strong> pour obtenir votre code temporaire, puis validez.
                </small>
            </div>

            <!-- Instructions dynamiques -->
            <div id="provider-instructions-vote"
                style="background: #F1F5F9; border-left: 4px solid #1ba0e2; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #334155;">
                <span id="instruction-text-vote">
                    <i class="fa-solid fa-info-circle" style="color: #1ba0e2; margin-right: 6px;"></i>
                    <strong>Sur smartphone</strong> : votre application Wave s'ouvrira directement pour valider en 1
                    clic sans scanner.<br>
                    <strong>Sur PC / Mac</strong> : vous pourrez scanner le QR code officiel Wave avec votre
                    application.
                </span>
            </div>

            <button type="submit" id="btn-submit-vote"
                style="width: 100%; background: var(--tikeli-orange, #FF4A0D); color: #ffffff; border: none; padding: 1.05rem 1.5rem; border-radius: 10px; font-size: 1.05rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.65rem; transition: background 0.2s ease, transform 0.1s ease; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35);">
                <i class="fa-solid fa-lock"></i>
                <span id="btn-vote-label">Valider et payer mes voix avec Wave</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>

            <div style="margin-top: 1rem; text-align: center;">
                <button type="button" id="btn-toggle-all-vote"
                    style="background: none; border: none; color: #64748b; font-size: 0.8rem; cursor: pointer; text-decoration: underline;">
                    Ou ouvrir le portail multi-moyens Bictorys
                </button>
            </div>

            <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid #E2E8F0; padding-top: 1.25rem;">
                <p
                    style="margin: 0; color: var(--eventia-muted, #737373); font-size: 0.82rem; display: flex; align-items: center; justify-content: center; gap: 0.45rem;">
                    <i class="fa-solid fa-shield-check" style="color: #10B981;"></i>
                    Paiement chiffré 256-bit certifié PCI-DSS
                </p>
                <small style="color: #94a3b8; font-size: 0.75rem; margin-top: 0.3rem; display: block;">
                    Vos voix seront enregistrées instantanément dès la confirmation de débit.
                </small>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const providerInput = document.getElementById('selected_provider_vote');
        const cards = document.querySelectorAll('.provider-vote-card');
        const instructions = document.getElementById('provider-instructions-vote');
        const instructionText = document.getElementById('instruction-text-vote');
        const orangeBlock = document.getElementById('orange-otp-block-vote');
        const btnLabel = document.getElementById('btn-vote-label');
        const btnAll = document.getElementById('btn-toggle-all-vote');
        const form = document.getElementById('bictorys-vote-form');
        const submitBtn = document.getElementById('btn-submit-vote');

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
                btnLabel.innerText = "Valider et payer avec " + config[prov].name;
            } else {
                instructions.style.borderLeftColor = '#64748b';
                instructionText.innerHTML = '<i class="fa-solid fa-info-circle"></i> Redirection vers le portail officiel Bictorys.';
                btnLabel.innerText = "Continuer vers le paiement sécurisé";
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