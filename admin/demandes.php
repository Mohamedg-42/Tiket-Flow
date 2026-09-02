<?php
// ==============================================================================
// CENTRE DES DEMANDES ADMINISTRATEUR (admin/demandes.php)
// Design Dashboard Pro - Validation d'événements, cotisations & plébiscites de vote
// ==============================================================================

$admin_page_title = "Centre des Demandes - Administration";
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

                $stmt_ev = $pdo->prepare("INSERT INTO events (user_id, nom, description, image, categorie, date_evenement, heure, lieu, prix_vote, type_vote, vote_question, commission_rate, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')");
                $stmt_ev->execute([$req['user_id'], $req['nom'], $req['description'], $req['image'], $req['categorie'], $req['date_evenement'], $req['heure'], $req['lieu'], (float)($req['prix_vote'] ?? 0), $req['type_vote'] ?? 'aucun', $req['vote_question'] ?? null, $commission_rate]);
                $new_event_id = (int)$pdo->lastInsertId();

                $ticket_types = json_decode($req['ticket_types_data'] ?? '[]', true);
                if (!empty($ticket_types) && is_array($ticket_types)) {
                    require_once '../includes/places.php';
                    $stmt_tt = $pdo->prepare("INSERT INTO ticket_types (event_id, nom, prix, frais_place, quantite, quantite_vendue) VALUES (?, ?, ?, ?, ?, 0)");
                    foreach ($ticket_types as $tt) {
                        $stmt_tt->execute([
                            $new_event_id,
                            $tt['nom'],
                            (float)$tt['prix'],
                            (float)($tt['frais_place'] ?? 0),
                            (int)$tt['quantite']
                        ]);
                        // Génération automatique des places pour ce tarif
                        generer_places_type($pdo, (int)$pdo->lastInsertId(), (int)$tt['quantite']);
                    }
                }

                // Création automatique des candidats / options de vote dans 'event_candidats'
                $candidats_data = json_decode($req['candidats_data'] ?? '[]', true);
                if (!empty($candidats_data) && is_array($candidats_data)) {
                    $stmt_c_ins = $pdo->prepare("INSERT INTO event_candidats (event_id, nom, description, photo) VALUES (?, ?, ?, ?)");
                    foreach ($candidats_data as $cd) {
                        $stmt_c_ins->execute([
                            $new_event_id,
                            $cd['nom'],
                            $cd['description'] ?? null,
                            $cd['photo'] ?? null
                        ]);
                    }
                }

                $pdo->prepare("UPDATE event_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?")->execute([$request_id]);
                $pdo->commit();

                $message = "L'événement « " . htmlspecialchars($req['nom']) . " » a été approuvé et publié sur la billetterie (commission : " . $commission_rate . "%).";
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

// ============ RÉCUPÉRATION DES DONNÉES SELON L'ONGLET ============
$event_requests = [];
$campagnes_list = [];
$classement_votes = [];

// Onglet 1 : Demandes d'événements
try {
    $event_requests = $pdo->query("
        SELECT r.*, u.nom AS promoteur_nom, u.email AS promoteur_email
        FROM event_requests r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY (r.statut = 'en_attente') DESC, r.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $event_requests = [];
}

// Onglet 2 : Campagnes de cotisation
try {
    $campagnes_list = $pdo->query("
        SELECT c.*, u.nom AS promoteur_nom, u.email AS promoteur_email
        FROM cotisation_campagnes c
        LEFT JOIN users u ON c.user_id = u.id
        ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $campagnes_list = [];
}

// Onglet 3 : Votes & plébiscites
try {
    $classement_votes = $pdo->query("
        SELECT e.id, e.nom, e.categorie, e.date_evenement, e.lieu, e.prix_vote, e.type_vote,
               (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes,
               (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) AS nb_likes,
               (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') AS recettes_votes,
               u.nom AS promoteur_nom
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.statut = 'actif'
        ORDER BY nb_votes DESC, nb_likes DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $classement_votes = [];
}

// Compteurs pour les badges
$nb_events_pending   = count(array_filter($event_requests, fn($r) => $r['statut'] === 'en_attente'));
$nb_campagnes_pending= count(array_filter($campagnes_list, fn($c) => $c['statut'] === 'en_attente'));
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-inbox" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Centre des Demandes & Validations
            </h1>
            <p>Examinez les propositions d'événements, validez les campagnes de cotisation et suivez les votes du public.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. ONGLETS DE CATÉGORIES EN HAUT (PILULES D'ONGLETS ACTIVES)
         ============================================================================== -->
    <div style="display: flex; gap: 0.4rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <a href="?tab=evenements" style="text-decoration: none; border-radius: 9px; padding: 0.5rem 1.05rem; font-size: 0.84rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'evenements' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-calendar-plus" style="<?php echo $tab === 'evenements' ? 'color: #2dd4bf;' : ''; ?>"></i>
            <span>Demandes d'Événements</span>
            <?php if ($nb_events_pending > 0): ?>
                <span style="background: #ef4444; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_events_pending; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=cotisations" style="text-decoration: none; border-radius: 9px; padding: 0.5rem 1.05rem; font-size: 0.84rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'cotisations' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899;"></i>
            <span>Campagnes de Cotisation</span>
            <?php if ($nb_campagnes_pending > 0): ?>
                <span style="background: #f59e0b; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_campagnes_pending; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=votes" style="text-decoration: none; border-radius: 9px; padding: 0.5rem 1.05rem; font-size: 0.84rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'votes' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-chart-simple" style="color: #0284c7;"></i>
            <span>Votes & Plébiscites</span>
        </a>
    </div>

    <!-- ==============================================================================
         2bis. KPIS DE SYNTHÈSE (style commun des pages admin)
         ============================================================================== -->
    <?php
    $kpi_evt_attente   = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
    $kpi_evt_approuves = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'approuve'")->fetchColumn();
    $kpi_camp_attente  = (int)$pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
    $kpi_votes_total   = (int)$pdo->query("SELECT COUNT(*) FROM event_votes")->fetchColumn();
    ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: clamp(0.75rem, 2vw, 1rem); margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ca8a04; text-transform: uppercase;">Événements en Attente</span>
                <span style="background: #fef9c3; color: #ca8a04; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hourglass-half"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ca8a04;"><?php echo $kpi_evt_attente; ?></div>
            <small style="color: #ca8a04; font-size: 0.75rem;">Demandes d'événements à traiter</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Événements Validés</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo $kpi_evt_approuves; ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Publiés sur la billetterie</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ec4899; text-transform: uppercase;">Campagnes en Attente</span>
                <span style="background: #fce7f3; color: #ec4899; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hand-holding-heart"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ec4899;"><?php echo $kpi_camp_attente; ?></div>
            <small style="color: #ec4899; font-size: 0.75rem;">Cotisations à examiner</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Votes du Public</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chart-simple"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo $kpi_votes_total; ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Suffrages exprimés sur la plateforme</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. CONTENU SELON L'ONGLET SÉLECTIONNÉ
         ============================================================================== -->
    <?php if ($tab === 'evenements'): ?>
        <!-- A. DEMANDES D'ÉVÉNEMENTS (tableau style Dashboard Pro) -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-calendar-plus" style="color: #f59e0b;"></i> Demandes d'Événements Reçues
                </h3>
            </div>

            <?php if (empty($event_requests)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                    Aucune proposition d'événement enregistrée pour l'instant.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Demande</th>
                                <th>Organisateur</th>
                                <th>Date & Lieu</th>
                                <th>Billetterie</th>
                                <th>Statut</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($event_requests as $r): ?>
                            <?php
                            $is_pending = ($r['statut'] === 'en_attente');
                            $t_data = json_decode($r['ticket_types_data'] ?? '[]', true) ?: [];
                            $r_candidats = json_decode($r['candidats_data'] ?? '[]', true) ?: [];
                            $has_vote = !empty($r['type_vote']) && $r['type_vote'] !== 'aucun' && $r['type_vote'] !== '';
                            $total_places_demandees = 0;
                            foreach ($t_data as $tt) { $total_places_demandees += (int)($tt['quantite'] ?? 0); }
                            $badge_st = [
                                'en_attente' => ['En attente', '#fef3c7', '#b45309'],
                                'approuve'   => ['Approuvée', '#dcfce7', '#166534'],
                                'refuse'     => ['Refusée', '#fee2e2', '#991b1b']
                            ];
                            [$st_text, $st_bg, $st_fg] = $badge_st[$r['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td style="max-width: 340px;">
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($r['nom']); ?>
                                    </strong>
                                    <span style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <i class="fa-solid fa-tag" style="color: var(--dash-primary);"></i> <?php echo htmlspecialchars($r['categorie']); ?>
                                        <?php if ($has_vote): ?> &nbsp;•&nbsp; <?php echo ($r['type_vote'] === 'concours') ? '🏆 Concours' : '🗳️ Réalisation'; ?><?php endif; ?>
                                        <?php if (!empty($r_candidats)): ?> &nbsp;•&nbsp; <i class="fa-solid fa-users"></i> <?php echo count($r_candidats); ?> candidat(s)<?php endif; ?>
                                        <br><i class="fa-regular fa-clock"></i> Soumise le <?php echo date('d/m/Y à H:i', strtotime($r['created_at'])); ?>
                                    </span>
                                    <details style="margin-top: 6px;">
                                        <summary style="font-size: 0.74rem; color: #0284c7; font-weight: 700; cursor: pointer;">Voir les détails</summary>
                                        <div style="margin-top: 8px; padding: 10px 12px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.8rem; color: #475569; line-height: 1.5;">
                                            <?php echo nl2br(htmlspecialchars($r['description'])); ?>

                        <!-- Système de vote demandé & candidats proposés (aide à la décision) -->
                        <?php
                        $r_candidats = json_decode($r['candidats_data'] ?? '[]', true) ?: [];
                        $has_vote = !empty($r['type_vote']) && $r['type_vote'] !== 'aucun' && $r['type_vote'] !== '';
                        ?>
                        <?php if ($has_vote || !empty($r_candidats)): ?>
                        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.85rem 1rem; margin: 0 0 1rem;">
                            <?php if ($has_vote): ?>
                            <div style="font-size: 0.82rem; color: #1e40af; margin-bottom: <?php echo !empty($r_candidats) ? '0.5rem' : '0'; ?>;">
                                <i class="fa-solid fa-chart-simple"></i> <strong>Système de vote demandé :</strong>
                                <?php echo ($r['type_vote'] === 'concours') ? '🏆 Concours (avec candidats)' : '🗳️ Réalisation'; ?>
                                <?php if ((float)($r['prix_vote'] ?? 0) > 0): ?>
                                    — <strong><?php echo number_format((float)$r['prix_vote'], 0, ',', ' '); ?> F</strong> / vote (payant)
                                <?php else: ?>
                                    — Gratuit
                                <?php endif; ?>
                                <?php if (!empty($r['vote_question'])): ?>
                                    <div style="margin-top: 0.25rem; font-style: italic;">« <?php echo htmlspecialchars($r['vote_question']); ?> »</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($r_candidats)): ?>
                            <div style="font-size: 0.8rem; color: #1e3a8a;">
                                <strong><i class="fa-solid fa-users"></i> <?php echo count($r_candidats); ?> candidat(s) proposé(s) :</strong>
                                <ul style="margin: 0.35rem 0 0; padding-left: 1.2rem;">
                                    <?php foreach ($r_candidats as $rc): ?>
                                        <li><?php echo htmlspecialchars($rc['nom'] ?? 'Candidat'); ?><?php echo !empty($rc['description']) ? ' — ' . htmlspecialchars($rc['description']) : ''; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Billetterie proposée -->
                        <?php if (!empty($t_data)): ?>
                        <div style="background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; padding: 0.7rem 1rem; margin: 0 0 1rem; font-size: 0.8rem; color: #115e59;">
                            <strong><i class="fa-solid fa-ticket"></i> Billetterie proposée :</strong>
                            <?php foreach ($t_data as $i => $tt): ?>
                                <span style="display: inline-block; background: #ffffff; border: 1px solid #ccfbf1; border-radius: 6px; padding: 2px 8px; margin: 3px 4px 0 0;">
                                    <?php echo htmlspecialchars($tt['nom'] ?? 'Tarif'); ?> — <?php echo number_format((float)($tt['prix'] ?? 0), 0, ',', ' '); ?> F × <?php echo (int)($tt['quantite'] ?? 0); ?> places
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Justificatifs & type de demandeur (dans les détails) -->
                        <div style="display: flex; gap: 1rem; font-size: 0.82rem; flex-wrap: wrap; margin-top: 8px;">
                            <span><i class="fa-solid fa-building-user"></i> Type : <strong><?php echo $r['type_personne'] === 'morale' ? 'Personne morale' : 'Personne physique'; ?></strong></span>
                            <?php if ($r['document_justificatif']): ?>
                                <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_justificatif']); ?>" target="_blank" style="color: #0284c7; font-weight: 700; text-decoration: underline;"><i class="fa-solid fa-file-pdf"></i> Pièce justificative</a>
                            <?php endif; ?>
                            <?php if ($r['document_autorisation']): ?>
                                <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_autorisation']); ?>" target="_blank" style="color: #0284c7; font-weight: 700; text-decoration: underline;"><i class="fa-solid fa-file-shield"></i> Autorisation légale</a>
                            <?php endif; ?>
                        </div>
                        </div>
                                    </details>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text); display: block;">
                                        <?php echo htmlspecialchars($r['promoteur_nom'] ?? 'Promoteur inconnu'); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo htmlspecialchars($r['promoteur_email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; color: var(--dash-text); display: block;">
                                        <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($r['date_evenement'])); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo date('H\hi', strtotime($r['heure'])); ?> — <i class="fa-solid fa-location-dot" style="color: #ef4444;"></i> <?php echo htmlspecialchars(mb_strimwidth($r['lieu'] ?? '', 0, 20, '...')); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if (!empty($t_data)): ?>
                                        <strong style="font-size: 0.84rem; color: var(--dash-text);"><?php echo count($t_data); ?> tarif(s)</strong>
                                        <small style="color: var(--dash-muted); font-size: 0.76rem; display: block;"><?php echo $total_places_demandees; ?> places</small>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.8rem;">Sans billetterie</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_text; ?>
                                    </span>
                                    <?php if (!$is_pending && !empty($r['reviewed_at'])): ?>
                                        <small style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;">Le <?php echo date('d/m/Y', strtotime($r['reviewed_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php if ($r['statut'] === 'refuse' && !empty($r['commentaire_admin'])): ?>
                                        <small style="color: #b91c1c; font-size: 0.72rem; display: block; margin-top: 3px;" title="<?php echo htmlspecialchars($r['commentaire_admin']); ?>">
                                            <i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars(mb_strimwidth($r['commentaire_admin'], 0, 26, '...')); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($is_pending): ?>
                                        <div style="display: inline-flex; flex-direction: column; align-items: flex-end; gap: 6px;">

                                            <form method="POST" style="display: inline-flex; align-items: center; gap: 0.5rem; margin: 0;">
                                                <input type="hidden" name="action" value="approuver_evenement">
                                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                                <div style="display: inline-flex; align-items: center; gap: 4px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 2px 8px;">
                                                    <span style="font-size: 0.74rem; color: var(--dash-muted); font-weight: 700;">Com.</span>
                                                    <input type="number" name="commission_rate" value="5.0" min="0" max="30" step="0.5" style="width: 45px; border: 0; background: transparent; font-weight: 800; font-size: 0.8rem; text-align: center; outline: none;">
                                                    <span style="font-size: 0.74rem; color: var(--dash-muted); font-weight: 700;">%</span>
                                                </div>
                                                <button type="submit" class="dash-btn-action" style="background: #16a34a; color: #ffffff; padding: 0.4rem 0.9rem; font-size: 0.8rem; font-weight: 800;">
                                                    <i class="fa-solid fa-check"></i> Approuver
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="var m = prompt('Motif du refus (visible par le promoteur) :', 'Dossier incomplet ou non conforme'); if (m === null || m.trim() === '') return false; this.querySelector('[name=commentaire_admin]').value = m.trim(); return true;" style="margin: 0;">
                                                <input type="hidden" name="action" value="refuser_evenement">
                                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                                <input type="hidden" name="commentaire_admin" value="Dossier incomplet ou non conforme">
                                                <button type="submit" class="dash-btn-action" style="background: #fee2e2; color: #ef4444; padding: 0.35rem 0.8rem; font-size: 0.78rem; font-weight: 800;">
                                                    <i class="fa-solid fa-xmark"></i> Refuser
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <a href="../client/accueil.php" target="_blank" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.74rem;" title="Voir la vitrine publique">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'cotisations'): ?>
        <!-- B. CAMPAGNES DE COTISATION (tableau style Dashboard Pro) -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899;"></i> Campagnes de Cotisation Proposées
                </h3>
            </div>

            <?php if (empty($campagnes_list)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                    Aucune campagne de cotisation proposée pour l'instant.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Campagne</th>
                                <th>Organisateur</th>
                                <th>Objectif</th>
                                <th>Statut</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campagnes_list as $c): ?>
                            <?php
                            $is_pending = ($c['statut'] === 'en_attente');
                            $badge_st = [
                                'en_attente' => ['En attente', '#fef3c7', '#b45309'],
                                'active'     => ['Active', '#dcfce7', '#166534'],
                                'terminee'   => ['Terminée', '#f1f5f9', '#475569'],
                                'refuse'     => ['Refusée', '#fee2e2', '#991b1b']
                            ];
                            [$st_text, $st_bg, $st_fg] = $badge_st[$c['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td style="max-width: 340px;">
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($c['titre']); ?>
                                    </strong>
                                    <?php if (!empty($c['date_limite'])): ?>
                                        <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                            <i class="fa-regular fa-calendar"></i> Échéance : <?php echo date('d/m/Y', strtotime($c['date_limite'])); ?>
                                        </small>
                                    <?php endif; ?>
                                    <details style="margin-top: 6px;">
                                        <summary style="font-size: 0.74rem; color: #0284c7; font-weight: 700; cursor: pointer;">Voir la description</summary>
                                        <div style="margin-top: 8px; padding: 10px 12px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.8rem; color: #475569; line-height: 1.5;">
                                            <?php echo nl2br(htmlspecialchars($c['description'] ?? '')); ?>
                                        </div>
                                    </details>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text);">
                                        <?php echo htmlspecialchars($c['promoteur_nom'] ?? 'Promoteur'); ?>
                                    </span>
                                </td>

                                <td>
                                    <strong style="font-size: 0.9rem; color: #059669;">
                                        <?php echo number_format((float)$c['montant_objectif'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <span style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_text; ?>
                                    </span>
                                    <?php if ($c['statut'] === 'refuse' && !empty($c['commentaire_admin'])): ?>
                                        <small style="color: #b91c1c; font-size: 0.72rem; display: block; margin-top: 3px;" title="<?php echo htmlspecialchars($c['commentaire_admin']); ?>">
                                            <i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars(mb_strimwidth($c['commentaire_admin'], 0, 26, '...')); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($is_pending): ?>
                                        <div style="display: inline-flex; gap: 5px;">
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="action" value="approuver_campagne">
                                                <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="dash-btn-action" style="background: #16a34a; color: #ffffff; padding: 0.35rem 0.8rem; font-size: 0.78rem; font-weight: 800;">
                                                    <i class="fa-solid fa-check"></i> Valider
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="var m = prompt('Motif du refus (visible par le promoteur) :', 'Campagne non conforme aux règles de la plateforme'); if (m === null || m.trim() === '') return false; this.querySelector('[name=commentaire_admin]').value = m.trim(); return true;" style="margin: 0;">
                                                <input type="hidden" name="action" value="refuser_campagne">
                                                <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                                                <input type="hidden" name="commentaire_admin" value="Campagne non validée">
                                                <button type="submit" class="dash-btn-action" style="background: #fee2e2; color: #ef4444; padding: 0.35rem 0.8rem; font-size: 0.78rem; font-weight: 800;">
                                                    <i class="fa-solid fa-xmark"></i> Refuser
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'votes'): ?>
        <!-- C. VOTES DU PUBLIC & ENGAGEMENT -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-ranking-star" style="color: #ca8a04;"></i> Plébiscite et Votes du Public
                </h3>
            </div>

            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Type de Vote</th>
                            <th>Suffrages</th>
                            <th>Likes</th>
                            <th>Recettes Encaissées</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rg = 1;
                        foreach ($classement_votes as $v): 
                        ?>
                            <tr>
                                <td>
                                    <strong style="font-size: 0.95rem; color: var(--dash-text);">
                                        <?php echo ($rg === 1) ? '🥇 1er' : (($rg === 2) ? '🥈 2e' : (($rg === 3) ? '🥉 3e' : '#' . $rg)); ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($v['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($v['date_evenement'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem;">
                                        <?php echo htmlspecialchars($v['promoteur_nom'] ?? 'Organisateur'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="background: <?php echo ($v['type_vote'] === 'concours') ? '#fef9c3' : '#e0f2fe'; ?>; color: <?php echo ($v['type_vote'] === 'concours') ? '#b45309' : '#0369a1'; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo ($v['type_vote'] === 'concours') ? '🏆 Concours' : '🗳️ Réalisation'; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #0284c7; font-size: 0.95rem;">
                                        <?php echo number_format((int)$v['nb_votes'], 0, ',', ' '); ?> votes
                                    </strong>
                                </td>
                                <td>
                                    <span style="color: #ef4444; font-weight: 700; font-size: 0.85rem;">
                                        <i class="fa-solid fa-heart"></i> <?php echo (int)$v['nb_likes']; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.92rem;">
                                        <?php echo number_format((float)$v['recettes_votes'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                            </tr>
                        <?php 
                            $rg++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
