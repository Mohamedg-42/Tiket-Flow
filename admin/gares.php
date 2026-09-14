<?php
// ==============================================================================
// GESTION DES GARES ROUTIÈRES / GUICHETS PHYSIQUES (admin/gares.php)
// Création des points de vente physiques, activation/suspension/arrêt des flux
// de vente, et rattachement des agents de guichet (comptes role=agent_gare).
// ==============================================================================

$admin_page_title = "Gares Routières - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Création d'une gare routière
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_station'])) {
    $nom = trim($_POST['nom'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $resp_nom = trim($_POST['responsable_nom'] ?? '');
    $resp_tel = trim($_POST['responsable_telephone'] ?? '');

    if (empty($nom)) {
        $message = "Le nom de la gare est obligatoire.";
        $msg_type = "error";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO stations (nom, ville, adresse, responsable_nom, responsable_telephone, statut)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$nom, $ville ?: null, $adresse ?: null, $resp_nom ?: null, $resp_tel ?: null]);
        $message = "Gare « " . htmlspecialchars($nom) . " » créée avec succès.";
        $msg_type = "success";
    }
}

// 2. Modification d'une gare
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_station'])) {
    $id = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $resp_nom = trim($_POST['responsable_nom'] ?? '');
    $resp_tel = trim($_POST['responsable_telephone'] ?? '');

    if ($id && !empty($nom)) {
        $stmt = $pdo->prepare("
            UPDATE stations SET nom = ?, ville = ?, adresse = ?, responsable_nom = ?, responsable_telephone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nom, $ville ?: null, $adresse ?: null, $resp_nom ?: null, $resp_tel ?: null, $id]);
        $message = "Gare mise à jour avec succès.";
        $msg_type = "success";
    }
}

// 3. Changement de statut (activer / suspendre / stopper)
if (isset($_GET['set_statut'])) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $new_statut = $_GET['set_statut'];
    if ($id && in_array($new_statut, ['active', 'suspendue', 'stoppee'], true)) {
        $pdo->prepare("UPDATE stations SET statut = ?, updated_at = NOW() WHERE id = ?")->execute([$new_statut, $id]);
        $message = "Statut de la gare mis à jour (" . $new_statut . ").";
        $msg_type = "success";
    }
}

// 4. Création d'un compte agent de guichet rattaché à une gare
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_agent'])) {
    $station_id = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['agent_nom'] ?? '');
    $prenom = trim($_POST['agent_prenom'] ?? '');
    $email = trim($_POST['agent_email'] ?? '');
    $telephone = trim($_POST['agent_telephone'] ?? '');
    $password = $_POST['agent_password'] ?? '';

    if (empty($nom) || empty($email) || empty($password) || !$station_id) {
        $message = "Veuillez renseigner tous les champs obligatoires de l'agent.";
        $msg_type = "error";
    } else {
        try {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (nom, prenom, email, telephone, password, role, station_id, statut, est_verifie)
                VALUES (?, ?, ?, ?, ?, 'agent_gare', ?, 'actif', 1)
            ");
            $stmt->execute([$nom, $prenom ?: null, $email, $telephone ?: '', $pass_hash, $station_id]);
            $message = "Agent « " . htmlspecialchars($nom) . " » créé et rattaché à la gare.";
            $msg_type = "success";
        } catch (PDOException $e) {
            $message = "Erreur lors de la création de l'agent (email déjà utilisé ?) : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// 5. Liste des gares avec statistiques
$stations = $pdo->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.role = 'agent_gare') AS nb_agents,
           (SELECT COUNT(*) FROM tickets t WHERE t.station_id = s.id AND t.canal = 'guichet') AS nb_tickets_vendus,
           (SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.station_id = s.id AND t.canal = 'guichet') AS montant_vendu
    FROM stations s
    ORDER BY s.created_at DESC
")->fetchAll();
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<div class="dash-container">
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-bus" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gares Routières & Guichets
            </h1>
            <p>Gérez vos points de vente physiques, activez ou coupez leurs flux de vente à tout moment, et suivez
                leurs performances séparément des ventes en ligne.</p>
        </div>
        <div class="dash-filter-bar">
            <a href="gares-ventes.php" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-chart-column" style="color: #FF4A0D;"></i>
                <span>Ventes & Clôtures</span>
            </a>
            <button type="button" class="dash-btn-action btn-primary" onclick="toggleAddModal(true)">
                <i class="fa-solid fa-plus"></i>
                <span>Nouvelle Gare</span>
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; color: #000000; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <div class="dash-card">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i>
                    Points de Vente Physiques
                </h3>
                <div class="dash-card-subtitle">Statut, agents rattachés et volume de ventes au guichet</div>
            </div>
            <span style="background: var(--dash-primary-light); color: var(--dash-primary); padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 800;">
                <?php echo count($stations); ?> gare(s)
            </span>
        </div>

        <div class="dash-table-wrapper">
            <table class="dash-pro-table">
                <thead>
                    <tr>
                        <th>Gare</th>
                        <th>Responsable</th>
                        <th>Agents</th>
                        <th>Billets vendus</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stations) > 0): ?>
                        <?php foreach ($stations as $s): ?>
                            <?php
                            $statut_colors = [
                                'active' => ['bg' => '#FFF2ED', 'tx' => '#FF4A0D', 'label' => 'Active'],
                                'suspendue' => ['bg' => '#F5F5F5', 'tx' => '#000000', 'label' => 'Suspendue'],
                                'stoppee' => ['bg' => '#F5F5F5', 'tx' => '#737373', 'label' => 'Stoppée'],
                            ];
                            $sc = $statut_colors[$s['statut']] ?? $statut_colors['active'];
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--dash-text);"><?php echo htmlspecialchars($s['nom']); ?></strong>
                                    <small style="display: block; color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($s['ville'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td style="font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($s['responsable_nom'] ?? '—'); ?>
                                    <?php if (!empty($s['responsable_telephone'])): ?>
                                        <small style="display: block; color: var(--dash-muted);"><?php echo htmlspecialchars($s['responsable_telephone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem;" onclick="openAgentModal(<?php echo (int) $s['id']; ?>, '<?php echo htmlspecialchars($s['nom'], ENT_QUOTES); ?>')">
                                        <i class="fa-solid fa-user-plus"></i> <?php echo (int) $s['nb_agents']; ?> agent(s)
                                    </button>
                                </td>
                                <td><strong><?php echo (int) $s['nb_tickets_vendus']; ?></strong></td>
                                <td><?php echo number_format((float) $s['montant_vendu'], 0, ',', ' '); ?> F</td>
                                <td>
                                    <span style="background: <?php echo $sc['bg']; ?>; color: <?php echo $sc['tx']; ?>; border-radius: 6px; padding: 3px 9px; font-weight: 800; font-size: 0.75rem;">
                                        <?php echo $sc['label']; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem;" onclick='openEditModal(<?php echo json_encode($s); ?>)' title="Modifier">
                                        <i class="fa-solid fa-pen-to-square" style="color: #FF4A0D;"></i>
                                    </button>
                                    <?php if ($s['statut'] !== 'active'): ?>
                                        <a href="gares.php?id=<?php echo (int) $s['id']; ?>&set_statut=active" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem; margin-left: 4px;" title="Activer">
                                            <i class="fa-solid fa-play" style="color: #16A34A;"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($s['statut'] !== 'suspendue'): ?>
                                        <a href="gares.php?id=<?php echo (int) $s['id']; ?>&set_statut=suspendue" class="dash-btn-action" style="padding: 4px 8px; font-size: 0.76rem; margin-left: 4px;" title="Suspendre temporairement" onclick="return confirm('Suspendre les ventes de cette gare ?')">
                                            <i class="fa-solid fa-pause" style="color: #D97706;"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($s['statut'] !== 'stoppee'): ?>
                                        <a href="gares.php?id=<?php echo (int) $s['id']; ?>&set_statut=stoppee" class="dash-btn-action btn-danger" style="padding: 4px 8px; font-size: 0.76rem; margin-left: 4px;" title="Arrêter définitivement" onclick="return confirm('Arrêter les ventes de cette gare ? Les agents ne pourront plus émettre de billets.')">
                                            <i class="fa-solid fa-stop"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--dash-muted); padding: 3rem 1rem;">
                                <i class="fa-solid fa-bus" style="font-size: 2rem; color: #E5E5E5; margin-bottom: 0.5rem; display: block;"></i>
                                Aucune gare routière enregistrée.
                                <br><button type="button" class="dash-btn-action btn-primary" onclick="toggleAddModal(true)" style="margin-top: 0.75rem;">+ Créer une première gare</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Création -->
<div id="modalAddStation" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-plus-circle" style="color: var(--dash-primary);"></i> Nouvelle Gare Routière
            </h3>
            <button type="button" onclick="toggleAddModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="gares.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_create_station" value="1">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom de la gare *</label>
                <input type="text" name="nom" required placeholder="Ex: Gare d'Adjamé" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Ville</label>
                    <input type="text" name="ville" placeholder="Abidjan" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Adresse</label>
                    <input type="text" name="adresse" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Responsable</label>
                    <input type="text" name="responsable_nom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Téléphone</label>
                    <input type="tel" name="responsable_telephone" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleAddModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Créer la Gare</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modification -->
<div id="modalEditStation" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-pen-to-square" style="color: #FF4A0D;"></i> Modifier la Gare
            </h3>
            <button type="button" onclick="toggleEditModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="gares.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_edit_station" value="1">
            <input type="hidden" name="station_id" id="edit_station_id">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom de la gare *</label>
                <input type="text" name="nom" id="edit_nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Ville</label>
                    <input type="text" name="ville" id="edit_ville" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Adresse</label>
                    <input type="text" name="adresse" id="edit_adresse" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Responsable</label>
                    <input type="text" name="responsable_nom" id="edit_resp_nom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Téléphone</label>
                    <input type="tel" name="responsable_telephone" id="edit_resp_tel" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleEditModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Agent -->
<div id="modalAgentStation" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); place-items: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #F5F5F5; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #000000; margin: 0;">
                <i class="fa-solid fa-user-plus" style="color: var(--dash-primary);"></i> Nouvel Agent — <span id="agent_station_nom"></span>
            </h3>
            <button type="button" onclick="toggleAgentModal(false)" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #737373;">&times;</button>
        </div>
        <form method="POST" action="gares.php" style="padding: 1.5rem;">
            <input type="hidden" name="action_create_agent" value="1">
            <input type="hidden" name="station_id" id="agent_station_id">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom *</label>
                    <input type="text" name="agent_nom" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Prénom</label>
                    <input type="text" name="agent_prenom" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Email (identifiant de connexion) *</label>
                <input type="email" name="agent_email" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Téléphone</label>
                    <input type="tel" name="agent_telephone" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Mot de passe *</label>
                    <input type="text" name="agent_password" required minlength="6" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; outline: none; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleAgentModal(false)" class="dash-btn-action">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary">Créer l'Agent</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleAddModal(show) { document.getElementById('modalAddStation').style.display = show ? 'grid' : 'none'; }
    function toggleEditModal(show) { document.getElementById('modalEditStation').style.display = show ? 'grid' : 'none'; }
    function toggleAgentModal(show) { document.getElementById('modalAgentStation').style.display = show ? 'grid' : 'none'; }

    function openEditModal(s) {
        document.getElementById('edit_station_id').value = s.id;
        document.getElementById('edit_nom').value = s.nom || '';
        document.getElementById('edit_ville').value = s.ville || '';
        document.getElementById('edit_adresse').value = s.adresse || '';
        document.getElementById('edit_resp_nom').value = s.responsable_nom || '';
        document.getElementById('edit_resp_tel').value = s.responsable_telephone || '';
        toggleEditModal(true);
    }

    function openAgentModal(stationId, stationNom) {
        document.getElementById('agent_station_id').value = stationId;
        document.getElementById('agent_station_nom').innerText = stationNom;
        toggleAgentModal(true);
    }
</script>

<?php include 'footer.php'; ?>
