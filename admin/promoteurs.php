<?php
// ==============================================================================
// GESTION DES PROMOTEURS ACTIFS (admin/promoteurs.php)
// Liste des promoteurs, solde, événements organisés et suspension/réactivation
// ==============================================================================

$admin_page_title = "Gestion des Promoteurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Actions d'activation / suspension de promoteur
if (isset($_GET['id']) && isset($_GET['action'])) {
    $promoter_id = (int)$_GET['id'];
    $action      = $_GET['action'];

    if ($action === 'suspend') {
        $stmt = $pdo->prepare("UPDATE promoters SET statut = 'suspendu' WHERE id = ?");
        $stmt->execute([$promoter_id]);
        $message = "Le compte du promoteur a été suspendu.";
        $msg_type = "error";
    } elseif ($action === 'activate') {
        $stmt = $pdo->prepare("UPDATE promoters SET statut = 'approuve' WHERE id = ?");
        $stmt->execute([$promoter_id]);
        $message = "Le promoteur a été réactivé avec succès.";
        $msg_type = "success";
    }
}

// 2. Récupération des promoteurs avec calcul de leurs événements et demandes d'infos
$sql = "
    SELECT p.*, u.nom AS user_nom, u.email AS user_email, u.telephone AS user_tel,
           (SELECT COUNT(*) FROM events e WHERE e.user_id = p.user_id) AS total_events,
           (SELECT COUNT(*) FROM information_requests ir WHERE ir.promoter_id = p.user_id) AS total_info_reqs
    FROM promoters p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.id DESC
";
$stmt = $pdo->query($sql);
$promoters = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Partenaires & Organisateurs</span>
        <h1>Gestion des Promoteurs</h1>
        <p>Suivez les promoteurs enregistrés, leur solde financier et leur statut.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="content-section">
    <div class="section-title">
        <span>Liste des Promoteurs (<?php echo count($promoters); ?>)</span>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Promoteur / Structure</th>
                    <th>Contact</th>
                    <th>Événements</th>
                    <th>Demandes d'Infos</th>
                    <th>Solde Disponible</th>
                    <th>Statut</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($promoters) > 0): ?>
                    <?php foreach ($promoters as $p): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($p['nom_commercial']); ?></strong><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($p['user_nom']); ?></small>
                            </td>

                            <td>
                                <small><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($p['email_contact'] ?: $p['user_email']); ?></small><br>
                                <small><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($p['telephone_contact'] ?: $p['user_tel']); ?></small>
                            </td>

                            <td>
                                <strong><?php echo (int)$p['total_events']; ?></strong> événement(s)
                            </td>

                            <td>
                                <strong><?php echo (int)$p['total_info_reqs']; ?></strong> demande(s)
                            </td>

                            <td>
                                <strong style="color: var(--primary); font-size: 1.05rem;">
                                    <?php echo number_format($p['solde'], 0, ',', ' '); ?> F
                                </strong>
                            </td>

                            <td>
                                <?php if ($p['statut'] === 'approuve'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Actif</span>
                                <?php elseif ($p['statut'] === 'suspendu'): ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-ban"></i> Suspendu</span>
                                <?php elseif ($p['statut'] === 'refuse'): ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> Refusé</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: bold;"><i class="fa-solid fa-clock"></i> En attente</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right;">
                                <?php if ($p['statut'] === 'approuve'): ?>
                                    <a href="?id=<?php echo $p['id']; ?>&action=suspend" class="btn-submit" style="display: inline-block; width: auto; padding: 0.35rem 0.75rem; background: #ef4444; text-decoration: none; font-size: 0.8rem;" onclick="return confirm('Voulez-vous vraiment suspendre ce promoteur ?')">
                                        <i class="fa-solid fa-ban"></i> Suspendre
                                    </a>
                                <?php elseif ($p['statut'] === 'suspendu'): ?>
                                    <a href="?id=<?php echo $p['id']; ?>&action=activate" class="btn-submit" style="display: inline-block; width: auto; padding: 0.35rem 0.75rem; background: #10b981; text-decoration: none; font-size: 0.8rem;" onclick="return confirm('Réactiver ce promoteur ?')">
                                        <i class="fa-solid fa-check"></i> Réactiver
                                    </a>
                                <?php else: ?>
                                    <a href="demandes-promoteurs.php" style="color: var(--primary); text-decoration: underline; font-size: 0.85rem;">Examiner dossier</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucun promoteur enregistré.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
