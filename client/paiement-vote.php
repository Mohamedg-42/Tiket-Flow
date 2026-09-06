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
    <a href="accueil.php?onglet=voter" class="back-link" style="margin-bottom: 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem; color: var(--eventia-muted, #737373); text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-arrow-left"></i> Annuler et retourner au classement
    </a>

    <div class="payment-card eventia-card" style="padding: 0; overflow: hidden; border: 1px solid var(--eventia-border, #E5E5E5); border-radius: var(--eventia-radius-lg, 14px); box-shadow: var(--eventia-shadow-lg, 0 12px 24px -4px rgba(16, 24, 40, 0.10));">
        <div class="payment-heading" style="background: linear-gradient(135deg, #000000 0%, #121212 100%); color: #ffffff; padding: 2rem;">
            <div class="payment-icon" style="width: 48px; height: 48px; background: rgba(255, 74, 13, 0.18); border: 1px solid rgba(255, 74, 13, 0.35); border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 1rem; color: var(--tikeli-orange, #FF4A0D);">
                <i class="fa-solid fa-vote-yea"></i>
            </div>
            <span class="page-kicker" style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em;">Vote #<?php echo (int)$vote_pay['id']; ?></span>
            <h1 style="color: #ffffff; margin: 0.2rem 0 0.5rem; font-size: 1.7rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;">Finaliser votre Vote</h1>
            <p style="color: #737373; font-size: 0.92rem; margin: 0;">
                Votre vote pour <strong><?php echo htmlspecialchars($vote_pay['event_nom']); ?></strong> sera comptabilisé dès la confirmation du paiement.
            </p>
        </div>

        <?php if (!empty($candidats_choisis)): ?>
            <!-- Affichage des candidats / choix sélectionnés avec images et descriptions -->
            <div style="background: #F5F5F5; border-bottom: 1px solid var(--eventia-border, #E5E5E5); padding: 1.25rem 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <span style="color: var(--eventia-navy, #000000); font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 700; font-size: 0.88rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-user-check" style="color: var(--eventia-amber-dark, #FF4A0D);"></i> Choix sélectionné(s) (<?php echo count($candidats_choisis); ?>)
                    </span>
                    <span class="eventia-badge eventia-badge-amber">
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
                        <div style="display: flex; gap: 1rem; align-items: center; background: #ffffff; border: 1px solid var(--eventia-border, #E5E5E5); border-radius: var(--eventia-radius-md, 10px); padding: 0.75rem 1rem; box-shadow: var(--eventia-shadow-xs);">
                            <img src="<?php echo $photo_url; ?>" alt="<?php echo htmlspecialchars($c['nom']); ?>" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid var(--eventia-amber, #FF4A0D); flex-shrink: 0;">
                            <div style="flex: 1; min-width: 0;">
                                <strong style="color: var(--eventia-navy, #000000); font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 0.95rem; display: block;"><?php echo htmlspecialchars($c['nom']); ?></strong>
                                <?php if (!empty($c['description'])): ?>
                                    <p style="color: var(--eventia-muted, #737373); font-size: 0.82rem; margin: 0.2rem 0 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo htmlspecialchars($c['description']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span style="color: var(--eventia-turquoise, #FF4A0D); font-size: 1.1rem;"><i class="fa-solid fa-circle-check"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="payment-amount" style="background: #F5F5F5; border-bottom: 1px solid var(--eventia-border, #E5E5E5); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <span style="color: var(--eventia-navy, #000000); font-weight: 700; font-size: 0.95rem; display: block;">Montant total du vote :</span>
                <?php if (!empty($candidats_choisis)): ?>
                    <small style="color: var(--eventia-muted, #737373); font-size: 0.8rem;"><?php echo count($candidats_choisis); ?> choix × <?php echo number_format((float)($vote_pay['montant'] / count($candidats_choisis)), 0, ',', ' '); ?> FCFA</small>
                <?php endif; ?>
            </div>
            <strong class="swiss-numeral" style="color: var(--eventia-navy, #000000); font-size: 1.8rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800;"><?php echo number_format((float)$vote_pay['montant'], 0, ',', ' '); ?> <span style="font-family: var(--font-body, 'Inter', sans-serif); font-size: 1rem; color: var(--eventia-muted, #737373); font-weight: 700;">FCFA</span></strong>
        </div>
        <div class="payment-methods" style="padding: 2rem;">
            <!-- Intégration Feexpay API V2 -->
            <div id="render" style="margin: 0 auto; max-width: 400px; text-align: center;"></div>

            <script src="https://api-v2.feexpay.me/feexpay-javascript-sdk/index.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const baseUrl = window.location.protocol + '//' + window.location.host + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                    const callbackUrl = baseUrl + '/callback-vote.php?vote_paiement_id=<?php echo $vote_pay['id']; ?>&methode=feexpay';

                    FeexPayButton.init("render", {
                        id: "80hxOfqxlIDOZJi",
                        amount: <?php echo (int)$vote_pay['montant']; ?>,
                        token: "test_Hg7Kjl3ZAM63UuIUpuudD9nKuu3ZAM67Kjl3Uuhn",
                        callback_url: callbackUrl,
                        mode: "SANDBOX",
                        custom_id: "VOTE_<?php echo $vote_pay['id']; ?>",
                        description: "Achat de vote #<?php echo (int)$vote_pay['id']; ?>",
                        networks: {
                            "Côte d'Ivoire": ["MTN", "ORANGE", "WAVE", "MOOV"]
                        }
                    });
                });
            </script>
            
            <p style="text-align: center; margin-top: 1.5rem; color: var(--muted); font-size: 0.85rem;">
                <i class="fa-solid fa-lock" style="color: #FF4A0D;"></i> Paiement sécurisé via Feexpay
            </p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
