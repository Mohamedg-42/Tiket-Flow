<?php
// ==============================================================================
// CONFIRMATION DU PAIEMENT D'UNE CONTRIBUTION (client/callback-cotisation.php)
// Même mécanisme que le paiement des billets : validation, référence de
// transaction et page de confirmation
// ==============================================================================

require_once '../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php');
    exit();
}

$cotisation_id = filter_input(INPUT_POST, 'cotisation_id', FILTER_VALIDATE_INT);
$methode       = $_POST['methode'] ?? '';
$telephone     = trim($_POST['telephone'] ?? '');
$methodes_autorisees = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

if (!$cotisation_id || !in_array($methode, $methodes_autorisees, true)) {
    $_SESSION['cotisation_message'] = "Méthode de paiement non reconnue.";
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// 1. Récupération de la contribution en attente
$stmt = $pdo->prepare('
    SELECT ct.*, c.titre AS campagne_titre
    FROM cotisations ct
    LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
    WHERE ct.id = ?
');
$stmt->execute([$cotisation_id]);
$cotisation = $stmt->fetch();

if (!$cotisation || $cotisation['statut'] !== 'en_attente') {
    $_SESSION['cotisation_message'] = 'Cette contribution est introuvable ou a déjà été payée.';
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// Référence unique de paiement et ID de transaction (comme pour les billets)
$reference          = 'PAY-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
$transaction_api_id = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);
$telephone_final    = $telephone !== '' ? $telephone : ($cotisation['telephone'] ?? '');

try {
    // 2. Validation de la contribution (statut 'payee' + référence de transaction)
    $stmt_pay = $pdo->prepare("
        UPDATE cotisations 
        SET statut = 'payee', methode = ?, reference = ?, transaction_id_api = ?, 
            telephone = COALESCE(NULLIF(?, ''), telephone), date_paiement = NOW()
        WHERE id = ? AND statut = 'en_attente'
    ");
    $stmt_pay->execute([$methode, $reference, $transaction_api_id, $telephone_final, $cotisation_id]);

    if ($stmt_pay->rowCount() === 0) {
        throw new Exception('Contribution déjà réglée ou introuvable.');
    }

    $paiement_ok = true;

} catch (Exception $e) {
    $_SESSION['cotisation_message'] = "Erreur lors du paiement : " . $e->getMessage();
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$page_title = "Contribution Confirmée - Ticket Flow";
$body_class = "client-page payment-page";
include 'header.php';
?>

<main class="client-main" style="max-width: 640px; margin: 0 auto; padding: 2rem 1rem;">

    <!-- ===== Confirmation du paiement de la contribution ===== -->
    <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); overflow: hidden; box-shadow: var(--shadow-xl);">
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #ffffff; padding: 2.25rem 2rem; text-align: center;">
            <div style="width: 60px; height: 60px; background: rgba(22, 163, 74, 0.25); border-radius: 50%; display: grid; place-items: center; font-size: 1.6rem; margin: 0 auto 1rem; color: #4ade80;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <span class="page-kicker" style="color: #4ade80;">Paiement réussi</span>
            <h1 style="color: #ffffff; margin: 0.3rem 0 0.5rem; font-size: 1.7rem;">Merci pour votre Contribution !</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Votre paiement a été confirmé par Mobile Money<?php echo !empty($cotisation['campagne_titre']) ? ' pour la campagne <strong style="color:#e2e8f0;">' . htmlspecialchars($cotisation['campagne_titre']) . '</strong>' : ''; ?>.
            </p>
        </div>

        <div style="padding: 2rem;">
            <!-- Récapitulatif -->
            <div style="background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--line-light); font-size: 0.9rem;">
                    <span style="color: var(--muted);">Contributeur</span>
                    <strong style="color: var(--navy);"><?php echo htmlspecialchars($cotisation['nom']); ?></strong>
                </div>
                <?php if (!empty($cotisation['campagne_titre'])): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--line-light); font-size: 0.9rem;">
                        <span style="color: var(--muted);">Campagne</span>
                        <strong style="color: var(--navy);"><?php echo htmlspecialchars($cotisation['campagne_titre']); ?></strong>
                    </div>
                <?php endif; ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--line-light); font-size: 0.9rem;">
                    <span style="color: var(--muted);">Opérateur</span>
                    <strong style="color: var(--navy); text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $methode)); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; font-size: 1.05rem;">
                    <span style="color: var(--navy); font-weight: 700;">Montant payé</span>
                    <strong style="color: var(--primary); font-size: 1.3rem;"><?php echo number_format((float)$cotisation['montant'], 0, ',', ' '); ?> FCFA</strong>
                </div>
            </div>

            <!-- Référence de transaction -->
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.5rem; text-align: center;">
                <small style="color: #166534; font-weight: 700; text-transform: uppercase; font-size: 0.72rem; display: block; margin-bottom: 4px;">Référence de transaction</small>
                <strong style="font-family: monospace; font-size: 1.1rem; color: #166534; letter-spacing: 1px;"><?php echo htmlspecialchars($reference); ?></strong>
                <small style="color: var(--muted); display: block; margin-top: 4px; font-size: 0.75rem;">Conservez cette référence comme preuve de votre contribution.</small>
            </div>

            <div style="display: flex; gap: 0.75rem;">
                <a href="accueil.php?onglet=cotisations" class="btn-submit" style="flex: 1; text-decoration: none; text-align: center; background: transparent; color: var(--muted); border: 1px solid var(--line);">
                    <i class="fa-solid fa-hand-holding-heart"></i> Autres campagnes
                </a>
                <a href="accueil.php" class="btn-submit" style="flex: 1; text-decoration: none; text-align: center;">
                    <i class="fa-solid fa-house"></i> Retour à l'accueil
                </a>
            </div>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>