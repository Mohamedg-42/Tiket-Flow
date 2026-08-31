<?php
// ==============================================================================
// EN-TÊTE DE L'ESPACE ADMINISTRATEUR (admin/header.php)
// Inclut la connexion BD, vérifie le rôle admin et affiche la barre latérale
// ==============================================================================

// 1. Connexion à la base de données
require_once '../config/database.php';

// 2. Vérification obligatoire du rôle 'admin'
require_once '../includes/auth.php';
checkRole('admin', '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$admin_page_title = $admin_page_title ?? 'Administration - Ticket Flow';

// Comptage rapide des demandes en attente pour les badges du menu
$badge_promoter_reqs = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_event_reqs    = (int) $pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_withdrawals   = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$badge_claims        = (int) $pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_attente'")->fetchColumn();

// Demandes de l'espace "Demandes" : événements + campagnes de cotisation en attente
$badge_event_reqs_all = (int) $pdo->query("SELECT COUNT(*) FROM event_requests")->fetchColumn();
try {
    $badge_campagnes_pending = (int) $pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
} catch (PDOException $e) {
    $badge_campagnes_pending = 0; // Table non migrée
}
$badge_demandes_all = $badge_event_reqs_all + $badge_campagnes_pending;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($admin_page_title); ?></title>
    <!-- Icônes FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Fichier CSS principal -->
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
        .stats-grid-compact {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .action-card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: var(--ink);
            transition: all 0.2s ease;
        }
        .action-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .action-card .badge-pending {
            background: #f59e0b;
            color: #fff;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Barre latérale de navigation pour l'Admin -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <i class="fa-solid fa-ticket"></i> TICKET FLOW
            </div>

            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-chart-line"></i> Dashboard
                    </a>
                </li>
                
                <li>
                    <a href="evenements.php" class="<?php echo in_array($current_page, ['evenements.php', 'creer-evenement.php', 'modifier-evenement.php'], true) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i> Événements
                    </a>
                </li>

                <li>
                    <a href="demandes-evenements.php" class="<?php echo $current_page === 'demandes-evenements.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-plus"></i> Demandes Événements
                        <?php if ($badge_event_reqs > 0): ?>
                            <span class="badge-count"><?php echo $badge_event_reqs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="tickets.php" class="<?php echo $current_page === 'tickets.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-ticket"></i> Types de Tickets
                    </a>
                </li>

                <li>
                    <a href="commandes.php" class="<?php echo $current_page === 'commandes.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-cart-shopping"></i> Commandes
                    </a>
                </li>

                <li>
                    <a href="paiements.php" class="<?php echo $current_page === 'paiements.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-credit-card"></i> Paiements
                    </a>
                </li>

                <li>
                    <a href="demandes.php" class="<?php echo $current_page === 'demandes.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-inbox"></i> Demandes
                        <?php if ($badge_demandes_all > 0): ?>
                            <span class="badge-count"><?php echo $badge_demandes_all; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="cotisations.php" class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hand-holding-heart"></i> Cotisations
                    </a>
                </li>

                <li>
                    <a href="utilisateurs.php" class="<?php echo $current_page === 'utilisateurs.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-users"></i> Utilisateurs
                    </a>
                </li>

                <li>
                    <a href="promoteurs.php" class="<?php echo $current_page === 'promoteurs.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-user-tie"></i> Promoteurs
                    </a>
                </li>

                <li>
                    <a href="demandes-promoteurs.php" class="<?php echo $current_page === 'demandes-promoteurs.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-id-card"></i> Demandes Promoteurs
                        <?php if ($badge_promoter_reqs > 0): ?>
                            <span class="badge-count"><?php echo $badge_promoter_reqs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="retraits.php" class="<?php echo $current_page === 'retraits.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-money-bill-transfer"></i> Retraits
                        <?php if ($badge_withdrawals > 0): ?>
                            <span class="badge-count"><?php echo $badge_withdrawals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="reclamations.php" class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-headset"></i> Réclamations
                        <?php if ($badge_claims > 0): ?>
                            <span class="badge-count"><?php echo $badge_claims; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="verification.php" class="<?php echo $current_page === 'verification.php' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-qrcode"></i> Vérification
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