<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY CLIENT (client/paiement.php)
// Saisie & confirmation des informations de paiement (utilisateurs connectés & invités)
// ==============================================================================

require_once '../config/database.php';
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
$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
$pay_token = hash_hmac('sha256', $order_id . '|' . $order['montant_total'] . '|' . $order['created_at'], $pay_secret);

$page_title = "Paiement Mobile Money - Tikéli";
$body_class = "client-page payment-page";
include 'header.php';
?>

<div class="payment-container" style="max-width: 580px; margin: 2rem auto; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <a href="accueil.php" class="back-link"
        style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner à l'accueil
    </a>

    <div class="payment-card eventia-card"
        style="padding: 0; overflow: hidden; border: 1px solid var(--eventia-border, #E5E5E5); border-radius: var(--eventia-radius-lg, 14px); box-shadow: var(--eventia-shadow-lg, 0 12px 24px -4px rgba(16, 24, 40, 0.10));">
        <div class="payment-heading"
            style="background: linear-gradient(135deg, #000000 0%, #121212 100%); color: #ffffff; padding: 2rem;">
            <div class="payment-icon"
                style="width: 48px; height: 48px; background: rgba(255, 74, 13, 0.18); border: 1px solid rgba(255, 74, 13, 0.35); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: var(--tikeli-orange, #FF4A0D);">
                <i class="fa-solid fa-credit-card"></i>
            </div>
            <span class="page-kicker"
                style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em;">Commande
                #<?php echo htmlspecialchars($order['numero_commande']); ?></span>
            <h1
                style="color: #ffffff; margin: 0.2rem 0 0.5rem; font-size: 1.7rem; font-family: var(--font-heading, 'Outfit', sans-serif);">
                Finaliser votre Paiement</h1>
            <p style="color: #737373; font-size: 0.92rem; margin: 0;">
                Titulaire :
                <strong><?php echo htmlspecialchars($order['client_nom'] ?: ($_SESSION['user_nom'] ?? 'Client')); ?></strong>
                (<?php echo htmlspecialchars($order['client_email'] ?: ($_SESSION['user_email'] ?? '')); ?>)
            </p>
        </div>

        <div class="payment-amount"
            style="background: #F5F5F5; border-bottom: 1px solid var(--eventia-border, #E5E5E5); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--eventia-navy, #000000); font-weight: 700; font-size: 0.95rem;">Montant Total à
                régler :</span>
            <strong class="swiss-numeral"
                style="color: var(--eventia-navy, #000000); font-size: 1.8rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;"><?php echo number_format($order['montant_total'], 0, ',', ' '); ?>
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
                    const callbackUrl = baseUrl + '/callback.php?order_id=<?php echo $order_id; ?>&methode=feexpay&pay_token=<?php echo $pay_token; ?>';

                    FeexPayButton.init("render", {
                        id: "80hxOfqxlIDOZJi",
                        amount: <?php echo (int) $order['montant_total']; ?>,
                        token: "test_Hg7Kjl3ZAM63UuIUpuudD9nKuu3ZAM67Kjl3Uuhn",
                        callback_url: callbackUrl,
                        mode: "SANDBOX",
                        custom_id: "ORDER_<?php echo $order_id; ?>",
                        description: "Achat tickets commande #<?php echo htmlspecialchars($order['numero_commande']); ?>",
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