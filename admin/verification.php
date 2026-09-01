<?php
// ==============================================================================
// CONTRÔLE D'ACCÈS & VÉRIFICATION DES BILLETS (admin/verification.php)
// Design Dashboard Pro - Compostage immédiat et traçabilité des entrées
// ==============================================================================

$admin_page_title = "Vérification des Billets - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Compostage direct d'un billet via code unique
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_ticket'])) {
    $code_saisi = trim($_POST['code_unique'] ?? '');

    if (!empty($code_saisi)) {
        $stmt_check = $pdo->prepare("
            SELECT t.*, e.nom as event_nom, u.nom as client_nom 
            FROM tickets t
            JOIN events e ON t.event_id = e.id
            JOIN users u ON t.user_id = u.id
            WHERE t.code_unique = ?
        ");
        $stmt_check->execute([$code_saisi]);
        $ticket_found = $stmt_check->fetch();

        if (!$ticket_found) {
            $message = "Code invalide : aucun billet trouvé correspondant à « " . htmlspecialchars($code_saisi) . " ».";
            $msg_type = "error";
        } elseif ($ticket_found['statut'] === 'utilise') {
            $message = "⚠️ ALERTE : Ce billet a DÉJÀ ÉTÉ UTILISÉ le " . date('d/m/Y à H:i', strtotime($ticket_found['date_utilisation'])) . " !";
            $msg_type = "error";
        } elseif ($ticket_found['statut'] === 'annule') {
            $message = "⛔ Billet ANNULÉ : Accès formellement refusé.";
            $msg_type = "error";
        } else {
            // Validation et compostage
            $stmt_val = $pdo->prepare("
                UPDATE tickets 
                SET statut = 'utilise', date_utilisation = NOW(), validated_by = ? 
                WHERE id = ?
            ");
            $stmt_val->execute([$_SESSION['user_id'], $ticket_found['id']]);
            $message = "✅ ENTRÉE AUTORISÉE : Billet validé avec succès pour « " . htmlspecialchars($ticket_found['client_nom']) . " » (Événement: " . htmlspecialchars($ticket_found['event_nom']) . ").";
            $msg_type = "success";
        }
    }
}

// 2. Filtres
$statut_f = $_GET['statut'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

$sql = "
    SELECT t.*, e.nom AS event_name, u.nom AS client_name, ag.nom as agent_nom
    FROM tickets t
    JOIN events e ON e.id = t.event_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN users ag ON ag.id = t.validated_by
    WHERE 1=1
";
$params = [];

if ($statut_f === 'utilise') {
    $sql .= " AND t.statut = 'utilise'";
} elseif ($statut_f === 'vendu') {
    $sql .= " AND t.statut = 'vendu'";
}

if (!empty($search)) {
    $sql .= " AND (t.code_unique LIKE ? OR e.nom LIKE ? OR u.nom LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY (t.statut = 'utilise') DESC, t.date_utilisation DESC, t.id DESC LIMIT 80";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// KPIs
$tot_scannes = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'utilise'")->fetchColumn();
$tot_valides = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'vendu'")->fetchColumn();
$tot_billets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut != 'annule'")->fetchColumn();
$taux_entree = ($tot_billets > 0) ? round(($tot_scannes / $tot_billets) * 100) : 0;
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-qrcode" style="color: #8b5cf6; font-size: 1.55rem;"></i>
                Scanner & Contrôle d'Accès aux Portes
            </h1>
            <p>Compostez manuellement un billet par son code unique ou inspectez l'historique des accès en direct.</p>
        </div>

        <div>
            <a href="tickets.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-ticket"></i> Voir Tous les Billets
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; font-weight: 700;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. COMPOSTAGE RAPIDE DE BILLET PAR CODE (ESPACE ADMIN)
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-left: 4px solid #8b5cf6;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
            <h3 style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-barcode" style="color: #8b5cf6;"></i> Saisie & Validation Immédiate d'un Billet
            </h3>
            <span style="font-size: 0.76rem; color: var(--dash-muted);">Opérateur : <strong><?php echo htmlspecialchars($admin_nom); ?></strong></span>
        </div>

        <form method="POST" action="verification.php" style="display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="scan_ticket" value="1">
            <div style="position: relative; flex: 1; min-width: 250px;">
                <i class="fa-solid fa-qrcode" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #8b5cf6; font-size: 1rem;"></i>
                <input type="text" name="code_unique" required autofocus placeholder="Entrez ou scannez le code unique (ex: TK-8F92A7K3)..." style="width: 100%; padding: 0.65rem 0.85rem 0.65rem 2.4rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.95rem; font-family: monospace; font-weight: 700; box-sizing: border-box; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="background: #8b5cf6; color: #ffffff; padding: 0.65rem 1.4rem; font-size: 0.85rem; font-weight: 800; border-radius: 8px;">
                <i class="fa-solid fa-check"></i> Valider l'Entrée
            </button>
        </form>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES EN HAUT
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous (<?php echo $tot_billets; ?>)
            </a>

            <a href="?statut=utilise&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'utilise' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #8b5cf6;"></i> Compostés (<?php echo $tot_scannes; ?>)
            </a>

            <a href="?statut=vendu&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'vendu' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-clock" style="color: #10b981;"></i> En attente de passage
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="verification.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Code, événement, client..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 180px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="verification.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #8b5cf6; text-transform: uppercase;">Entrées Compostées</span>
                <span style="background: #f5f3ff; color: #8b5cf6; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-qrcode"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #8b5cf6;"><?php echo number_format($tot_scannes, 0, ',', ' '); ?></div>
            <small style="color: #8b5cf6; font-size: 0.75rem;">Spectateurs admis en salle</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">En Attente d'Arrivée</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hourglass-half"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_valides, 0, ',', ' '); ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Billets non encore présentés</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Taux de Présence</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chart-pie"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo $taux_entree; ?>%</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Ratio entrées / billets vendus</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Billetterie</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo number_format($tot_billets, 0, ',', ' '); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Capacité totale émise</small>
        </div>
    </div>

    <!-- ==============================================================================
         5. TABLEAU HISTORIQUE DES VÉRIFICATIONS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Journal des Contrôles d'Entrée (<?php echo count($tickets); ?>)
            </h3>
        </div>

        <?php if (empty($tickets)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-qrcode" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun billet à afficher.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Code Billet</th>
                            <th>Événement</th>
                            <th>Spectateur</th>
                            <th>Type de Formule</th>
                            <th>Statut d'Accès</th>
                            <th style="text-align: right;">Heure de Passage / Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                            <?php $is_scanned = ($t['statut'] === 'utilise'); ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; font-size: 0.92rem; color: var(--dash-primary);">
                                        <?php echo htmlspecialchars($t['code_unique']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($t['event_name']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($t['client_name'] ?? 'Spectateur'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($t['type_ticket']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_scanned): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                            <i class="fa-solid fa-circle-check"></i> Scanné
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                            <i class="fa-solid fa-clock"></i> En attente
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($is_scanned && !empty($t['date_utilisation'])): ?>
                                        <span style="font-size: 0.82rem; font-weight: 700; color: #166534; display: block;">
                                            <?php echo date('d/m/Y H:i:s', strtotime($t['date_utilisation'])); ?>
                                        </span>
                                        <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                            par <?php echo htmlspecialchars($t['agent_nom'] ?: 'Administrateur'); ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.78rem;">Non composté</span>
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
