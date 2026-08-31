<?php
// ==============================================================================
// GESTION DES COTISATIONS & CAMPAGNES DE CONTRIBUTION (admin/cotisations.php)
// L'admin crée des campagnes, gère leurs statuts et valide les contributions
// ==============================================================================

$page_title = "Cotisations & Contributions - Administration";
$admin_page_title = $page_title;
include 'header.php';

$message = "";
$msg_type = "";

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'creer_campagne') {
            $titre            = trim($_POST['titre'] ?? '');
            $description      = trim($_POST['description'] ?? '');
            $montant_objectif = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
            $date_limite      = trim($_POST['date_limite'] ?? '');

            if ($titre === '' || !$montant_objectif || $montant_objectif < 1000) {
                $message = "Veuillez renseigner un titre et un montant à atteindre valide (minimum 1 000 FCFA).";
                $msg_type = "error";
            } else {
                // Upload de l'image (optionnel)
                $image_name = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($ext, $allowed, true)) {
                        $upload_events = '../uploads/events/';
                        if (!is_dir($upload_events)) {
                            mkdir($upload_events, 0777, true);
                        }
                        $image_name = 'campagne_' . uniqid() . '.' . $ext;
                        move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image_name);
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut)
                    VALUES (NULL, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$titre, $description ?: null, $image_name, $montant_objectif, $date_limite ?: null]);
                $message = "La campagne « " . $titre . " » a été créée avec succès.";
                $msg_type = "success";
            }

        } elseif ($action === 'changer_statut') {
            $campagne_id = (int)($_POST['campagne_id'] ?? 0);
            $statut = $_POST['statut'] ?? '';
            if (in_array($statut, ['active', 'terminee', 'annulee'], true) && $campagne_id > 0) {
                $stmt = $pdo->prepare("UPDATE cotisation_campagnes SET statut = ? WHERE id = ?");
                $stmt->execute([$statut, $campagne_id]);
                $message = "Le statut de la campagne a été mis à jour.";
                $msg_type = "success";
            }

        } elseif ($action === 'statut_cotisation') {
            $cotisation_id = (int)($_POST['cotisation_id'] ?? 0);
            $statut = $_POST['statut'] ?? '';
            if (in_array($statut, ['payee', 'annule', 'en_attente'], true) && $cotisation_id > 0) {
                $stmt = $pdo->prepare("UPDATE cotisations SET statut = ? WHERE id = ?");
                $stmt->execute([$statut, $cotisation_id]);
                $message = "Le statut de la contribution a été mis à jour.";
                $msg_type = "success";
            }
        }
    } catch (PDOException $e) {
        $message = "Erreur base de données : exécutez config/migration-cotisations-campagnes.sql pour créer les tables.";
        $msg_type = "error";
    }
}

// Données : campagnes + stats + contributions
$campagnes = [];
$toutes_contributions = [];
$stats = ['nb_campagnes' => 0, 'total_objectif' => 0, 'total_collecte' => 0];
try {
    $campagnes = $pdo->query("
        SELECT c.*, u.nom AS promoteur_nom,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        LEFT JOIN users u ON u.id = c.user_id
        ORDER BY c.created_at DESC
    ")->fetchAll();

    $toutes_contributions = $pdo->query("
        SELECT ct.*, c.titre AS campagne_titre
        FROM cotisations ct
        LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
        ORDER BY ct.created_at DESC
        LIMIT 50
    ")->fetchAll();

    $stats['nb_campagnes']   = count($campagnes);
    $stats['total_objectif'] = array_sum(array_column($campagnes, 'montant_objectif'));
    $stats['total_collecte'] = array_sum(array_column($campagnes, 'montant_collecte'));
} catch (PDOException $e) {
    // Tables pas encore migrées
}
?>

<div class="page-header" style="margin-bottom: 1.5rem;">
    <h1 style="margin: 0; font-size: 1.6rem; color: var(--navy);"><i class="fa-solid fa-hand-holding-heart" style="color: var(--primary);"></i> Cotisations & Contributions</h1>
    <p style="color: var(--muted); margin: 0.3rem 0 0; font-size: 0.9rem;">
        Gérez les campagnes de cotisation créées par les promoteurs et validez les contributions reçues.
    </p>
</div>

<?php if (!empty($message)): ?>
    <div class="alert <?php echo ($msg_type === 'success') ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom: 1.5rem;">
        <i class="fa-solid fa-circle-<?php echo ($msg_type === 'success') ? 'check' : 'exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- ===== Statistiques ===== -->
<div class="stats-grid-compact">
    <div class="stat-card">
        <i class="fa-solid fa-bullhorn" style="color: var(--primary);"></i>
        <div>
            <strong style="font-size: 1.3rem; display: block;"><?php echo (int)$stats['nb_campagnes']; ?></strong>
            <small style="color: var(--muted);">Campagne(s) au total</small>
        </div>
    </div>
    <div class="stat-card">
        <i class="fa-solid fa-bullseye" style="color: #3b82f6;"></i>
        <div>
            <strong style="font-size: 1.3rem; display: block;"><?php echo number_format($stats['total_objectif'], 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Objectifs cumulés</small>
        </div>
    </div>
    <div class="stat-card">
        <i class="fa-solid fa-coins" style="color: #16a34a;"></i>
        <div>
            <strong style="font-size: 1.3rem; display: block;"><?php echo number_format($stats['total_collecte'], 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Total collecté</small>
        </div>
    </div>
</div>

<!-- ===== Création d'une campagne par l'admin ===== -->
<div class="card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);">
        <i class="fa-solid fa-circle-plus" style="color: var(--primary);"></i> Créer une campagne de cotisation
    </h2>

    <form method="POST" action="cotisations.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="creer_campagne">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="camp_titre">Titre de la campagne *</label>
                <input type="text" id="camp_titre" name="titre" required placeholder="Ex: Festival Nuits d'Abidjan 2026" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="camp_description">Description</label>
                <textarea id="camp_description" name="description" rows="3" placeholder="Expliquez le projet financé..." style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px; font-family: inherit;"></textarea>
            </div>

            <div class="form-group">
                <label for="camp_objectif">Montant à atteindre (FCFA) *</label>
                <input type="number" id="camp_objectif" name="montant_objectif" required min="1000" step="1000" placeholder="Ex: 2000000" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>

            <div class="form-group">
                <label for="camp_date_limite">Date limite (optionnelle)</label>
                <input type="date" id="camp_date_limite" name="date_limite" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>

            <div class="form-group">
                <label for="camp_image">Image de la campagne (optionnelle)</label>
                <input type="file" id="camp_image" name="image" accept="image/png, image/jpeg, image/webp" style="width: 100%; padding: 0.45rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>
        </div>

        <button type="submit" class="btn-submit" style="margin-top: 0.5rem; width: auto; padding: 0.75rem 1.5rem;">
            <i class="fa-solid fa-paper-plane"></i> Créer la campagne
        </button>
    </form>
</div>

<!-- ===== Liste des campagnes ===== -->
<div class="card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);">
        <i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Campagnes de cotisation
    </h2>

    <?php if (empty($campagnes)): ?>
        <p style="color: var(--muted); text-align: center; padding: 2rem 0; margin: 0;">Aucune campagne pour le moment.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem; min-width: 720px;">
                <thead>
                    <tr style="text-align: left; color: var(--muted); border-bottom: 2px solid var(--line);">
                        <th style="padding: 0.6rem 0.5rem;">Campagne</th>
                        <th style="padding: 0.6rem 0.5rem;">Promoteur</th>
                        <th style="padding: 0.6rem 0.5rem;">Avancement</th>
                        <th style="padding: 0.6rem 0.5rem;">Statut</th>
                        <th style="padding: 0.6rem 0.5rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campagnes as $camp): ?>
                        <?php
                        $collecte     = (float)$camp['montant_collecte'];
                        $objectif     = (float)$camp['montant_objectif'];
                        $pct_collecte = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100)) : 0;
                        ?>
                        <tr style="border-bottom: 1px solid var(--line-light);">
                            <td style="padding: 0.75rem 0.5rem;">
                                <strong style="color: var(--navy);"><?php echo htmlspecialchars($camp['titre']); ?></strong>
                                <small style="color: var(--muted); display: block;">
                                    Objectif : <?php echo number_format($objectif, 0, ',', ' '); ?> F
                                    <?php echo $camp['date_limite'] ? '· jusqu\'au ' . date('d/m/Y', strtotime($camp['date_limite'])) : ''; ?>
                                </small>
                            </td>
                            <td style="padding: 0.75rem 0.5rem;">
                                <?php echo $camp['promoteur_nom'] ? htmlspecialchars($camp['promoteur_nom']) : '<em style="color: var(--muted);">Administration</em>'; ?>
                            </td>
                            <td style="padding: 0.75rem 0.5rem; min-width: 180px;">
                                <div style="display: flex; justify-content: space-between; font-size: 0.76rem; font-weight: 700; margin-bottom: 3px;">
                                    <span style="color: var(--primary);"><?php echo number_format($collecte, 0, ',', ' '); ?> F</span>
                                    <span style="color: var(--muted);"><?php echo $pct_collecte; ?>%</span>
                                </div>
                                <div style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo $pct_collecte; ?>%; background: linear-gradient(90deg, var(--primary), var(--primary-light)); border-radius: 999px;"></div>
                                </div>
                                <small style="color: var(--muted);"><?php echo (int)$camp['nb_contributeurs']; ?> contributeur(s)</small>
                            </td>

                            <td style="padding: 0.75rem 0.5rem;">
                                <?php if ($camp['statut'] === 'active'): ?>
                                    <span style="color: #16a34a; font-weight: 700;">Active</span>
                                <?php elseif ($camp['statut'] === 'terminee'): ?>
                                    <span style="color: #64748b; font-weight: 700;">Terminée</span>
                                <?php else: ?>
                                    <span style="color: #b91c1c; font-weight: 700;">Annulée</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.75rem 0.5rem;">
                                <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 0.35rem;">
                                    <input type="hidden" name="action" value="changer_statut">
                                    <input type="hidden" name="campagne_id" value="<?php echo (int)$camp['id']; ?>">
                                    <?php if ($camp['statut'] !== 'active'): ?>
                                        <button type="submit" name="statut" value="active" style="background: #dcfce7; color: #166534; border: 0; border-radius: 6px; padding: 0.4rem 0.6rem; cursor: pointer; font-weight: 700;" title="Activer">
                                            <i class="fa-solid fa-play"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($camp['statut'] !== 'terminee'): ?>
                                        <button type="submit" name="statut" value="terminee" style="background: #e2e8f0; color: #475569; border: 0; border-radius: 6px; padding: 0.4rem 0.6rem; cursor: pointer; font-weight: 700;" title="Terminer">
                                            <i class="fa-solid fa-flag-checkered"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($camp['statut'] !== 'annulee'): ?>
                                        <button type="submit" name="statut" value="annulee" style="background: #fee2e2; color: #b91c1c; border: 0; border-radius: 6px; padding: 0.4rem 0.6rem; cursor: pointer; font-weight: 700;" title="Annuler">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>



<!-- ===== Contributions reçues ===== -->
<div class="card" style="padding: 1.5rem;">
    <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);">
        <i class="fa-solid fa-users" style="color: var(--primary);"></i> Contributions reçues (50 dernières)
    </h2>

    <?php if (empty($toutes_contributions)): ?>
        <p style="color: var(--muted); text-align: center; padding: 2rem 0; margin: 0;">Aucune contribution pour le moment.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem; min-width: 720px;">
                <thead>
                    <tr style="text-align: left; color: var(--muted); border-bottom: 2px solid var(--line);">
                        <th style="padding: 0.6rem 0.5rem;">Contributeur</th>
                        <th style="padding: 0.6rem 0.5rem;">Campagne</th>
                        <th style="padding: 0.6rem 0.5rem;">Montant</th>
                        <th style="padding: 0.6rem 0.5rem;">Statut</th>
                        <th style="padding: 0.6rem 0.5rem;">Date</th>
                        <th style="padding: 0.6rem 0.5rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toutes_contributions as $ct): ?>
                        <tr style="border-bottom: 1px solid var(--line-light);">
                            <td style="padding: 0.7rem 0.5rem;">
                                <strong><?php echo htmlspecialchars($ct['nom']); ?></strong>
                                <small style="color: var(--muted); display: block;"><?php echo htmlspecialchars($ct['telephone'] ?? $ct['email'] ?? '—'); ?></small>
                            </td>
                            <td style="padding: 0.7rem 0.5rem;">
                                <?php echo $ct['campagne_titre'] ? htmlspecialchars($ct['campagne_titre']) : '<em style="color: var(--muted);">Contribution générale</em>'; ?>
                            </td>
                            <td style="padding: 0.7rem 0.5rem; font-weight: 700; color: var(--primary);"><?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F</td>
                            <td style="padding: 0.7rem 0.5rem;">
                                <?php if ($ct['statut'] === 'payee'): ?>
                                    <span style="color: #16a34a; font-weight: 700;">Payée</span>
                                <?php elseif ($ct['statut'] === 'annule'): ?>
                                    <span style="color: #b91c1c; font-weight: 700;">Annulée</span>
                                <?php else: ?>
                                    <span style="color: #b45309; font-weight: 700;">En attente</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.7rem 0.5rem;"><?php echo date('d/m/Y H:i', strtotime($ct['created_at'])); ?></td>
                            <td style="padding: 0.7rem 0.5rem;">
                                <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 0.35rem;">
                                    <input type="hidden" name="action" value="statut_cotisation">
                                    <input type="hidden" name="cotisation_id" value="<?php echo (int)$ct['id']; ?>">
                                    <?php if ($ct['statut'] !== 'payee'): ?>
                                        <button type="submit" name="statut" value="payee" style="background: #dcfce7; color: #166534; border: 0; border-radius: 6px; padding: 0.4rem 0.6rem; cursor: pointer; font-weight: 700;" title="Marquer comme payée">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($ct['statut'] !== 'annule'): ?>
                                        <button type="submit" name="statut" value="annule" style="background: #fee2e2; color: #b91c1c; border: 0; border-radius: 6px; padding: 0.4rem 0.6rem; cursor: pointer; font-weight: 700;" title="Annuler">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>