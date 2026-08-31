<?php
// ==============================================================================
// SUIVI DES ÉVÉNEMENTS DU PROMOTEUR (promoteur/mes-evenements.php)
// Liste des événements validés et des demandes en cours d'examen
// ==============================================================================

$page_title = "Mes Événements - Espace Promoteur";
include 'header.php';

// 1. Événements validés et publiés avec décompte vendus / restants
$stmt_pub = $pdo->prepare("
    SELECT e.*, 
           (SELECT COALESCE(SUM(tt.quantite_vendue), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS tickets_vendus,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_places,
           (SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.event_id = e.id AND t.statut IN ('vendu', 'utilise')) AS total_recette
    FROM events e 
    WHERE e.user_id = ? 
    ORDER BY e.date_evenement DESC
");
$stmt_pub->execute([$_SESSION['user_id']]);
$published_events = $stmt_pub->fetchAll();

// 2. Demandes de création soumises en attente ou traitées
$stmt_req = $pdo->prepare("
    SELECT * FROM event_requests 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt_req->execute([$_SESSION['user_id']]);
$event_requests = $stmt_req->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Espace Promoteur</span>
        <h1>Mes Événements</h1>
        <p>Suivez vos événements en ligne et l'état d'examen de vos demandes.</p>
    </div>
    <a href="demande-evenement.php" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.5rem; text-decoration: none;">
        <i class="fa-solid fa-plus"></i> Proposer un événement
    </a>
</div>

<!-- 1. Événements en ligne (publiés) -->
<div class="content-section" style="margin-bottom: 2rem;">
    <div class="section-title"><i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Événements Publiés sur la Plateforme (<?php echo count($published_events); ?>)</div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Événement</th>
                    <th>Date & Heure</th>
                    <th>Lieu</th>
                    <th>Billets Vendus & Restants</th>
                    <th>Recettes Brutes</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($published_events) > 0): ?>
                    <?php foreach ($published_events as $ev): ?>
                        <?php 
                        $vendus   = (int)$ev['tickets_vendus'];
                        $total    = (int)$ev['total_places'];
                        $restants = max(0, $total - $vendus);
                        $pct      = ($total > 0) ? round(($vendus / $total) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ev['nom']); ?></strong><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($ev['categorie']); ?> · Commission plateforme: <?php echo (float)$ev['commission_rate']; ?>%</small>
                            </td>
                            <td>
                                <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?><br>
                                <small style="color: var(--muted);"><i class="fa-regular fa-clock"></i> <?php echo substr($ev['heure'], 0, 5); ?></small>
                            </td>
                            <td><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ev['lieu']); ?></td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 3px;">
                                    <div>
                                        <strong style="color: #0284c7; font-size: 0.88rem;">
                                            <i class="fa-solid fa-circle-check"></i> <?php echo $vendus; ?> vendu(s)
                                        </strong>
                                    </div>
                                    <div>
                                        <?php if ($total > 0 && $restants === 0): ?>
                                            <span style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 4px; padding: 1px 6px; font-weight: 800; font-size: 0.76rem;">
                                                <i class="fa-solid fa-ban"></i> Épuisé
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; border-radius: 4px; padding: 1px 6px; font-weight: 800; font-size: 0.76rem;">
                                                <i class="fa-solid fa-ticket"></i> <?php echo $restants; ?> restant(s)
                                            </span>
                                        <?php endif; ?>
                                        <small style="color: var(--muted); font-size: 0.76rem; margin-left: 2px;">/ <?php echo $total; ?> total</small>
                                    </div>
                                    <?php if ($total > 0): ?>
                                        <div style="background: #e2e8f0; height: 5px; border-radius: 3px; width: 90px; margin-top: 2px; overflow: hidden;">
                                            <div style="background: var(--primary); height: 100%; width: <?php echo min(100, $pct); ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color: var(--primary);"><?php echo number_format($ev['total_recette'], 0, ',', ' '); ?> F</strong>
                            </td>
                            <td>
                                <?php if ($ev['statut'] === 'actif'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle"></i> En cours</span>
                                <?php elseif ($ev['statut'] === 'termine'): ?>
                                    <span style="color: #64748b; font-weight: bold;"><i class="fa-solid fa-flag-checkered"></i> Terminé</span>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-weight: bold;"><?php echo ucfirst($ev['statut']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Vous n'avez aucun événement actif pour le moment.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2. Demandes soumises à l'admin -->
<div class="content-section">
    <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Historique de mes demandes soumises (<?php echo count($event_requests); ?>)</div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Événement Proposé</th>
                    <th>Date & Lieu prévus</th>
                    <th>Tarifs proposés</th>
                    <th>Statut de la demande</th>
                    <th>Remarque Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($event_requests) > 0): ?>
                    <?php foreach ($event_requests as $req): ?>
                        <?php $tickets = json_decode($req['ticket_types_data'] ?? '[]', true); ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($req['nom']); ?></strong><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($req['categorie']); ?> · Commission plateforme: <strong>5.0%</strong></small>
                            </td>
                            <td>
                                <?php echo date('d/m/Y', strtotime($req['date_evenement'])); ?> à <?php echo substr($req['heure'], 0, 5); ?><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($req['lieu']); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($tickets)): ?>
                                    <?php foreach ($tickets as $t): ?>
                                        <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 0.78rem; margin-right: 4px; margin-bottom: 2px;">
                                            <?php echo htmlspecialchars($t['nom']); ?> : <?php echo number_format($t['prix'], 0, ',', ' '); ?> F (<?php echo $t['quantite']; ?> places)
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['statut'] === 'approuve'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Validée</span>
                                <?php elseif ($req['statut'] === 'refuse'): ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> Refusée</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: bold;"><i class="fa-solid fa-clock"></i> En examen</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($req['commentaire_admin'])): ?>
                                    <small style="color: var(--ink);"><?php echo htmlspecialchars($req['commentaire_admin']); ?></small>
                                <?php else: ?>
                                    <small style="color: var(--muted);">En attente d'examen</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucune proposition d'événement envoyée.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
