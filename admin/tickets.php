<?php
// ==============================================================================
// GESTION & FILTRAGE DES TICKETS (admin/tickets.php)
// Filtrage multicritère des billets vendus et gestion des tarifs
// ==============================================================================

$admin_page_title = "Gestion & Filtrage des Tickets - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Paramètres de filtrage des tickets vendus
$filter_event  = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$filter_type   = trim($_GET['type'] ?? '');
$filter_status = trim($_GET['statut'] ?? '');
$filter_code   = trim($_GET['code'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_date   = trim($_GET['date'] ?? '');

// 2. Construction de la requête SQL dynamique pour les tickets
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

if (!empty($filter_type)) {
    $sql_tickets .= " AND t.type_ticket = ?";
    $params[] = $filter_type;
}

if (!empty($filter_status)) {
    $sql_tickets .= " AND t.statut = ?";
    $params[] = $filter_status;
}

if (!empty($filter_code)) {
    $sql_tickets .= " AND t.code_unique LIKE ?";
    $params[] = "%$filter_code%";
}

if (!empty($filter_client)) {
    $sql_tickets .= " AND (u.nom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$filter_client%";
    $params[] = "%$filter_client%";
}

if (!empty($filter_date)) {
    $sql_tickets .= " AND DATE(t.date_achat) = ?";
    $params[] = $filter_date;
}

$sql_tickets .= " ORDER BY t.date_achat DESC";
$stmt_tks = $pdo->prepare($sql_tickets);
$stmt_tks->execute($params);
$tickets_list = $stmt_tks->fetchAll();

// Liste de tous les événements pour la liste déroulante
$events_list = $pdo->query("SELECT id, nom FROM events ORDER BY nom ASC")->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Billetterie & Traçabilité</span>
        <h1>Gestion & Filtrage des Billets</h1>
        <p>Consultez, recherchez et filtrez l'ensemble des billets émis sur la plateforme.</p>
    </div>
</div>

<!-- 1. Formulaire de Filtrage Multicritère (Section 10) -->
<div class="content-section" style="margin-bottom: 2rem;">
    <div class="section-title"><i class="fa-solid fa-filter"></i> Filtrer les billets</div>
    
    <form method="GET" action="tickets.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Événement</label>
            <select name="event_id" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                <option value="">Tous les événements</option>
                <?php foreach ($events_list as $ev): ?>
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
                <option value="vendu" <?php echo ($filter_status === 'vendu') ? 'selected' : ''; ?>>Vendu (Valide)</option>
                <option value="utilise" <?php echo ($filter_status === 'utilise') ? 'selected' : ''; ?>>Utilisé / Composté</option>
                <option value="annule" <?php echo ($filter_status === 'annule') ? 'selected' : ''; ?>>Annulé</option>
            </select>
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Code du ticket</label>
            <input type="text" name="code" placeholder="Ex: TK-8F92A7K3" value="<?php echo htmlspecialchars($filter_code); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Client (Nom ou Email)</label>
            <input type="text" name="client" placeholder="Ex: Jean Dupont" value="<?php echo htmlspecialchars($filter_client); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div>
            <label style="font-size: 0.82rem; font-weight: bold; display: block; margin-bottom: 4px;">Date d'achat</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn-submit" style="width: 100%; margin: 0; padding: 0.65rem;">
                <i class="fa-solid fa-magnifying-glass"></i> Filtrer
            </button>
            <a href="tickets.php" class="btn-submit" style="width: auto; background: transparent; color: var(--muted); border: 1px solid var(--line); text-decoration: none; padding: 0.65rem;" title="Réinitialiser">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        </div>
    </form>
</div>

<!-- 2. Tableau des résultats -->
<div class="content-section">
    <div class="section-title">
        <span>Billets trouvés (<?php echo count($tickets_list); ?>)</span>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Code Ticket</th>
                    <th>Événement</th>
                    <th>Type</th>
                    <th>Prix</th>
                    <th>Acheteur / Client</th>
                    <th>Date d'Achat</th>
                    <th>Statut</th>
                    <th>Validation</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($tickets_list) > 0): ?>
                    <?php foreach ($tickets_list as $t): ?>
                        <tr>
                            <td>
                                <strong style="font-family: monospace; font-size: 0.95rem; color: var(--navy);">
                                    <?php echo htmlspecialchars($t['code_unique']); ?>
                                </strong>
                            </td>

                            <td>
                                <strong><?php echo htmlspecialchars($t['event_name']); ?></strong>
                            </td>

                            <td>
                                <span style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 0.78rem;">
                                    <?php echo htmlspecialchars($t['type_ticket']); ?>
                                </span>
                            </td>

                            <td>
                                <strong><?php echo number_format($t['prix'], 0, ',', ' '); ?> F</strong>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($t['client_nom']); ?><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($t['client_email']); ?></small>
                            </td>

                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($t['date_achat'])); ?>
                            </td>

                            <td>
                                <?php if ($t['statut'] === 'vendu'): ?>
                                    <span style="color: #10b981; font-weight: bold;"><i class="fa-solid fa-circle"></i> Vendu (Valide)</span>
                                <?php elseif ($t['statut'] === 'utilise'): ?>
                                    <span style="color: #6366f1; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Utilisé</span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($t['statut']); ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($t['statut'] === 'utilise'): ?>
                                    <small style="color: var(--muted);">
                                        <?php echo date('d/m/Y H:i', strtotime($t['date_utilisation'])); ?>
                                        <?php if ($t['agent_nom']): ?>
                                            par <?php echo htmlspecialchars($t['agent_nom']); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.8rem;">En attente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--muted); padding: 2.5rem;">
                            Aucun billet ne correspond aux critères de recherche.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
