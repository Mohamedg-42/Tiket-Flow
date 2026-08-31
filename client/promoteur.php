<?php
// ==============================================================================
// PROFIL PUBLIC DU PROMOTEUR (client/promoteur.php)
// Affiche les informations de l'organisateur, ses événements et formulaire de contact
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
$promoter = $stmt->fetch();

if (!$promoter) {
    header('Location: accueil.php');
    exit();
}

$message = "";
$msg_type = "";

// 2. Traitement d'une demande d'informations (Section 8)
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

        $message = "Votre demande d'informations a été transmise au promoteur avec succès !";
        $msg_type = "success";
    }
}

// 3. Récupération des événements de ce promoteur
$stmt_evs = $pdo->prepare("SELECT * FROM events WHERE user_id = ? AND statut = 'actif' ORDER BY date_evenement ASC");
$stmt_evs->execute([$promoter_user_id]);
$events = $stmt_evs->fetchAll();

$page_title = htmlspecialchars($promoter['nom_commercial']) . " - Profil Organisateur";
include 'header.php';
?>

<main class="client-main" style="max-width: 1000px; margin: 2rem auto; padding: 0 1rem;">
    <!-- En-tête profil du promoteur -->
    <div style="background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(18, 43, 57, 0.05);">
        <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
            <div style="width: 80px; height: 80px; background: #e0f2fe; color: #0284c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem;">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div style="flex: 1;">
                <span class="page-kicker">Organisateur Vérifié</span>
                <h1 style="color: var(--navy); margin: 0.2rem 0 0.5rem; font-size: 1.8rem;">
                    <?php echo htmlspecialchars($promoter['nom_commercial']); ?>
                </h1>
                <p style="color: var(--muted); margin: 0; font-size: 0.95rem;">
                    <?php echo htmlspecialchars($promoter['description'] ?: 'Organisateur professionnel d\'événements culturels et artistiques.'); ?>
                </p>
            </div>
        </div>

        <div style="display: flex; gap: 2rem; border-top: 1px solid var(--line); margin-top: 1.5rem; padding-top: 1.25rem; flex-wrap: wrap;">
            <div>
                <small style="color: var(--muted); display: block;">Événements organisés</small>
                <strong style="color: var(--primary); font-size: 1.3rem;"><?php echo (int)$promoter['total_events']; ?></strong>
            </div>
            <div>
                <small style="color: var(--muted); display: block;">Personnes intéressées / Demandes</small>
                <strong style="color: var(--navy); font-size: 1.3rem;"><?php echo (int)$promoter['total_requests']; ?></strong>
            </div>
            <?php if (!empty($promoter['adresse'])): ?>
                <div>
                    <small style="color: var(--muted); display: block;">Localisation</small>
                    <strong><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($promoter['adresse']); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom: 1.5rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 380px; gap: 2rem; align-items: start;">
        <!-- 1. Événements du promoteur -->
        <div>
            <div class="section-title"><i class="fa-solid fa-calendar-days"></i> Événements à l'affiche</div>
            <?php if (count($events) > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($events as $ev): ?>
                        <div style="background: var(--paper); border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0 0 0.3rem; color: var(--navy);"><?php echo htmlspecialchars($ev['nom']); ?></h3>
                                <small style="color: var(--muted);"><i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?> · <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ev['lieu']); ?></small>
                            </div>
                            <a href="accueil.php?q=<?php echo urlencode($ev['nom']); ?>" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.5rem 1rem; font-size: 0.85rem;">
                                Réserver
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: var(--muted);">Aucun événement à venir pour le moment.</p>
            <?php endif; ?>
        </div>

        <!-- 2. Formulaire : Demander des informations (Section 8) -->
        <div class="content-section">
            <div class="section-title"><i class="fa-solid fa-envelope"></i> Demander des Informations</div>
            <form method="POST">
                <input type="hidden" name="send_info_request" value="1">

                <div class="form-group">
                    <label for="nom_demandeur">Votre nom complet *</label>
                    <input type="text" id="nom_demandeur" name="nom_demandeur" required placeholder="Ex: Jean Dupont" value="<?php echo htmlspecialchars($_SESSION['user_nom'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email_demandeur">Votre adresse email *</label>
                    <input type="email" id="email_demandeur" name="email_demandeur" required placeholder="exemple@mail.com" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="tel_demandeur">Téléphone (Optionnel)</label>
                    <input type="tel" id="tel_demandeur" name="tel_demandeur" placeholder="Ex: 07 00 00 00 00">
                </div>

                <div class="form-group">
                    <label for="message">Votre message ou question *</label>
                    <textarea id="message" name="message" rows="3" required placeholder="Demande de partenariat, question sur les réservations..."></textarea>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <i class="fa-solid fa-paper-plane"></i> Envoyer ma demande
                </button>
            </form>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
