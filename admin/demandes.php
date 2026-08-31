<?php
// ==============================================================================
// PAGE UNIQUE DES DEMANDES (admin/demandes.php)
// Navigation à bulles comme l'accueil client : Événements | Cotisations | Votes
// - Événements : examen et publication des demandes des promoteurs
// - Cotisations : approbation des campagnes proposées par les promoteurs
// - Votes : classement des événements par votes et likes reçus
// ==============================================================================

$admin_page_title = "Demandes - Administration";
include 'header.php';

$message = "";
$msg_type = "";

$tab = $_GET['tab'] ?? 'evenements';
if (!in_array($tab, ['evenements', 'cotisations', 'votes'], true)) {
    $tab = 'evenements';
}

// ============ TRAITEMENT DES ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----- Événements : approuver / refuser -----
    if ($action === 'approuver_evenement' || $action === 'refuser_evenement') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM event_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req || $req['statut'] !== 'en_attente') {
            $message = "Cette demande n'existe pas ou a déjà été traitée.";
            $msg_type = "error";
        } elseif ($action === 'approuver_evenement') {
            $commission_rate = (float)($_POST['commission_rate'] ?? 5.00);
            if ($commission_rate < 0) $commission_rate = 5.00;

            try {
                $pdo->beginTransaction();

                $stmt_ev = $pdo->prepare("INSERT INTO events (user_id, nom, description, image, categorie, date_evenement, heure, lieu, commission_rate, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')");
                $stmt_ev->execute([$req['user_id'], $req['nom'], $req['description'], $req['image'], $req['categorie'], $req['date_evenement'], $req['heure'], $req['lieu'], $commission_rate]);
                $new_event_id = (int)$pdo->lastInsertId();

                $ticket_types = json_decode($req['ticket_types_data'] ?? '[]', true);
                if (!empty($ticket_types) && is_array($ticket_types)) {
                    $stmt_tt = $pdo->prepare("INSERT INTO ticket_types (event_id, nom, prix, quantite, quantite_vendue) VALUES (?, ?, ?, ?, 0)");
                    foreach ($ticket_types as $tt) {
                        $stmt_tt->execute([$new_event_id, $tt['nom'], (float)$tt['prix'], (int)$tt['quantite']]);
                    }
                }

                $pdo->prepare("UPDATE event_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?")->execute([$request_id]);
                $pdo->commit();

                $message = "L'événement « " . htmlspecialchars($req['nom']) . " » a été approuvé et publié (commission : " . $commission_rate . "%).";
                $msg_type = "success";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur lors de la validation : " . $e->getMessage();
                $msg_type = "error";
            }
        } else {
            $commentaire = trim($_POST['commentaire_admin'] ?? '') ?: 'Aucun motif fourni';
            $pdo->prepare("UPDATE event_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?")->execute([$commentaire, $request_id]);
            $message = "La demande d'événement a été refusée. Le promoteur verra le motif dans son espace.";
            $msg_type = "success";
        }
    // ----- Cotisations : approuver / refuser une campagne -----
    } elseif ($action === 'approuver_campagne' || $action === 'refuser_campagne') {
        $campagne_id = (int)($_POST['campagne_id'] ?? 0);
        $stmt_c = $pdo->prepare("SELECT titre FROM cotisation_campagnes WHERE id = ? AND statut = 'en_attente'");
        $stmt_c->execute([$campagne_id]);
        $camp = $stmt_c->fetch();

        if (!$camp) {
            $message = "Cette campagne n'existe pas ou a déjà été traitée.";
            $msg_type = "error";
        } elseif ($action === 'approuver_campagne') {
            $pdo->prepare("UPDATE cotisation_campagnes SET statut = 'active', commentaire_admin = NULL, reviewed_at = NOW() WHERE id = ?")->execute([$campagne_id]);
            $message = "La campagne « " . htmlspecialchars($camp['titre']) . " » est approuvée : elle est désormais visible dans l'onglet Cotisations du site.";
            $msg_type = "success";
        } else {
            $commentaire = trim($_POST['commentaire_admin'] ?? '') ?: 'Aucun motif fourni';
            $pdo->prepare("UPDATE cotisation_campagnes SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?")->execute([$commentaire, $campagne_id]);
            $message = "La campagne « " . htmlspecialchars($camp['titre']) . " » a été refusée. Le promoteur verra le motif dans son espace.";
            $msg_type = "success";
        }
    }
}

// ============ COMPTEURS POUR LES BULLES ============
$nb_events_pending = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
try {
    $nb_campagnes_pending = (int)$pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
} catch (PDOException $e) {
    $nb_campagnes_pending = 0; // table pas encore migrée
}

// ============ DONNÉES SELON LA BULLE ACTIVE ============
$event_requests = [];
if ($tab === 'evenements') {
    $event_requests = $pdo->query("
        SELECT er.*, u.nom AS promoteur_nom, u.email AS promoteur_email
        FROM event_requests er LEFT JOIN users u ON u.id = er.user_id
        ORDER BY er.statut = 'en_attente' DESC, er.created_at DESC
    ")->fetchAll();
}

$campagnes = [];
if ($tab === 'cotisations') {
    try {
        $campagnes = $pdo->query("
            SELECT c.*, u.nom AS createur_nom,
                   COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                              WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte
            FROM cotisation_campagnes c LEFT JOIN users u ON u.id = c.user_id
            ORDER BY c.statut = 'en_attente' DESC, c.created_at DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        $campagnes = [];
    }
}

$classement_votes = [];
$derniers_votes = [];
if ($tab === 'votes') {
    $classement_votes = $pdo->query("
        SELECT * FROM (
            SELECT e.id, e.nom, e.categorie, u.nom AS promoteur_nom,
                   (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes,
                   (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) AS nb_likes
            FROM events e LEFT JOIN users u ON u.id = e.user_id
        ) t
        WHERE t.nb_votes > 0 OR t.nb_likes > 0
        ORDER BY t.nb_votes DESC, t.nb_likes DESC
    ")->fetchAll();

    $derniers_votes = $pdo->query("
        SELECT v.created_at, e.nom AS event_nom, COALESCE(u.nom, 'Visiteur') AS votant
        FROM event_votes v
        JOIN events e ON e.id = v.event_id
        LEFT JOIN users u ON u.id = v.user_id
        ORDER BY v.created_at DESC LIMIT 15
    ")->fetchAll();
}

// Libellés / couleurs des statuts
function badge_campagne_statut($statut) {
    switch ($statut) {
        case 'en_attente': return ['En attente de validation', '#fef3c7', '#92400e'];
        case 'active':     return ['Active', '#dcfce7', '#166534'];
        case 'terminee':   return ['Terminée', '#e2e8f0', '#475569'];
        case 'annulee':    return ['Refusée / Annulée', '#fee2e2', '#b91c1c'];
    }
    return [$statut, '#e2e8f0', '#475569'];
}
function badge_demande_statut($statut) {
    switch ($statut) {
        case 'en_attente': return ['En attente', '#fef3c7', '#92400e'];
        case 'approuve':   return ['Approuvée', '#dcfce7', '#166534'];
        case 'refuse':     return ['Refusée', '#fee2e2', '#b91c1c'];
    }
    return [$statut, '#e2e8f0', '#475569'];
}
?>
<style>
    .demandes-chips { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .demandes-chip {
        display: inline-flex; align-items: center; gap: 0.45rem;
        padding: 0.5rem 1.1rem; background: #ffffff; border: 1px solid var(--line);
        border-radius: 999px; color: var(--ink); font-size: 0.85rem; font-weight: 700;
        text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.2s ease;
    }
    .demandes-chip i { color: var(--primary); }
    .demandes-chip:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-2px); }
    .demandes-chip.active { background: var(--primary); border-color: var(--primary); color: #ffffff; }
    .demandes-chip.active i { color: #ffffff; }
    .chip-count { background: #f1f5f9; border-radius: 999px; padding: 1px 8px; font-size: 0.75rem; color: var(--ink); }
    .demandes-chip.active .chip-count { background: rgba(255,255,255,0.25); color: #ffffff; }
</style>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Administration</span>
        <h1><i class="fa-solid fa-inbox"></i> Centre des Demandes</h1>
        <p>Examinez les événements proposés, validez les campagnes de cotisation et suivez l'engagement des visiteurs.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="margin-bottom: 1.5rem;">
        <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i> <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Bulles de navigation -->
<div class="demandes-chips">
    <a href="?tab=evenements" class="demandes-chip <?php echo $tab === 'evenements' ? 'active' : ''; ?>">
        <i class="fa-solid fa-calendar-plus"></i> Événements
        <?php if ($nb_events_pending > 0): ?><span class="chip-count"><?php echo $nb_events_pending; ?></span><?php endif; ?>
    </a>
    <a href="?tab=cotisations" class="demandes-chip <?php echo $tab === 'cotisations' ? 'active' : ''; ?>">
        <i class="fa-solid fa-hand-holding-heart"></i> Cotisations
        <?php if ($nb_campagnes_pending > 0): ?><span class="chip-count"><?php echo $nb_campagnes_pending; ?></span><?php endif; ?>
    </a>
    <a href="?tab=votes" class="demandes-chip <?php echo $tab === 'votes' ? 'active' : ''; ?>">
        <i class="fa-solid fa-chart-simple"></i> Votes & Likes
    </a>
</div>
<?php if ($tab === 'evenements'): ?>
<div class="content-section">
    <?php if (empty($event_requests)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Aucune demande d'événement.
        </div>
    <?php else: ?>
        <?php foreach ($event_requests as $r): list($s_label, $s_bg, $s_fg) = badge_demande_statut($r['statut']); ?>
            <div class="card" style="padding: 1.5rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--navy); font-size: 1.1rem;"><?php echo htmlspecialchars($r['nom']); ?></strong>
                        <small style="color: var(--muted); display: block; margin-top: 2px;">
                            par <strong><?php echo htmlspecialchars($r['promoteur_nom'] ?? 'Promoteur supprimé'); ?></strong>
                            · <?php echo htmlspecialchars($r['categorie']); ?> · <?php echo date('d/m/Y H\h', strtotime($r['date_evenement'] . ' ' . $r['heure'])); ?>
                            · <?php echo htmlspecialchars($r['lieu']); ?>
                        </small>
                    </div>
                    <span style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 4px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: bold; white-space: nowrap;">
                        <?php echo $s_label; ?>
                    </span>
                </div>

                <p style="color: var(--muted); font-size: 0.9rem; margin: 0.75rem 0;"><?php echo nl2br(htmlspecialchars(mb_strimwidth($r['description'], 0, 220, '...'))); ?></p>

                <div style="display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: 0.85rem;">
                    <span><i class="fa-solid fa-building-user"></i> <?php echo $r['type_personne'] === 'morale' ? 'Personne morale' : 'Personne physique'; ?></span>
                    <?php if ($r['document_justificatif']): ?><a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_justificatif']); ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> Justificatif</a><?php endif; ?>
                    <?php if ($r['document_autorisation']): ?><a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_autorisation']); ?>" target="_blank"><i class="fa-solid fa-file-shield"></i> Autorisation</a><?php endif; ?>
                </div>
                <?php
                $ticket_types = json_decode($r['ticket_types_data'] ?? '[]', true);
                if (!empty($ticket_types) && is_array($ticket_types)):
                ?>
                    <div style="margin-top: 0.75rem; font-size: 0.85rem;">
                        <?php foreach ($ticket_types as $t): ?>
                            <div><strong><?php echo htmlspecialchars($t['nom']); ?> :</strong> <?php echo number_format((float)$t['prix'], 0, ',', ' '); ?> FCFA (<?php echo (int)$t['quantite']; ?> places)</div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($r['statut'] === 'en_attente'): ?>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; border-top: 1px solid var(--line); padding-top: 1rem; margin-top: 1rem;">
                        <form method="POST" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
                            <input type="hidden" name="action" value="approuver_evenement">
                            <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                            <div>
                                <label style="font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 4px;">Taux de commission (%)</label>
                                <input type="number" step="0.5" name="commission_rate" value="5.0" min="0" max="50" style="width: 110px; padding: 0.6rem; border: 1px solid var(--line); border-radius: 6px;">
                            </div>
                            <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1.25rem; background: #10b981;" onclick="return confirm('Valider cet événement et le publier immédiatement sur le site ?')">
                                <i class="fa-solid fa-check"></i> Valider & Publier
                            </button>
                        </form>
                        <form method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; margin-left: auto;">
                            <input type="hidden" name="action" value="refuser_evenement">
                            <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                            <input type="text" name="commentaire_admin" placeholder="Motif du refus..." style="padding: 0.6rem; border: 1px solid var(--line); border-radius: 6px; min-width: 240px;">
                            <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1rem; background: #ef4444;" onclick="return confirm('Refuser cette demande ?')">
                                <i class="fa-solid fa-xmark"></i> Refuser
                            </button>
                        </form>
                    </div>
                <?php elseif (!empty($r['commentaire_admin'])): ?>
                    <div style="margin-top: 0.75rem; font-size: 0.85rem; color: #b91c1c; background: #fef2f2; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-comment-dots"></i> Motif : <?php echo htmlspecialchars($r['commentaire_admin']); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php elseif ($tab === 'cotisations'): ?>
<div class="content-section">
    <p style="color: var(--muted); font-size: 0.9rem; margin: 0 0 1rem;">
        Les campagnes créées par les promoteurs attendent votre validation avant d'apparaître sur le site client.
    </p>
    <?php if (empty($campagnes)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Aucune campagne de cotisation.
        </div>
    <?php else: ?>
        <?php foreach ($campagnes as $c): list($s_label, $s_bg, $s_fg) = badge_campagne_statut($c['statut']); ?>
            <div class="card" style="padding: 1.5rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--navy); font-size: 1.1rem;"><?php echo htmlspecialchars($c['titre']); ?></strong>
                        <small style="color: var(--muted); display: block; margin-top: 2px;">
                            par <strong><?php echo $c['user_id'] ? htmlspecialchars($c['createur_nom'] ?? 'Promoteur supprimé') : 'Administration'; ?></strong>
                            · Objectif : <?php echo number_format((float)$c['montant_objectif'], 0, ',', ' '); ?> FCFA
                            · Déjà collecté : <?php echo number_format((float)$c['montant_collecte'], 0, ',', ' '); ?> FCFA
                            <?php if ($c['date_limite']): ?>· Jusqu'au <?php echo date('d/m/Y', strtotime($c['date_limite'])); ?><?php endif; ?>
                        </small>
                    </div>
                    <span style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 4px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: bold; white-space: nowrap;">
                        <?php echo $s_label; ?>
                    </span>
                </div>

                <?php if (!empty($c['description'])): ?>
                    <p style="color: var(--muted); font-size: 0.9rem; margin: 0.75rem 0;"><?php echo nl2br(htmlspecialchars(mb_strimwidth($c['description'], 0, 220, '...'))); ?></p>
                <?php endif; ?>
                <?php if ($c['statut'] === 'en_attente'): ?>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; border-top: 1px solid var(--line); padding-top: 1rem; margin-top: 1rem;">
                        <form method="POST">
                            <input type="hidden" name="action" value="approuver_campagne">
                            <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1.25rem; background: #10b981;" onclick="return confirm('Approuver cette campagne ? Elle sera immédiatement visible sur le site client.')">
                                <i class="fa-solid fa-check"></i> Approuver & Publier
                            </button>
                        </form>
                        <form method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; margin-left: auto;">
                            <input type="hidden" name="action" value="refuser_campagne">
                            <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                            <input type="text" name="commentaire_admin" placeholder="Motif du refus..." style="padding: 0.6rem; border: 1px solid var(--line); border-radius: 6px; min-width: 240px;">
                            <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1rem; background: #ef4444;" onclick="return confirm('Refuser cette campagne ?')">
                                <i class="fa-solid fa-xmark"></i> Refuser
                            </button>
                        </form>
                    </div>
                <?php elseif (!empty($c['commentaire_admin'])): ?>
                    <div style="margin-top: 0.75rem; font-size: 0.85rem; color: #b91c1c; background: #fef2f2; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-comment-dots"></i> Motif : <?php echo htmlspecialchars($c['commentaire_admin']); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php else: /* tab = votes */ ?>
<div class="content-section">
    <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);"><i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Classement par engagement</h2>
    <?php if (empty($classement_votes)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-chart-simple" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Aucun vote ou like enregistré pour le moment.
        </div>
    <?php else: ?>
        <div class="card" style="padding: 0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <thead>
                    <tr style="background: #f8fafc; text-align: left;">
                        <th style="padding: 0.75rem 1rem;">#</th>
                        <th style="padding: 0.75rem 1rem;">Événement</th>
                        <th style="padding: 0.75rem 1rem;">Promoteur</th>
                        <th style="padding: 0.75rem 1rem; text-align: center;"><i class="fa-solid fa-up-long" style="color: var(--primary);"></i> Votes</th>
                        <th style="padding: 0.75rem 1rem; text-align: center;"><i class="fa-solid fa-heart" style="color: #ef4444;"></i> Likes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classement_votes as $i => $ev): ?>
                        <tr style="border-top: 1px solid var(--line);">
                            <td style="padding: 0.75rem 1rem; font-weight: bold;"><?php echo $i + 1; ?></td>
                            <td style="padding: 0.75rem 1rem;">
                                <strong><?php echo htmlspecialchars($ev['nom']); ?></strong>
                                <small style="color: var(--muted); display: block;"><?php echo htmlspecialchars($ev['categorie']); ?></small>
                            </td>
                            <td style="padding: 0.75rem 1rem; color: var(--muted);"><?php echo htmlspecialchars($ev['promoteur_nom'] ?? '—'); ?></td>
                            <td style="padding: 0.75rem 1rem; text-align: center;"><strong style="color: var(--primary);"><?php echo (int)$ev['nb_votes']; ?></strong></td>
                            <td style="padding: 0.75rem 1rem; text-align: center;"><strong style="color: #ef4444;"><?php echo (int)$ev['nb_likes']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($derniers_votes)): ?>
        <h2 style="margin: 1.75rem 0 1rem; font-size: 1.15rem; color: var(--navy);"><i class="fa-solid fa-clock-rotate-left"></i> Derniers votes</h2>
        <div class="card" style="padding: 1.25rem;">
            <?php foreach ($derniers_votes as $v): ?>
                <div style="display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.88rem;">
                    <span><i class="fa-solid fa-up-long" style="color: var(--primary);"></i> <strong><?php echo htmlspecialchars($v['votant']); ?></strong> a voté pour « <?php echo htmlspecialchars($v['event_nom']); ?> »</span>
                    <small style="color: var(--muted); white-space: nowrap;"><?php echo date('d/m/Y H:i', strtotime($v['created_at'])); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
