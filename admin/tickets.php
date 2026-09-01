<?php
// ==============================================================================
// GESTION & TRAÇABILITÉ DES TICKETS (admin/tickets.php)
// Design Dashboard Pro - Contrôle, filtrage et validation des billets émis
// ==============================================================================

$admin_page_title = "Gestion des Billets - Administration";
include 'header.php';

$filter_event  = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$filter_status = trim($_GET['statut'] ?? '');
$filter_search = trim($_GET['q'] ?? '');

$sql_tickets = "
    SELECT t.*, e.nom AS event_name, u.nom AS client_nom, u.email AS client_email, ag.nom AS agent_nom
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    JOIN users u ON t.user_id = u.id
    LEFT JOIN users ag ON t.validated_by = ag.id
    WHERE 1=1
";
$params = [];

if (!empty($filter_event)) {
    $sql_tickets .= " AND t.event_id = ?";
    $params[] = $filter_event;
}

if (!empty($filter_status) && in_array($filter_status, ['vendu', 'utilise', 'annule'], true)) {
    $sql_tickets .= " AND t.statut = ?";
    $params[] = $filter_status;
}

if (!empty($filter_search)) {
    $sql_tickets .= " AND (t.code_unique LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR e.nom LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}

$sql_tickets .= " ORDER BY t.date_achat DESC";
$stmt_tks = $pdo->prepare($sql_tickets);
$stmt_tks->execute($params);
$tickets_list = $stmt_tks->fetchAll();

// KPIs
$tot_emis    = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut != 'annule'")->fetchColumn();
$tot_scannes = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'utilise'")->fetchColumn();
$tot_valides = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'vendu'")->fetchColumn();
$recette_tkt = (float)$pdo->query("SELECT COALESCE(SUM(prix), 0) FROM tickets WHERE statut != 'annule'")->fetchColumn();

// Liste des événements pour filtre
$events_list = $pdo->query("SELECT id, nom FROM events ORDER BY nom ASC")->fetchAll();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-ticket" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Traçabilité & Contrôle des Billets
            </h1>
            <p>Historique des billets vendus, validation aux accès et recherche par QR / Code unique.</p>
        </div>

        <div>
            <a href="verification.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-qrcode"></i> Ouvrir le Scanner Direct
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT (AU-DESSUS DES KPIS)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $filter_status === '' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $filter_status === '' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous (<?php echo $tot_emis; ?>)
            </a>

            <a href="?statut=vendu&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $filter_status === 'vendu' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Valides (<?php echo $tot_valides; ?>)
            </a>

            <a href="?statut=utilise&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $filter_status === 'utilise' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-barcode" style="color: #8b5cf6;"></i> Compostés / Utilisés (<?php echo $tot_scannes; ?>)
            </a>

            <a href="?statut=annule&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $filter_status === 'annule' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Annulés
            </a>
        </div>

        <!-- À DROITE : SÉLECTEUR ÉVÉNEMENT & RECHERCHE -->
        <form method="GET" action="tickets.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filter_status); ?>">

            <select name="event_id" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer; max-width: 200px;">
                <option value="">Tous les événements</option>
                <?php foreach ($events_list as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>" <?php echo ($filter_event == $ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(mb_strimwidth($ev['nom'], 0, 25, '...')); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Code, client, email..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($filter_status !== '' || $filter_event || $filter_search !== ''): ?>
                <a href="tickets.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Billets Actifs (Valides)</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_valides, 0, ',', ' '); ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">En attente de passage aux portes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #8b5cf6; text-transform: uppercase;">Participants Scannés</span>
                <span style="background: #f5f3ff; color: #8b5cf6; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-qrcode"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #8b5cf6;"><?php echo number_format($tot_scannes, 0, ',', ' '); ?></div>
            <small style="color: #8b5cf6; font-size: 0.75rem;">Entrées compostées par les agents</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Recette Billetterie</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-sack-dollar"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($recette_tkt, 0, ',', ' '); ?> F</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Total des billets vendus</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Billets Émis</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo number_format($tot_emis, 0, ',', ' '); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Volume global de billetterie</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES BILLETS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Billets Filtrés (<?php echo count($tickets_list); ?>)
            </h3>
        </div>

        <?php if (empty($tickets_list)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-ticket" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun billet ne correspond à vos critères de recherche.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Code Unique</th>
                            <th>Événement</th>
                            <th>Formule</th>
                            <th>Tarif</th>
                            <th>Acheteur</th>
                            <th>Date d'Achat</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Contrôle Entrée</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets_list as $t): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; font-size: 0.95rem; color: var(--dash-primary);">
                                        <?php echo htmlspecialchars($t['code_unique']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($t['event_name']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($t['type_ticket']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #16a34a; font-size: 0.92rem;">
                                        <?php echo number_format($t['prix'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <span style="color: var(--dash-text); font-weight: 700; font-size: 0.84rem; display: block;">
                                        <?php echo htmlspecialchars($t['client_nom']); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($t['client_email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo date('d/m/Y H:i', strtotime($t['date_achat'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($t['statut'] === 'vendu'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟢 Valide</span>
                                    <?php elseif ($t['statut'] === 'utilise'): ?>
                                        <span style="background: #f5f3ff; color: #6d28d9; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟣 Composté</span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🔴 Annulé</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($t['statut'] === 'utilise'): ?>
                                        <small style="color: var(--dash-muted); font-size: 0.75rem;">
                                            Scanné le <?php echo date('d/m H:i', strtotime($t['date_utilisation'])); ?>
                                            <?php if ($t['agent_nom']): ?>
                                                par <strong><?php echo htmlspecialchars($t['agent_nom']); ?></strong>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.76rem;">Non scanné</span>
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
