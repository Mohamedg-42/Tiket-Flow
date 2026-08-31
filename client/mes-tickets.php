<?php
// ==============================================================================
// MES TICKETS CLIENT (client/mes-tickets.php)
// Affichage des billets achetés avec leur code unique et QR Code pour contrôle
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin('../connexion.php');

$page_title = "Mes Tickets - Ticket Flow";
$body_class = "client-page tickets-page";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// ===== Filtres des billets : par événement, par type et par statut =====
$filtre_event  = filter_input(INPUT_GET, 'event', FILTER_VALIDATE_INT) ?: 0;
$filtre_type   = trim($_GET['type'] ?? '');
$filtre_statut = trim($_GET['statut'] ?? '');
$filtres_statut_valides = ['vendu', 'utilise', 'annule'];
if (!in_array($filtre_statut, $filtres_statut_valides, true)) {
    $filtre_statut = '';
}

// Liste des événements pour lesquels le client possède des billets
$stmt_liste_events = $pdo->prepare("
    SELECT DISTINCT e.id, e.nom
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE t.user_id = ?
    ORDER BY e.nom ASC
");
$stmt_liste_events->execute([$user_id]);
$mes_evenements = $stmt_liste_events->fetchAll();

// Liste des types de billets possédés par le client
$stmt_liste_types = $pdo->prepare("SELECT DISTINCT type_ticket FROM tickets WHERE user_id = ? ORDER BY type_ticket ASC");
$stmt_liste_types->execute([$user_id]);
$types_billets = array_column($stmt_liste_types->fetchAll(), 'type_ticket');

// Le type filtré doit exister dans la liste (sécurité, évite toute injection)
if ($filtre_type !== '' && !in_array($filtre_type, $types_billets, true)) {
    $filtre_type = '';
}

// Construction sécurisée des conditions de filtrage (requêtes préparées)
$sql_filtres    = "";
$params_filtres = [];
if ($filtre_event > 0) {
    $sql_filtres .= " AND t.event_id = ?";
    $params_filtres[] = $filtre_event;
}
if ($filtre_type !== '') {
    $sql_filtres .= " AND t.type_ticket = ?";
    $params_filtres[] = $filtre_type;
}
if ($filtre_statut !== '') {
    $sql_filtres .= " AND t.statut = ?";
    $params_filtres[] = $filtre_statut;
}

// Récupération des tickets achetés par le client (avec filtres appliqués)
$sql = "
    SELECT t.*, e.nom AS event_name, e.date_evenement, e.heure, e.lieu, e.image AS event_image
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE t.user_id = ?
    $sql_filtres
    ORDER BY t.date_achat DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$user_id], $params_filtres));
$tickets = $stmt->fetchAll();
?>

<main class="client-main" style="max-width: 1000px; margin: 0 auto; padding: 2rem 1rem;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <div class="page-heading">
            <span class="page-kicker">Votre Espace Billetterie</span>
            <h1><i class="fa-solid fa-ticket"></i> Mes Billets & QR Codes</h1>
            <p>Présentez ces QR codes à l'entrée de l'événement pour faire scanner votre entrée.</p>
        </div>
        <a href="accueil.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.25rem;">
            <i class="fa-solid fa-arrow-left"></i> Retour aux événements
        </a>
    </div>

    <!-- Filtres des billets : par événement, par type et par statut -->
    <?php if (count($mes_evenements) > 0): ?>
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.75rem; background: #f8fafc; border: 1px solid var(--line); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy); display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-filter" style="color: var(--primary);"></i> Filtrer :
            </span>

            <select name="event" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 280px;">
                <option value="0">Tous les événements</option>
                <?php foreach ($mes_evenements as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($filtre_event === (int)$ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['nom']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer; max-width: 220px;">
                <option value="">Tous les types</option>
                <?php foreach ($types_billets as $tb): ?>
                    <option value="<?php echo htmlspecialchars($tb); ?>" <?php echo ($filtre_type === $tb) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tb); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="statut" onchange="this.form.submit()"
                    style="padding: 0.5rem 0.85rem; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 0.88rem; background: #ffffff; color: var(--ink); cursor: pointer;">
                <option value="">Tous les statuts</option>
                <option value="vendu" <?php echo ($filtre_statut === 'vendu') ? 'selected' : ''; ?>>Valide</option>
                <option value="utilise" <?php echo ($filtre_statut === 'utilise') ? 'selected' : ''; ?>>Utilisé</option>
                <option value="annule" <?php echo ($filtre_statut === 'annule') ? 'selected' : ''; ?>>Annulé</option>
            </select>

            <?php if ($filtre_event > 0 || $filtre_type !== '' || $filtre_statut !== ''): ?>
                <a href="mes-tickets.php" style="font-size: 0.82rem; font-weight: 700; color: var(--danger); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <i class="fa-solid fa-xmark"></i> Réinitialiser
                </a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if (count($tickets) > 0): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
            <?php foreach ($tickets as $t): ?>
                <?php
                $is_used = ($t['statut'] === 'utilise');
                $is_cancelled = ($t['statut'] === 'annule');

                // Lien de partage WhatsApp du billet
                $ticket_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/telecharger-ticket.php?code=' . urlencode($t['code_unique']);
                $wa_text = "🎟️ Mon billet Ticket Flow\nÉvénement : " . $t['event_name']
                    . "\nType : " . $t['type_ticket']
                    . (!empty($t['place_numero']) ? "\nPlace : " . $t['place_numero'] : '')
                    . "\nCode : " . $t['code_unique']
                    . "\nTélécharger le billet : " . $ticket_url;
                ?>
                <div style="background: var(--paper); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; box-shadow: 0 8px 25px rgba(18, 43, 57, 0.06); display: flex; flex-direction: column;">
                    <!-- En-tête du billet -->
                    <div style="background: var(--navy); color: #ffffff; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: bold; font-size: 0.95rem;">
                            <i class="fa-solid fa-ticket"></i> <?php echo htmlspecialchars($t['type_ticket']); ?>
                        </span>
                        <span>
                            <?php if ($is_used): ?>
                                <span style="background: #ef4444; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                                    UTILISÉ
                                </span>
                            <?php elseif ($is_cancelled): ?>
                                <span style="background: #64748b; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                                    ANNULÉ
                                </span>
                            <?php else: ?>
                                <span style="background: #10b981; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                                    <i class="fa-solid fa-circle-check"></i> VALIDE
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div style="padding: 1.25rem; display: flex; flex-direction: column; align-items: center; text-align: center; flex: 1;">
                        <!-- QR Code -->
                        <div style="background: #ffffff; border: 2px dashed var(--line); border-radius: 10px; padding: 0.75rem; margin-bottom: 1rem;">
                            <img src="<?php echo htmlspecialchars($t['qr_code']); ?>" alt="QR Code" style="width: 170px; height: 170px; display: block; opacity: <?php echo $is_used ? '0.4' : '1'; ?>;">
                        </div>

                        <!-- Code unique -->
                        <div style="background: #f1f5f9; padding: 0.4rem 1rem; border-radius: 6px; font-family: monospace; font-size: 1.15rem; font-weight: bold; color: var(--navy); margin-bottom: 1rem; letter-spacing: 1px;">
                            <?php echo htmlspecialchars($t['code_unique']); ?>
                        </div>

                        <!-- Détails de l'événement -->
                        <h3 style="margin: 0 0 0.4rem; color: var(--navy); font-size: 1.15rem;">
                            <?php echo htmlspecialchars($t['event_name']); ?>
                        </h3>

                        <div style="color: var(--muted); font-size: 0.88rem; margin-bottom: 0.75rem;">
                            <div><i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($t['date_evenement'])); ?> à <?php echo substr($t['heure'], 0, 5); ?></div>
                            <div><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($t['lieu']); ?></div>
                            <?php if (!empty($t['place_numero'])): ?>
                                <div><i class="fa-solid fa-chair" style="color: var(--primary);"></i> Place : <strong style="color: var(--navy);"><?php echo htmlspecialchars($t['place_numero']); ?></strong></div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: auto; width: 100%; border-top: 1px solid var(--line); padding-top: 1rem; display: flex; flex-direction: column; gap: 0.65rem;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--muted);">
                                <span>Prix payé :</span>
                                <strong style="color: var(--primary); font-size: 0.95rem;"><?php echo number_format($t['prix'], 0, ',', ' '); ?> FCFA</strong>
                            </div>

                            <div style="display: flex; gap: 0.5rem;">
                                <a href="telecharger-ticket.php?code=<?php echo urlencode($t['code_unique']); ?>" target="_blank" class="btn-submit" style="flex: 1; padding: 0.55rem; font-size: 0.8rem; text-decoration: none;">
                                    <i class="fa-solid fa-download"></i> PDF
                                </a>
                                <a href="https://wa.me/?text=<?php echo urlencode($wa_text); ?>" target="_blank" class="btn-submit" style="flex: 1; padding: 0.55rem; font-size: 0.8rem; background: #25D366; text-decoration: none;" title="Envoyer ce billet sur WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; background: var(--paper); border: 1px solid var(--line); border-radius: 12px; padding: 3.5rem 1rem;">
            <i class="fa-solid fa-ticket" style="font-size: 3rem; color: var(--line); margin-bottom: 1rem; display: block;"></i>
            <h3 style="color: var(--navy); margin-bottom: 0.5rem;">Vous n'avez aucun billet pour le moment</h3>
            <p style="color: var(--muted); margin-bottom: 1.5rem;">Réservez votre premier événement dès maintenant !</p>
            <a href="accueil.php" class="btn-submit" style="display: inline-block; width: auto; text-decoration: none; padding: 0.65rem 1.5rem;">
                Explorer les événements
            </a>
        </div>
    <?php endif; ?>
</main>

<?php include 'footer.php'; ?>