<?php
include 'header.php';

$sql = "SELECT t.*, e.nom AS event_name, u.nom AS client_name
        FROM tickets t
        JOIN events e ON e.id = t.event_id
        LEFT JOIN users u ON u.id = t.user_id
        ORDER BY CASE WHEN t.statut = 'utilisé' THEN 0 ELSE 1 END, t.date_utilisation DESC, t.id DESC";
$stmt = $pdo->query($sql);
$tickets = $stmt->fetchAll();
$verified_count = count(array_filter($tickets, static fn ($ticket) => $ticket['statut'] === 'utilisé'));
?>
<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Contrôle d'accès</span>
        <h1>Suivi des vérifications</h1>
        <p>Consultez les tickets déjà contrôlés à l'entrée des événements.</p>
    </div>
    <div class="verification-summary"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><strong><?php echo $verified_count; ?></strong><span>vérifié(s)</span></div>
</div>

<div class="content-section events-section">
    <div class="section-title">Historique des tickets</div>
    <div class="table-wrapper">
        <table class="events-table verification-table">
            <thead>
                <tr><th>Code</th><th>Événement</th><th>Client</th><th>Type</th><th>Statut</th><th>Date de vérification</th></tr>
            </thead>
            <tbody>
            <?php if ($tickets): foreach ($tickets as $ticket): ?>
                <tr>
                    <td class="event-name verification-code"><?php echo htmlspecialchars($ticket['code_unique']); ?></td>
                    <td><?php echo htmlspecialchars($ticket['event_name']); ?></td>
                    <td><?php echo htmlspecialchars($ticket['client_name'] ?? 'Client'); ?></td>
                    <td><?php echo htmlspecialchars($ticket['type_ticket']); ?></td>
                    <td>
                        <?php $is_verified = $ticket['statut'] === 'utilisé'; ?>
                        <span class="event-status <?php echo $is_verified ? 'status-active' : 'status-pending'; ?>">
                            <i class="fa-solid <?php echo $is_verified ? 'fa-circle-check' : 'fa-clock'; ?>" aria-hidden="true"></i>
                            <?php echo $is_verified ? 'Vérifié' : 'En attente'; ?>
                        </span>
                    </td>
                    <td><?php echo $is_verified && !empty($ticket['date_utilisation']) ? htmlspecialchars($ticket['date_utilisation']) : 'Pas encore vérifié'; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="empty-events"><i class="fa-solid fa-qrcode" aria-hidden="true"></i>Aucun ticket à vérifier.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'footer.php'; ?>
