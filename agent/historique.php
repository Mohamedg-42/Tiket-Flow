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

<main class="container" style="margin: clamp(1rem, 3vw, 2rem) auto; padding: 0 var(--container-padding);">
    <div class="page-header" style="margin-bottom: var(--spacing-lg);">
        <div class="page-heading">
            <span class="page-kicker" style="font-size: var(--font-size-xs);"><i class="fa-solid fa-clock-rotate-left"></i> Traçabilité</span>
            <h1 style="font-size: var(--font-size-3xl); margin-bottom: var(--spacing-sm);">Historique de mes Validations</h1>
            <p style="font-size: var(--font-size-sm); color: var(--muted);">Retrouvez la liste des billets que vous avez scannés à l'entrée.</p>
        </div>
        <a href="verification.php" class="btn-submit" style="width: auto; text-decoration: none; padding: clamp(0.65rem, 1.5vw, 0.85rem) clamp(1rem, 2vw, 1.25rem); font-size: var(--font-size-sm); display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-camera"></i> Retour au Scanner
        </a>
    </div>

    <div class="content-section" style="width: 100%; box-sizing: border-box;">
        <div class="section-title" style="font-size: var(--font-size-lg);">
            <span>Billets Validés (<?php echo count($validated_tickets); ?>)</span>
        </div>

        <div class="table-responsive" style="width: 100%; max-width: 100%; box-sizing: border-box;">
            <table class="events-table" style="width: 100%; max-width: 100%; box-sizing: border-box;">
                <thead>
                    <tr>
                        <th style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">Code Ticket</th>
                        <th style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">Événement</th>
                        <th style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">Catégorie</th>
                        <th style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">Titulaire</th>
                        <th style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">Date & Heure</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($validated_tickets) > 0): ?>
                        <?php foreach ($validated_tickets as $tk): ?>
                            <tr>
                                <td style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">
                                    <strong style="font-family: monospace; font-size: clamp(0.75rem, 2vw, 1rem); color: var(--navy); word-break: break-word;">
                                        <?php echo htmlspecialchars($tk['code_unique']); ?>
                                    </strong>
                                </td>
                                <td style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">
                                    <strong style="word-break: break-word;"><?php echo htmlspecialchars($tk['event_name']); ?></strong>
                                </td>
                                <td style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">
                                    <span style="background: #e0f2fe; color: #0369a1; padding: clamp(0.25rem, 0.5vw, 0.35rem) clamp(0.5rem, 1vw, 0.65rem); border-radius: 6px; font-weight: bold; font-size: var(--font-size-xs); display: inline-block; word-break: break-word;">
                                        <?php echo htmlspecialchars($tk['type_ticket']); ?>
                                    </span>
                                </td>
                                <td style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">
                                    <strong style="word-break: break-word; display: block;"><?php echo htmlspecialchars($tk['client_nom']); ?></strong>
                                    <small style="color: var(--muted); display: block; word-break: break-word; font-size: var(--font-size-xs);"><?php echo htmlspecialchars($tk['client_email']); ?></small>
                                </td>
                                <td style="font-size: var(--font-size-xs); padding: clamp(0.5rem, 1.5vw, 0.8rem);">
                                    <span style="color: #10b981; font-weight: bold; display: flex; align-items: center; gap: 0.3rem; word-break: break-word;">
                                        <i class="fa-solid fa-check"></i> <?php echo date('d/m/Y à H:i', strtotime($tk['date_utilisation'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--muted); padding: clamp(2rem, 5vw, 3rem) var(--spacing-md); font-size: var(--font-size-sm);">
                                <i class="fa-solid fa-inbox" style="font-size: 2.5rem; display: block; margin-bottom: var(--spacing-md); color: var(--line);"></i>
                                Vous n'avez encore validé aucun billet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
