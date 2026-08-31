<?php
// ==============================================================================
// GESTION DES DEMANDES DE PROMOTEURS (admin/demandes-promoteurs.php)
// Examen, validation ou refus des dossiers d'éligibilité reçus à l'inscription
// ==============================================================================

$admin_page_title = "Demandes d'éligibilité Promoteurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Traitement des actions (Approuver ou Refuser)
if (isset($_GET['id']) && isset($_GET['action'])) {
    $id     = (int)$_GET['id'];
    $action = $_GET['action'];

    // On récupère la demande
    $stmt_req = $pdo->prepare("SELECT * FROM promoter_requests WHERE id = ?");
    $stmt_req->execute([$id]);
    $req = $stmt_req->fetch();

    if ($req) {
        $user_id = (int)$req['user_id'];

        if ($action === 'approve') {
            // A. Validation de la demande
            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            // B. Activation du compte utilisateur
            $stmt_u = $pdo->prepare("UPDATE users SET role = 'promoteur', est_verifie = 1 WHERE id = ?");
            $stmt_u->execute([$user_id]);

            // C. Mise à jour du profil promoteur
            $stmt_p = $pdo->prepare("UPDATE promoters SET statut = 'approuve' WHERE user_id = ?");
            $stmt_p->execute([$user_id]);

            $message = "Le promoteur " . htmlspecialchars($req['nom_complet']) . " a été approuvé avec succès ! Son compte est maintenant actif.";
            $msg_type = "success";

        } elseif ($action === 'reject') {
            $motif = trim($_POST['motif_refus'] ?? 'Dossier incomplet ou critères non remplis');

            // A. Refus de la demande
            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$motif, $id]);

            // B. Mise à jour du statut dans promoters
            $stmt_p = $pdo->prepare("UPDATE promoters SET statut = 'refuse' WHERE user_id = ?");
            $stmt_p->execute([$user_id]);

            $message = "La demande du promoteur a été refusée.";
            $msg_type = "error";
        }
    }
}

// 2. Filtre par statut (en_attente par défaut)
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'approuve', 'refuse', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "SELECT * FROM promoter_requests";
if ($tab !== 'tous') {
    $sql .= " WHERE statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY created_at DESC");
}
$requests = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Éligibilité & Conformité</span>
        <h1>Demandes de Promoteurs</h1>
        <p>Examinez les dossiers d'éligibilité soumis lors de l'inscription.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Onglets de filtrage -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--line); padding-bottom: 0.5rem;">
    <a href="?tab=en_attente" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'en_attente') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-clock"></i> En Attente
    </a>
    <a href="?tab=approuve" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'approuve') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-check"></i> Approuvées
    </a>
    <a href="?tab=refuse" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'refuse') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-xmark"></i> Refusées
    </a>
    <a href="?tab=tous" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'tous') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        Toutes les demandes
    </a>
</div>

<!-- Tableau des candidatures -->
<div class="content-section">
    <div class="section-title">
        <span>Candidatures (<?php echo count($requests); ?>)</span>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Candidat / Structure</th>
                    <th>Activité & Expérience</th>
                    <th>Pièce d'Identité</th>
                    <th>Projets & Réseaux</th>
                    <th>Statut</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($requests) > 0): ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($r['nom_complet']); ?></strong><br>
                                <small style="color: var(--muted);"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($r['email']); ?></small><br>
                                <small style="color: var(--muted);"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($r['telephone']); ?></small>
                            </td>

                            <td>
                                <strong><?php echo htmlspecialchars($r['activite']); ?></strong><br>
                                <small style="color: var(--muted);">Exp: <?php echo htmlspecialchars($r['experience']); ?></small>
                            </td>

                            <td>
                                <?php if (!empty($r['piece_identite']) && $r['piece_identite'] !== 'default.jpg'): ?>
                                    <a href="../uploads/ids/<?php echo urlencode($r['piece_identite']); ?>" target="_blank" style="color: var(--primary); font-weight: bold; text-decoration: underline; font-size: 0.88rem;">
                                        <i class="fa-solid fa-file-lines"></i> Voir document
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.85rem;">Non fournie</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div style="max-width: 250px; font-size: 0.85rem; color: var(--ink);">
                                    <?php echo htmlspecialchars($r['description']); ?>
                                </div>
                                <?php if (!empty($r['reseaux_sociaux'])): ?>
                                    <small style="color: var(--primary); display: block; margin-top: 4px;">
                                        <i class="fa-solid fa-globe"></i> <?php echo htmlspecialchars($r['reseaux_sociaux']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($r['statut'] === 'approuve'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Approuvé</span>
                                <?php elseif ($r['statut'] === 'refuse'): ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> Refusé</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: bold;"><i class="fa-solid fa-clock"></i> En attente</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($r['statut'] === 'en_attente'): ?>
                                    <a href="?id=<?php echo $r['id']; ?>&action=approve" class="btn-submit" style="display: inline-block; width: auto; padding: 0.4rem 0.8rem; background: #10b981; text-decoration: none; margin-right: 5px; font-size: 0.82rem;" onclick="return confirm('Valider ce promoteur et activer son compte ?')">
                                        <i class="fa-solid fa-check"></i> Approuver
                                    </a>
                                    <a href="?id=<?php echo $r['id']; ?>&action=reject" class="btn-submit" style="display: inline-block; width: auto; padding: 0.4rem 0.8rem; background: #ef4444; text-decoration: none; font-size: 0.82rem;" onclick="return confirm('Refuser cette candidature ?')">
                                        <i class="fa-solid fa-xmark"></i> Refuser
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.85rem;">Traité</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucune candidature trouvée dans cette section.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
