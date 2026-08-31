<?php
// ==============================================================================
// HISTORIQUE DES VÉRIFICATIONS AGENT (agent/historique.php)
// Liste des billets scannés et validés par l'agent connecté
// ==============================================================================

$page_title = "Historique des Scans - Espace Agent";
include 'header.php';

$agent_id = (int)$_SESSION['user_id'];

// Récupération des billets validés par cet agent
$sql = "
    SELECT t.*, e.nom AS event_name, u.nom AS client_nom, u.email AS client_email
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    JOIN users u ON t.user_id = u.id
    WHERE t.validated_by = ?
    ORDER BY t.date_utilisation DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$agent_id]);
$validated_tickets = $stmt->fetchAll();
?>

<main style="max-width: 1000px; margin: 2rem auto; padding: 0 1rem;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <div class="page-heading">
            <span class="page-kicker">Traçabilité</span>
            <h1><i class="fa-solid fa-clock-rotate-left"></i> Historique de mes Validations</h1>
            <p>Retrouvez la liste des billets que vous avez scannés à l'entrée.</p>
        </div>
        <a href="verification.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.25rem;">
            <i class="fa-solid fa-camera"></i> Retour au Scanner
        </a>
    </div>

    <div class="content-section">
        <div class="section-title">
            <span>Billets Validés (<?php echo count($validated_tickets); ?>)</span>
        </div>

        <div class="table-wrapper">
            <table class="events-table">
                <thead>
                    <tr>
                        <th>Code Ticket</th>
                        <th>Événement</th>
                        <th>Catégorie</th>
                        <th>Titulaire</th>
                        <th>Date & Heure de scan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($validated_tickets) > 0): ?>
                        <?php foreach ($validated_tickets as $tk): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; font-size: 1rem; color: var(--navy);">
                                        <?php echo htmlspecialchars($tk['code_unique']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($tk['event_name']); ?></strong>
                                </td>
                                <td>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 0.8rem;">
                                        <?php echo htmlspecialchars($tk['type_ticket']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($tk['client_nom']); ?><br>
                                    <small style="color: var(--muted);"><?php echo htmlspecialchars($tk['client_email']); ?></small>
                                </td>
                                <td>
                                    <span style="color: #10b981; font-weight: bold;">
                                        <i class="fa-solid fa-check"></i> <?php echo date('d/m/Y à H:i:s', strtotime($tk['date_utilisation'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--muted); padding: 3rem;">
                                Vous n'avez encore validé aucun billet aujourd'hui.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
