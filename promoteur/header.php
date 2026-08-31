<?php
// ==============================================================================
// EN-TÊTE ESPACE PROMOTEUR (promoteur/header.php)
// Navigation et sécurisation de l'espace promoteur
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

// Seul le rôle 'promoteur' ou 'admin' peut accéder
checkRole(['promoteur', 'admin'], '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'Espace Promoteur - Ticket Flow';

// Récupération des informations et du solde du promoteur
$stmt_p = $pdo->prepare("SELECT * FROM promoters WHERE user_id = ?");
$stmt_p->execute([$_SESSION['user_id']]);
$promoter_profile = $stmt_p->fetch();

$solde_actuel = $promoter_profile ? (float)$promoter_profile['solde'] : 0.00;

// Badge "Mes Demandes" : événements + campagnes de cotisation en attente
$stmt_bd = $pdo->prepare("SELECT COUNT(*) FROM event_requests WHERE user_id = ? AND statut = 'en_attente'");
$stmt_bd->execute([$_SESSION['user_id']]);
$badge_demandes_promoteur = (int)$stmt_bd->fetchColumn();
try {
    $stmt_bc = $pdo->prepare("SELECT COUNT(*) FROM cotisation_campagnes WHERE user_id = ? AND statut = 'en_attente'");
    $stmt_bc->execute([$_SESSION['user_id']]);
    $badge_demandes_promoteur += (int)$stmt_bc->fetchColumn();
} catch (PDOException $e) { /* table non migrée */ }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .badge-count {
            background: #ef4444;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: bold;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: auto;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Barre latérale du promoteur -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <i class="fa-solid fa-bullhorn"></i> PROMOTEUR
            </div>

            <!-- Solde du promoteur dans le menu -->
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.75rem; margin-bottom: 1.5rem; text-align: center;">
                <small style="color: #166534; font-weight: bold; display: block; font-size: 0.75rem; text-transform: uppercase;">Solde Disponible</small>
                <strong style="color: #15803d; font-size: 1.15rem;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
            </div>

            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-chart-line"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="demande-evenement.php" class="<?php echo $current_page === 'demande-evenement.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-plus"></i> Proposer un Événement
                    </a>
                </li>
                <li>
                    <a href="mes-evenements.php" class="<?php echo $current_page === 'mes-evenements.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i> Mes Événements
                    </a>
                </li>
                <li>
                    <a href="demandes.php" class="<?php echo in_array($current_page, ['demandes.php', 'demande-evenement.php'], true) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-inbox"></i> Mes Demandes
                        <?php if ($badge_demandes_promoteur > 0): ?><span class="badge-count"><?php echo $badge_demandes_promoteur; ?></span><?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="cotisations.php" class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hand-holding-heart"></i> Mes Cotisations
                    </a>
                </li>
                <li>
                    <a href="mes-ventes.php" class="<?php echo $current_page === 'mes-ventes.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-ticket"></i> Mes Ventes & Tickets
                    </a>
                </li>
                <li>
                    <a href="agents.php" class="<?php echo $current_page === 'agents.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-users-gear"></i> Mes Agents de Contrôle
                    </a>
                </li>
                <li>
                    <a href="solde.php" class="<?php echo in_array($current_page, ['solde.php', 'retraits.php'], true) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-wallet"></i> Solde & Retraits
                    </a>
                </li>
                <li>
                    <a href="profil.php" class="<?php echo $current_page === 'profil.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-id-card"></i> Mon Profil Public
                    </a>
                </li>
                <li>
                    <a href="reclamations.php" class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-headset"></i> Réclamations
                    </a>
                </li>
            </ul>

            <!-- Pied de la barre latérale : Déconnexion avec séparation nette -->
            <div class="sidebar-footer" style="margin-top: auto; padding-top: 1.15rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                <a href="../deconnexion.php" class="logout-btn" style="margin: 0; width: 100%;">
                    <i class="fa-solid fa-right-from-bracket"></i> Déconnexion
                </a>
            </div>
        </aside>

        <!-- Contenu principal -->
        <div class="main-content">
