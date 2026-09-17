<?php
// ==============================================================================
// CONFIRMATION DU PAIEMENT D'UN VOTE PAYANT (client/callback-vote.php)
// Valide le paiement puis enregistre le vote dans event_votes
// ==============================================================================

require_once '../config/database.php';
session_start();

// FeexPay / passerelle redirige via GET
$vote_paiement_id = filter_input(INPUT_GET, 'vote_paiement_id', FILTER_VALIDATE_INT) ?: (filter_var($_GET['vote_paiement_id'] ?? null, FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'vote_paiement_id', FILTER_VALIDATE_INT));
$methode          = $_GET['methode'] ?? $_POST['methode'] ?? '';
$telephone        = trim($_POST['telephone_paiement'] ?? '');
$methodes_autorisees = ['wave', 'orange_money', 'mtn_money', 'moov_money', 'kadevpay', 'feexpay', 'bictorys'];

if (!$vote_paiement_id || !in_array($methode, $methodes_autorisees, true)) {
    $_SESSION['vote_message'] = "Paiement non validé ou méthode non reconnue.";
    $_SESSION['vote_type']    = 'error';
    header('Location: accueil.php?onglet=voter');
    exit();
}

// 1. Récupération du paiement de vote
$stmt = $pdo->prepare("
    SELECT vp.*, e.nom AS event_nom, e.visibilite AS event_visibilite, e.access_token AS event_access_token
    FROM vote_paiements vp
    JOIN events e ON e.id = vp.event_id
    WHERE vp.id = ?
");
$stmt->execute([$vote_paiement_id]);
$vote_pay = $stmt->fetch();

if (!$vote_pay) {
    $_SESSION['vote_message'] = 'Ce paiement de vote est introuvable.';
    header('Location: accueil.php?onglet=voter');
    exit();
}

// 1.1 Vérification de la signature cryptographique sécurisée anti-falsification (SEC-002)
if (!defined('APP_SECRET_KEY')) {
    error_log("TikeWA CRITIQUE: APP_SECRET_KEY non défini — inclure config/env.php");
    $_SESSION['vote_message'] = "Erreur de configuration serveur. Veuillez contacter l'administrateur.";
    $_SESSION['vote_type'] = 'error';
    header('Location: accueil.php?onglet=voter');
    exit();
}
$pay_secret = APP_SECRET_KEY;
$expected_token = hash_hmac('sha256', $vote_pay['id'] . '|' . $vote_pay['montant'] . '|' . $vote_pay['created_at'], $pay_secret);
$vote_token = $_GET['vote_token'] ?? $_POST['vote_token'] ?? '';

if (empty($vote_token) || !hash_equals($expected_token, $vote_token)) {
    $_SESSION['vote_message'] = "Validation rejetée : signature de paiement de vote invalide ou absente.";
    $_SESSION['vote_type'] = 'error';
    header('Location: accueil.php?onglet=voter');
    exit();
}

$is_already_paid = in_array($vote_pay['statut'], ['paye', 'valide'], true);

if (!$is_already_paid && $vote_pay['statut'] !== 'en_attente') {
    $_SESSION['vote_message'] = 'Ce paiement de vote est introuvable ou a été annulé.';
    header('Location: accueil.php?onglet=voter');
    exit();
}

// Référence unique de paiement et ID de transaction
$payment_ref = trim($_GET['reference'] ?? $_POST['reference'] ?? $_GET['transaction_id'] ?? $_GET['charge_id'] ?? $_GET['chargeId'] ?? '');
if (!empty($payment_ref)) {
    $prefix = (strtoupper($methode) === 'BICTORYS') ? 'VOTE-BICTORYS-' : 'VOTE-FEEXPAY-';
    $reference = (stripos($payment_ref, 'VOTE-') === 0 || stripos($payment_ref, 'PAY-') === 0) ? $payment_ref : ($prefix . $payment_ref);
    $transaction_api_id = $payment_ref;
} else {
    $reference          = 'VOTE-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
    $transaction_api_id = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

try {
    if (!$is_already_paid) {
        $pdo->beginTransaction();

        // 2. Validation du paiement (statut 'paye' + référence + téléphone)
        $stmt_pay = $pdo->prepare("
            UPDATE vote_paiements
            SET statut = 'paye', methode = ?, reference = ?, transaction_id_api = ?, telephone = COALESCE(NULLIF(?, ''), telephone)
            WHERE id = ? AND statut = 'en_attente'
        ");
        $stmt_pay->execute([$methode, $reference, $transaction_api_id, $telephone, $vote_paiement_id]);

        // 3. Enregistrement du/des vote(s) (choix multiples supportés)
        $cands_ids = [];
        if (!empty($vote_pay['candidats_ids'])) {
            $cands_ids = json_decode($vote_pay['candidats_ids'], true);
        } elseif (!empty($vote_pay['candidat_id'])) {
            $cands_ids = [(int)$vote_pay['candidat_id']];
        }

        $final_visitor_id = !empty($vote_pay['visitor_id'])
            ? $vote_pay['visitor_id']
            : (session_id() ?: ('vis_' . bin2hex(random_bytes(8))));

        if (!empty($cands_ids) && is_array($cands_ids)) {
            $ins = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id, candidat_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            foreach ($cands_ids as $cid) {
                $ins->execute([$vote_pay['event_id'], $vote_pay['user_id'], $final_visitor_id, (int) $cid]);
            }
        } else {
            $ins = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id, candidat_id, created_at) VALUES (?, ?, ?, NULL, NOW())");
            $ins->execute([$vote_pay['event_id'], $vote_pay['user_id'], $final_visitor_id]);
        }

        $pdo->commit();
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['vote_message'] = friendly_db_error($e, 'vote', "Une erreur technique est survenue lors de l'enregistrement de votre vote. Veuillez contacter le support si votre compte a été débité.");
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

$page_title = "Vote Confirmé - TikeWA";
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
            Vote Confirmé !
        </h1>
        <p style="color: var(--muted, #64748b); font-size: 1rem; margin-bottom: 1.5rem; line-height: 1.5;">
            Votre vote officiel pour <strong style="color: var(--navy, #0f172a);"><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong>
            d'un montant de <strong style="color: var(--navy, #0f172a);"><?php echo number_format((float) $vote_pay['montant'], 0, ',', ' '); ?> FCFA</strong> a été validé avec succès via
            <strong><?php echo strtoupper(htmlspecialchars(str_replace('_', ' ', $methode))); ?></strong>.
        </p>

        <!-- Candidats validés -->
        <?php if (!empty($candidats_confirmes)): ?>
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 1.15rem 1.25rem; margin-bottom: 1.5rem; text-align: left;">
                <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.75rem;">
                    <i class="fa-solid fa-trophy" style="color: var(--tikeli-orange, #FF4A0D); margin-right: 4px;"></i> Choix validé(s) pour cet événement :
                </span>
                <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                    <?php foreach ($candidats_confirmes as $cc): ?>
                        <?php
                        $default_cand_photo = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                        $p_img = $default_cand_photo;
                        if (!empty($cc['photo'])) {
                            $raw_p = trim($cc['photo']);
                            if (strpos($raw_p, 'http') === 0) {
                                $p_img = htmlspecialchars($raw_p);
                            } elseif (file_exists(__DIR__ . '/../uploads/candidats/' . $raw_p)) {
                                $p_img = '../uploads/candidats/' . htmlspecialchars($raw_p);
                            } elseif (file_exists(__DIR__ . '/../' . ltrim($raw_p, '/'))) {
                                $p_img = '../' . ltrim(htmlspecialchars($raw_p), '/');
                            }
                        }
                        ?>
                        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 0.85rem; background: #ffffff; border: 1px solid #E2E8F0; border-radius: 10px; width: 100%; box-sizing: border-box; min-width: 0;">
                            <img src="<?php echo $p_img; ?>" alt="<?php echo htmlspecialchars($cc['nom']); ?>"
                                style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; object-position: center top; border: 2px solid var(--tikeli-orange, #FF4A0D); flex-shrink: 0;"
                                loading="lazy" decoding="async"
                                onerror="this.onerror=null; this.src='<?php echo $default_cand_photo; ?>';">
                            <div style="flex: 1 1 0%; min-width: 0; overflow: hidden;">
                                <strong style="color: #0f172a; font-size: 0.92rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;"><?php echo htmlspecialchars($cc['nom']); ?></strong>
                                <?php if (!empty($cc['description'])): ?>
                                    <small style="color: #64748b; font-size: 0.76rem; display: block; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; margin-top: 1px;">
                                        <?php echo htmlspecialchars($cc['description']); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <span style="background: rgba(255, 74, 13, 0.12); color: var(--tikeli-orange, #FF4A0D); font-size: 0.76rem; font-weight: 800; padding: 3px 10px; border-radius: 999px; border: 1px solid rgba(255, 74, 13, 0.25); flex-shrink: 0; white-space: nowrap;">
                                +1 Vote
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Récapitulatif technique -->
        <div
            style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.92rem;">
            <div
                style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #E2E8F0;">
                <span style="color: #64748b; font-family: 'Space Mono', monospace; font-size: 0.8rem; text-transform: uppercase;">Événement</span>
                <strong style="color: #0f172a; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong>
            </div>
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
                    <?php echo number_format((float) $vote_pay['montant'], 0, ',', ' '); ?>
                    <span style="font-size: 0.85rem; font-family: 'Space Mono', monospace;">FCFA</span>
                </strong>
            </div>
        </div>

        <?php
        $share_vote_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ticket-platform/client/accueil.php?onglet=voter';
        $wa_vote_text = "🗳️ Je viens de voter sur TikeWA pour « " . htmlspecialchars($vote_pay['event_nom']) . " » ! Soutenez vos favoris ici : " . $share_vote_url;
        $wa_vote_href = "https://api.whatsapp.com/send?text=" . urlencode($wa_vote_text);
        ?>

        <!-- Boutons d'actions harmonisés -->
        <div style="display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?php echo $wa_vote_href; ?>" target="_blank" class="btn-submit"
                style="width: auto; padding: 0.75rem 1.4rem; background: #25D366; color: white; text-decoration: none; border-color: #25D366; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                <i class="fa-brands fa-whatsapp" style="font-size: 1.15rem;"></i> Inviter des proches sur WhatsApp
            </a>

            <?php if (($vote_pay['event_visibilite'] ?? 'public') === 'prive'): ?>
                <a href="vote.php?id=<?php echo (int) $vote_pay['event_id']; ?>&token=<?php echo urlencode($vote_pay['event_access_token'] ?? ''); ?>"
                    class="btn-submit"
                    style="width: auto; padding: 0.75rem 1.4rem; background: var(--tikeli-orange, #FF4A0D); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                    <i class="fa-solid fa-vote-yea"></i> Retourner au scrutin privé
                </a>
            <?php else: ?>
                <a href="accueil.php?onglet=voter" class="btn-submit"
                    style="width: auto; padding: 0.75rem 1.4rem; background: var(--tikeli-orange, #FF4A0D); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 8px;">
                    <i class="fa-solid fa-trophy"></i> Voir le classement des votes
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
