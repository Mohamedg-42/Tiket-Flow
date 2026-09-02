<?php
// ==============================================================================
// EN-TÊTE DE L'ESPACE ADMINISTRATEUR (admin/header.php)
// Administration Control Center - Navigation moderne, Profil Admin & Éléments actifs lumineux
// ==============================================================================

// 1. Connexion à la base de données
require_once '../config/database.php';

// 2. Vérification obligatoire du rôle 'admin'
require_once '../includes/auth.php';
checkRole('admin', '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$admin_page_title = $admin_page_title ?? 'Administration Control Center - Ticket Flow';

// Informations admin connecté
$admin_nom = $_SESSION['user_nom'] ?? ($_SESSION['nom'] ?? 'Administrateur');
$admin_email = $_SESSION['user_email'] ?? ($_SESSION['email'] ?? 'admin@ticketflow.com');
$words = explode(' ', trim($admin_nom));
$admin_initiales = strtoupper(substr($words[0] ?? 'A', 0, 1) . substr($words[1] ?? '', 0, 1));

// 3. Comptage rapide des demandes et notifications
$badge_promoter_reqs = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_event_reqs    = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_withdrawals   = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$badge_claims        = (int)$pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_attente'")->fetchColumn();

try {
    $badge_campagnes_pending = (int)$pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
} catch (PDOException $e) {
    $badge_campagnes_pending = 0;
}
$badge_demandes_all = $badge_event_reqs + $badge_campagnes_pending;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0d9488">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($admin_page_title); ?></title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- CSS Platform & Dashboard Pro -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../Css/dashboard-pro.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../Css/responsive-pro.css">

    <style>
        /* ==============================================================================
           ADMINISTRATION CONTROL CENTER (SIDEBAR LATÉRALE PRO)
           ============================================================================== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 275px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            padding: 1.25rem 1rem;
            background: #0b1329;
            color: #ffffff;
            border-right: 1px solid rgba(255, 255, 255, 0.07);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .ctrl-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.1rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-decoration: none;
        }
        .ctrl-brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.2px;
        }
        /* Harmonisation des variables d'accentuation avec le promoteur */
        :root {
            --dash-primary: #0d9488;
            --dash-primary-light: #f0fdfa;
            --dash-primary-hover: #0f766e;
            --dash-secondary: #0284c7;
            --primary: #0d9488;
            --primary-light: #14b8a6;
            --primary-dark: #0f766e;
        }

        .dash-btn-action.btn-primary,
        .btn-submit {
            background: linear-gradient(135deg, #0d9488, #0284c7) !important;
            border-color: #0d9488 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.3) !important;
        }
        .dash-btn-action.btn-primary:hover,
        .btn-submit:hover {
            background: linear-gradient(135deg, #0f766e, #0369a1) !important;
            box-shadow: 0 6px 18px rgba(13, 148, 136, 0.4) !important;
        }

        .ctrl-brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #0d9488, #0284c7);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.35);
        }
        .ctrl-badge-admin {
            background: rgba(13, 148, 136, 0.2);
            color: #2dd4bf;
            border: 1px solid rgba(45, 212, 191, 0.35);
            font-size: 0.65rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Profil Administrateur */
        .ctrl-user-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 0.65rem 0.75rem;
            margin-bottom: 1.15rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .ctrl-user-card:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(45, 212, 191, 0.3);
        }
        .ctrl-avatar {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: linear-gradient(135deg, #3b82f6, #0d9488);
            color: #ffffff;
            font-weight: 800;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ctrl-user-name {
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }
        .ctrl-user-role {
            color: #10b981;
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Menu & Navigation */
        .ctrl-nav-scroll {
            flex: 1;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding-right: 2px;
            margin-bottom: 0.75rem;
        }
        .ctrl-nav-scroll::-webkit-scrollbar {
            display: none;
        }

        .ctrl-section-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.8px;
            padding: 0.6rem 0.5rem 0.35rem;
            display: block;
        }

        .ctrl-menu {
            list-style: none;
            padding: 0;
            margin: 0 0 0.65rem 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .ctrl-menu li {
            margin: 0;
        }
        .ctrl-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.62rem 0.85rem;
            border-radius: 9px;
            color: #94a3b8;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.18s ease;
            position: relative;
        }
        .ctrl-menu a i {
            font-size: 0.95rem;
            width: 18px;
            text-align: center;
            color: #64748b;
            transition: all 0.18s ease;
        }
        .ctrl-menu a:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(3px);
        }
        .ctrl-menu a:hover i {
            color: #2dd4bf;
        }

        /* ÉLÉMENT ACTIF DANS LE SLIDE LATÉRAL : MÊME DÉGRADÉ & LUMIÈRE QUE PROMOTEUR */
        .ctrl-menu a.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, rgba(13, 148, 136, 0.95), rgba(2, 132, 199, 0.9)) !important;
            font-weight: 800 !important;
            border-left: 4px solid #2dd4bf !important;
            box-shadow: 0 4px 18px rgba(13, 148, 136, 0.45) !important;
            padding-left: calc(0.85rem - 2px);
        }
        .ctrl-menu a.active i {
            color: #ffffff !important;
            filter: drop-shadow(0 0 8px #2dd4bf) !important;
        }
        .ctrl-active-dot {
            margin-left: auto;
            color: #2dd4bf;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            animation: pulse-glow 2s infinite ease-in-out;
        }
        @keyframes pulse-glow {
            0%, 100% { opacity: 0.8; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.15); }
        }

        .badge-count {
            margin-left: auto;
            background: #ef4444;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 999px;
        }

        /* Pied Barre Latérale */
        .ctrl-footer {
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .ctrl-btn-site {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .ctrl-btn-site:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border-color: rgba(99, 102, 241, 0.4);
        }
        .ctrl-btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            color: #f87171;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .ctrl-btn-logout:hover {
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
        }

        /* Barre mobile et overlay coulissant */
        .ctrl-mobile-topbar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 99;
            background: #0b1329;
            padding: 0.75rem 1rem;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .ctrl-slide-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 99;
        }
        @media (max-width: 991px) {
            .ctrl-mobile-topbar {
                display: flex;
            }
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }
            .sidebar.show-slide {
                transform: translateX(0);
                box-shadow: 10px 0 35px rgba(0, 0, 0, 0.5);
            }
            .ctrl-slide-overlay.active {
                display: block;
            }
            .ctrl-close-slide-btn {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                width: 30px;
                height: 30px;
            }
        }
    </style>
</head>

<body class="dash-pro-layout">
    <!-- Barre Mobile avec bouton d'ouverture du slide sur le côté -->
    <div class="ctrl-mobile-topbar">
        <div style="display: flex; align-items: center; gap: 8px; color: #ffffff; font-weight: 800; font-size: 1rem;">
            <div class="ctrl-brand-icon" style="width: 30px; height: 30px; font-size: 0.9rem;">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <span>Admin Control Center</span>
        </div>

        <button type="button" onclick="toggleAdminSidebar(true)" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #ffffff; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-bars-staggered"></i> Menu Admin
        </button>
    </div>

    <!-- Overlay sombre pour fermer le slide en cliquant à l'extérieur -->
    <div id="ctrlAdminOverlay" class="ctrl-slide-overlay" onclick="toggleAdminSidebar(false)"></div>

    <div class="dashboard-wrapper">
        <!-- ==============================================================================
             BARRE LATÉRALE DE CONTRÔLE ADMIN (SLIDE LATÉRAL)
             ============================================================================== -->
        <aside class="sidebar" id="ctrlAdminSidebar">
            <!-- 1. Marque & Titre -->
            <div class="ctrl-brand">
                <a href="dashboard.php" class="ctrl-brand-logo" style="text-decoration: none;">
                    <div class="ctrl-brand-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <span style="display: block; line-height: 1.1;">Ticket Flow</span>
                        <small style="font-size: 0.62rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Super Admin</small>
                    </div>
                </a>
                
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="ctrl-badge-admin">ADMIN</span>
                    <button type="button" onclick="toggleAdminSidebar(false)" style="background: transparent; border: 0; color: #94a3b8; font-size: 1.1rem; cursor: pointer; display: none;" class="ctrl-close-slide-btn">&times;</button>
                </div>
            </div>

            <!-- 2. Profil Administrateur -->
            <div class="ctrl-user-card">
                <div class="ctrl-avatar"><?php echo htmlspecialchars($admin_initiales); ?></div>
                <div style="overflow: hidden; flex: 1;">
                    <span class="ctrl-user-name"><?php echo htmlspecialchars($admin_nom); ?></span>
                    <span class="ctrl-user-role">
                        <i class="fa-solid fa-shield" style="font-size: 0.55rem; color: #10b981;"></i> Accès Total
                    </span>
                </div>
            </div>

            <!-- 3. Navigation Segmentée -->
            <div class="ctrl-nav-scroll">
                <!-- SECTION 1 : VUE GLOBALE & ÉVÉNEMENTS -->
                <span class="ctrl-section-label">Supervision & Billetterie</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>Dashboard Global</span>
                            <?php if ($current_page === 'dashboard.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="evenements.php" class="<?php echo in_array($current_page, ['evenements.php', 'creer-evenement.php', 'modifier-evenement.php'], true) ? 'active' : ''; ?>">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span>Tous les Événements</span>
                            <?php if (in_array($current_page, ['evenements.php', 'creer-evenement.php', 'modifier-evenement.php'], true)): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="votes.php" class="<?php echo $current_page === 'votes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-ranking-star"></i>
                            <span>Votes & Concours</span>
                            <?php if ($current_page === 'votes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="tickets.php" class="<?php echo $current_page === 'tickets.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-ticket"></i>
                            <span>Gestion des Billets</span>
                            <?php if ($current_page === 'tickets.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="commandes.php" class="<?php echo $current_page === 'commandes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-cart-shopping"></i>
                            <span>Commandes Clients</span>
                            <?php if ($current_page === 'commandes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="paiements.php" class="<?php echo $current_page === 'paiements.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-credit-card"></i>
                            <span>Paiements Mobile Money</span>
                            <?php if ($current_page === 'paiements.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 2 : APPROBATIONS & VALIDATIONS -->
                <span class="ctrl-section-label">Validation & Demandes</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="demandes.php" class="<?php echo $current_page === 'demandes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Demandes d'Événements</span>
                            <?php if ($badge_demandes_all > 0): ?>
                                <span class="badge-count"><?php echo $badge_demandes_all; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'demandes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="demandes-promoteurs.php" class="<?php echo $current_page === 'demandes-promoteurs.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Dossiers Promoteurs</span>
                            <?php if ($badge_promoter_reqs > 0): ?>
                                <span class="badge-count"><?php echo $badge_promoter_reqs; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'demandes-promoteurs.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 3 : FINANCES & RETRAITS -->
                <span class="ctrl-section-label">Trésorerie & Opérations</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="cotisations.php" class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                            <span>Campagnes de Cotisation</span>
                            <?php if ($current_page === 'cotisations.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="retraits.php" class="<?php echo $current_page === 'retraits.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-money-bill-transfer"></i>
                            <span>Retraits Promoteurs</span>
                            <?php if ($badge_withdrawals > 0): ?>
                                <span class="badge-count"><?php echo $badge_withdrawals; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'retraits.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 4 : COMPTES & CONTRÔLE TERRAIN -->
                <span class="ctrl-section-label">Utilisateurs & Sécurité</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="utilisateurs.php" class="<?php echo $current_page === 'utilisateurs.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-users"></i>
                            <span>Tous les Utilisateurs</span>
                            <?php if ($current_page === 'utilisateurs.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="promoteurs.php" class="<?php echo $current_page === 'promoteurs.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-user-tie"></i>
                            <span>Comptes Promoteurs</span>
                            <?php if ($current_page === 'promoteurs.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="verification.php" class="<?php echo $current_page === 'verification.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-qrcode"></i>
                            <span>Vérification Billets</span>
                            <?php if ($current_page === 'verification.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="reclamations.php" class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-headset"></i>
                            <span>Support & Tickets</span>
                            <?php if ($badge_claims > 0): ?>
                                <span class="badge-count" style="background: #0284c7;"><?php echo $badge_claims; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'reclamations.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 4. Pied de Page Sidebar -->
            <div class="ctrl-footer">
                <a href="../client/accueil.php" target="_blank" class="ctrl-btn-site">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Voir le Site Public</span>
                </a>
                <a href="../deconnexion.php" class="ctrl-btn-logout" onclick="return confirm('Voulez-vous vous déconnecter de l\'administration ?');">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>

        <script>
        function toggleAdminSidebar(force) {
            const sidebar = document.getElementById('ctrlAdminSidebar');
            const overlay = document.getElementById('ctrlAdminOverlay');
            if (!sidebar) return;

            const isShown = sidebar.classList.contains('show-slide');
            const shouldShow = (typeof force === 'boolean') ? force : !isShown;

            if (shouldShow) {
                sidebar.classList.add('show-slide');
                if (overlay) overlay.classList.add('active');
            } else {
                sidebar.classList.remove('show-slide');
                if (overlay) overlay.classList.remove('active');
            }
        }
        </script>

        <!-- ==============================================================================
             CONTENU PRINCIPAL DE LA PAGE ADMIN
             ============================================================================== -->
        <div class="main-content">