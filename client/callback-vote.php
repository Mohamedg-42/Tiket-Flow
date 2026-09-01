<?php
// ==============================================================================
// CONFIRMATION DU PAIEMENT D'UN VOTE PAYANT (client/callback-vote.php)
// Valide le paiement puis enregistre le vote dans event_votes
// ==============================================================================

require_once '../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php');
    exit();
}

$vote_paiement_id = filter_input(INPUT_POST, 'vote_paiement_id', FILTER_VALIDATE_INT);
$methode          = $_POST['methode'] ?? '';
$telephone        = trim($_POST['telephone'] ?? '');
$methodes_autorisees = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

if (!$vote_paiement_id || !in_array($methode, $methodes_autorisees, true)) {
    $_SESSION['vote_message'] = "Méthode de paiement non reconnue.";
    header('Location: accueil.php?onglet=voter');
    exit();
}

// 1. Récupération du paiement de vote en attente
$stmt = $pdo->prepare("
    SELECT vp.*, e.nom AS event_nom
    FROM vote_paiements vp
    JOIN events e ON e.id = vp.event_id
    WHERE vp.id = ?
");
$stmt->execute([$vote_paiement_id]);
$vote_pay = $stmt->fetch();

if (!$vote_pay || $vote_pay['statut'] !== 'en_attente') {
    $_SESSION['vote_message'] = 'Ce paiement de vote est introuvable ou a déjà été réglé.';
    header('Location: accueil.php?onglet=voter');
    exit();
}

// Référence unique de paiement et ID de transaction (comme pour les billets)
$reference          = 'VOTE-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
$transaction_api_id = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);

try {
    $pdo->beginTransaction();

    // 2. Validation du paiement (statut 'paye' + référence)
    $stmt_pay = $pdo->prepare("
        UPDATE vote_paiements
        SET statut = 'paye', methode = ?, reference = ?, transaction_id_api = ?
        WHERE id = ? AND statut = 'en_attente'
    ");
    $stmt_pay->execute([$methode, $reference, $transaction_api_id, $vote_paiement_id]);
    if ($stmt_pay->rowCount() === 0) {
        throw new Exception('Paiement déjà réglé ou introuvable.');
    }

    // 3. Enregistrement du/des vote(s) (choix multiples supportés)
    $cands_ids = [];
    if (!empty($vote_pay['candidats_ids'])) {
        $cands_ids = json_decode($vote_pay['candidats_ids'], true);
    } elseif (!empty($vote_pay['candidat_id'])) {
        $cands_ids = [(int)$vote_pay['candidat_id']];
    }

    if (!empty($cands_ids) && is_array($cands_ids)) {
        $ins = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id, candidat_id) VALUES (?, ?, ?, ?)");
        foreach ($cands_ids as $cid) {
            $ins->execute([$vote_pay['event_id'], $vote_pay['user_id'], $vote_pay['visitor_id'], (int)$cid]);
        }
    } else {
        $ins = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id, candidat_id) VALUES (?, ?, ?, NULL)");
        $ins->execute([$vote_pay['event_id'], $vote_pay['user_id'], $vote_pay['visitor_id']]);
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['vote_message'] = "Erreur lors du paiement : " . $e->getMessage();
    header('Location: accueil.php?onglet=voter');
    exit();
}

// Récupération des candidats choisis pour l'affichage de confirmation
$candidats_confirmes = [];
if (!empty($cands_ids) && is_array($cands_ids)) {
    $in_c = implode(',', array_map('intval', $cands_ids));
    if ($in_c !== '') {
        $candidats_confirmes = $pdo->query("SELECT * FROM event_candidats WHERE id IN ($in_c)")->fetchAll();
    }
}

$page_title = "Vote Confirmé - Ticket Flow";
$body_class = "client-page payment-page";
include 'header.php';
?>
<main class="client-main" style="max-width: 640px; margin: 0 auto; padding: 2rem 1rem;">
    <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); overflow: hidden; box-shadow: var(--shadow-xl);">
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #ffffff; padding: 2.25rem 2rem; text-align: center;">
            <div style="width: 60px; height: 60px; background: rgba(22, 163, 74, 0.25); border-radius: 50%; display: grid; place-items: center; font-size: 1.7rem; color: #4ade80; margin: 0 auto 1rem;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h1 style="color: #ffffff; margin: 0 0 0.5rem; font-size: 1.7rem;">Vote Confirmé !</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Votre vote pour <strong style="color: #e2e8f0;"><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong> a bien été comptabilisé.
            </p>
        </div>

        <div style="padding: 2rem;">
            <?php if (!empty($candidats_confirmes)): ?>
                <div style="margin-bottom: 1.5rem;">
                    <span style="color: var(--navy); font-weight: 700; font-size: 0.85rem; text-transform: uppercase; display: block; margin-bottom: 0.75rem;">
                        <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Choix validé(s) pour cet événement :
                    </span>
                    <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                        <?php foreach ($candidats_confirmes as $cc): ?>
                            <?php
                            $p_img = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                            if (!empty($cc['photo'])) {
                                if (strpos($cc['photo'], 'http') === 0) {
                                    $p_img = htmlspecialchars($cc['photo']);
                                } elseif (file_exists('../uploads/candidats/' . $cc['photo'])) {
                                    $p_img = '../uploads/candidats/' . htmlspecialchars($cc['photo']);
                                }
                            }
                            ?>
                            <div style="display: flex; align-items: center; gap: 0.9rem; padding: 0.7rem 1rem; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 8px;">
                                <img src="<?php echo $p_img; ?>" alt="<?php echo htmlspecialchars($cc['nom']); ?>" style="width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid #16a34a;">
                                <div style="flex: 1;">
                                    <strong style="color: #166534; font-size: 0.95rem; display: block;"><?php echo htmlspecialchars($cc['nom']); ?></strong>
                                    <?php if (!empty($cc['description'])): ?>
                                        <small style="color: var(--muted); display: block; line-height: 1.3; font-size: 0.8rem;"><?php echo htmlspecialchars($cc['description']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <span style="background: #16a34a; color: #ffffff; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                    +1 Vote
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div style="background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--line-light); font-size: 0.9rem;">
                    <span style="color: var(--muted);">Événement</span>
                    <strong style="color: var(--navy);"><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--line-light); font-size: 0.9rem;">
                    <span style="color: var(--muted);">Opérateur</span>
                    <strong style="color: var(--navy); text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $methode)); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; font-size: 1.05rem;">
                    <span style="color: var(--navy); font-weight: 700;">Montant payé</span>
                    <strong style="color: var(--primary); font-size: 1.3rem;"><?php echo number_format((float)$vote_pay['montant'], 0, ',', ' '); ?> FCFA</strong>
                </div>
            </div>

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.5rem; text-align: center;">
                <small style="color: #166534; font-weight: 700; text-transform: uppercase; font-size: 0.72rem; display: block; margin-bottom: 4px;">Référence de transaction</small>
                <strong style="font-family: monospace; font-size: 1.1rem; color: #166534; letter-spacing: 1px;"><?php echo htmlspecialchars($reference); ?></strong>
                <small style="color: var(--muted); display: block; margin-top: 4px; font-size: 0.75rem;">Conservez cette référence comme preuve de votre vote.</small>
            </div>

            <div style="display: flex; gap: 0.75rem;">
                <a href="accueil.php?onglet=voter" class="btn-submit" style="flex: 1; text-decoration: none; text-align: center; background: transparent; color: var(--muted); border: 1px solid var(--line);">
                    <i class="fa-solid fa-trophy"></i> Voir le classement
                </a>
                <a href="accueil.php" class="btn-submit" style="flex: 1; text-decoration: none; text-align: center;">
                    <i class="fa-solid fa-house"></i> Retour à l'accueil
                </a>
            </div>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>