<?php
// ==============================================================================
// GESTION DES COTISATIONS & CAMPAGNES DE CONTRIBUTION (admin/cotisations.php)
// Design Dashboard Pro - Contrôle, arbitrage et validation des fonds de solidarité
// ==============================================================================

$admin_page_title = "Cotisations & Cagnottes - Administration";
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
                $message = "Veuillez renseigner un titre et un montant objectif valide (minimum 1 000 FCFA).";
                $msg_type = "error";
            } else {
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
                $message = "La campagne « " . htmlspecialchars($titre) . " » a été créée et mise en ligne avec succès.";
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
        $message = "Erreur base de données : " . $e->getMessage();
        $msg_type = "error";
    }
}

// Filtres
$statut_f = $_GET['statut'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

// Données : campagnes + stats + contributions
$campagnes = [];
$toutes_contributions = [];
try {
    $sql_c = "
        SELECT c.*, u.nom AS promoteur_nom, u.email as promoteur_email,
               COALESCE((SELECT SUM(ct.montant) FROM cotisations ct WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS montant_collecte,
               COALESCE((SELECT COUNT(*) FROM cotisations ct WHERE ct.campagne_id = c.id AND ct.statut IN ('en_attente', 'payee')), 0) AS nb_contributeurs
        FROM cotisation_campagnes c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE 1=1
    ";
    $params_c = [];

    if ($statut_f === 'active') {
        $sql_c .= " AND c.statut = 'active'";
    } elseif ($statut_f === 'terminee') {
        $sql_c .= " AND c.statut = 'terminee'";
    } elseif ($statut_f === 'annulee') {
        $sql_c .= " AND c.statut = 'annulee'";
    } elseif ($statut_f === 'en_attente') {
        $sql_c .= " AND c.statut = 'en_attente'";
    }

    if (!empty($search)) {
        $sql_c .= " AND (c.titre LIKE ? OR u.nom LIKE ?)";
        $params_c[] = "%$search%";
        $params_c[] = "%$search%";
    }

    $sql_c .= " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC";
    $stmt_c = $pdo->prepare($sql_c);
    $stmt_c->execute($params_c);
    $campagnes = $stmt_c->fetchAll();

    $toutes_contributions = $pdo->query("
        SELECT ct.*, c.titre AS campagne_titre
        FROM cotisations ct
        LEFT JOIN cotisation_campagnes c ON c.id = ct.campagne_id
        ORDER BY ct.created_at DESC
        LIMIT 40
    ")->fetchAll();

    $tot_campagnes = (int)$pdo->query("SELECT COUNT(*) FROM cotisation_campagnes")->fetchColumn();
    $tot_objectif  = (float)$pdo->query("SELECT COALESCE(SUM(montant_objectif), 0) FROM cotisation_campagnes")->fetchColumn();
    $tot_collecte   = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM cotisations WHERE statut IN ('en_attente', 'payee')")->fetchColumn();
    $tot_donateurs = (int)$pdo->query("SELECT COUNT(DISTINCT email) FROM cotisations WHERE statut = 'payee'")->fetchColumn();

} catch (PDOException $e) {
    $campagnes = [];
    $toutes_contributions = [];
    $tot_campagnes = $tot_objectif = $tot_collecte = $tot_donateurs = 0;
}
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-hand-holding-heart" style="color: #ec4899; font-size: 1.55rem;"></i>
                Gestion des Cotisations & Cagnottes
            </h1>
            <p>Supervisez les collectes de fonds solidaires, validez les campagnes et suivez les dons reçus.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <button type="button" onclick="document.getElementById('formNewCampagne').scrollIntoView({behavior: 'smooth'})" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-plus"></i> Nouvelle Campagne Admin
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES BIEN VISIBLES)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #2dd4bf;' : ''; ?>"></i> Toutes (<?php echo $tot_campagnes; ?>)
            </a>

            <a href="?statut=active&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'active' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-bolt" style="color: #10b981;"></i> En cours (Actives)
            </a>

            <a href="?statut=terminee&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'terminee' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-flag-checkered" style="color: #64748b;"></i> Clôturées
            </a>

            <a href="?statut=en_attente&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-clock" style="color: #f59e0b;"></i> En attente
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="cotisations.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Titre ou promoteur..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="cotisations.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE COTISATION (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Total Collecté</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_collecte, 0, ',', ' '); ?> F</div>
            <small style="color: #16a34a; font-size: 0.75rem;">Dons reçus via Mobile Money</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Objectifs Cumulés</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bullseye"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($tot_objectif, 0, ',', ' '); ?> F</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Budget total recherché sur les campagnes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ec4899; text-transform: uppercase;">Campagnes Publiées</span>
                <span style="background: #fdf2f8; color: #ec4899; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-hand-holding-heart"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ec4899;"><?php echo $tot_campagnes; ?></div>
            <small style="color: #ec4899; font-size: 0.75rem;">Incluses dans l'espace Cotisations</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Donateurs Solidaires</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-users"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $tot_donateurs; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Contributeurs distincts enregistrés</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES CAMPAGNES DE COTISATION
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Campagnes (<?php echo count($campagnes); ?>)
            </h3>
        </div>

        <?php if (empty($campagnes)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-hand-holding-heart" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucune campagne de cotisation dans cette catégorie.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Campagne</th>
                            <th>Initiateur</th>
                            <th>Collecté / Objectif</th>
                            <th>Avancement</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Actions Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campagnes as $camp): ?>
                            <?php
                            $col = (float)$camp['montant_collecte'];
                            $obj = (float)$camp['montant_objectif'];
                            $pct = ($obj > 0) ? min(100, round(($col / $obj) * 100)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($camp['titre']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo $camp['date_limite'] ? '<i class="fa-regular fa-calendar"></i> Jusqu\'au ' . date('d/m/Y', strtotime($camp['date_limite'])) : 'Durée illimitée'; ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem;">
                                        <?php echo $camp['promoteur_nom'] ? htmlspecialchars($camp['promoteur_nom']) : '<span style="color: #0d9488; font-weight: 700;">Plateforme (Admin)</span>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #16a34a; font-size: 0.92rem;">
                                        <?php echo number_format($col, 0, ',', ' '); ?> F
                                    </strong>
                                    <small style="color: var(--dash-muted); display: block; font-size: 0.74rem;">
                                        sur <?php echo number_format($obj, 0, ',', ' '); ?> F
                                    </small>
                                </td>
                                <td>
                                    <div style="font-size: 0.8rem; font-weight: 700; color: var(--dash-text);">
                                        <?php echo $pct; ?>% <small style="color: var(--dash-muted); font-weight: normal;">(<?php echo (int)$camp['nb_contributeurs']; ?> donateurs)</small>
                                    </div>
                                    <div style="background: #e2e8f0; height: 6px; border-radius: 999px; overflow: hidden; margin-top: 4px; width: 110px;">
                                        <div style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, #ec4899, #8b5cf6); border-radius: 999px;"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($camp['statut'] === 'active'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟢 Active</span>
                                    <?php elseif ($camp['statut'] === 'terminee'): ?>
                                        <span style="background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🏁 Terminée</span>
                                    <?php elseif ($camp['statut'] === 'en_attente'): ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟡 En attente</span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🔴 Annulée</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 4px; margin: 0;">
                                        <input type="hidden" name="action" value="changer_statut">
                                        <input type="hidden" name="campagne_id" value="<?php echo (int)$camp['id']; ?>">
                                        <?php if ($camp['statut'] !== 'active'): ?>
                                            <button type="submit" name="statut" value="active" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #dcfce7; color: #166534;" title="Activer / Publier">
                                                <i class="fa-solid fa-play"></i> Activer
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($camp['statut'] !== 'terminee'): ?>
                                            <button type="submit" name="statut" value="terminee" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #f1f5f9; color: #475569;" title="Marquer comme Terminée">
                                                <i class="fa-solid fa-flag-checkered"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($camp['statut'] !== 'annulee'): ?>
                                            <button type="submit" name="statut" value="annulee" class="dash-btn-action" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #fee2e2; color: #ef4444;" title="Annuler">
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

    <!-- ==============================================================================
         5. FORMULAIRE DE CRÉATION DE NOUVELLE CAMPAGNE (ESPACE ADMIN)
         ============================================================================== -->
    <div class="dash-card" id="formNewCampagne" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-circle-plus" style="color: var(--dash-primary);"></i> Créer une Campagne Institutionnelle (Plateforme)
            </h3>
        </div>

        <form method="POST" action="cotisations.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="creer_campagne">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Titre de la campagne *</label>
                    <input type="text" name="titre" required placeholder="Ex: Soutien aux Artistes et Festivals Nationaux 2026" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Description et Objectifs du Projet</label>
                    <textarea name="description" rows="3" placeholder="Présentez le projet d'intérêt général financé..." style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box;"></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Montant Objectif (FCFA) *</label>
                    <input type="number" name="montant_objectif" required min="1000" step="5000" placeholder="Ex: 5000000" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Date d'échéance (Optionnelle)</label>
                    <input type="date" name="date_limite" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.85rem; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--dash-text);">Bannière / Visuel (Optionnel)</label>
                    <input type="file" name="image" accept="image/png, image/jpeg, image/webp" style="width: 100%; padding: 0.45rem; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.82rem; box-sizing: border-box;">
                </div>
            </div>

            <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.65rem 1.4rem;">
                <i class="fa-solid fa-paper-plane"></i> Mettre en Ligne la Campagne
            </button>
        </form>
    </div>

    <!-- ==============================================================================
         6. DERNIÈRES CONTRIBUTIONS REÇUES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-users" style="color: var(--dash-primary);"></i> Contributions & Dons Reçus (Récents)
            </h3>
        </div>

        <?php if (empty($toutes_contributions)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--dash-muted);">
                Aucune contribution reçue pour l'instant.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Contributeur</th>
                            <th>Campagne</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toutes_contributions as $ct): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($ct['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($ct['telephone'] ?? $ct['email'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; color: var(--dash-text);">
                                        <?php echo $ct['campagne_titre'] ? htmlspecialchars($ct['campagne_titre']) : '<em>Contribution Générale</em>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #16a34a; font-size: 0.95rem;">
                                        <?php echo number_format((float)$ct['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($ct['statut'] === 'payee'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟢 Payée</span>
                                    <?php elseif ($ct['statut'] === 'annule'): ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🔴 Annulée</span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟡 En attente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo date('d/m/Y à H:i', strtotime($ct['created_at'])); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" action="cotisations.php" style="display: inline-flex; gap: 4px; margin: 0;">
                                        <input type="hidden" name="action" value="statut_cotisation">
                                        <input type="hidden" name="cotisation_id" value="<?php echo (int)$ct['id']; ?>">
                                        <?php if ($ct['statut'] !== 'payee'): ?>
                                            <button type="submit" name="statut" value="payee" class="dash-btn-action" style="padding: 0.3rem 0.6rem; font-size: 0.74rem; background: #dcfce7; color: #166534;" title="Valider comme Payée">
                                                <i class="fa-solid fa-check"></i> Valider
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($ct['statut'] !== 'annule'): ?>
                                            <button type="submit" name="statut" value="annule" class="dash-btn-action" style="padding: 0.3rem 0.6rem; font-size: 0.74rem; background: #fee2e2; color: #ef4444;" title="Annuler">
                                                <i class="fa-solid fa-xmark"></i>
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
</div>

<?php include 'footer.php'; ?>