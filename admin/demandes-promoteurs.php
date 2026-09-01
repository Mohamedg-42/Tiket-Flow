<?php
// ==============================================================================
// GESTION DES DEMANDES DE PROMOTEURS (admin/demandes-promoteurs.php)
// Design Dashboard Pro - Validation d'éligibilité légale et activation des organisateurs
// ==============================================================================

$admin_page_title = "Demandes d'éligibilité Promoteurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Traitement des actions (Approuver ou Refuser)
if (isset($_GET['id']) && isset($_GET['action'])) {
    $id     = (int)$_GET['id'];
    $action = $_GET['action'];

    $stmt_req = $pdo->prepare("SELECT * FROM promoter_requests WHERE id = ?");
    $stmt_req->execute([$id]);
    $req = $stmt_req->fetch();

    if ($req) {
        $user_id = (int)$req['user_id'];

        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            $stmt_u = $pdo->prepare("UPDATE users SET role = 'promoteur', est_verifie = 1 WHERE id = ?");
            $stmt_u->execute([$user_id]);

            $stmt_p = $pdo->prepare("UPDATE promoters SET statut = 'approuve' WHERE user_id = ?");
            $stmt_p->execute([$user_id]);

            $message = "Le promoteur « " . htmlspecialchars($req['nom_complet']) . " » a été approuvé avec succès ! Son compte promoteur est désormais actif.";
            $msg_type = "success";

        } elseif ($action === 'reject') {
            $motif = trim($_POST['motif_refus'] ?? 'Dossier incomplet ou critères non remplis');

            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$motif, $id]);

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

// KPIs
$nb_attente = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$nb_approuve = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'approuve'")->fetchColumn();
$nb_refuse = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'refuse'")->fetchColumn();
$nb_total = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-id-card" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Dossiers d'Éligibilité Promoteurs
            </h1>
            <p>Vérifiez les pièces légales d'identité et de société avant d'autoriser la publication d'événements.</p>
        </div>

        <div>
            <a href="promoteurs.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-user-tie"></i> Voir Tous les Promoteurs
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES)
         ============================================================================== -->
    <div style="display: flex; gap: 0.4rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <a href="?tab=en_attente" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-clock" style="color: #f59e0b;"></i>
            <span>En Attente d'Examen</span>
            <?php if ($nb_attente > 0): ?>
                <span style="background: #ef4444; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_attente; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=approuve" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'approuve' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
            <span>Dossiers Approuvés</span>
        </a>

        <a href="?tab=refuse" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'refuse' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-ban" style="color: #ef4444;"></i>
            <span>Refusés</span>
        </a>

        <a href="?tab=tous" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Tous les Dossiers (<?php echo $nb_total; ?>)</span>
        </a>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">Dossiers En Attente</span>
                <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-clock"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo $nb_attente; ?></div>
            <small style="color: #b45309; font-size: 0.75rem;">À vérifier et valider</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Promoteurs Validés</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo $nb_approuve; ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Comptes organisateurs actifs</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ef4444; text-transform: uppercase;">Dossiers Rejetés</span>
                <span style="background: #fee2e2; color: #ef4444; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ban"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ef4444;"><?php echo $nb_refuse; ?></div>
            <small style="color: #ef4444; font-size: 0.75rem;">Non conformes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Dossiers</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-id-card"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $nb_total; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Historique d'inscriptions</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. LISTE DES DOSSIERS
         ============================================================================== -->
    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
        <?php if (empty($requests)): ?>
            <div class="dash-card" style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-id-card" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucune demande d'éligibilité dans cette catégorie.
            </div>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <?php
                $is_p = ($r['statut'] === 'en_attente');
                $badge_st = [
                    'en_attente' => ['En attente', '#fef3c7', '#b45309'],
                    'approuve'   => ['Approuvé', '#dcfce7', '#166534'],
                    'refuse'     => ['Refusé', '#fee2e2', '#991b1b']
                ];
                [$st_label, $st_bg, $st_fg] = $badge_st[$r['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                ?>
                <div class="dash-card" style="padding: 1.5rem; <?php echo $is_p ? 'border-left: 4px solid #f59e0b;' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                                <span style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 2px 9px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">
                                    <?php echo $st_label; ?>
                                </span>
                                <span style="color: var(--dash-muted); font-size: 0.8rem;">
                                    Soumis le <?php echo date('d/m/Y à H:i', strtotime($r['created_at'])); ?>
                                </span>
                            </div>

                            <h3 style="margin: 0; color: var(--dash-text); font-size: 1.2rem; font-weight: 800;">
                                <?php echo htmlspecialchars($r['nom_commercial'] ?: $r['nom_complet']); ?>
                            </h3>
                            <small style="color: var(--dash-muted); font-size: 0.82rem;">
                                Responsable : <strong><?php echo htmlspecialchars($r['nom_complet']); ?></strong> · Téléphone : <?php echo htmlspecialchars($r['telephone']); ?>
                            </small>
                        </div>

                        <div style="text-align: right; font-size: 0.84rem;">
                            <span style="display: block; color: var(--dash-muted);">Type de Personne :</span>
                            <strong style="color: var(--dash-text);"><?php echo $r['type_personne'] === 'morale' ? '🏢 Entreprise / Société' : '👤 Personne Physique'; ?></strong>
                        </div>
                    </div>

                    <!-- Justificatifs légaux téléchargeables -->
                    <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1rem; display: flex; gap: 1.5rem; flex-wrap: wrap; font-size: 0.85rem;">
                        <?php if ($r['piece_identite']): ?>
                            <a href="../uploads/promoter_docs/<?php echo htmlspecialchars($r['piece_identite']); ?>" target="_blank" style="color: #0284c7; font-weight: 700; text-decoration: underline;">
                                <i class="fa-solid fa-id-badge"></i> Consulter la Pièce d'Identité
                            </a>
                        <?php else: ?>
                            <span style="color: var(--dash-muted);"><i class="fa-solid fa-id-badge"></i> Pièce d'identité non fournie</span>
                        <?php endif; ?>

                        <?php if ($r['registre_commerce']): ?>
                            <a href="../uploads/promoter_docs/<?php echo htmlspecialchars($r['registre_commerce']); ?>" target="_blank" style="color: #0284c7; font-weight: 700; text-decoration: underline;">
                                <i class="fa-solid fa-file-invoice"></i> Registre de Commerce (RCCM / DFE)
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <?php if ($is_p): ?>
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                            <a href="?id=<?php echo $r['id']; ?>&action=approve" onclick="return confirm('Confirmez-vous l\'approbation de ce promoteur ? Il pourra publier des événements.');" class="dash-btn-action" style="background: #16a34a; color: #ffffff; padding: 0.45rem 1.15rem; font-size: 0.82rem; font-weight: 800; text-decoration: none;">
                                <i class="fa-solid fa-check"></i> Valider et Activer le Compte
                            </a>

                            <button type="button" onclick="document.getElementById('reject-box-<?php echo $r['id']; ?>').style.display = 'block'" class="dash-btn-action" style="background: #fee2e2; color: #ef4444; padding: 0.45rem 0.85rem; font-size: 0.82rem; font-weight: 800;">
                                <i class="fa-solid fa-xmark"></i> Refuser
                            </button>
                        </div>

                        <!-- Formulaire de refus dépliant -->
                        <div id="reject-box-<?php echo $r['id']; ?>" style="display: none; margin-top: 0.75rem; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 0.75rem;">
                            <form method="POST" action="demandes-promoteurs.php?id=<?php echo $r['id']; ?>&action=reject">
                                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #9f1239; margin-bottom: 4px;">Motif de rejet transmis au demandeur :</label>
                                <input type="text" name="motif_refus" required placeholder="Ex: Pièce d'identité illisible ou expirée..." style="width: 100%; padding: 0.5rem; border: 1px solid #fecdd3; border-radius: 6px; font-size: 0.82rem; margin-bottom: 6px; box-sizing: border-box;">
                                <button type="submit" class="dash-btn-action" style="background: #e11d48; color: #ffffff; padding: 0.35rem 0.85rem; font-size: 0.78rem;">Confirmer le Rejet</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
