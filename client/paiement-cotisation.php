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
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$page_title = "Paiement de votre Contribution - Ticket Flow";
$body_class = "client-page payment-page";
include 'header.php';
?>

<div class="payment-container" style="max-width: 580px; margin: 2rem auto; padding: 0 1rem;">
    <a href="accueil.php?onglet=cotisations" class="back-link" style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--muted); text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner aux cotisations
    </a>

    <div class="payment-card" style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); overflow: hidden; box-shadow: var(--shadow-xl);">
        <div class="payment-heading" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #ffffff; padding: 2rem;">
            <div class="payment-icon" style="width: 48px; height: 48px; background: rgba(255,255,255,0.12); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: #38bdf8;">
                <i class="fa-solid fa-hand-holding-heart"></i>
            </div>
            <span class="page-kicker" style="color: #38bdf8;">Contribution #<?php echo (int)$cotisation['id']; ?></span>
            <h1 style="color: #ffffff; margin: 0.2rem 0 0.5rem; font-size: 1.7rem;">Finaliser votre Contribution</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Contributeur : <strong><?php echo htmlspecialchars($cotisation['nom'] ?: ($_SESSION['user_nom'] ?? 'Client')); ?></strong>
                <?php if (!empty($cotisation['campagne_titre'])): ?>
                    · Campagne : <strong><?php echo htmlspecialchars($cotisation['campagne_titre']); ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="payment-amount" style="background: #f8fafc; border-bottom: 1px solid var(--line); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--navy); font-weight: 700; font-size: 0.95rem;">Montant de la contribution :</span>
            <strong style="color: var(--primary); font-size: 1.6rem;"><?php echo number_format((float)$cotisation['montant'], 0, ',', ' '); ?> FCFA</strong>
        </div>

        <div class="payment-methods" style="padding: 2rem;">
            <form action="callback-cotisation.php" method="POST" style="display: grid; gap: 1rem;">
                <input type="hidden" name="cotisation_id" value="<?php echo (int)$cotisation_id; ?>">

                <?php if (empty($cotisation['telephone'])): ?>
                    <!-- Saisie du numéro Mobile Money demandée uniquement si aucun téléphone n'est connu -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="cot_pay_tel"><i class="fa-solid fa-phone"></i> Votre numéro Mobile Money *</label>
                        <input type="tel" id="cot_pay_tel" name="telephone" required placeholder="Ex: 07 00 00 00 00" style="width: 100%; padding: 0.7rem; border: 1px solid var(--line); border-radius: 6px;">
                    </div>
                <?php endif; ?>

                <p style="color: var(--navy); font-weight: 700; font-size: 0.9rem; margin: 0;">
                    <i class="fa-solid fa-mobile-screen-button" style="color: var(--primary);"></i> Choisissez votre opérateur Mobile Money :
                </p>

                <label class="payment-option payment-wave" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 2px solid #bfdbfe; border-radius: var(--radius-md); background: #eff6ff; cursor: pointer;">
                    <input type="radio" name="methode" value="wave" checked style="accent-color: #0284c7; transform: scale(1.2);">
                    <i class="fa-solid fa-water" style="color: #0284c7; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">Wave Mobile Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">Paiement instantané sans frais</small>
                    </div>
                </label>

                <label class="payment-option payment-orange" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; cursor: pointer;">
                    <input type="radio" name="methode" value="orange_money" style="accent-color: #ea580c; transform: scale(1.2);">
                    <i class="fa-solid fa-wallet" style="color: #ea580c; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">Orange Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">Côte d'Ivoire, Sénégal, Mali...</small>
                    </div>
                </label>

                <label class="payment-option payment-mtn" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; cursor: pointer;">
                    <input type="radio" name="methode" value="mtn_money" style="accent-color: #ca8a04; transform: scale(1.2);">
                    <i class="fa-solid fa-money-bill-transfer" style="color: #ca8a04; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">MTN Mobile Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">MoMo Côte d'Ivoire & Afrique</small>
                    </div>
                </label>

                <label class="payment-option payment-moov" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; cursor: pointer;">
                    <input type="radio" name="methode" value="moov_money" style="accent-color: #16a34a; transform: scale(1.2);">
                    <i class="fa-solid fa-building-columns" style="color: #16a34a; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">Moov Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">Flooz et Moov Money</small>
                    </div>
                </label>

                <button type="submit" class="btn-submit" style="margin-top: 0.75rem; padding: 1rem; font-size: 1.05rem;">
                    <i class="fa-solid fa-lock"></i> Valider et Payer <?php echo number_format((float)$cotisation['montant'], 0, ',', ' '); ?> FCFA
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
