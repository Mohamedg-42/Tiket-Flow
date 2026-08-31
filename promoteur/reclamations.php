<?php
// ==============================================================================
// RÉCLAMATIONS PROMOTEUR (promoteur/reclamations.php)
// Déposer une réclamation ou poser une question à l'administration
// ==============================================================================

$page_title = "Réclamations & Support - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Dépôt d'une réclamation par le promoteur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_claim'])) {
    $sujet = trim($_POST['sujet'] ?? '');
    $msg   = trim($_POST['message'] ?? '');

    if (empty($sujet) || empty($msg)) {
        $message = "Veuillez renseigner le sujet et le message de votre réclamation.";
        $msg_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO claims (user_id, sujet, message, statut, created_at) VALUES (?, ?, ?, 'en_attente', NOW())");
        $stmt_ins->execute([$user_id, $sujet, $msg]);

        $message = "Votre réclamation a été transmise à l'administrateur.";
        $msg_type = "success";
    }
}

// 2. Historique des réclamations du promoteur
$stmt_claims = $pdo->prepare("SELECT * FROM claims WHERE user_id = ? ORDER BY created_at DESC");
$stmt_claims->execute([$user_id]);
$claims = $stmt_claims->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Assistance Promoteur</span>
        <h1>Support & Réclamations</h1>
        <p>Contactez l'administrateur concernant un virement, un événement ou un problème technique.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 360px 1fr; gap: 2rem; align-items: start;">
    <!-- 1. Formulaire -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-pen"></i> Nouvelle Demande</div>
        <form method="POST">
            <input type="hidden" name="send_claim" value="1">

            <div class="form-group">
                <label for="sujet">Objet de la réclamation *</label>
                <input type="text" id="sujet" name="sujet" required placeholder="Ex: Question sur un retrait, Commission...">
            </div>

            <div class="form-group">
                <label for="message">Message détaillé *</label>
                <textarea id="message" name="message" rows="4" required placeholder="Décrivez votre préoccupation..."></textarea>
            </div>

            <button type="submit" class="btn-submit" style="width: 100%;">
                <i class="fa-solid fa-paper-plane"></i> Envoyer au support
            </button>
        </form>
    </div>

    <!-- 2. Historique des réclamations -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Mes Demandes de Support (<?php echo count($claims); ?>)</div>

        <?php if (count($claims) > 0): ?>
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <?php foreach ($claims as $cl): ?>
                    <div style="background: #f8faf9; border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <h3 style="margin: 0; color: var(--navy); font-size: 1.05rem;"><?php echo htmlspecialchars($cl['sujet']); ?></h3>
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
                        </div>

                        <p style="background: #ffffff; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--line); font-size: 0.9rem; margin: 0 0 0.75rem; color: var(--ink);">
                            <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                        </p>

                        <?php if (!empty($cl['reponse_admin'])): ?>
                            <div style="background: #eff6ff; border-left: 4px solid var(--primary); padding: 0.75rem; border-radius: 0 6px 6px 0;">
                                <strong style="color: var(--primary); font-size: 0.85rem; display: block; margin-bottom: 0.25rem;">
                                    <i class="fa-solid fa-reply"></i> Réponse de l'Administration :
                                </strong>
                                <p style="margin: 0; font-size: 0.88rem; color: var(--ink);">
                                    <?php echo nl2br(htmlspecialchars($cl['reponse_admin'])); ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <small style="color: var(--muted); font-style: italic;">
                                <i class="fa-solid fa-hourglass-half"></i> En attente de réponse...
                            </small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; color: var(--muted); padding: 3rem 1rem;">
                Vous n'avez envoyé aucune réclamation.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
