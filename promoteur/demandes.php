<?php
// ==============================================================================
// MES DEMANDES (promoteur/demandes.php)
// Navigation à bulles comme l'accueil client : Événements | Cotisations | Votes
// Suivi des demandes de l'événement, des campagnes en attente de validation
// et de l'engagement (votes/likes) sur les événements du promoteur
// ==============================================================================

$page_title = "Mes Demandes - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

$tab = $_GET['tab'] ?? 'evenements';
if (!in_array($tab, ['evenements', 'cotisations', 'votes'], true)) {
    $tab = 'evenements';
}

// ---- Événements : demandes du promoteur ----
$mes_demandes = $pdo->prepare("
    SELECT * FROM event_requests WHERE user_id = ?
    ORDER BY statut = 'en_attente' DESC, created_at DESC
");
$mes_demandes->execute([$user_id]);
$mes_demandes = $mes_demandes->fetchAll();

// ---- Cotisations : campagnes du promoteur ----
$mes_campagnes = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        WHERE c.user_id = ?
        ORDER BY c.statut = 'en_attente' DESC, c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $mes_campagnes = $stmt->fetchAll();
} catch (PDOException $e) {
    $mes_campagnes = [];
}

// ---- Votes : engagement sur les événements du promoteur ----
$mon_classement = $pdo->prepare("
    SELECT * FROM (
        SELECT e.id, e.nom, e.categorie,
               (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes,
               (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) AS nb_likes
        FROM events e WHERE e.user_id = ?
    ) t
    ORDER BY t.nb_votes DESC, t.nb_likes DESC
");
$mon_classement->execute([$user_id]);
$mon_classement = $mon_classement->fetchAll();

// Compteurs pour les bulles
$nb_dem_events = count(array_filter($mes_demandes, fn($d) => $d['statut'] === 'en_attente'));
$nb_dem_campagnes = count(array_filter($mes_campagnes, fn($c) => $c['statut'] === 'en_attente'));

function dem_badge($statut) {
    switch ($statut) {
        case 'en_attente': return ['En attente de validation', '#fef3c7', '#92400e'];
        case 'approuve':
        case 'active':     return ['Validée', '#dcfce7', '#166534'];
        case 'refuse':     return ['Refusée', '#fee2e2', '#b91c1c'];
        case 'annulee':    return ['Refusée / Annulée', '#fee2e2', '#b91c1c'];
        case 'terminee':   return ['Terminée', '#e2e8f0', '#475569'];
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
        <span class="page-kicker">Espace Promoteur</span>
        <h1><i class="fa-solid fa-inbox"></i> Mes Demandes</h1>
        <p>Suivez vos propositions d'événements, vos campagnes de cotisation et l'engagement du public.</p>
    </div>
</div>

<!-- Bulles de navigation -->
<div class="demandes-chips">
    <a href="?tab=evenements" class="demandes-chip <?php echo $tab === 'evenements' ? 'active' : ''; ?>">
        <i class="fa-solid fa-calendar-plus"></i> Événements
        <?php if ($nb_dem_events > 0): ?><span class="chip-count"><?php echo $nb_dem_events; ?></span><?php endif; ?>
    </a>
    <a href="?tab=cotisations" class="demandes-chip <?php echo $tab === 'cotisations' ? 'active' : ''; ?>">
        <i class="fa-solid fa-hand-holding-heart"></i> Cotisations
        <?php if ($nb_dem_campagnes > 0): ?><span class="chip-count"><?php echo $nb_dem_campagnes; ?></span><?php endif; ?>
    </a>
    <a href="?tab=votes" class="demandes-chip <?php echo $tab === 'votes' ? 'active' : ''; ?>">
        <i class="fa-solid fa-chart-simple"></i> Votes & Likes
    </a>
</div>

<?php if ($tab === 'evenements'): ?>
<div class="content-section">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
        <a href="demande-evenement.php" class="btn-submit" style="width: auto; padding: 0.6rem 1.25rem; text-decoration: none;">
            <i class="fa-solid fa-plus"></i> Proposer un nouvel événement
        </a>
    </div>
    <?php if (empty($mes_demandes)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Vous n'avez pas encore proposé d'événement.
        </div>
    <?php else: ?>
        <?php foreach ($mes_demandes as $d): list($s_label, $s_bg, $s_fg) = dem_badge($d['statut']); ?>
            <div class="card" style="padding: 1.5rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--navy); font-size: 1.05rem;"><?php echo htmlspecialchars($d['nom']); ?></strong>
                        <small style="color: var(--muted); display: block; margin-top: 2px;">
                            <?php echo htmlspecialchars($d['categorie']); ?> · <?php echo date('d/m/Y H\h', strtotime($d['date_evenement'] . ' ' . $d['heure'])); ?> · <?php echo htmlspecialchars($d['lieu']); ?>
                        </small>
                    </div>
                    <span style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 4px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: bold; white-space: nowrap;">
                        <?php echo $s_label; ?>
                    </span>
                </div>
                <?php if ($d['statut'] === 'en_attente'): ?>
                    <p style="color: #92400e; font-size: 0.85rem; margin: 0.75rem 0 0; background: #fffbeb; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-hourglass-half"></i> Votre demande est en cours d'examen par l'administration. Vous serez notifié dès validation.
                    </p>
                <?php elseif (!empty($d['commentaire_admin'])): ?>
                    <p style="color: #b91c1c; font-size: 0.85rem; margin: 0.75rem 0 0; background: #fef2f2; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-comment-dots"></i> Motif de l'administration : <?php echo htmlspecialchars($d['commentaire_admin']); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php elseif ($tab === 'cotisations'): ?>
<div class="content-section">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
        <a href="cotisations.php" class="btn-submit" style="width: auto; padding: 0.6rem 1.25rem; text-decoration: none;">
            <i class="fa-solid fa-plus"></i> Proposer une campagne de cotisation
        </a>
    </div>
    <?php if (empty($mes_campagnes)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Vous n'avez pas encore proposé de campagne de cotisation.
        </div>
    <?php else: ?>
        <?php foreach ($mes_campagnes as $c): list($s_label, $s_bg, $s_fg) = dem_badge($c['statut']); ?>
            <div class="card" style="padding: 1.5rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--navy); font-size: 1.05rem;"><?php echo htmlspecialchars($c['titre']); ?></strong>
                        <small style="color: var(--muted); display: block; margin-top: 2px;">
                            Objectif : <?php echo number_format((float)$c['montant_objectif'], 0, ',', ' '); ?> FCFA
                            · Déjà collecté : <?php echo number_format((float)$c['montant_collecte'], 0, ',', ' '); ?> FCFA
                            · <?php echo (int)$c['nb_contributeurs']; ?> contributeur(s)
                        </small>
                    </div>
                    <span style="background: <?php echo $s_bg; ?>; color: <?php echo $s_fg; ?>; padding: 4px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: bold; white-space: nowrap;">
                        <?php echo $s_label; ?>
                    </span>
                </div>

                <!-- Barre de progression -->
                <?php
                $objectif = (float)$c['montant_objectif'];
                $pct = ($objectif > 0) ? min(100, round(((float)$c['montant_collecte'] / $objectif) * 100)) : 0;
                ?>
                <div style="height: 10px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin: 0.75rem 0 0.35rem;">
                    <div style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, var(--primary), var(--primary-light)); border-radius: 999px;"></div>
                </div>
                <small style="color: var(--muted); font-weight: 600;"><?php echo $pct; ?>% de l'objectif</small>

                <?php if ($c['statut'] === 'en_attente'): ?>
                    <p style="color: #92400e; font-size: 0.85rem; margin: 0.75rem 0 0; background: #fffbeb; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-hourglass-half"></i> Votre campagne attend la validation de l'administration avant d'être visible sur le site.
                    </p>
                <?php elseif (!empty($c['commentaire_admin'])): ?>
                    <p style="color: #b91c1c; font-size: 0.85rem; margin: 0.75rem 0 0; background: #fef2f2; border-radius: 6px; padding: 0.6rem 0.8rem;">
                        <i class="fa-solid fa-comment-dots"></i> Motif de l'administration : <?php echo htmlspecialchars($c['commentaire_admin']); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php else: /* tab = votes */ ?>
<div class="content-section">
    <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);"><i class="fa-solid fa-chart-simple"></i> Engagement du public sur vos événements</h2>
    <?php if (empty($mon_classement)): ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-chart-simple" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Aucun événement publié pour le moment.
        </div>
    <?php else: ?>
        <?php foreach ($mon_classement as $ev): ?>
            <div class="card" style="padding: 1.25rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div>
                    <strong style="color: var(--navy);"><?php echo htmlspecialchars($ev['nom']); ?></strong>
                    <small style="color: var(--muted); display: block;"><?php echo htmlspecialchars($ev['categorie']); ?></small>
                </div>
                <div style="display: flex; gap: 1.25rem;">
                    <span style="background: #eff6ff; color: #1d4ed8; border-radius: 999px; padding: 6px 14px; font-size: 0.85rem; font-weight: bold;">
                        <i class="fa-solid fa-up-long"></i> <?php echo (int)$ev['nb_votes']; ?> vote<?php echo (int)$ev['nb_votes'] > 1 ? 's' : ''; ?>
                    </span>
                    <span style="background: #fef2f2; color: #b91c1c; border-radius: 999px; padding: 6px 14px; font-size: 0.85rem; font-weight: bold;">
                        <i class="fa-solid fa-heart"></i> <?php echo (int)$ev['nb_likes']; ?> like<?php echo (int)$ev['nb_likes'] > 1 ? 's' : ''; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>