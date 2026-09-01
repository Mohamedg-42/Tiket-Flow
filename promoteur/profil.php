<?php
// ==============================================================================
// GESTION DU PROFIL PUBLIC DU PROMOTEUR (promoteur/profil.php)
// Design Dashboard Pro - Identité de marque, coordonnées & Boîte de réception
// ==============================================================================

$page_title = "Mon Profil Public - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Mise à jour des informations publiques du promoteur
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $nom_commercial    = trim($_POST['nom_commercial'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $telephone_contact = trim($_POST['telephone_contact'] ?? '');
    $email_contact     = trim($_POST['email_contact'] ?? '');
    $adresse           = trim($_POST['adresse'] ?? '');
    $site_web          = trim($_POST['site_web'] ?? '');
    $reseaux_sociaux   = trim($_POST['reseaux_sociaux'] ?? '');

    if (empty($nom_commercial)) {
        $message = "Le nom commercial ou nom d'organisation est obligatoire.";
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

// ------------------------------------------------------------------------------
// 2. Récupération des données du promoteur & Statistiques de notoriété
// ------------------------------------------------------------------------------
$stmt_prom = $pdo->prepare("
    SELECT p.*, u.nom AS user_nom, u.email AS user_email, u.telephone AS user_tel,
           (SELECT COUNT(*) FROM events e WHERE e.user_id = p.user_id) AS total_events,
           (SELECT COALESCE(SUM(tt.quantite_vendue), 0) FROM ticket_types tt JOIN events e ON tt.event_id = e.id WHERE e.user_id = p.user_id) AS total_billets_vendus
    FROM promoters p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id = ?
");
$stmt_prom->execute([$user_id]);
$prom = $stmt_prom->fetch(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 3. Récupération des demandes d'informations reçues du public
// ------------------------------------------------------------------------------
$stmt_reqs = $pdo->prepare("
    SELECT * FROM information_requests 
    WHERE promoter_id = ? 
    ORDER BY created_at DESC
");
$stmt_reqs->execute([$user_id]);
$info_requests = $stmt_reqs->fetchAll(PDO::FETCH_ASSOC);

$nom_affiche = !empty($prom['nom_commercial']) ? $prom['nom_commercial'] : ($prom['user_nom'] ?? 'Promoteur');
$words = explode(' ', trim($nom_affiche));
$initials = strtoupper(substr($words[0] ?? 'P', 0, 1) . substr($words[1] ?? '', 0, 1));
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.profile-avatar-large {
    width: 68px;
    height: 68px;
    border-radius: 18px;
    background: linear-gradient(135deg, var(--dash-primary), #0284c7);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
    flex-shrink: 0;
}
.inquiry-card {
    background: #ffffff;
    border: 1px solid var(--dash-border);
    border-radius: 12px;
    padding: 1rem 1.15rem;
    transition: all 0.2s ease;
}
.inquiry-card:hover {
    border-color: var(--dash-primary);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-id-card" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Mon Profil Public & Boîte de Réception
            </h1>
            <p>Personnalisez votre image de marque, vos coordonnées officielles et consultez les demandes d'informations de vos festivaliers.</p>
        </div>

        <div>
            <a href="../client/promoteur.php?id=<?php echo $user_id; ?>" target="_blank" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir mon Profil Public
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. CARTE D'IDENTITÉ PROMOTEUR & NOTORIÉTÉ (HERO CARD PRO)
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.75rem; padding: 1.5rem; background: linear-gradient(135deg, #ffffff 60%, #f8fafc); border: 1px solid var(--dash-border);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 1.25rem;">
                <div class="profile-avatar-large"><?php echo htmlspecialchars($initials); ?></div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <h2 style="margin: 0; font-size: 1.35rem; color: var(--dash-text); font-weight: 800;">
                            <?php echo htmlspecialchars($nom_affiche); ?>
                        </h2>
                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #bbf7d0;">
                            <i class="fa-solid fa-circle-check"></i> Promoteur Certifié
                        </span>
                    </div>

                    <p style="margin: 4px 0 0; color: var(--dash-muted); font-size: 0.85rem; max-width: 600px;">
                        <?php echo !empty($prom['description']) ? htmlspecialchars($prom['description']) : "Aucune description renseignée. Remplissez le formulaire ci-dessous pour présenter votre agence."; ?>
                    </p>

                    <div style="display: flex; gap: 1rem; margin-top: 8px; font-size: 0.8rem; color: var(--dash-muted); flex-wrap: wrap;">
                        <?php if (!empty($prom['adresse'])): ?>
                            <span><i class="fa-solid fa-location-dot" style="color: var(--dash-primary);"></i> <?php echo htmlspecialchars($prom['adresse']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($prom['telephone_contact'])): ?>
                            <span><i class="fa-solid fa-phone" style="color: var(--dash-primary);"></i> <?php echo htmlspecialchars($prom['telephone_contact']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($prom['site_web'])): ?>
                            <a href="<?php echo htmlspecialchars($prom['site_web']); ?>" target="_blank" style="color: var(--dash-primary); text-decoration: none; font-weight: 600;">
                                <i class="fa-solid fa-globe"></i> Site Web Officiel
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Mini statistiques d'impact public -->
            <div style="display: flex; gap: 1.25rem; border-left: 1px solid var(--dash-border); padding-left: 1.25rem;">
                <div style="text-align: center;">
                    <strong style="font-size: 1.45rem; color: var(--dash-text); font-weight: 800; display: block;">
                        <?php echo (int)($prom['total_events'] ?? 0); ?>
                    </strong>
                    <small style="color: var(--dash-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Événements</small>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.45rem; color: #059669; font-weight: 800; display: block;">
                        <?php echo number_format((int)($prom['total_billets_vendus'] ?? 0), 0, ',', ' '); ?>
                    </strong>
                    <small style="color: var(--dash-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Billets Émis</small>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.45rem; color: #0284c7; font-weight: 800; display: block;">
                        <?php echo count($info_requests); ?>
                    </strong>
                    <small style="color: var(--dash-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Messages</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
         3. GRILLE DEUX COLONNES : FORMULAIRE PRO & BOÎTE DE RÉCEPTION
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 1.5rem; align-items: start;">
        <!-- COLONNE GAUCHE : FORMULAIRE D'ÉDITION -->
        <div class="dash-card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--dash-border);">
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i>
                    Éditer mes Informations Publiques
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Ces éléments sont visibles par tous les acheteurs sur votre vitrine d'organisateur.</small>
            </div>

            <form method="POST" action="profil.php" style="padding: 1.5rem;">
                <input type="hidden" name="update_profile" value="1">

                <div style="margin-bottom: 1rem;">
                    <label for="nom_commercial" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Nom commercial / Raison sociale de l'organisation *
                    </label>
                    <input type="text" id="nom_commercial" name="nom_commercial" required value="<?php echo htmlspecialchars($prom['nom_commercial'] ?? ''); ?>" placeholder="Ex: Ivoire Events Production" style="width: 100%; padding: 0.6rem 0.85rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.88rem; font-weight: 700;">
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="description" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Présentation de votre agence / Slogan
                    </label>
                    <textarea id="description" name="description" rows="3" placeholder="Présentez votre parcours, vos types d'événements et votre expertise..." style="width: 100%; padding: 0.6rem 0.85rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"><?php echo htmlspecialchars($prom['description'] ?? ''); ?></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div>
                        <label for="telephone_contact" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-solid fa-phone" style="color: var(--dash-primary);"></i> Téléphone public
                        </label>
                        <input type="tel" id="telephone_contact" name="telephone_contact" value="<?php echo htmlspecialchars($prom['telephone_contact'] ?? ''); ?>" placeholder="07 00 00 00 00" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                    <div>
                        <label for="email_contact" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-solid fa-envelope" style="color: var(--dash-primary);"></i> Email professionnel
                        </label>
                        <input type="email" id="email_contact" name="email_contact" value="<?php echo htmlspecialchars($prom['email_contact'] ?? ''); ?>" placeholder="contact@agence.ci" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="adresse" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        <i class="fa-solid fa-location-dot" style="color: var(--dash-primary);"></i> Ville / Adresse physique
                    </label>
                    <input type="text" id="adresse" name="adresse" value="<?php echo htmlspecialchars($prom['adresse'] ?? ''); ?>" placeholder="Ex: Cocody Angré 8ème Tranche, Abidjan" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <div>
                        <label for="site_web" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-solid fa-globe" style="color: var(--dash-primary);"></i> Site Internet
                        </label>
                        <input type="url" id="site_web" name="site_web" value="<?php echo htmlspecialchars($prom['site_web'] ?? ''); ?>" placeholder="https://mon-agence.ci" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                    <div>
                        <label for="reseaux_sociaux" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                            <i class="fa-brands fa-instagram" style="color: #ec4899;"></i> Réseaux Sociaux
                        </label>
                        <input type="text" id="reseaux_sociaux" name="reseaux_sociaux" value="<?php echo htmlspecialchars($prom['reseaux_sociaux'] ?? ''); ?>" placeholder="@ivoire_events (IG, FB...)" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.65rem 1.35rem; font-size: 0.9rem; font-weight: 800; border-radius: 10px;">
                        <i class="fa-solid fa-floppy-disk"></i> Enregistrer mon Profil
                    </button>
                </div>
            </form>
        </div>

        <!-- COLONNE DROITE : DEMANDES D'INFORMATIONS REÇUES -->
        <div class="dash-card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-inbox" style="color: #0284c7;"></i>
                    Messages & Demandes d'Infos (<?php echo count($info_requests); ?>)
                </h3>
            </div>

            <div style="padding: 1.25rem;">
                <?php if (count($info_requests) > 0): ?>
                    <div style="display: flex; flex-direction: column; gap: 0.85rem; max-height: 580px; overflow-y: auto;">
                        <?php foreach ($info_requests as $req): ?>
                            <div class="inquiry-card">
                                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
                                    <strong style="color: var(--dash-text); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($req['nom_demandeur']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo date('d/m/Y H:i', strtotime($req['created_at'])); ?>
                                    </small>
                                </div>

                                <div style="display: flex; gap: 10px; font-size: 0.78rem; margin-bottom: 8px; flex-wrap: wrap;">
                                    <a href="mailto:<?php echo htmlspecialchars($req['email_demandeur']); ?>" style="color: var(--dash-primary); text-decoration: none; font-weight: 600;">
                                        <i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($req['email_demandeur']); ?>
                                    </a>
                                    <?php if (!empty($req['telephone_demandeur'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($req['telephone_demandeur']); ?>" style="color: var(--dash-text); text-decoration: none;">
                                            <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($req['telephone_demandeur']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 0.65rem 0.85rem; font-size: 0.82rem; color: var(--dash-text); line-height: 1.4;">
                                    <?php echo nl2br(htmlspecialchars($req['message'])); ?>
                                </div>

                                <div style="margin-top: 8px; display: flex; justify-content: flex-end; gap: 6px;">
                                    <a href="mailto:<?php echo htmlspecialchars($req['email_demandeur']); ?>?subject=Réponse à votre demande d'informations" class="dash-btn-action" style="padding: 0.3rem 0.65rem; font-size: 0.74rem; text-decoration: none;">
                                        <i class="fa-solid fa-reply"></i> Répondre par email
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; color: var(--dash-muted); padding: 3.5rem 1rem;">
                        <i class="fa-solid fa-envelope-open" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                        <strong style="display: block; font-size: 0.95rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucune demande reçue</strong>
                        <p style="font-size: 0.8rem; margin: 0;">Les questions posées par vos clients sur votre profil public s'afficheront ici en direct.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
