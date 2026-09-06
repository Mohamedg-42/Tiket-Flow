<?php
// ==============================================================================
// GESTION DES RÉCLAMATIONS CLIENT (client/reclamations.php)
// Déposer une réclamation / demande d'assistance et suivre les réponses de l'admin
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin('../connexion.php');

$page_title = "Support & Réclamations - Eventia";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Dépôt d'une nouvelle réclamation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_claim'])) {
    $sujet    = trim($_POST['sujet'] ?? '');
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: null;
    $msg      = trim($_POST['message'] ?? '');

    if (empty($sujet) || empty($msg)) {
        $message = "Veuillez renseigner le sujet et le message de votre réclamation.";
        $msg_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("
            INSERT INTO claims (user_id, order_id, sujet, message, statut, created_at) 
            VALUES (?, ?, ?, ?, 'en_attente', NOW())
        ");
        $stmt_ins->execute([$user_id, $order_id, $sujet, $msg]);

        $message = "Votre réclamation a été transmise à notre service client. Nous vous répondrons sous peu.";
        $msg_type = "success";
    }
}

// 2. Récupération des commandes de l'utilisateur pour la liste déroulante
$stmt_orders = $pdo->prepare("SELECT id, numero_commande, montant_total FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt_orders->execute([$user_id]);
$user_orders = $stmt_orders->fetchAll();

// 3. Récupération des réclamations déposées par le client
$stmt_claims = $pdo->prepare("
    SELECT c.*, o.numero_commande 
    FROM claims c 
    LEFT JOIN orders o ON c.order_id = o.id 
    WHERE c.user_id = ? 
    ORDER BY c.created_at DESC
");
$stmt_claims->execute([$user_id]);
$claims_list = $stmt_claims->fetchAll();
?>

<main class="client-main swiss-spread">
    <div class="swiss-wrap">
        <!-- Calque de Grille Modulaire Müller-Brockmann -->
        <div class="guides" aria-hidden="true">
            <div class="cols"></div>
            <div class="rows"></div>
            <div class="mline l"></div>
            <div class="mline r"></div>
        </div>

        <div class="page-header" style="margin-bottom: 2rem;">
            <div class="page-heading">
                <span class="page-kicker">Service Client</span>
                <h1 class="swiss-headline"><i class="fa-solid fa-headset"></i> Support & Réclamations</h1>
                <p>Un problème avec une commande ou un billet ? Déposez une réclamation ci-dessous.</p>
            </div>
        </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom: 1.5rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="responsive-side-grid" style="display: grid; grid-template-columns: minmax(300px, 380px) 1fr; gap: 2rem; align-items: start;">
        <!-- 1. Formulaire de nouvelle réclamation -->
        <div class="content-section eventia-card" style="padding: 1.75rem;">
            <div class="section-title" style="font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 1.15rem; font-weight: 700; color: var(--eventia-navy, #000000); margin-bottom: 1.25rem;">
                <i class="fa-solid fa-pen" style="color: var(--eventia-amber-dark, #FF4A0D);"></i> Nouvelle Réclamation
            </div>
            <form method="POST" class="eventia-form">
                <input type="hidden" name="send_claim" value="1">

                <div class="eventia-form-group">
                    <label for="sujet" class="eventia-label">Sujet de votre demande *</label>
                    <input type="text" id="sujet" name="sujet" class="eventia-input" required placeholder="Ex: Problème de paiement, QR code non reçu...">
                </div>

                <div class="eventia-form-group">
                    <label for="order_id" class="eventia-label">Commande concernée (Optionnel)</label>
                    <select name="order_id" id="order_id" class="eventia-select">
                        <option value="">-- Aucune commande spécifique --</option>
                        <?php foreach ($user_orders as $ord): ?>
                            <option value="<?php echo $ord['id']; ?>">
                                <?php echo htmlspecialchars($ord['numero_commande']); ?> (<?php echo number_format($ord['montant_total'], 0, ',', ' '); ?> F)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eventia-form-group">
                    <label for="message" class="eventia-label">Description détaillée *</label>
                    <textarea id="message" name="message" rows="4" class="eventia-textarea" required placeholder="Expliquez clairement votre situation..."></textarea>
                </div>

                <button type="submit" class="eventia-btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    <i class="fa-solid fa-paper-plane"></i> Envoyer ma réclamation
                </button>
            </form>
        </div>

        <!-- 2. Historique et réponses de l'administration -->
        <div class="content-section eventia-card" style="padding: 1.75rem;">
            <div class="section-title" style="font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 1.15rem; font-weight: 700; color: var(--eventia-navy, #000000); margin-bottom: 1.25rem;">
                <i class="fa-solid fa-clock-rotate-left" style="color: var(--eventia-navy-light, #000000);"></i> Mes Réclamations (<?php echo count($claims_list); ?>)
            </div>

            <?php if (count($claims_list) > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <?php foreach ($claims_list as $cl): ?>
                        <div style="background: #F5F5F5; border: 1px solid var(--eventia-border, #E5E5E5); border-radius: var(--eventia-radius-md, 10px); padding: 1.25rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <h3 style="margin: 0; color: var(--eventia-navy, #000000); font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 1.05rem; font-weight: 700;">
                                    <?php echo htmlspecialchars($cl['sujet']); ?>
                                </h3>
                                <span>
                                    <?php if ($cl['statut'] === 'resolue'): ?>
                                        <span class="eventia-badge eventia-badge-success">RÉSOLUE</span>
                                    <?php elseif ($cl['statut'] === 'en_cours'): ?>
                                        <span class="eventia-badge eventia-badge-amber">EN COURS</span>
                                    <?php elseif ($cl['statut'] === 'fermee'): ?>
                                        <span class="eventia-badge">FERMÉE</span>
                                    <?php else: ?>
                                        <span class="eventia-badge eventia-badge-warning">EN ATTENTE</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div style="font-size: 0.8rem; color: var(--eventia-muted, #737373); margin-bottom: 0.75rem;">
                                Déposée le <?php echo date('d/m/Y à H:i', strtotime($cl['created_at'])); ?>
                                <?php if ($cl['numero_commande']): ?>
                                    · Commande <strong>#<?php echo htmlspecialchars($cl['numero_commande']); ?></strong>
                                <?php endif; ?>
                            </div>

                            <p style="background: #ffffff; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.9rem; margin: 0 0 0.75rem; color: var(--eventia-text, #000000); line-height: 1.5;">
                                <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                            </p>

                            <!-- Réponse de l'administrateur si disponible -->
                            <?php if (!empty($cl['reponse_admin'])): ?>
                                <div style="background: #FFF2ED; border-left: 4px solid var(--eventia-navy, #000000); padding: 0.85rem; border-radius: 0 8px 8px 0; margin-top: 0.5rem;">
                                    <strong style="color: var(--eventia-navy, #000000); font-size: 0.85rem; display: block; margin-bottom: 0.25rem;">
                                        <i class="fa-solid fa-reply"></i> Réponse de l'Administration :
                                    </strong>
                                    <p style="margin: 0; font-size: 0.88rem; color: var(--eventia-text, #000000); line-height: 1.5;">
                                        <?php echo nl2br(htmlspecialchars($cl['reponse_admin'])); ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <small style="color: var(--eventia-muted, #737373); font-style: italic;">
                                    <i class="fa-solid fa-hourglass-half"></i> En attente de réponse du support...
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="eventia-empty-state" style="padding: 2.5rem 1rem;">
                    <i class="fa-solid fa-circle-check eventia-empty-state-icon" style="color: var(--eventia-turquoise, #FF4A0D);"></i>
                    <p class="eventia-empty-state-desc">Vous n'avez aucune réclamation en cours.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div> <!-- Fin de .swiss-wrap -->
</main>

<?php include 'footer.php'; ?>
