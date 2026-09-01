<?php
// ==============================================================================
// PROFIL PUBLIC DU PROMOTEUR (client/promoteur.php)
// Vitrine officielle de l'organisateur avec ses événements et formulaire de contact
// ==============================================================================

require_once '../config/database.php';
session_start();

$promoter_user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$promoter_user_id) {
    header('Location: accueil.php');
    exit();
}

// 1. Récupération des données du promoteur
$stmt = $pdo->prepare("
    SELECT p.*, u.nom AS user_nom, u.email AS user_email,
           (SELECT COUNT(*) FROM events e WHERE e.user_id = p.user_id AND e.statut = 'actif') AS total_events,
           (SELECT COUNT(*) FROM information_requests ir WHERE ir.promoter_id = p.user_id) AS total_requests
    FROM promoters p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id = ? AND p.statut = 'approuve'
");
$stmt->execute([$promoter_user_id]);
$promoter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$promoter) {
    header('Location: accueil.php');
    exit();
}

$message = "";
$msg_type = "";

// 2. Traitement d'une demande d'informations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_info_request'])) {
    $nom_demandeur   = trim($_POST['nom_demandeur'] ?? '');
    $email_demandeur = trim($_POST['email_demandeur'] ?? '');
    $tel_demandeur   = trim($_POST['tel_demandeur'] ?? '');
    $msg             = trim($_POST['message'] ?? '');
    $connected_user_id = $_SESSION['user_id'] ?? null;

    if (empty($nom_demandeur) || empty($email_demandeur) || empty($msg)) {
        $message = "Veuillez remplir votre nom, votre email et votre message.";
        $msg_type = "error";
    } elseif (!filter_var($email_demandeur, FILTER_VALIDATE_EMAIL)) {
        $message = "Adresse email invalide.";
        $msg_type = "error";
    } else {
        $sql_ins = "INSERT INTO information_requests (promoter_id, user_id, nom_demandeur, email_demandeur, telephone_demandeur, message, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt_ins = $pdo->prepare($sql_ins);
        $stmt_ins->execute([$promoter_user_id, $connected_user_id, $nom_demandeur, $email_demandeur, $tel_demandeur, $msg]);

        $message = "Votre message a été transmis avec succès à l'organisateur !";
        $msg_type = "success";
    }
}

// 3. Récupération des événements de ce promoteur
$stmt_evs = $pdo->prepare("
    SELECT e.*,
           (SELECT COALESCE(SUM(tt.quantite_vendue), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS tickets_vendus,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_places,
           (SELECT MIN(tt.prix) FROM ticket_types tt WHERE tt.event_id = e.id) AS prix_min
    FROM events e 
    WHERE e.user_id = ? AND e.statut IN ('actif', 'approuve') 
    ORDER BY e.date_evenement ASC
");
$stmt_evs->execute([$promoter_user_id]);
$events = $stmt_evs->fetchAll(PDO::FETCH_ASSOC);

$nom_titre = !empty($promoter['nom_commercial']) ? $promoter['nom_commercial'] : $promoter['user_nom'];
$page_title = htmlspecialchars($nom_titre) . " - Profil Organisateur Officiel";
include 'header.php';

$words = explode(' ', trim($nom_titre));
$initials = strtoupper(substr($words[0] ?? 'O', 0, 1) . substr($words[1] ?? '', 0, 1));
?>

<main class="client-main" style="max-width: 1100px; margin: 2rem auto; padding: 0 1.25rem;">
    <!-- ==============================================================================
         1. HERO BANNER ORGANISATEUR CERTIFIÉ
         ============================================================================== -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 2.25rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04); position: relative; overflow: hidden;">
        <div style="position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(90deg, #0d9488, #0284c7);"></div>

        <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
            <!-- Grand Avatar -->
            <div style="width: 86px; height: 86px; background: linear-gradient(135deg, #0d9488, #0284c7); color: #ffffff; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 800; box-shadow: 0 6px 16px rgba(13, 148, 136, 0.25); flex-shrink: 0;">
                <?php echo htmlspecialchars($initials); ?>
            </div>

            <div style="flex: 1; min-width: 280px;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="background: #dcfce7; color: #166534; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #bbf7d0;">
                        <i class="fa-solid fa-circle-check"></i> Organisateur Officiel & Certifié
                    </span>
                    <?php if (!empty($promoter['adresse'])): ?>
                        <span style="color: #64748b; font-size: 0.82rem;">
                            <i class="fa-solid fa-location-dot" style="color: #0d9488;"></i> <?php echo htmlspecialchars($promoter['adresse']); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 style="color: #0f172a; margin: 0.4rem 0 0.5rem; font-size: 2rem; font-weight: 800;">
                    <?php echo htmlspecialchars($nom_titre); ?>
                </h1>

                <p style="color: #64748b; margin: 0; font-size: 0.95rem; line-height: 1.5; max-width: 720px;">
                    <?php echo htmlspecialchars($promoter['description'] ?: 'Organisateur professionnel d\'événements culturels, concerts, spectacles et conférences.'); ?>
                </p>
            </div>
        </div>

        <!-- Coordonnées & Métriques en bas de la bannière -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; margin-top: 1.75rem; padding-top: 1.25rem; flex-wrap: wrap; gap: 1.25rem;">
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                <div>
                    <small style="color: #94a3b8; display: block; font-size: 0.76rem; font-weight: 700; text-transform: uppercase;">Événements à l'affiche</small>
                    <strong style="color: #0d9488; font-size: 1.45rem; font-weight: 800;"><?php echo count($events); ?></strong>
                </div>
                <div>
                    <small style="color: #94a3b8; display: block; font-size: 0.76rem; font-weight: 700; text-transform: uppercase;">Intérêts & Demandes</small>
                    <strong style="color: #0f172a; font-size: 1.45rem; font-weight: 800;"><?php echo (int)$promoter['total_requests']; ?></strong>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <?php if (!empty($promoter['site_web'])): ?>
                    <a href="<?php echo htmlspecialchars($promoter['site_web']); ?>" target="_blank" style="padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #e2e8f0; background: #ffffff; color: #0f172a; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-globe" style="color: #0d9488;"></i> Site Web
                    </a>
                <?php endif; ?>
                <?php if (!empty($promoter['telephone_contact'])): ?>
                    <a href="tel:<?php echo htmlspecialchars($promoter['telephone_contact']); ?>" style="padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #e2e8f0; background: #ffffff; color: #0f172a; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-phone" style="color: #0d9488;"></i> Appeler
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom: 1.75rem; border-radius: 12px;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. GRILLE : ÉVÉNEMENTS DU PROMOTEUR & FORMULAIRE DE PRISE DE CONTACT
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: 1fr 380px; gap: 2rem; align-items: start;">
        <!-- LISTE DES ÉVÉNEMENTS DISPONIBLES -->
        <div>
            <h2 style="margin: 0 0 1.25rem; font-size: 1.2rem; color: #0f172a; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-calendar-days" style="color: #0d9488;"></i> Événements & Billetteries Disponibles (<?php echo count($events); ?>)
            </h2>

            <?php if (count($events) > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($events as $ev): ?>
                        <?php 
                            $img_ev = !empty($ev['image']) ? '../uploads/' . htmlspecialchars($ev['image']) : '../images/default-event.jpg';
                            $prix_min = (float)($ev['prix_min'] ?? 0);
                        ?>
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: all 0.2s ease;">
                            <div style="display: flex; gap: 1rem; align-items: center;">
                                <img src="<?php echo $img_ev; ?>" alt="Affiche" style="width: 72px; height: 72px; border-radius: 10px; object-fit: cover; border: 1px solid #e2e8f0; flex-shrink: 0;" onerror="this.src='../images/default-event.jpg';">
                                <div>
                                    <span style="background: #f1f5f9; color: #475569; padding: 1px 6px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($ev['categorie']); ?>
                                    </span>
                                    <h3 style="margin: 0.25rem 0 0.35rem; color: #0f172a; font-size: 1.05rem; font-weight: 800;">
                                        <?php echo htmlspecialchars($ev['nom']); ?>
                                    </h3>
                                    <div style="color: #64748b; font-size: 0.8rem; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                        <span><i class="fa-regular fa-calendar" style="color: #0d9488;"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?></span>
                                        <span><i class="fa-regular fa-clock"></i> <?php echo substr($ev['heure'], 0, 5); ?></span>
                                        <span><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ev['lieu']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div style="text-align: right; flex-shrink: 0;">
                                <?php if ($prix_min > 0): ?>
                                    <span style="display: block; font-size: 0.75rem; color: #94a3b8;">À partir de</span>
                                    <strong style="color: #0d9488; font-size: 1.15rem; font-weight: 800; display: block; margin-bottom: 6px;">
                                        <?php echo number_format($prix_min, 0, ',', ' '); ?> F
                                    </strong>
                                <?php else: ?>
                                    <span style="background: #dcfce7; color: #166534; padding: 2px 7px; border-radius: 4px; font-weight: 800; font-size: 0.75rem; display: inline-block; margin-bottom: 6px;">
                                        Gratuit
                                    </span>
                                <?php endif; ?>

                                <a href="accueil.php?q=<?php echo urlencode($ev['nom']); ?>" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.45rem 1rem; font-size: 0.82rem; font-weight: 700; border-radius: 8px;">
                                    Réserver mon billet
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 3rem 1.5rem; text-align: center; color: #94a3b8;">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                    <strong style="display: block; font-size: 1rem; color: #0f172a; margin-bottom: 0.25rem;">Aucun événement actif</strong>
                    Cet organisateur prépare ses prochains rendez-vous culturels.
                </div>
            <?php endif; ?>
        </div>

        <!-- FORMULAIRE DE CONTACT PUBLIC -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <h3 style="margin: 0 0 0.35rem; font-size: 1.1rem; color: #0f172a; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-envelope" style="color: #0d9488;"></i> Poser une Question
            </h3>
            <p style="color: #64748b; font-size: 0.82rem; margin: 0 0 1.25rem;">
                Partenariat, réservation de groupe ou question relative à la programmation.
            </p>

            <form method="POST">
                <input type="hidden" name="send_info_request" value="1">

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="nom_demandeur" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: #0f172a;">
                        Votre nom complet *
                    </label>
                    <input type="text" id="nom_demandeur" name="nom_demandeur" required placeholder="Ex: Jean Dupont" value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem;">
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="email_demandeur" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: #0f172a;">
                        Votre adresse email *
                    </label>
                    <input type="email" id="email_demandeur" name="email_demandeur" required placeholder="exemple@mail.com" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem;">
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="tel_demandeur" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: #0f172a;">
                        Numéro de téléphone (Optionnel)
                    </label>
                    <input type="tel" id="tel_demandeur" name="tel_demandeur" placeholder="Ex: 07 00 00 00 00" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem;">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="message" style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: #0f172a;">
                        Votre message *
                    </label>
                    <textarea id="message" name="message" rows="4" required placeholder="Écrivez votre message à l'attention de l'organisateur..." style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; line-height: 1.4;"></textarea>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%; padding: 0.75rem; font-weight: 800; border-radius: 8px;">
                    <i class="fa-solid fa-paper-plane"></i> Transmettre mon message
                </button>
            </form>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
