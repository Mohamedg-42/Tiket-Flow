<?php
// ==============================================================================
// SUIVI DES VENTES DU PROMOTEUR (promoteur/mes-ventes.php)
// Affichage des tickets vendus, des tickets restants et traçabilité des achats
// ==============================================================================

$page_title = "Mes Ventes & Billets - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// 1. Récupération de l'état des stocks (Vendus & Restants) par catégorie de billets
$stmt_stock = $pdo->prepare("
    SELECT tt.nom AS category_name, tt.prix, tt.quantite AS total_places, tt.quantite_vendue AS vendus,
           (tt.quantite - tt.quantite_vendue) AS restants, e.nom AS event_nom
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    WHERE e.user_id = ?
    ORDER BY e.nom ASC, tt.prix DESC
");
$stmt_stock->execute([$user_id]);
$stocks_summary = $stmt_stock->fetchAll();

// 2. Filtres sur les ventes individuelles
$filter_event  = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$filter_status = trim($_GET['statut'] ?? '');
$filter_code   = trim($_GET['code'] ?? '');
$filter_type   = trim($_GET['type'] ?? '');

$sql = "
    SELECT t.*, e.nom AS event_name, 
           COALESCE(t.client_nom, u.nom, 'Client') AS buyer_name,
           COALESCE(t.client_email, u.email, '') AS buyer_email
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE e.user_id = ?
";
$params = [$user_id];

if ($filter_event) {
    $sql .= " AND e.id = ?";
    $params[] = $filter_event;
}

if (!empty($filter_status)) {
    $sql .= " AND t.statut = ?";
    $params[] = $filter_status;
}

if (!empty($filter_code)) {
    $sql .= " AND t.code_unique LIKE ?";
    $params[] = "%$filter_code%";
}

if (!empty($filter_type)) {
    $sql .= " AND t.type_ticket LIKE ?";
    $params[] = "%$filter_type%";
}

$sql .= " ORDER BY t.date_achat DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Liste des événements pour le filtre
$my_events_stmt = $pdo->prepare("SELECT id, nom FROM events WHERE user_id = ? ORDER BY nom ASC");
$my_events_stmt->execute([$user_id]);
$my_events_list = $my_events_stmt->fetchAll();

$total_filtre = array_sum(array_column($sales, 'prix'));
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker"><i class="fa-solid fa-chart-simple"></i> Suivi des Ventes & Stocks</span>
        <h1>Mes Ventes & Disponibilité des Billets</h1>
        <p>Visualisez en temps réel les billets vendus, les places restantes et l'historique des acheteurs.</p>
    </div>
</div>

<!-- 1. Synthèse des Stocks : Billets Vendus & Billets Restants par Catégorie -->
<div class="content-section" style="margin-bottom: 2rem;">
    <div class="section-title">
        <i class="fa-solid fa-boxes-stacked"></i> État des Stocks & Places Restantes (<?php echo count($stocks_summary); ?> catégories)
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Événement</th>
                    <th>Catégorie de Billet</th>
                    <th>Prix Unitaire</th>
                    <th>Billets Vendus</th>
                    <th>Billets Restants</th>
                    <th>Capacité Totale</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($stocks_summary) > 0): ?>
                    <?php foreach ($stocks_summary as $stk): ?>
                        <?php 
                        $v = (int)$stk['vendus'];
                        $tot = (int)$stk['total_places'];
                        $r = max(0, $tot - $v);
                        $pct = ($tot > 0) ? round(($v / $tot) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($stk['event_nom']); ?></strong>
                            </td>
                            <td>
                                <span style="background: #e0f2fe; color: #0284c7; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 0.82rem;">
                                    <?php echo htmlspecialchars($stk['category_name']); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo number_format($stk['prix'], 0, ',', ' '); ?> F</strong>
                            </td>
                            <td>
                                <strong style="color: #0284c7; font-size: 0.95rem;">
                                    <i class="fa-solid fa-circle-check"></i> <?php echo $v; ?> vendu(s)
                                </strong>
                            </td>
                            <td>
                                <?php if ($r === 0 && $tot > 0): ?>
                                    <span style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 4px; padding: 2px 8px; font-weight: 800; font-size: 0.78rem;">
                                        <i class="fa-solid fa-ban"></i> Épuisé
                                    </span>
                                <?php else: ?>
                                    <span style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; border-radius: 4px; padding: 2px 8px; font-weight: 800; font-size: 0.78rem;">
                                        <i class="fa-solid fa-ticket"></i> <?php echo $r; ?> restant(s)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong><?php echo $tot; ?> places</strong>
                                    <div style="background: #e2e8f0; height: 5px; border-radius: 3px; width: 60px; overflow: hidden;">
                                        <div style="background: var(--primary); height: 100%; width: <?php echo min(100, $pct); ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucune catégorie de billet configurée pour vos événements.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2. Formulaire de Filtrage des Billets Achetés -->
<div class="content-section" style="margin-bottom: 2rem;">
    <div class="section-title"><i class="fa-solid fa-filter"></i> Filtrer les Billets Individuels</div>
    <form method="GET" action="mes-ventes.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Événement</label>
            <select name="event_id" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                <option value="">Tous mes événements</option>
                <?php foreach ($my_events_list as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>" <?php echo ($filter_event == $ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['nom']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Type de billet</label>
            <input type="text" name="type" placeholder="Ex: VIP, STANDARD" value="<?php echo htmlspecialchars($filter_type); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Statut</label>
            <select name="statut" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                <option value="">Tous les statuts</option>
                <option value="vendu" <?php echo ($filter_status === 'vendu') ? 'selected' : ''; ?>>Vendu (Non utilisé)</option>
                <option value="utilise" <?php echo ($filter_status === 'utilise') ? 'selected' : ''; ?>>Utilisé / Entré</option>
            </select>
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Code Ticket</label>
            <input type="text" name="code" placeholder="TK-..." value="<?php echo htmlspecialchars($filter_code); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn-submit" style="width: 100%; margin: 0; padding: 0.65rem;">
                <i class="fa-solid fa-magnifying-glass"></i> Filtrer
            </button>
            <?php if ($filter_event || !empty($filter_status) || !empty($filter_code) || !empty($filter_type)): ?>
                <a href="mes-ventes.php" class="btn-submit" style="width: auto; background: transparent; color: var(--muted); border: 1px solid var(--line); text-decoration: none; padding: 0.65rem;" title="Réinitialiser">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- 3. Liste détaillée des billets achetés -->
<div class="content-section">
    <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fa-solid fa-ticket"></i> Billets Individuels (<?php echo count($sales); ?>)</span>
        <span style="font-size: 0.95rem; color: var(--primary-dark); font-weight: 800; font-family: 'Outfit', sans-serif;">
            Recettes : <?php echo number_format($total_filtre, 0, ',', ' '); ?> FCFA
        </span>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Code Billet</th>
                    <th>Événement</th>
                    <th>Catégorie</th>
                    <th>Prix Payé</th>
                    <th>Acheteur</th>
                    <th>Date d'Achat</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($sales) > 0): ?>
                    <?php foreach ($sales as $s): ?>
                        <tr>
                            <td>
                                <strong style="font-family: monospace; font-size: 0.95rem; color: var(--navy); letter-spacing: 0.5px;">
                                    <?php echo htmlspecialchars($s['code_unique']); ?>
                                </strong>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($s['event_name']); ?></strong>
                            </td>
                            <td>
                                <span style="background: #f1f5f9; border: 1px solid var(--line); padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($s['type_ticket']); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: var(--primary);"><?php echo number_format($s['prix'], 0, ',', ' '); ?> F</strong>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($s['buyer_name']); ?></strong><br>
                                <small style="color: var(--muted); font-size: 0.78rem;"><?php echo htmlspecialchars($s['buyer_email']); ?></small>
                            </td>
                            <td>
                                <small style="color: var(--muted); font-size: 0.82rem;">
                                    <?php echo date('d/m/Y H:i', strtotime($s['date_achat'])); ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($s['statut'] === 'vendu'): ?>
                                    <span style="color: #0284c7; font-weight: 700; font-size: 0.82rem;"><i class="fa-solid fa-circle-check"></i> Valide</span>
                                <?php elseif ($s['statut'] === 'utilise'): ?>
                                    <span style="color: #16a34a; font-weight: 700; font-size: 0.82rem;"><i class="fa-solid fa-check-double"></i> Entré</span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-weight: 700; font-size: 0.82rem;"><?php echo ucfirst($s['statut']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 2.5rem 1rem;">
                            Aucun billet trouvé pour ces critères.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
