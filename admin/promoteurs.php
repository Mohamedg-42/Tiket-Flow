<?php
// ==============================================================================
// GESTION DES PROMOTEURS (admin/promoteurs.php)
// Design Dashboard Pro - Supervision des partenaires, soldes financiers et statuts
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

// Filtre statut et recherche
$statut_f = $_GET['statut'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, u.nom AS user_nom, u.email AS user_email, u.telephone AS user_tel,
           (SELECT COUNT(*) FROM events e WHERE e.user_id = p.user_id) AS total_events,
           (SELECT COUNT(*) FROM information_requests ir WHERE ir.promoter_id = p.user_id) AS total_info_reqs
    FROM promoters p
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($statut_f === 'approuve') {
    $sql .= " AND p.statut = 'approuve'";
} elseif ($statut_f === 'suspendu') {
    $sql .= " AND p.statut = 'suspendu'";
} elseif ($statut_f === 'en_attente') {
    $sql .= " AND p.statut = 'en_attente'";
}

if (!empty($search)) {
    $sql .= " AND (p.nom_commercial LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR p.telephone_contact LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$promoters = $stmt->fetchAll();

// KPIs
$tot_promoters = (int)$pdo->query("SELECT COUNT(*) FROM promoters")->fetchColumn();
$tot_actifs    = (int)$pdo->query("SELECT COUNT(*) FROM promoters WHERE statut = 'approuve'")->fetchColumn();
$tot_suspendus = (int)$pdo->query("SELECT COUNT(*) FROM promoters WHERE statut = 'suspendu'")->fetchColumn();
$tot_soldes    = (float)$pdo->query("SELECT COALESCE(SUM(solde), 0) FROM promoters")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-user-tie" style="color: #10b981; font-size: 1.55rem;"></i>
                Gestion des Promoteurs Partenaires
            </h1>
            <p>Supervisez les organisateurs agréés, consultez leurs soldes de trésorerie et gérez leurs statuts.</p>
        </div>

        <div>
            <a href="demandes-promoteurs.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-id-card"></i> Dossiers d'Éligibilité en Attente
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
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous (<?php echo $tot_promoters; ?>)
            </a>

            <a href="?statut=approuve&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'approuve' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Actifs (<?php echo $tot_actifs; ?>)
            </a>

            <a href="?statut=suspendu&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'suspendu' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Suspendus (<?php echo $tot_suspendus; ?>)
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="promoteurs.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Structure, nom, email..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 180px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="promoteurs.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Promoteurs Actifs</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-user-tie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo $tot_actifs; ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Organisateurs autorisés</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Soldes Cumulés Promoteurs</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-wallet"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($tot_soldes, 0, ',', ' '); ?> F</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Total des portefeuilles promoteurs</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ef4444; text-transform: uppercase;">Comptes Suspendus</span>
                <span style="background: #fee2e2; color: #ef4444; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ban"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ef4444;"><?php echo $tot_suspendus; ?></div>
            <small style="color: #ef4444; font-size: 0.75rem;">Accès temporairement bloqués</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Partenaires</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-handshake"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $tot_promoters; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Tous statuts confondus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES PROMOTEURS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Promoteurs (<?php echo count($promoters); ?>)
            </h3>
        </div>

        <?php if (empty($promoters)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-user-tie" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun promoteur ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Structure / Promoteur</th>
                            <th>Contact Direct</th>
                            <th>Événements</th>
                            <th>Demandes Infos</th>
                            <th>Solde Retirable</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Action Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promoters as $p): ?>
                            <?php
                            $is_susp = ($p['statut'] === 'suspendu');
                            $statut_badge = [
                                'approuve' => ['Actif', '#dcfce7', '#166534'],
                                'suspendu' => ['Suspendu', '#fee2e2', '#991b1b'],
                                'en_attente' => ['En attente', '#fef3c7', '#b45309']
                            ];
                            [$st_l, $st_b, $st_f] = $statut_badge[$p['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($p['nom_commercial'] ?: $p['user_nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        Responsable : <?php echo htmlspecialchars($p['user_nom']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-text); display: block;">
                                        <i class="fa-regular fa-envelope" style="color: var(--dash-muted);"></i> <?php echo htmlspecialchars($p['email_contact'] ?: $p['user_email']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($p['telephone_contact'] ?: $p['user_tel']); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong style="font-size: 0.88rem; color: var(--dash-text);">
                                        <?php echo (int)$p['total_events']; ?>
                                    </strong> événement(s)
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem; color: var(--dash-text);">
                                        <?php echo (int)$p['total_info_reqs']; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.98rem; font-weight: 800;">
                                        <?php echo number_format((float)$p['solde'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <span style="background: <?php echo $st_b; ?>; color: <?php echo $st_f; ?>; padding: 3px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_l; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($is_susp): ?>
                                        <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=activate" onclick="return confirm('Réactiver ce promoteur ?');" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.74rem; background: #dcfce7; color: #166534;">
                                            <i class="fa-solid fa-unlock"></i> Réactiver
                                        </a>
                                    <?php else: ?>
                                        <a href="promoteurs.php?id=<?php echo $p['id']; ?>&action=suspend" onclick="return confirm('Voulez-vous suspendre temporairement ce promoteur ?');" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.74rem; background: #fee2e2; color: #ef4444;">
                                            <i class="fa-solid fa-ban"></i> Suspendre
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
</div>

<?php include 'footer.php'; ?>
