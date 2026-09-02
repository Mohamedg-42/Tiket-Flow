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

<main class="client-main" style="max-width: 1000px; margin: 2rem auto; padding: 0 clamp(0.75rem, 2vw, 1.5rem);">
    <div class="page-header" style="margin-bottom: 2rem;">
        <div class="page-heading">
            <span class="page-kicker">Service Client</span>
            <h1><i class="fa-solid fa-headset"></i> Support & Réclamations</h1>
            <p>Un problème avec une commande ou un billet ? Déposez une réclamation ci-dessous.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom: 1.5rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="responsive-side-grid" style="display: grid; grid-template-columns: 360px 1fr; gap: 2rem; align-items: start;">
        <!-- 1. Formulaire de nouvelle réclamation -->
        <div class="content-section">
            <div class="section-title"><i class="fa-solid fa-pen"></i> Nouvelle Réclamation</div>
            <form method="POST">
                <input type="hidden" name="send_claim" value="1">

                <div class="form-group">
                    <label for="sujet">Sujet de votre demande *</label>
                    <input type="text" id="sujet" name="sujet" required placeholder="Ex: Problème de paiement, QR code non reçu...">
                </div>

                <div class="form-group">
                    <label for="order_id">Commande concernée (Optionnel)</label>
                    <select name="order_id" id="order_id">
                        <option value="">-- Aucune commande spécifique --</option>
                        <?php foreach ($user_orders as $ord): ?>
                            <option value="<?php echo $ord['id']; ?>">
                                <?php echo htmlspecialchars($ord['numero_commande']); ?> (<?php echo number_format($ord['montant_total'], 0, ',', ' '); ?> F)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="message">Description détaillée *</label>
                    <textarea id="message" name="message" rows="4" required placeholder="Expliquez clairement votre situation..."></textarea>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <i class="fa-solid fa-paper-plane"></i> Envoyer ma réclamation
                </button>
            </form>
        </div>

        <!-- 2. Historique et réponses de l'administration -->
        <div class="content-section">
            <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Mes Réclamations (<?php echo count($claims_list); ?>)</div>

            <?php if (count($claims_list) > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <?php foreach ($claims_list as $cl): ?>
                        <div style="background: #f8faf9; border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <h3 style="margin: 0; color: var(--navy); font-size: 1.05rem;">
                                    <?php echo htmlspecialchars($cl['sujet']); ?>
                                </h3>
                                <span>
                                    <?php if ($cl['statut'] === 'resolue'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: bold;">RÉSOLUE</span>
                                    <?php elseif ($cl['statut'] === 'en_cours'): ?>
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: bold;">EN COURS</span>
                                    <?php elseif ($cl['statut'] === 'fermee'): ?>
                                        <span style="background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: bold;">FERMÉE</span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: bold;">EN ATTENTE</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div style="font-size: 0.8rem; color: var(--muted); margin-bottom: 0.75rem;">
                                Déposée le <?php echo date('d/m/Y à H:i', strtotime($cl['created_at'])); ?>
                                <?php if ($cl['numero_commande']): ?>
                                    · Commande <strong>#<?php echo htmlspecialchars($cl['numero_commande']); ?></strong>
                                <?php endif; ?>
                            </div>

                            <p style="background: #ffffff; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--line); font-size: 0.9rem; margin: 0 0 0.75rem; color: var(--ink);">
                                <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                            </p>

                            <!-- Réponse de l'administrateur si disponible -->
                            <?php if (!empty($cl['reponse_admin'])): ?>
                                <div style="background: #eff6ff; border-left: 4px solid var(--primary); padding: 0.75rem; border-radius: 0 6px 6px 0; margin-top: 0.5rem;">
                                    <strong style="color: var(--primary); font-size: 0.85rem; display: block; margin-bottom: 0.25rem;">
                                        <i class="fa-solid fa-reply"></i> Réponse de l'Administration :
                                    </strong>
                                    <p style="margin: 0; font-size: 0.88rem; color: var(--ink);">
                                        <?php echo nl2br(htmlspecialchars($cl['reponse_admin'])); ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <small style="color: var(--muted); font-style: italic;">
                                    <i class="fa-solid fa-hourglass-half"></i> En attente de réponse du support...
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: var(--muted); padding: 3rem 1rem;">
                    <i class="fa-solid fa-circle-check" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.5rem; display: block;"></i>
                    Vous n'avez aucune réclamation en cours.
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
