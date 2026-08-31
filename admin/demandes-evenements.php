<?php
// ==============================================================================
// GESTION DES DEMANDES D'ÉVÉNEMENTS (admin/demandes-evenements.php)
// Examen du statut juridique (personne physique/morale), documents légaux et publication
// ==============================================================================

$admin_page_title = "Demandes d'Événements - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// Traitement de l'action de l'administrateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $request_id = (int)$_POST['request_id'];
    $action     = $_POST['action_type'];

    // On récupère la demande
    $stmt = $pdo->prepare("SELECT * FROM event_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();

    if ($req && $req['statut'] === 'en_attente') {
        if ($action === 'approve') {
            $commission_rate = (float)($_POST['commission_rate'] ?? 5.00);
            if ($commission_rate < 0) $commission_rate = 5.00;

            try {
                $pdo->beginTransaction();

                // 1. Création de l'événement officiel dans 'events'
                $sql_ev = "INSERT INTO events (user_id, nom, description, image, categorie, date_evenement, heure, lieu, commission_rate, statut) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')";
                $stmt_ev = $pdo->prepare($sql_ev);
                $stmt_ev->execute([
                    $req['user_id'],
                    $req['nom'],
                    $req['description'],
                    $req['image'],
                    $req['categorie'],
                    $req['date_evenement'],
                    $req['heure'],
                    $req['lieu'],
                    $commission_rate
                ]);

                $new_event_id = (int)$pdo->lastInsertId();

                // 2. Création automatique des types de tickets dans 'ticket_types'
                $ticket_types = json_decode($req['ticket_types_data'] ?? '[]', true);
                if (!empty($ticket_types) && is_array($ticket_types)) {
                    $sql_tt = "INSERT INTO ticket_types (event_id, nom, prix, quantite, quantite_vendue) VALUES (?, ?, ?, ?, 0)";
                    $stmt_tt = $pdo->prepare($sql_tt);
                    foreach ($ticket_types as $tt) {
                        $stmt_tt->execute([
                            $new_event_id,
                            $tt['nom'],
                            (float)$tt['prix'],
                            (int)$tt['quantite']
                        ]);
                    }
                }

                // 3. Mise à jour du statut de la demande
                $stmt_upd = $pdo->prepare("UPDATE event_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?");
                $stmt_upd->execute([$request_id]);

                $pdo->commit();

                $message = "L'événement « " . htmlspecialchars($req['nom']) . " » a été approuvé et publié avec un taux de commission de " . $commission_rate . "% !";
                $msg_type = "success";

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Erreur lors de la validation : " . $e->getMessage();
                $msg_type = "error";
            }

        } elseif ($action === 'reject') {
            $commentaire = trim($_POST['commentaire_admin'] ?? 'Informations ou justificatifs incomplets ou non conformes');
            $stmt_ref = $pdo->prepare("UPDATE event_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt_ref->execute([$commentaire, $request_id]);

            $message = "La demande d'événement a été refusée.";
            $msg_type = "error";
        }
    }
}

// Filtres
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'approuve', 'refuse', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "
    SELECT er.*, u.nom AS promoteur_nom, u.email AS promoteur_email, u.telephone AS promoteur_tel
    FROM event_requests er
    JOIN users u ON er.user_id = u.id
";

if ($tab !== 'tous') {
    $sql .= " WHERE er.statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY er.created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY er.created_at DESC");
}
$requests = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Contrôle & Validation Légale</span>
        <h1>Demandes de Création d'Événements</h1>
        <p>Vérifiez le statut juridique du déclarant (personne physique ou morale), examinez ses justificatifs et fixez la commission.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Onglets -->
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

<!-- Liste des demandes -->
<div class="content-section">
    <div class="section-title">Demandes d'événements (<?php echo count($requests); ?>)</div>

    <?php if (count($requests) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1.75rem;">
            <?php foreach ($requests as $r): ?>
                <?php 
                $tickets = json_decode($r['ticket_types_data'] ?? '[]', true); 
                $is_morale = ($r['type_personne'] === 'morale');
                ?>
                <div style="background: var(--paper); border: 1px solid var(--line); border-radius: 12px; padding: 1.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    
                    <!-- En-tête de la carte -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.4rem; flex-wrap: wrap;">
                                <span style="font-size: 0.82rem; text-transform: uppercase; color: var(--primary); font-weight: bold;">
                                    <i class="fa-solid fa-user-tie"></i> Promoteur : <?php echo htmlspecialchars($r['promoteur_nom']); ?> (<?php echo htmlspecialchars($r['promoteur_email']); ?> - <?php echo htmlspecialchars($r['promoteur_tel']); ?>)
                                </span>

                                <!-- Badge Personne Physique / Morale -->
                                <?php if ($is_morale): ?>
                                    <span style="background: #e0e7ff; color: #3730a3; padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 0.78rem;">
                                        <i class="fa-solid fa-building"></i> Personne Morale (Entreprise / Ass.)
                                    </span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #334155; padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 0.78rem;">
                                        <i class="fa-solid fa-user"></i> Personne Physique (Particulier)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h2 style="margin: 0.2rem 0; color: var(--navy); font-size: 1.45rem;">
                                <?php echo htmlspecialchars($r['nom']); ?>
                            </h2>

                            <div style="color: var(--muted); font-size: 0.9rem; margin-top: 0.2rem;">
                                <span><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($r['categorie']); ?></span> · 
                                <span><i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($r['date_evenement'])); ?> à <?php echo substr($r['heure'], 0, 5); ?></span> · 
                                <span><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($r['lieu']); ?></span>
                            </div>
                        </div>

                        <div>
                            <?php if ($r['statut'] === 'approuve'): ?>
                                <span style="background: #dcfce7; color: #166534; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;">
                                    <i class="fa-solid fa-check"></i> Publié
                                </span>
                            <?php elseif ($r['statut'] === 'refuse'): ?>
                                <span style="background: #fee2e2; color: #991b1b; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;">
                                    <i class="fa-solid fa-xmark"></i> Refusé
                                </span>
                            <?php else: ?>
                                <span style="background: #fef3c7; color: #92400e; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;">
                                    <i class="fa-solid fa-clock"></i> En attente de validation
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bloc d'informations légales de l'organisateur -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem; color: var(--navy); font-size: 0.95rem;">
                            <i class="fa-solid fa-scale-balanced"></i> Statut & Pièces Justificatives Fournies :
                        </h4>
                        
                        <?php if ($is_morale): ?>
                            <div style="margin-bottom: 0.6rem; font-size: 0.9rem;">
                                <span>Structure : <strong><?php echo htmlspecialchars($r['nom_structure'] ?: 'Non précisé'); ?></strong></span>
                                <?php if (!empty($r['numero_rccm'])): ?>
                                    · <span>N° RCCM / SIRET : <strong><?php echo htmlspecialchars($r['numero_rccm']); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem;">
                            <!-- Document Justificatif -->
                            <?php if (!empty($r['document_justificatif']) && file_exists('../uploads/event_docs/' . $r['document_justificatif'])): ?>
                                <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_justificatif']); ?>" target="_blank" class="btn-submit" style="width: auto; padding: 0.4rem 0.9rem; font-size: 0.82rem; text-decoration: none; background: #0284c7;">
                                    <i class="fa-solid fa-file-pdf"></i> <?php echo $is_morale ? 'Voir RCCM / Statuts' : 'Voir Pièce d\'Identité'; ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size: 0.82rem; color: var(--muted);"><i class="fa-solid fa-circle-exclamation"></i> Aucun justificatif téléversé</span>
                            <?php endif; ?>

                            <!-- Document d'autorisation -->
                            <?php if (!empty($r['document_autorisation']) && file_exists('../uploads/event_docs/' . $r['document_autorisation'])): ?>
                                <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_autorisation']); ?>" target="_blank" class="btn-submit" style="width: auto; padding: 0.4rem 0.9rem; font-size: 0.82rem; text-decoration: none; background: #475569;">
                                    <i class="fa-solid fa-file-lines"></i> Voir Autorisation / Contrat de salle
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Détails de l'événement et tarifs -->
                    <div style="background: #f8faf9; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <strong style="font-size: 0.9rem; color: var(--navy);">Description :</strong>
                        <p style="margin: 0.4rem 0 0.8rem; font-size: 0.92rem; color: var(--ink); line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($r['description'])); ?>
                        </p>

                        <?php if (!empty($r['infos_supplementaires'])): ?>
                            <strong style="font-size: 0.85rem; color: var(--muted);">Infos supplémentaires :</strong>
                            <p style="margin: 0.2rem 0 0.8rem; font-size: 0.88rem; color: var(--muted);">
                                <?php echo htmlspecialchars($r['infos_supplementaires']); ?>
                            </p>
                        <?php endif; ?>

                        <strong style="font-size: 0.9rem; color: var(--navy);">Grille tarifaire demandée :</strong>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem;">
                            <?php if (!empty($tickets)): ?>
                                <?php foreach ($tickets as $t): ?>
                                    <div style="background: #ffffff; border: 1px solid var(--line); padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.88rem;">
                                        <strong><?php echo htmlspecialchars($t['nom']); ?> :</strong> 
                                        <span style="color: var(--primary); font-weight: bold;"><?php echo number_format($t['prix'], 0, ',', ' '); ?> FCFA</span>
                                        <span style="color: var(--muted); font-size: 0.8rem;">(<?php echo $t['quantite']; ?> places)</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($r['statut'] === 'en_attente'): ?>
                        <!-- Formulaire de validation avec taux de commission ou refus -->
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; border-top: 1px solid var(--line); padding-top: 1rem;">
                            <form method="POST" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="action_type" value="approve">

                                <div>
                                    <label style="font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 4px;">Taux de commission (%)</label>
                                    <input type="number" step="0.5" name="commission_rate" value="5.0" min="0" max="50" style="width: 110px; padding: 0.6rem; border: 1px solid var(--line); border-radius: 6px;">
                                </div>

                                <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1.25rem; background: #10b981;" onclick="return confirm('Valider cet événement et le publier immédiatement sur le site ?')">
                                    <i class="fa-solid fa-check"></i> Valider & Publier l'Événement
                                </button>
                            </form>

                            <form method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; margin-left: auto;">
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="action_type" value="reject">

                                <div>
                                    <input type="text" name="commentaire_admin" placeholder="Motif du refus (ex: pièces non valides)..." style="padding: 0.6rem; border: 1px solid var(--line); border-radius: 6px; min-width: 260px;">
                                </div>

                                <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.6rem 1rem; background: #ef4444;" onclick="return confirm('Refuser cette proposition d\'événement ?')">
                                    <i class="fa-solid fa-xmark"></i> Refuser
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; color: var(--muted); padding: 3rem;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            Aucune demande d'événement dans cette section.
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
