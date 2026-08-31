<?php
// ==============================================================================
// GESTION DES RÉCLAMATIONS (admin/reclamations.php)
// Consultation, réponse et résolution des réclamations clients et promoteurs
// ==============================================================================

$admin_page_title = "Gestion des Réclamations - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Répondre et changer le statut d'une réclamation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_claim'])) {
    $claim_id      = (int)$_POST['claim_id'];
    $reponse_admin = trim($_POST['reponse_admin'] ?? '');
    $nouveau_statut= $_POST['nouveau_statut'] ?? 'resolue';

    $statuts_valides = ['en_attente', 'en_cours', 'resolue', 'fermee'];

    if (in_array($nouveau_statut, $statuts_valides, true)) {
        $stmt = $pdo->prepare("UPDATE claims SET reponse_admin = ?, statut = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$reponse_admin, $nouveau_statut, $claim_id]);

        $message = "La réponse a été enregistrée et le statut de la réclamation a été mis à jour.";
        $msg_type = "success";
    }
}

// 2. Filtres
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'en_cours', 'resolue', 'fermee', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "
    SELECT c.*, u.nom AS auteur_nom, u.email AS auteur_email, u.role AS auteur_role, o.numero_commande
    FROM claims c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN orders o ON c.order_id = o.id
";

if ($tab !== 'tous') {
    $sql .= " WHERE c.statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY c.created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY c.created_at DESC");
}
$claims_list = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Support & Médiation</span>
        <h1>Gestion des Réclamations</h1>
        <p>Traitez les demandes d'assistance des clients et des promoteurs.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Onglets -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--line); padding-bottom: 0.5rem; flex-wrap: wrap;">
    <a href="?tab=en_attente" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.88rem; <?php echo ($tab === 'en_attente') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-clock"></i> En Attente
    </a>
    <a href="?tab=en_cours" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.88rem; <?php echo ($tab === 'en_cours') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-spinner"></i> En Cours
    </a>
    <a href="?tab=resolue" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.88rem; <?php echo ($tab === 'resolue') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-check"></i> Résolues
    </a>
    <a href="?tab=fermee" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.88rem; <?php echo ($tab === 'fermee') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-lock"></i> Fermées
    </a>
    <a href="?tab=tous" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.88rem; <?php echo ($tab === 'tous') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        Toutes (<?php echo count($claims_list); ?>)
    </a>
</div>

<!-- Liste des réclamations -->
<div class="content-section">
    <div class="section-title">Réclamations (<?php echo count($claims_list); ?>)</div>

    <?php if (count($claims_list) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($claims_list as $cl): ?>
                <div style="background: var(--paper); border: 1px solid var(--line); border-radius: 10px; padding: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div>
                            <span style="font-size: 0.8rem; text-transform: uppercase; font-weight: bold; color: <?php echo ($cl['auteur_role'] === 'promoteur') ? '#10b981' : '#0284c7'; ?>;">
                                <i class="fa-solid <?php echo ($cl['auteur_role'] === 'promoteur') ? 'fa-bullhorn' : 'fa-user'; ?>"></i>
                                <?php echo ucfirst($cl['auteur_role']); ?> : <?php echo htmlspecialchars($cl['auteur_nom']); ?> (<?php echo htmlspecialchars($cl['auteur_email']); ?>)
                            </span>
                            <h2 style="margin: 0.3rem 0; color: var(--navy); font-size: 1.25rem;">
                                <?php echo htmlspecialchars($cl['sujet']); ?>
                            </h2>
                            <div style="color: var(--muted); font-size: 0.85rem;">
                                Déposée le <?php echo date('d/m/Y à H:i', strtotime($cl['created_at'])); ?>
                                <?php if ($cl['numero_commande']): ?>
                                    · Commande <strong>#<?php echo htmlspecialchars($cl['numero_commande']); ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <?php if ($cl['statut'] === 'resolue'): ?>
                                <span style="background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 14px; font-weight: bold; font-size: 0.8rem;"><i class="fa-solid fa-check"></i> Résolue</span>
                            <?php elseif ($cl['statut'] === 'en_cours'): ?>
                                <span style="background: #e0f2fe; color: #0369a1; padding: 4px 12px; border-radius: 14px; font-weight: bold; font-size: 0.8rem;"><i class="fa-solid fa-spinner"></i> En cours</span>
                            <?php elseif ($cl['statut'] === 'fermee'): ?>
                                <span style="background: #f1f5f9; color: #475569; padding: 4px 12px; border-radius: 14px; font-weight: bold; font-size: 0.8rem;">Fermée</span>
                            <?php else: ?>
                                <span style="background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 14px; font-weight: bold; font-size: 0.8rem;"><i class="fa-solid fa-clock"></i> En attente</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="background: #f8faf9; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <strong style="font-size: 0.85rem; color: var(--navy);">Message de l'utilisateur :</strong>
                        <p style="margin: 0.3rem 0 0; font-size: 0.92rem; color: var(--ink); line-height: 1.4;">
                            <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                        </p>
                    </div>

                    <!-- Formulaire de réponse et changement de statut -->
                    <form method="POST" style="border-top: 1px solid var(--line); padding-top: 1rem;">
                        <input type="hidden" name="claim_id" value="<?php echo $cl['id']; ?>">
                        <input type="hidden" name="reply_claim" value="1">

                        <div class="form-group" style="margin-bottom: 0.75rem;">
                            <label for="rep_<?php echo $cl['id']; ?>" style="font-size: 0.85rem;"><i class="fa-solid fa-reply"></i> Votre réponse à l'utilisateur</label>
                            <textarea id="rep_<?php echo $cl['id']; ?>" name="reponse_admin" rows="2" placeholder="Saisissez la réponse ou les instructions apportées au problème..."><?php echo htmlspecialchars($cl['reponse_admin'] ?? ''); ?></textarea>
                        </div>

                        <div style="display: flex; gap: 1rem; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <label style="font-size: 0.85rem; font-weight: bold;">Statut :</label>
                                <select name="nouveau_statut" style="padding: 0.45rem 0.8rem; border-radius: 6px; border: 1px solid var(--line); font-size: 0.88rem; font-weight: bold;">
                                    <option value="en_cours" <?php echo ($cl['statut'] === 'en_cours') ? 'selected' : ''; ?>>En cours</option>
                                    <option value="resolue" <?php echo ($cl['statut'] === 'resolue') ? 'selected' : ''; ?>>Résolue</option>
                                    <option value="fermee" <?php echo ($cl['statut'] === 'fermee') ? 'selected' : ''; ?>>Fermée</option>
                                    <option value="en_attente" <?php echo ($cl['statut'] === 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                                </select>
                            </div>

                            <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.55rem 1.25rem; font-size: 0.9rem;">
                                <i class="fa-solid fa-paper-plane"></i> Enregistrer & Répondre
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            Aucune réclamation dans cette catégorie.
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
