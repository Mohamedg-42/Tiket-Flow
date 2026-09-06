<?php
// ==============================================================================
// HISTORIQUE DES VÉRIFICATIONS AGENT (agent/historique.php)
// Liste des billets scannés et validés par l'agent connecté
// ==============================================================================

$page_title = "Historique des Scans - Espace Agent";
include 'header.php';

$agent_id = (int) $_SESSION['user_id'];

// Filtres
$filter_period = trim($_GET['periode'] ?? 'toutes');
$search_q = trim($_GET['q'] ?? '');

// Construction de la requête
$sql = "
    SELECT t.*, e.nom AS event_name, e.lieu, u.nom AS client_nom, u.email AS client_email
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.validated_by = ?
";
$params = [$agent_id];

if ($filter_period === 'aujourd_hui') {
    $sql .= " AND DATE(t.date_utilisation) = CURDATE()";
} elseif ($filter_period === '7_jours') {
    $sql .= " AND t.date_utilisation >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if (!empty($search_q)) {
    $sql .= " AND (t.code_unique LIKE ? OR u.nom LIKE ? OR e.nom LIKE ?)";
    $params[] = "%$search_q%";
    $params[] = "%$search_q%";
    $params[] = "%$search_q%";
}

$sql .= " ORDER BY t.date_utilisation DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$validated_tickets = $stmt->fetchAll();

// KPIs
$stmt_today = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE validated_by = ? AND DATE(date_utilisation) = CURDATE()");
$stmt_today->execute([$agent_id]);
$kpi_today = (int) $stmt_today->fetchColumn();

$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE validated_by = ?");
$stmt_total->execute([$agent_id]);
$kpi_total = (int) $stmt_total->fetchColumn();
?>

<main style="flex: 1; max-width: 1000px; width: 100%; margin: 1.5rem auto 3rem; padding: 0 clamp(1rem, 3vw, 2rem); box-sizing: border-box;">
    
    <!-- En-tête de page -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <div>
            <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255, 177, 46, 0.15); color: var(--eventia-amber-dark, #FF4A0D); padding: 3px 9px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem;">
                <i class="fa-solid fa-clock-rotate-left"></i> Traçabilité Contrôle
            </div>
            <h1 style="margin: 0; font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 1.6rem; font-weight: 800; color: var(--eventia-navy, #000000); letter-spacing: -0.02em;">
                Historique de mes Validations
            </h1>
            <p style="margin: 0.25rem 0 0; color: var(--eventia-muted, #737373); font-size: 0.88rem;">
                Liste en temps réel des billets scannés et autorisés à l'entrée.
            </p>
        </div>

        <a href="verification.php" class="eventia-btn-primary" style="padding: 0.55rem 1.15rem; font-size: 0.86rem; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 800; border-radius: 10px;">
            <i class="fa-solid fa-camera"></i> Retour au Scanner
        </a>
    </div>

    <!-- KPIs Simples -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div style="background: var(--eventia-white, #ffffff); border: 1px solid var(--eventia-border, #E5E5E5); border-radius: 14px; padding: 1rem 1.25rem; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
            <span style="font-size: 0.74rem; font-weight: 800; color: var(--eventia-amber-dark, #FF4A0D); text-transform: uppercase; display: block; margin-bottom: 0.25rem;">
                Scannés Aujourd'hui
            </span>
            <div style="font-size: 1.8rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 900; color: var(--eventia-navy, #000000);">
                <?php echo number_format($kpi_today, 0, ',', ' '); ?>
            </div>
        </div>

        <div style="background: var(--eventia-white, #ffffff); border: 1px solid var(--eventia-border, #E5E5E5); border-radius: 14px; padding: 1rem 1.25rem; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
            <span style="font-size: 0.74rem; font-weight: 800; color: var(--eventia-turquoise-dark, #FF4A0D); text-transform: uppercase; display: block; margin-bottom: 0.25rem;">
                Total Validations
            </span>
            <div style="font-size: 1.8rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 900; color: var(--eventia-navy, #000000);">
                <?php echo number_format($kpi_total, 0, ',', ' '); ?>
            </div>
        </div>
    </div>

    <!-- Filtres & Recherche Simple -->
    <div style="background: var(--eventia-white, #ffffff); border: 1px solid var(--eventia-border, #E5E5E5); border-radius: 14px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
        <form method="GET" action="historique.php" style="display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <select name="periode" onchange="this.form.submit()" style="padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.84rem; font-weight: 700; background: #ffffff; color: var(--eventia-navy, #000000); cursor: pointer;">
                <option value="toutes" <?php echo $filter_period === 'toutes' ? 'selected' : ''; ?>>Toutes les dates</option>
                <option value="aujourd_hui" <?php echo $filter_period === 'aujourd_hui' ? 'selected' : ''; ?>>Aujourd'hui</option>
                <option value="7_jours" <?php echo $filter_period === '7_jours' ? 'selected' : ''; ?>>7 derniers jours</option>
            </select>

            <div style="position: relative; flex: 1; min-width: 180px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--eventia-muted, #737373); font-size: 0.82rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Rechercher code, nom, événement..." style="width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem 0.5rem 2rem; border-radius: 8px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.84rem; background: #ffffff;">
            </div>

            <button type="submit" class="eventia-btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.84rem; font-weight: 700; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($filter_period !== 'toutes' || !empty($search_q)): ?>
                <a href="historique.php" style="color: var(--eventia-danger, #000000); font-size: 0.82rem; font-weight: 600; text-decoration: underline; margin-left: 4px;">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tableau Simple et Lisible -->
    <div style="background: var(--eventia-white, #ffffff); border: 1px solid var(--eventia-border, #E5E5E5); border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--eventia-border, #E5E5E5); display: flex; justify-content: space-between; align-items: center;">
            <strong style="color: var(--eventia-navy, #000000); font-size: 0.95rem; font-family: var(--font-heading, 'Outfit', sans-serif);">
                Billets Compostés (<?php echo count($validated_tickets); ?>)
            </strong>
        </div>

        <?php if (count($validated_tickets) > 0): ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: #F5F5F5; border-bottom: 1px solid var(--eventia-border, #E5E5E5); color: var(--eventia-muted, #737373); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">
                            <th style="padding: 0.75rem 1.25rem;">Code Billet</th>
                            <th style="padding: 0.75rem 1rem;">Événement</th>
                            <th style="padding: 0.75rem 1rem;">Catégorie</th>
                            <th style="padding: 0.75rem 1rem;">Titulaire</th>
                            <th style="padding: 0.75rem 1.25rem; text-align: right;">Heure de Scan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($validated_tickets as $tk): ?>
                            <tr style="border-bottom: 1px solid var(--eventia-border, #E5E5E5);">
                                <td style="padding: 0.85rem 1.25rem;">
                                    <strong style="font-family: monospace; font-size: 0.92rem; color: var(--eventia-navy, #000000); font-weight: 800; background: #F5F5F5; padding: 3px 7px; border-radius: 6px;">
                                        <?php echo htmlspecialchars($tk['code_unique']); ?>
                                    </strong>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <strong style="color: var(--eventia-navy, #000000); display: block; font-size: 0.88rem;">
                                        <?php echo htmlspecialchars($tk['event_name']); ?>
                                    </strong>
                                    <?php if (!empty($tk['lieu'])): ?>
                                        <small style="color: var(--eventia-muted, #737373); font-size: 0.74rem;"><?php echo htmlspecialchars($tk['lieu']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <span style="background: #FFF2ED; color: #FF4A0D; padding: 3px 8px; border-radius: 6px; font-weight: 800; font-size: 0.76rem; display: inline-block;">
                                        <?php echo htmlspecialchars($tk['type_ticket']); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <span style="color: var(--eventia-navy, #000000); font-weight: 600; display: block;">
                                        <?php echo htmlspecialchars($tk['client_nom'] ?: 'Client'); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.85rem 1.25rem; text-align: right;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px; color: #FF4A0D; font-weight: 800; font-size: 0.8rem; background: #FFF2ED; padding: 3px 8px; border-radius: 999px;">
                                        <i class="fa-solid fa-check"></i> <?php echo date('d/m/Y H:i', strtotime($tk['date_utilisation'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--eventia-muted, #737373);">
                <i class="fa-solid fa-ticket" style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1rem; color: var(--eventia-navy, #000000); margin-bottom: 0.25rem;">Aucun billet trouvé</strong>
                <p style="font-size: 0.84rem; margin: 0 0 1rem;">Aucune validation enregistrée pour les filtres sélectionnés.</p>
                <a href="verification.php" class="eventia-btn-primary" style="display: inline-flex; text-decoration: none; font-size: 0.85rem; font-weight: 800; border-radius: 8px;">
                    <i class="fa-solid fa-camera"></i> Ouvrir le Scanner
                </a>
            </div>
        <?php endif; ?>
    </div>

</main>

<?php include 'footer.php'; ?>