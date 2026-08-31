<?php
// ==============================================================================
// PROFIL PROMOTEUR & DEMANDES D'INFORMATIONS (promoteur/profil.php)
// Gestion de son profil public et consultation des demandes d'informations reçues
// ==============================================================================

$page_title = "Mon Profil Public - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Mise à jour des informations publiques du promoteur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $nom_commercial    = trim($_POST['nom_commercial'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $telephone_contact = trim($_POST['telephone_contact'] ?? '');
    $email_contact     = trim($_POST['email_contact'] ?? '');
    $adresse           = trim($_POST['adresse'] ?? '');
    $site_web          = trim($_POST['site_web'] ?? '');
    $reseaux_sociaux   = trim($_POST['reseaux_sociaux'] ?? '');

    if (empty($nom_commercial)) {
        $message = "Le nom commercial ou nom de structure est obligatoire.";
        $msg_type = "error";
    } else {
        $stmt_upd = $pdo->prepare("
            UPDATE promoters 
            SET nom_commercial = ?, description = ?, telephone_contact = ?, email_contact = ?, adresse = ?, site_web = ?, reseaux_sociaux = ?
            WHERE user_id = ?
        ");
        $stmt_upd->execute([$nom_commercial, $description, $telephone_contact, $email_contact, $adresse, $site_web, $reseaux_sociaux, $user_id]);

        $message = "Votre profil public a été mis à jour avec succès !";
        $msg_type = "success";
    }
}

// 2. Récupération des données du profil
$stmt = $pdo->prepare("SELECT * FROM promoters WHERE user_id = ?");
$stmt->execute([$user_id]);
$prom = $stmt->fetch();

// 3. Récupération des demandes d'informations reçues (Section 8)
$stmt_reqs = $pdo->prepare("SELECT * FROM information_requests WHERE promoter_id = ? ORDER BY created_at DESC");
$stmt_reqs->execute([$user_id]);
$info_requests = $stmt_reqs->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Visibilité</span>
        <h1>Mon Profil Public & Demandes d'Infos</h1>
        <p>Gérez votre image de marque et répondez aux messages de votre public.</p>
    </div>
    <a href="../client/promoteur.php?id=<?php echo $user_id; ?>" target="_blank" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.25rem;">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir mon profil public
    </a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: start;">
    <!-- 1. Formulaire d'édition de profil -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-pen-to-square"></i> Modifier mes informations publiques</div>
        <form method="POST">
            <input type="hidden" name="update_profile" value="1">

            <div class="form-group">
                <label for="nom_commercial">Nom commercial / Raison sociale *</label>
                <input type="text" id="nom_commercial" name="nom_commercial" required value="<?php echo htmlspecialchars($prom['nom_commercial'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="description">Présentation publique</label>
                <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($prom['description'] ?? ''); ?></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="telephone_contact">Téléphone de contact</label>
                    <input type="text" id="telephone_contact" name="telephone_contact" value="<?php echo htmlspecialchars($prom['telephone_contact'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email_contact">Email professionnel</label>
                    <input type="email" id="email_contact" name="email_contact" value="<?php echo htmlspecialchars($prom['email_contact'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="adresse">Adresse / Ville</label>
                <input type="text" id="adresse" name="adresse" value="<?php echo htmlspecialchars($prom['adresse'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="site_web">Site Web</label>
                <input type="url" id="site_web" name="site_web" placeholder="https://..." value="<?php echo htmlspecialchars($prom['site_web'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="reseaux_sociaux">Réseaux Sociaux</label>
                <input type="text" id="reseaux_sociaux" name="reseaux_sociaux" placeholder="Facebook, Instagram..." value="<?php echo htmlspecialchars($prom['reseaux_sociaux'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn-submit" style="width: 100%;">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer les modifications
            </button>
        </form>
    </div>

    <!-- 2. Demandes d'informations reçues (Section 8) -->
    <div class="content-section">
        <div class="section-title">
            <span><i class="fa-solid fa-inbox"></i> Demandes d'Informations Reçues (<?php echo count($info_requests); ?>)</span>
        </div>

        <?php if (count($info_requests) > 0): ?>
            <div style="display: flex; flex-direction: column; gap: 1rem; max-height: 500px; overflow-y: auto;">
                <?php foreach ($info_requests as $req): ?>
                    <div style="background: #f8faf9; border: 1px solid var(--line); border-radius: 8px; padding: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                            <strong><?php echo htmlspecialchars($req['nom_demandeur']); ?></strong>
                            <small style="color: var(--muted);"><?php echo date('d/m/Y H:i', strtotime($req['created_at'])); ?></small>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.5rem;">
                            <a href="mailto:<?php echo htmlspecialchars($req['email_demandeur']); ?>" style="color: var(--primary);"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($req['email_demandeur']); ?></a>
                            <?php if ($req['telephone_demandeur']): ?>
                                · <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($req['telephone_demandeur']); ?>
                            <?php endif; ?>
                        </div>
                        <p style="margin: 0; font-size: 0.9rem; color: var(--ink); background: #ffffff; padding: 0.6rem; border-radius: 6px; border: 1px solid var(--line);">
                            <?php echo nl2br(htmlspecialchars($req['message'])); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; color: var(--muted); padding: 3rem 1rem;">
                <i class="fa-solid fa-envelope-open" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.5rem; display: block;"></i>
                Vous n'avez pas encore reçu de demande d'informations.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
