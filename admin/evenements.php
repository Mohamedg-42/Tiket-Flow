<?php
// ==============================================================================
// GESTION & FILTRAGE DES ÉVÉNEMENTS (admin/evenements.php)
// Design Dashboard Pro - Filtres en haut, KPIs dynamiques et supervision globale
// ==============================================================================

$admin_page_title = "Gestion des Événements - Administration";
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

// Catégories disponibles pour le filtre
$categories_list = $pdo->query("SELECT DISTINCT categorie FROM events WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion des Événements
            </h1>
            <p>Supervisez, filtrez et gérez l'ensemble des événements et spectacles de la plateforme.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <a href="creer-evenement.php" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.2rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-plus"></i> Créer un Événement
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT (AU-DESSUS DES KPIS)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUTS -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $status_f === '' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $status_f === '' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous (<?php echo $total_events; ?>)
            </a>

            <a href="?statut=actif&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $status_f === 'actif' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-bolt" style="color: #10b981;"></i> Actifs (<?php echo $active_events; ?>)
            </a>

            <a href="?statut=termine&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $status_f === 'termine' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-flag-checkered" style="color: #64748b;"></i> Terminés (<?php echo $ended_events; ?>)
            </a>

            <a href="?statut=en_attente&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $status_f === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-clock" style="color: #f59e0b;"></i> En attente
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS CATÉGORIE, PÉRIODE & RECHERCHE -->
        <form method="GET" action="evenements.php" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($status_f); ?>">

            <!-- Sélecteur Catégorie -->
            <select name="categorie" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer; max-width: 160px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($categories_list as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Sélecteur Période -->
            <select name="periode" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="" <?php echo ($period === '') ? 'selected' : ''; ?>>Toutes les dates</option>
                <option value="a_venir" <?php echo ($period === 'a_venir') ? 'selected' : ''; ?>>À venir</option>
                <option value="aujourdhui" <?php echo ($period === 'aujourdhui') ? 'selected' : ''; ?>>Aujourd'hui</option>
                <option value="passe" <?php echo ($period === 'passe') ? 'selected' : ''; ?>>Passés</option>
            </select>

            <!-- Champ Recherche -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nom, salle, promoteur..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($status_f !== '' || $category !== '' || $period !== '' || $search !== ''): ?>
                <a href="evenements.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. KPIS DE SYNTHÈSE (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: clamp(0.75rem, 2vw, 1rem); margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Événements Filtrés</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo count($events); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Sur un total de <?php echo $total_events; ?> créés</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Événements Actifs</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bolt"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo $active_events; ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Visibles en billetterie publique</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Événements Clos</span>
                <span style="background: #f1f5f9; color: #64748b; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-flag-checkered"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #64748b;"><?php echo $ended_events; ?></div>
            <small style="color: #64748b; font-size: 0.75rem;">Éditions archivées ou terminées</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ca8a04; text-transform: uppercase;">Billets Émis</span>
                <span style="background: #fef9c3; color: #ca8a04; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo number_format($total_tickets, 0, ',', ' '); ?></div>
            <small style="color: #ca8a04; font-size: 0.75rem;">Total des billets vendus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES ÉVÉNEMENTS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Événements (<?php echo count($events); ?>)
            </h3>
        </div>

        <?php if (empty($events)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun événement ne correspond à vos critères de recherche.<br>
                <a href="evenements.php" style="color: var(--dash-primary); font-weight: 700; text-decoration: underline; margin-top: 0.5rem; display: inline-block;">Réinitialiser les filtres</a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Affiche</th>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Date & Heure</th>
                            <th>Lieu</th>
                            <th>Ventes / Capacité</th>
                            <th>Recette</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev): ?>
                            <?php
                            $taux = ($ev['total_places'] > 0) ? round(($ev['total_vendus'] / $ev['total_places']) * 100) : 0;
                            
                            // Image
                            $img_src = 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=150&q=80';
                            if (!empty($ev['image'])) {
                                if (strpos($ev['image'], 'http') === 0) {
                                    $img_src = htmlspecialchars($ev['image']);
                                } elseif (file_exists('../uploads/events/' . $ev['image'])) {
                                    $img_src = '../uploads/events/' . htmlspecialchars($ev['image']);
                                }
                            }

                            // Statut badge
                            $statut_badge = [
                                'actif' => ['Actif', '#dcfce7', '#166534'],
                                'termine' => ['Terminé', '#f1f5f9', '#475569'],
                                'annule' => ['Annulé', '#fee2e2', '#b91c1c'],
                                'en_attente' => ['En attente', '#fef3c7', '#92400e']
                            ];
                            [$st_label, $st_bg, $st_fg] = $statut_badge[$ev['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td>
                                    <img src="<?php echo $img_src; ?>" alt="Affiche" style="width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid var(--dash-border);">
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($ev['nom']); ?>
                                    </strong>
                                    <span style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <i class="fa-solid fa-tag" style="color: var(--dash-primary);"></i> <?php echo htmlspecialchars($ev['categorie']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text);">
                                        <?php echo htmlspecialchars($ev['promoter_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; color: var(--dash-text); display: block;">
                                        <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo date('H\hi', strtotime($ev['heure'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <i class="fa-solid fa-location-dot" style="color: #ef4444;"></i> <?php echo htmlspecialchars(mb_strimwidth($ev['lieu'], 0, 20, '...')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.82rem; font-weight: 700; color: var(--dash-text);">
                                        <?php echo $ev['total_vendus']; ?> / <?php echo $ev['total_places']; ?> <small style="color: var(--dash-muted);">(<?php echo $taux; ?>%)</small>
                                    </div>
                                    <div style="background: #e2e8f0; height: 5px; border-radius: 999px; overflow: hidden; margin-top: 4px; width: 85px;">
                                        <div style="height: 100%; width: <?php echo min(100, $taux); ?>%; background: #0284c7; border-radius: 999px;"></div>
                                    </div>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.9rem;">
                                        <?php echo number_format((float)$ev['total_recette'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="new_status" onchange="this.form.submit()" style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; border: 1px solid rgba(0,0,0,0.06); padding: 3px 8px; border-radius: 6px; font-size: 0.76rem; font-weight: 800; cursor: pointer; outline: none;">
                                            <option value="actif" <?php echo $ev['statut'] === 'actif' ? 'selected' : ''; ?>>🟢 Actif</option>
                                            <option value="termine" <?php echo $ev['statut'] === 'termine' ? 'selected' : ''; ?>>🏁 Terminé</option>
                                            <option value="annule" <?php echo $ev['statut'] === 'annule' ? 'selected' : ''; ?>>🔴 Annulé</option>
                                            <option value="en_attente" <?php echo $ev['statut'] === 'en_attente' ? 'selected' : ''; ?>>🟡 En attente</option>
                                        </select>
                                    </form>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 5px;">
                                        <a href="../client/accueil.php" target="_blank" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.74rem;" title="Voir la vitrine publique">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        <a href="modifier-evenement.php?id=<?php echo $ev['id']; ?>" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.74rem;" title="Modifier cet événement">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="supprimer-evenement.php?id=<?php echo $ev['id']; ?>" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.74rem; color: #ef4444;" onclick="return confirm('Confirmez-vous la suppression définitive de cet événement ?');" title="Supprimer">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
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
