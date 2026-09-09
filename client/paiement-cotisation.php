<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY DES CONTRIBUTIONS (client/paiement-cotisation.php)
// Même flux que le paiement des billets : choix de l'opérateur puis confirmation
// ==============================================================================

require_once '../config/database.php';
session_start();

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

$cotisation_id = filter_input(INPUT_GET, 'cotisation_id', FILTER_VALIDATE_INT);
if (!$cotisation_id) {
    header('Location: accueil.php');
    exit();
}

// Récupération de la contribution en attente
$stmt = $pdo->prepare('
    SELECT ct.*, c.titre AS campagne_titre
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

$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
$cotisation_token = hash_hmac('sha256', $cotisation['id'] . '|' . $cotisation['montant'] . '|' . $cotisation['created_at'], $pay_secret);

$page_title = "Paiement de votre Contribution - Tikéli";
$body_class = "client-page payment-page";
include 'header.php';
?>

<div class="payment-container" style="max-width: 580px; margin: 2rem auto; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <a href="accueil.php?onglet=cotisations" class="back-link"
        style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner aux cotisations
    </a>

    <div class="payment-card eventia-card"
        style="padding: 0; overflow: hidden; border: 1px solid var(--eventia-border, #E5E5E5); border-radius: var(--eventia-radius-lg, 14px); box-shadow: var(--eventia-shadow-lg, 0 12px 24px -4px rgba(16, 24, 40, 0.10));">
        <div class="payment-heading"
            style="background: linear-gradient(135deg, #000000 0%, #121212 100%); color: #ffffff; padding: 2rem;">
            <div class="payment-icon"
                style="width: 48px; height: 48px; background: rgba(255, 74, 13, 0.18); border: 1px solid rgba(255, 74, 13, 0.35); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: var(--tikeli-orange, #FF4A0D);">
                <i class="fa-solid fa-hand-holding-heart"></i>
            </div>
            <span class="page-kicker"
                style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em;">Contribution
                #<?php echo (int) $cotisation['id']; ?></span>
            <h1
                style="color: #ffffff; margin: 0.2rem 0 0.5rem; font-size: 1.7rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;">
                Finaliser votre Contribution</h1>
            <p style="color: #737373; font-size: 0.92rem; margin: 0;">
                Contributeur :
                <strong><?php echo htmlspecialchars($cotisation['nom'] ?: ($_SESSION['user_nom'] ?? 'Client')); ?></strong>
                <?php if (!empty($cotisation['campagne_titre'])): ?>
                    · Campagne : <strong><?php echo htmlspecialchars($cotisation['campagne_titre']); ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="payment-amount"
            style="background: #F5F5F5; border-bottom: 1px solid var(--eventia-border, #E5E5E5); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--eventia-navy, #000000); font-weight: 700; font-size: 0.95rem;">Montant de la
                contribution :</span>
            <strong class="swiss-numeral"
                style="color: var(--eventia-turquoise-dark, #FF4A0D); font-size: 1.8rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;"><?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?>
                <span
                    style="font-family: var(--font-body, 'Inter', sans-serif); font-size: 1rem; color: var(--eventia-muted, #737373); font-weight: 700;">FCFA</span></strong>
        </div>

        <div class="payment-methods" style="padding: 2rem;">
            <!-- Intégration Feexpay API V2 -->
            <div id="render" style="margin: 0 auto; max-width: 400px; text-align: center;"></div>

            <script src="https://api-v2.feexpay.me/feexpay-javascript-sdk/index.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const baseUrl = window.location.protocol + '//' + window.location.host + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                    const callbackUrl = baseUrl + '/callback-cotisation.php?cotisation_id=<?php echo $cotisation_id; ?>&methode=feexpay&cotisation_token=<?php echo $cotisation_token; ?>';

                    FeexPayButton.init("render", {
                        id: "80hxOfqxlIDOZJi",
                        amount: <?php echo (int) $cotisation['montant']; ?>,
                        token: "test_Hg7Kjl3ZAM63UuIUpuudD9nKuu3ZAM67Kjl3Uuhn",
                        callback_url: callbackUrl,
                        mode: "SANDBOX",
                        custom_id: "COT_<?php echo $cotisation_id; ?>",
                        description: "Cotisation #<?php echo (int) $cotisation['id']; ?>",
                        networks: {
                            "Côte d'Ivoire": ["MTN", "ORANGE", "WAVE", "MOOV"]
                        }
                    });
                });
            </script>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--eventia-muted, #737373); font-size: 0.85rem;">
                <i class="fa-solid fa-lock" style="color: var(--eventia-turquoise, #FF4A0D);"></i> Paiement sécurisé et chiffré via Feexpay
            </p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>