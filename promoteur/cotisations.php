<?php
// ==============================================================================
// GESTION DES COTISATIONS / CAMPAGNES DE CONTRIBUTION (promoteur/cotisations.php)
// Le promoteur crée ses campagnes de cotisation (avec montant à atteindre),
// suit l'avancement (barre de progression) et les contributions reçues
// ==============================================================================

$page_title = "Mes Cotisations - Espace Promoteur";
include 'header.php';

$message = "";
$msg_type = "";

// Traitement du formulaire de création de campagne
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_campagne') {
    $titre            = trim($_POST['titre'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $montant_objectif = filter_input(INPUT_POST, 'montant_objectif', FILTER_VALIDATE_FLOAT);
    $date_limite      = trim($_POST['date_limite'] ?? '');

    if ($titre === '' || !$montant_objectif || $montant_objectif < 1000) {
        $message = "Veuillez renseigner un titre et un montant à atteindre valide (minimum 1 000 FCFA).";
        $msg_type = "error";
    } else {
        // Upload de l'image de la campagne (optionnel)
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

        try {
            $stmt = $pdo->prepare("
                INSERT INTO cotisation_campagnes (user_id, titre, description, image, montant_objectif, date_limite, statut)
                VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $titre,
                $description ?: null,
                $image_name,
                $montant_objectif,
                $date_limite ?: null
            ]);
            $message = "La campagne « " . $titre . " » a été soumise. Elle sera visible par les visiteurs dès sa validation par l'administration.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur base de données : exécutez config/migration-cotisations-campagnes.sql pour créer les tables.";
            $msg_type = "error";
        }
    }
}

// Campagnes du promoteur avec montants collectés
$mes_campagnes = [];
$contributions_par_campagne = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct
                          WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $mes_campagnes = $stmt->fetchAll();

    // Contributions reçues par campagne
    $stmt_ct = $pdo->prepare("
        SELECT campagne_id, nom, telephone, montant, statut, created_at
        FROM cotisations
        WHERE campagne_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    foreach ($mes_campagnes as $camp) {
        $stmt_ct->execute([$camp['id']]);
        $contributions_par_campagne[$camp['id']] = $stmt_ct->fetchAll();
    }
} catch (PDOException $e) {
    $mes_campagnes = []; // Tables pas encore migrées
}
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="margin: 0; font-size: 1.6rem; color: var(--navy);"><i class="fa-solid fa-hand-holding-heart" style="color: var(--primary);"></i> Mes Cotisations</h1>
        <p style="color: var(--muted); margin: 0.3rem 0 0; font-size: 0.9rem;">
            Créez des campagnes de cotisation pour financer vos événements. Les visiteurs contribuent directement depuis la billetterie.
        </p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert <?php echo ($msg_type === 'success') ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom: 1.5rem;">
        <i class="fa-solid fa-circle-<?php echo ($msg_type === 'success') ? 'check' : 'exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">

    <!-- ===== Formulaire de création d'une campagne ===== -->
    <div class="card" style="padding: 1.5rem;">
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
                    <textarea id="camp_description" name="description" rows="3" placeholder="Expliquez le projet financé et l'usage des contributions..." style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px; font-family: inherit;"></textarea>
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


    <!-- ===== Liste des campagnes du promoteur ===== -->
    <div class="card" style="padding: 1.5rem;">
        <h2 style="margin: 0 0 1rem; font-size: 1.15rem; color: var(--navy);">
            <i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Mes campagnes (<?php echo count($mes_campagnes); ?>)
        </h2>

        <?php if (empty($mes_campagnes)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem; color: var(--muted);">
                <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: var(--line); display: block; margin-bottom: 0.75rem;"></i>
                <p style="margin: 0;">Vous n'avez créé aucune campagne de cotisation pour le moment.</p>
            </div>
        <?php else: ?>
            <?php foreach ($mes_campagnes as $camp): ?>
                <?php
                $collecte         = (float)$camp['montant_collecte'];
                $objectif         = (float)$camp['montant_objectif'];
                $pct_collecte     = ($objectif > 0) ? min(100, round(($collecte / $objectif) * 100)) : 0;
                $objectif_atteint = ($objectif > 0 && $collecte >= $objectif);
                ?>
                <div style="border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 240px;">
                            <strong style="color: var(--navy); font-size: 1.05rem;"><?php echo htmlspecialchars($camp['titre']); ?></strong>
                            <small style="color: var(--muted); display: block; margin-top: 2px;">
                                <i class="fa-regular fa-calendar"></i>
                                <?php echo $camp['date_limite'] ? 'Jusqu\'au ' . date('d/m/Y', strtotime($camp['date_limite'])) : 'Sans limite'; ?>
                                · <?php
                                    $statuts_labels = ['en_attente' => 'En attente de validation', 'active' => 'Active', 'terminee' => 'Terminée', 'annulee' => 'Refusée'];
                                    echo $statuts_labels[$camp['statut']] ?? htmlspecialchars($camp['statut']);
                                  ?>
                            </small>
                            <?php if ($camp['statut'] === 'en_attente'): ?>
                                <small style="color: #92400e; display: block; margin-top: 4px;">
                                    <i class="fa-solid fa-hourglass-half"></i> En attente de validation par l'administration
                                </small>
                            <?php elseif (!empty($camp['commentaire_admin'])): ?>
                                <small style="color: #b91c1c; display: block; margin-top: 4px;">
                                    <i class="fa-solid fa-comment-dots"></i> Motif : <?php echo htmlspecialchars($camp['commentaire_admin']); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div style="text-align: right;">
                            <strong style="color: var(--primary); font-size: 1.1rem;"><?php echo number_format($collecte, 0, ',', ' '); ?> FCFA</strong>
                            <small style="color: var(--muted); display: block;">sur <?php echo number_format($objectif, 0, ',', ' '); ?> FCFA</small>
                        </div>
                    </div>

                    <!-- Barre de progression -->
                    <div style="height: 10px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin: 0.75rem 0 0.35rem;">
                        <div style="height: 100%; width: <?php echo $pct_collecte; ?>%; background: <?php echo $objectif_atteint ? '#16a34a' : 'linear-gradient(90deg, var(--primary), var(--primary-light))'; ?>; border-radius: 999px; transition: width 0.4s ease;"></div>
                    </div>
                    <small style="color: var(--muted); font-weight: 600;">
                        <?php echo $pct_collecte; ?>% de l'objectif · <?php echo (int)$camp['nb_contributeurs']; ?> contributeur(s)
                        <?php if ($objectif_atteint): ?>
                            <span style="color: #16a34a;"><i class="fa-solid fa-circle-check"></i> Objectif atteint !</span>
                        <?php endif; ?>
                    </small>


                    <!-- Dernières contributions reçues -->
                    <?php if (!empty($contributions_par_campagne[$camp['id']])): ?>
                        <details style="margin-top: 0.75rem;">
                            <summary style="cursor: pointer; font-weight: 700; color: var(--primary); font-size: 0.85rem;">
                                Voir les contributions reçues (<?php echo count($contributions_par_campagne[$camp['id']]); ?>)
                            </summary>
                            <table style="width: 100%; border-collapse: collapse; margin-top: 0.6rem; font-size: 0.85rem;">
                                <thead>
                                    <tr style="text-align: left; color: var(--muted); border-bottom: 1px solid var(--line);">
                                        <th style="padding: 0.4rem 0;">Contributeur</th>
                                        <th style="padding: 0.4rem;">Téléphone</th>
                                        <th style="padding: 0.4rem;">Montant</th>
                                        <th style="padding: 0.4rem;">Statut</th>
                                        <th style="padding: 0.4rem;">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contributions_par_campagne[$camp['id']] as $ct): ?>
                                        <tr style="border-bottom: 1px solid var(--line-light);">
                                            <td style="padding: 0.45rem 0; font-weight: 600;"><?php echo htmlspecialchars($ct['nom']); ?></td>
                                            <td style="padding: 0.45rem;"><?php echo htmlspecialchars($ct['telephone'] ?? '—'); ?></td>
                                            <td style="padding: 0.45rem; font-weight: 700; color: var(--primary);"><?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F</td>
                                            <td style="padding: 0.45rem;">
                                                <?php if ($ct['statut'] === 'payee'): ?>
                                                    <span style="color: #16a34a; font-weight: 700;">Payée</span>
                                                <?php elseif ($ct['statut'] === 'annule'): ?>
                                                    <span style="color: #b91c1c; font-weight: 700;">Annulée</span>
                                                <?php else: ?>
                                                    <span style="color: #b45309; font-weight: 700;">En attente</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 0.45rem;"><?php echo date('d/m/Y H:i', strtotime($ct['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php include 'footer.php'; ?>