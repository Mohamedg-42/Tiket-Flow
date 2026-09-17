<?php
// ==============================================================================
// CONFIRMATION DU PAIEMENT D'UNE CONTRIBUTION (client/callback-cotisation.php)
// Même mécanisme que le paiement des billets : validation, référence de
// transaction et page de confirmation
// ==============================================================================

require_once '../config/database.php';
session_start();

// FeexPay / passerelle redirige via GET
$cotisation_id = filter_input(INPUT_GET, 'cotisation_id', FILTER_VALIDATE_INT) ?: (filter_var($_GET['cotisation_id'] ?? null, FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'cotisation_id', FILTER_VALIDATE_INT));
$methode = $_GET['methode'] ?? $_POST['methode'] ?? '';
$methodes_autorisees = ['wave', 'orange_money', 'mtn_money', 'moov_money', 'kadevpay', 'feexpay', 'bictorys'];

if (!$cotisation_id || !in_array($methode, $methodes_autorisees, true)) {
    $_SESSION['cotisation_message'] = "Paiement non validé ou méthode non reconnue.";
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// 1. Récupération de la contribution
$stmt = $pdo->prepare('
    SELECT ct.*, c.titre AS campagne_titre, c.visibilite AS campagne_visibilite, c.access_token AS campagne_access_token
    FROM cotisations ct
    LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
    WHERE ct.id = ?
');
$stmt->execute([$cotisation_id]);
$cotisation = $stmt->fetch();

if (!$cotisation) {
    $_SESSION['cotisation_message'] = 'Cette contribution est introuvable.';
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// 1.1 Vérification de la signature cryptographique sécurisée anti-falsification (SEC-002)
if (!defined('APP_SECRET_KEY')) {
    error_log("TikeWA CRITIQUE: APP_SECRET_KEY non défini — inclure config/env.php");
    $_SESSION['cotisation_message'] = "Erreur de configuration serveur. Veuillez contacter l'administrateur.";
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}
$pay_secret = APP_SECRET_KEY;
$expected_token = hash_hmac('sha256', $cotisation['id'] . '|' . $cotisation['montant'] . '|' . $cotisation['created_at'], $pay_secret);
$cotisation_token = $_GET['cotisation_token'] ?? $_POST['cotisation_token'] ?? '';

if (empty($cotisation_token) || !hash_equals($expected_token, $cotisation_token)) {
    $_SESSION['cotisation_message'] = "Validation rejetée : signature de paiement de contribution invalide ou absente.";
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$is_already_paid = in_array($cotisation['statut'], ['payee', 'valide'], true);

if (!$is_already_paid && $cotisation['statut'] !== 'en_attente') {
    $_SESSION['cotisation_message'] = 'Cette contribution est introuvable ou a été annulée.';
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// Référence unique de paiement et ID de transaction
$payment_ref = trim($_GET['reference'] ?? $_POST['reference'] ?? $_GET['transaction_id'] ?? $_GET['charge_id'] ?? $_GET['chargeId'] ?? '');
if (!empty($payment_ref)) {
    $prefix = (strtoupper($methode) === 'BICTORYS') ? 'PAY-BICTORYS-' : 'PAY-FEEXPAY-';
    $reference = (stripos($payment_ref, 'PAY-') === 0) ? $payment_ref : ($prefix . $payment_ref);
    $transaction_api_id = $payment_ref;
} else {
    $reference = 'PAY-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
    $transaction_api_id = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);
}
$telephone_final = $cotisation['telephone'] ?? '';

try {
    if (!$is_already_paid) {
        $pdo->beginTransaction();

        // 2. Validation de la contribution (statut 'payee' + référence de transaction)
        $stmt_pay = $pdo->prepare("
            UPDATE cotisations 
            SET statut = 'payee', methode = ?, reference = ?, transaction_id_api = ?, 
                telephone = COALESCE(NULLIF(?, ''), telephone), date_paiement = NOW()
            WHERE id = ? AND statut = 'en_attente'
        ");
        $stmt_pay->execute([$methode, $reference, $transaction_api_id, $telephone_final, $cotisation_id]);

        $pdo->commit();
    }

    $paiement_ok = true;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['cotisation_message'] = friendly_db_error($e, 'cotisation', "Une erreur est survenue lors de l'enregistrement de votre contribution. Veuillez contacter le support si votre compte a été débité.");
    $_SESSION['cotisation_type'] = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$page_title = "Contribution Confirmée - TikeWA";
$body_class = "client-page payment-page";
include 'header.php';
?>

<main class="payment-success-container"
    style="max-width: 800px; margin: 2rem auto 3.5rem; padding: 0 clamp(0.75rem, 2vw, 1.5rem);">

    <!-- En-tête de confirmation harmonisé avec les événements -->
    <div
        style="text-align: center; background: #ffffff; border: 1px solid var(--line, #E2E8F0); border-radius: 16px; padding: clamp(1.5rem, 4vw, 2.5rem); margin-bottom: 2rem; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06);">
        <div
            style="width: 72px; height: 72px; background: rgba(16, 185, 129, 0.12); color: #059669; border-radius: 50%; display: grid; place-items: center; font-size: 2.2rem; margin: 0 auto 1.25rem; box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.08);">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <h1 style="color: var(--navy, #0f172a); margin-bottom: 0.5rem; font-size: clamp(1.5rem, 3.5vw, 2rem); font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;">
            Paiement Confirmé !
        </h1>
        <p style="color: var(--muted, #64748b); font-size: 1rem; margin-bottom: 1.5rem; line-height: 1.5;">
            Votre contribution pour <?php echo !empty($cotisation['campagne_titre']) ? 'la cause <strong>« ' . htmlspecialchars($cotisation['campagne_titre']) . ' »</strong>' : 'cette campagne'; ?>
            d'un montant de <strong style="color: var(--navy, #0f172a);"><?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?> FCFA</strong> a été réglée avec succès via
            <strong><?php echo strtoupper(htmlspecialchars(str_replace('_', ' ', $methode))); ?></strong>.
        </p>

        <!-- Récapitulatif technique -->
        <div
            style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.92rem;">
            <div
                style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #E2E8F0;">
                <span style="color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.8rem; text-transform: uppercase;">Donateur</span>
                <strong style="color: #0f172a;"><?php echo htmlspecialchars($cotisation['nom']); ?></strong>
            </div>
            <?php if (!empty($cotisation['campagne_titre'])): ?>
                <div
                    style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #E2E8F0;">
                    <span style="color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.8rem; text-transform: uppercase;">Campagne</span>
                    <strong style="color: #0f172a; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($cotisation['campagne_titre']); ?></strong>
                </div>
            <?php endif; ?>
            <div
                style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #E2E8F0;">
                <span style="color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.8rem; text-transform: uppercase;">Moyen de Paiement</span>
                <strong style="color: #0f172a; text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $methode)); ?></strong>
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #E2E8F0;">
                <span style="color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.8rem; text-transform: uppercase;">Référence Bictorys</span>
                <strong style="color: #0f172a; font-family: 'Space Mono', monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($reference); ?></strong>
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0 0.2rem; font-size: 1.05rem;">
                <span style="color: #0f172a; font-weight: 700;">Montant Encaissé</span>
                <strong style="color: var(--tikeli-orange, #FF4A0D); font-size: 1.35rem; font-family: 'Outfit', sans-serif; font-weight: 900;">
                    <?php echo number_format((float) $cotisation['montant'], 0, ',', ' '); ?>
                    <span style="font-size: 0.85rem; font-family: 'Space Mono', monospace;">FCFA</span>
                </strong>
            </div>
        </div>

        <?php
        $campagne_id_url = (int) ($cotisation['campagne_id'] ?? 0);
        $share_cot_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ticket-platform/client/accueil.php?onglet=cotisations';
        $wa_cot_text = "🤝 Je viens de soutenir la cause « " . ($cotisation['campagne_titre'] ?? 'cette campagne') . " » sur TikeWA ! Participez vous aussi à la collecte ici : " . $share_cot_url;
        $wa_cot_href = "https://api.whatsapp.com/send?text=" . urlencode($wa_cot_text);
        ?>

        <!-- Boutons d'actions harmonisés -->
        <div style="display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?php echo $wa_cot_href; ?>" target="_blank" class="btn-submit"
                style="width: auto; padding: 0.75rem 1.4rem; background: #25D366; color: white; text-decoration: none; border-color: #25D366; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                <i class="fa-brands fa-whatsapp" style="font-size: 1.15rem;"></i> Partager par WhatsApp
            </a>

            <?php if (($cotisation['campagne_visibilite'] ?? 'public') === 'prive' && !empty($cotisation['campagne_id'])): ?>
                <a href="cotisation.php?id=<?php echo (int) $cotisation['campagne_id']; ?>&token=<?php echo urlencode($cotisation['campagne_access_token'] ?? ''); ?>"
                    class="btn-submit"
                    style="width: auto; padding: 0.75rem 1.4rem; background: var(--tikeli-orange, #FF4A0D); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                    <i class="fa-solid fa-hand-holding-heart"></i> Voir la campagne
                </a>
            <?php else: ?>
                <a href="accueil.php?onglet=cotisations" class="btn-submit"
                    style="width: auto; padding: 0.75rem 1.4rem; background: var(--tikeli-orange, #FF4A0D); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                    <i class="fa-solid fa-hand-holding-heart"></i> Explorer les campagnes
                </a>
            <?php endif; ?>

            <a href="accueil.php" class="btn-submit"
                style="width: auto; padding: 0.75rem 1.25rem; background: transparent; color: var(--navy, #0f172a); border: 1px solid var(--line, #E2E8F0); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                <i class="fa-solid fa-house"></i> Retour à l'accueil
            </a>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>