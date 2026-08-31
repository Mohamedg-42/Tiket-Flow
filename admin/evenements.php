<?php
// ==============================================================================
// GESTION & FILTRAGE DES ÉVÉNEMENTS (admin/evenements.php)
// Filtres avancés (statut, catégorie, recherche), métriques et gestion complète
// ==============================================================================

$admin_page_title = "Gestion & Filtrage des Événements - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Action rapide : Changement direct de statut par l'admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ev_id      = (int)$_POST['event_id'];
    $new_status = $_POST['new_status'] ?? 'actif';

    if (in_array($new_status, ['actif', 'termine', 'annule', 'en_attente'], true)) {
        $stmt_status = $pdo->prepare("UPDATE events SET statut = ? WHERE id = ?");
        $stmt_status->execute([$new_status, $ev_id]);
        $message = "Le statut de l'événement a été mis à jour avec succès.";
        $msg_type = "success";
    }
}

// 2. Filtres et recherche
$search    = trim($_GET['q'] ?? '');
$status_f  = trim($_GET['statut'] ?? '');
$category  = trim($_GET['categorie'] ?? '');
$period    = trim($_GET['periode'] ?? '');

$sql = "
    SELECT e.*, 
           COALESCE(p.nom_commercial, u.nom, 'Administrateur') AS promoter_name,
           (SELECT COALESCE(SUM(tt.quantite_vendue), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_vendus,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_places,
           (SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.event_id = e.id AND t.statut != 'annule') AS total_recette
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN promoters p ON u.id = p.user_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (e.nom LIKE ? OR e.lieu LIKE ? OR p.nom_commercial LIKE ? OR u.nom LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_f) && in_array($status_f, ['actif', 'termine', 'annule', 'en_attente'], true)) {
    $sql .= " AND e.statut = ?";
    $params[] = $status_f;
}

if (!empty($category)) {
    $sql .= " AND e.categorie = ?";
    $params[] = $category;
}

if ($period === 'a_venir') {
    $sql .= " AND (e.date_evenement > CURDATE() OR (e.date_evenement = CURDATE() AND e.heure >= CURTIME()))";
} elseif ($period === 'passe') {
    $sql .= " AND (e.date_evenement < CURDATE() OR (e.date_evenement = CURDATE() AND e.heure < CURTIME()))";
} elseif ($period === 'aujourdhui') {
    $sql .= " AND e.date_evenement = CURDATE()";
}

$sql .= " ORDER BY e.date_evenement DESC, e.heure DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// 3. Calcul des compteurs globaux
$total_events   = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$active_events  = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE statut = 'actif'")->fetchColumn();
$ended_events   = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE statut = 'termine'")->fetchColumn();
$total_tickets  = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE statut != 'annule'")->fetchColumn();

// Récupération de la liste unique des catégories existantes
$categories_list = $pdo->query("SELECT DISTINCT categorie FROM events WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker"><i class="fa-solid fa-calendar-days"></i> Billetterie & Programmation</span>
        <h1>Gestion & Filtrage des Événements</h1>
        <p>Recherchez, filtrez par statut ou catégorie et gérez l'ensemble des événements de la plateforme.</p>
    </div>
    <a href="creer-evenement.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.75rem 1.4rem;">
        <i class="fa-solid fa-plus"></i> Créer un événement
    </a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom: 1.5rem;">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- 1. Cartes Récapitulatives -->
<div class="stats-grid">
    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-info">
            <span>Total Événements</span>
            <strong><?php echo $total_events; ?></strong>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #16a34a;">
        <div class="stat-icon icon-green"><i class="fa-solid fa-bolt"></i></div>
        <div class="stat-info">
            <span>Événements En Ligne</span>
            <strong style="color: #16a34a;"><?php echo $active_events; ?></strong>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #64748b;">
        <div class="stat-icon" style="background: #f1f5f9; color: #64748b;"><i class="fa-solid fa-flag-checkered"></i></div>
        <div class="stat-info">
            <span>Événements Terminés</span>
            <strong style="color: #64748b;"><?php echo $ended_events; ?></strong>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #f59e0b;">
        <div class="stat-icon icon-orange"><i class="fa-solid fa-ticket"></i></div>
        <div class="stat-info">
            <span>Billets Émis</span>
            <strong style="color: var(--navy);"><?php echo $total_tickets; ?></strong>
        </div>
    </div>
</div>

<!-- 2. Barre de Recherche et Filtres Multi-Critères -->
<div class="content-section" style="margin-bottom: 1.5rem; padding: 1.25rem 1.5rem;">
    <form method="GET" action="evenements.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto auto; gap: 0.85rem; align-items: end;">
        <!-- Recherche textuelle -->
        <div class="form-group" style="margin: 0;">
            <label for="q" style="font-size: 0.8rem;"><i class="fa-solid fa-magnifying-glass"></i> Mots-clés</label>
            <input type="text" id="q" name="q" placeholder="Nom, salle, promoteur..." value="<?php echo htmlspecialchars($search); ?>">
        </div>

        <!-- Filtre par Statut -->
        <div class="form-group" style="margin: 0;">
            <label for="statut" style="font-size: 0.8rem;"><i class="fa-solid fa-toggle-on"></i> Statut</label>
            <select name="statut" id="statut">
                <option value="">Tous les statuts</option>
                <option value="actif" <?php echo ($status_f === 'actif') ? 'selected' : ''; ?>>🟢 Actif (En ligne)</option>
                <option value="termine" <?php echo ($status_f === 'termine') ? 'selected' : ''; ?>>🏁 Terminé (Clos)</option>
                <option value="annule" <?php echo ($status_f === 'annule') ? 'selected' : ''; ?>>🔴 Annulé</option>
                <option value="en_attente" <?php echo ($status_f === 'en_attente') ? 'selected' : ''; ?>>🟡 En attente</option>
            </select>
        </div>

        <!-- Filtre par Catégorie -->
        <div class="form-group" style="margin: 0;">
            <label for="categorie" style="font-size: 0.8rem;"><i class="fa-solid fa-icons"></i> Catégorie</label>
            <select name="categorie" id="categorie">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categories_list as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filtre par Période -->
        <div class="form-group" style="margin: 0;">
            <label for="periode" style="font-size: 0.8rem;"><i class="fa-solid fa-calendar"></i> Période</label>
            <select name="periode" id="periode">
                <option value="">Toutes les dates</option>
                <option value="a_venir" <?php echo ($period === 'a_venir') ? 'selected' : ''; ?>>À venir</option>
                <option value="aujourdhui" <?php echo ($period === 'aujourdhui') ? 'selected' : ''; ?>>Aujourd'hui</option>
                <option value="passe" <?php echo ($period === 'passe') ? 'selected' : ''; ?>>Passés</option>
            </select>
        </div>

        <!-- Bouton Filtrer -->
        <button type="submit" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.4rem;">
            <i class="fa-solid fa-filter"></i> Filtrer
        </button>

        <!-- Réinitialiser si filtre actif -->
        <?php if (!empty($search) || !empty($status_f) || !empty($category) || !empty($period)): ?>
            <a href="evenements.php" class="btn-submit" style="background: transparent; color: var(--muted); border: 1px solid var(--line); width: auto; margin: 0; padding: 0.75rem 1rem; text-decoration: none;" title="Réinitialiser les filtres">
                <i class="fa-solid fa-xmark"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- 3. Tableau des événements -->
<div class="content-section">
    <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fa-solid fa-list-check"></i> Événements Correspondants (<?php echo count($events); ?>)</span>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th style="min-width: 260px;">Événement & Organisateur</th>
                    <th>Date & Heure</th>
                    <th>Lieu</th>
                    <th>Remplissage</th>
                    <th>Recettes Brutes</th>
                    <th>Statut</th>
                    <th style="text-align: right; min-width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($events) > 0): ?>
                    <?php foreach ($events as $ev): ?>
                        <tr>
                            <!-- 1. Événement, Catégorie et Promoteur -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.85rem;">
                                    <div style="width: 44px; height: 44px; border-radius: 8px; background: #0f172a; color: var(--primary); display: grid; place-items: center; font-size: 1.15rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-masks-theater"></i>
                                    </div>
                                    <div>
                                        <strong style="color: var(--navy); font-size: 0.95rem; display: block;">
                                            <?php echo htmlspecialchars($ev['nom']); ?>
                                        </strong>
                                        <small style="color: var(--muted); font-size: 0.78rem;">
                                            <span style="background: #e0f2fe; color: #0284c7; padding: 1px 5px; border-radius: 3px; font-weight: 700;"><?php echo htmlspecialchars($ev['categorie']); ?></span>
                                            · Org: <strong><?php echo htmlspecialchars($ev['promoter_name']); ?></strong>
                                        </small>
                                    </div>
                                </div>
                            </td>

                            <!-- 2. Date & Heure -->
                            <td>
                                <strong style="color: var(--navy); display: block; font-size: 0.88rem;">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary);"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                </strong>
                                <small style="color: var(--muted); font-size: 0.78rem;">
                                    <i class="fa-regular fa-clock"></i> <?php echo substr($ev['heure'], 0, 5); ?>
                                </small>
                            </td>

                            <!-- 3. Lieu -->
                            <td>
                                <span style="font-size: 0.86rem; color: var(--ink); display: flex; align-items: center; gap: 4px;">
                                    <i class="fa-solid fa-location-dot" style="color: #ef4444; font-size: 0.8rem;"></i>
                                    <?php echo htmlspecialchars($ev['lieu']); ?>
                                </span>
                            </td>

                            <!-- 4. Billets Vendus & Billets Restants -->
                            <td>
                                <?php 
                                $vendus   = (int)$ev['total_vendus'];
                                $total    = (int)$ev['total_places'];
                                $restants = max(0, $total - $vendus);
                                $pct      = ($total > 0) ? round(($vendus / $total) * 100) : 0;
                                ?>
                                <div style="display: flex; flex-direction: column; gap: 3px;">
                                    <div>
                                        <strong style="color: #0284c7; font-size: 0.88rem;">
                                            <i class="fa-solid fa-circle-check"></i> <?php echo $vendus; ?> vendu(s)
                                        </strong>
                                    </div>

                                    <div>
                                        <?php if ($total > 0 && $restants === 0): ?>
                                            <span style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 4px; padding: 1px 6px; font-weight: 800; font-size: 0.76rem;">
                                                <i class="fa-solid fa-ban"></i> Épuisé
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; border-radius: 4px; padding: 1px 6px; font-weight: 800; font-size: 0.76rem;">
                                                <i class="fa-solid fa-ticket"></i> <?php echo $restants; ?> restant(s)
                                            </span>
                                        <?php endif; ?>
                                        <small style="color: var(--muted); font-size: 0.76rem; margin-left: 2px;">/ <?php echo $total; ?> total</small>
                                    </div>

                                    <?php if ($total > 0): ?>
                                        <div style="background: #e2e8f0; height: 5px; border-radius: 3px; width: 100px; margin-top: 2px; overflow: hidden;">
                                            <div style="background: <?php echo ($pct >= 90) ? '#ef4444' : 'var(--primary)'; ?>; height: 100%; width: <?php echo min(100, $pct); ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 5. Recettes -->
                            <td>
                                <strong style="color: var(--primary-dark); font-size: 0.95rem; font-family: 'Outfit', sans-serif;">
                                    <?php echo number_format($ev['total_recette'], 0, ',', ' '); ?> FCFA
                                </strong>
                            </td>

                            <!-- 6. Statut & Sélecteur Rapide -->
                            <td>
                                <form method="POST" action="evenements.php" style="display: inline-block;">
                                    <input type="hidden" name="update_status" value="1">
                                    <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                    <select name="new_status" onchange="this.form.submit()" style="padding: 3px 8px; border-radius: 20px; font-size: 0.78rem; font-weight: 800; text-transform: uppercase; cursor: pointer; border: 1px solid var(--line); 
                                        background: <?php echo ($ev['statut'] === 'actif') ? '#dcfce7; color: #15803d;' : (($ev['statut'] === 'termine') ? '#f1f5f9; color: #64748b;' : '#fee2e2; color: #b91c1c;'); ?>">
                                        <option value="actif" <?php echo ($ev['statut'] === 'actif') ? 'selected' : ''; ?>>🟢 Actif</option>
                                        <option value="termine" <?php echo ($ev['statut'] === 'termine') ? 'selected' : ''; ?>>🏁 Terminé</option>
                                        <option value="annule" <?php echo ($ev['statut'] === 'annule') ? 'selected' : ''; ?>>🔴 Annulé</option>
                                        <option value="en_attente" <?php echo ($ev['statut'] === 'en_attente') ? 'selected' : ''; ?>>🟡 En attente</option>
                                    </select>
                                </form>
                            </td>

                            <!-- 7. Actions -->
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.4rem; justify-content: flex-end;">
                                    <a href="modifier-evenement.php?id=<?php echo $ev['id']; ?>" class="btn-submit" style="width: auto; padding: 0.4rem 0.65rem; font-size: 0.8rem; background: #0284c7; text-decoration: none;" title="Modifier cet événement">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="supprimer-evenement.php?id=<?php echo $ev['id']; ?>" class="btn-submit" style="width: auto; padding: 0.4rem 0.65rem; font-size: 0.8rem; background: #ef4444; text-decoration: none;" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet événement ?')" title="Supprimer">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 3rem 1rem;">
                            <i class="fa-regular fa-calendar-xmark" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.75rem; display: block;"></i>
                            Aucun événement ne correspond à vos critères de recherche ou de filtre.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>