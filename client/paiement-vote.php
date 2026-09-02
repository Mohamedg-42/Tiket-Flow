<?php
// ==============================================================================
// PAIEMENT MOBILE MONEY D'UN VOTE PAYANT (client/paiement-vote.php)
// Même flux que le paiement des billets / contributions
// ==============================================================================

require_once '../config/database.php';
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
        $user_telephone = (string)$stmt_utel->fetchColumn();
    } catch (PDOException $e) {
        $user_telephone = '';
    }
}

// Récupération du paiement de vote en attente + infos de l'événement
$stmt = $pdo->prepare("
    SELECT vp.*, e.nom AS event_nom
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

// Récupération des candidats sélectionnés avec leurs photos et descriptions
$candidats_choisis = [];
$cands_ids = [];
if (!empty($vote_pay['candidats_ids'])) {
    $cands_ids = json_decode($vote_pay['candidats_ids'], true);
} elseif (!empty($vote_pay['candidat_id'])) {
    $cands_ids = [(int)$vote_pay['candidat_id']];
}
if (!empty($cands_ids) && is_array($cands_ids)) {
    $in_sql = implode(',', array_map('intval', $cands_ids));
    if ($in_sql !== '') {
        $candidats_choisis = $pdo->query("SELECT * FROM event_candidats WHERE id IN ($in_sql)")->fetchAll();
    }
}

$page_title = "Paiement de votre Vote - Eventia";
$body_class = "client-page payment-page";
include 'header.php';
?>
<div class="payment-container" style="max-width: 580px; margin: 2rem auto; padding: 0 clamp(0.75rem, 2vw, 1rem);">
    <a href="accueil.php?onglet=voter" class="back-link" style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--muted); text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner au classement
    </a>

    <div class="payment-card" style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); overflow: hidden; box-shadow: var(--shadow-xl);">
        <div class="payment-heading" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #ffffff; padding: 2rem;">
            <div class="payment-icon" style="width: 48px; height: 48px; background: rgba(255,255,255,0.12); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: #38bdf8;">
                <i class="fa-solid fa-up-long"></i>
            </div>
            <span class="page-kicker" style="color: #38bdf8;">Vote #<?php echo (int)$vote_pay['id']; ?></span>
            <h1 style="color: #ffffff; margin: 0.2rem 0 0.5rem; font-size: 1.7rem;">Finaliser votre Vote</h1>
            <p style="color: #94a3b8; font-size: 0.92rem; margin: 0;">
                Votre vote pour <strong><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong> sera comptabilisé dès la confirmation du paiement.
            </p>
        </div>

        <?php if (!empty($candidats_choisis)): ?>
            <!-- Affichage des candidats / choix sélectionnés avec images et descriptions -->
            <div style="background: #f8fafc; border-bottom: 1px solid var(--line); padding: 1.25rem 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <span style="color: var(--navy); font-weight: 700; font-size: 0.88rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-user-check" style="color: var(--primary);"></i> Choix sélectionné(s) (<?php echo count($candidats_choisis); ?>)
                    </span>
                    <span style="font-size: 0.8rem; background: var(--primary-soft); color: var(--primary); padding: 2px 8px; border-radius: 6px; font-weight: 700;">
                        <?php echo count($candidats_choisis); ?> vote(s)
                    </span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($candidats_choisis as $c): ?>
                        <?php
                        $photo_url = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                        if (!empty($c['photo'])) {
                            if (strpos($c['photo'], 'http') === 0) {
                                $photo_url = htmlspecialchars($c['photo']);
                            } elseif (file_exists('../uploads/candidats/' . $c['photo'])) {
                                $photo_url = '../uploads/candidats/' . htmlspecialchars($c['photo']);
                            }
                        }
                        ?>
                        <div style="display: flex; gap: 1rem; align-items: center; background: #ffffff; border: 1px solid var(--line); border-radius: 10px; padding: 0.75rem 1rem; box-shadow: var(--shadow-sm);">
                            <img src="<?php echo $photo_url; ?>" alt="<?php echo htmlspecialchars($c['nom']); ?>" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">
                            <div style="flex: 1; min-width: 0;">
                                <strong style="color: var(--navy); font-size: 0.95rem; display: block;"><?php echo htmlspecialchars($c['nom']); ?></strong>
                                <?php if (!empty($c['description'])): ?>
                                    <p style="color: var(--muted); font-size: 0.82rem; margin: 0.2rem 0 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo htmlspecialchars($c['description']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span style="color: #16a34a; font-size: 1.1rem;"><i class="fa-solid fa-circle-check"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="payment-amount" style="background: #f8fafc; border-bottom: 1px solid var(--line); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <span style="color: var(--navy); font-weight: 700; font-size: 0.95rem; display: block;">Montant total du vote :</span>
                <?php if (!empty($candidats_choisis)): ?>
                    <small style="color: var(--muted); font-size: 0.8rem;"><?php echo count($candidats_choisis); ?> choix × <?php echo number_format((float)($vote_pay['montant'] / count($candidats_choisis)), 0, ',', ' '); ?> FCFA</small>
                <?php endif; ?>
            </div>
            <strong style="color: var(--primary); font-size: 1.6rem;"><?php echo number_format((float)$vote_pay['montant'], 0, ',', ' '); ?> FCFA</strong>
        </div>
        <div class="payment-methods" style="padding: 2rem;">
            <form method="POST" action="callback-vote.php" style="display: grid; gap: 1rem;">
                <input type="hidden" name="vote_paiement_id" value="<?php echo (int)$vote_pay['id']; ?>">

                <?php if (!$is_logged_in && empty($user_telephone)): ?>
                    <!-- Saisie du numéro Mobile Money demandée uniquement pour les invités sans téléphone pré-renseigné (comme pour l'achat de billets) -->
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label for="telephone_paiement" style="font-weight: 700; color: var(--navy); font-size: 0.88rem;">
                            <i class="fa-solid fa-phone" style="color: var(--primary);"></i> Numéro Mobile Money pour le débit *
                        </label>
                        <input type="tel" id="telephone_paiement" name="telephone_paiement" required
                               placeholder="Ex: 07 00 00 00 00"
                               value="<?php echo htmlspecialchars($user_telephone ?? ''); ?>"
                               style="padding: 0.8rem 1rem; font-size: 1.05rem; font-weight: 600; letter-spacing: 0.5px;">
                    </div>
                <?php elseif ($is_logged_in && !empty($user_telephone)): ?>
                    <input type="hidden" name="telephone_paiement" value="<?php echo htmlspecialchars($user_telephone); ?>">
                <?php endif; ?>

                <p style="color: var(--navy); font-weight: 700; font-size: 0.85rem; margin-bottom: 0.75rem;">
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

                <label class="payment-option payment-orange" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; margin-top: 0.6rem; cursor: pointer;">
                    <input type="radio" name="methode" value="orange_money" style="accent-color: #ea580c; transform: scale(1.2);">
                    <i class="fa-solid fa-wallet" style="color: #ea580c; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">Orange Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">Côte d'Ivoire, Sénégal, Mali...</small>
                    </div>
                </label>

                <label class="payment-option payment-mtn" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; margin-top: 0.6rem; cursor: pointer;">
                    <input type="radio" name="methode" value="mtn_money" style="accent-color: #ca8a04; transform: scale(1.2);">
                    <i class="fa-solid fa-money-bill-transfer" style="color: #ca8a04; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">MTN Mobile Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">MoMo Côte d'Ivoire & Afrique</small>
                    </div>
                </label>

                <label class="payment-option payment-moov" style="display: flex; align-items: center; gap: 1rem; padding: 0.95rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: #ffffff; margin-top: 0.6rem; cursor: pointer;">
                    <input type="radio" name="methode" value="moov_money" style="accent-color: #16a34a; transform: scale(1.2);">
                    <i class="fa-solid fa-building-columns" style="color: #16a34a; font-size: 1.3rem;"></i>
                    <div>
                        <strong style="display: block; color: var(--navy); font-size: 0.95rem;">Moov Money</strong>
                        <small style="color: var(--muted); font-size: 0.78rem;">Flooz et Moov Money</small>
                    </div>
                </label>

                <button type="submit" class="btn-submit" style="margin-top: 0.75rem; padding: 1rem; font-size: 1.05rem;">
                    <i class="fa-solid fa-lock"></i> Valider et Payer <?php echo number_format((float)$vote_pay['montant'], 0, ',', ' '); ?> FCFA
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
