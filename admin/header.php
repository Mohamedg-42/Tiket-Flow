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
$admin_page_title = $admin_page_title ?? 'Administration Control Center - Tikéli';

// Informations admin connecté (Prénom & Nom)
$admin_prenom = trim($_SESSION['user_prenom'] ?? ($_SESSION['prenom'] ?? ''));
$admin_nom_base = trim($_SESSION['user_nom'] ?? ($_SESSION['nom'] ?? ''));

// Si le prénom ou le nom n'est pas encore en session, récupérer directement depuis la base
if ((empty($admin_prenom) || empty($admin_nom_base)) && !empty($_SESSION['user_id'])) {
    try {
        $stmt_admin = $pdo->prepare("SELECT prenom, nom, email FROM users WHERE id = ?");
        $stmt_admin->execute([(int) $_SESSION['user_id']]);
        $u_admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        if ($u_admin) {
            if (empty($admin_prenom) && !empty($u_admin['prenom'])) {
                $admin_prenom = trim($u_admin['prenom']);
                $_SESSION['user_prenom'] = $admin_prenom;
                $_SESSION['prenom'] = $admin_prenom;
            }
            if (empty($admin_nom_base) && !empty($u_admin['nom'])) {
                $admin_nom_base = trim($u_admin['nom']);
                $_SESSION['user_nom'] = $admin_nom_base;
                $_SESSION['nom'] = $admin_nom_base;
            }
        }
    } catch (PDOException $e) {
    }
}

$admin_fullname = trim($admin_prenom . ' ' . $admin_nom_base);
if (empty($admin_fullname)) {
    $admin_fullname = 'Administrateur';
}
$admin_nom = $admin_fullname; // Garantit la compatibilité complète dans tout l'espace admin
$admin_email = $_SESSION['user_email'] ?? ($_SESSION['email'] ?? 'admin@eventia.com');

// Initiales nettes sur 2 lettres (Prénom + Nom)
$admin_initiales = '';
if (!empty($admin_prenom)) {
    $admin_initiales .= mb_strtoupper(mb_substr($admin_prenom, 0, 1, 'UTF-8'), 'UTF-8');
}
if (!empty($admin_nom_base)) {
    $admin_initiales .= mb_strtoupper(mb_substr($admin_nom_base, 0, 1, 'UTF-8'), 'UTF-8');
}
if (empty($admin_initiales)) {
    $words = explode(' ', trim($admin_fullname));
    $admin_initiales = strtoupper(substr($words[0] ?? 'A', 0, 1) . substr($words[1] ?? '', 0, 1));
}
if (empty($admin_initiales)) {
    $admin_initiales = 'AD';
}

// 3. Comptage rapide des demandes et notifications
$badge_promoter_reqs = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_event_reqs = (int) $pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
$badge_withdrawals = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$badge_claims = (int) $pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_attente'")->fetchColumn();

try {
    $badge_campagnes_pending = (int) $pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
} catch (PDOException $e) {
    $badge_campagnes_pending = 0;
}
$badge_demandes_all = $badge_event_reqs + $badge_campagnes_pending;

$badge_my_tasks = 0;
$badge_all_tasks = 0;
try {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $stmt_mt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND statut IN ('a_faire', 'en_cours')");
    $stmt_mt->execute([$uid]);
    $badge_my_tasks = (int) $stmt_mt->fetchColumn();

    $badge_all_tasks = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE statut IN ('a_faire', 'en_cours')")->fetchColumn();
} catch (PDOException $e) {
    $badge_my_tasks = 0;
    $badge_all_tasks = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($admin_page_title); ?></title>
    <link rel="icon" type="image/png" href="../images/logo.png">

    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- CSS Platform & Dashboard Pro -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard-pro.css">
    <!-- Tikéli Brand Design System -->
    <link rel="stylesheet" href="../css/eventia-brand.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../css/responsive-pro.css">

    <style>
        /* ==============================================================================
           ADMINISTRATION CONTROL CENTER (SIDEBAR LATÉRALE TIKÉLI BLEU NUIT)
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
            background: var(--tikeli-black, #000000);
            color: #ffffff;
            border-right: 1px solid rgba(255, 255, 255, 0.07);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.35);
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
            font-family: var(--font-heading, 'Outfit', sans-serif);
        }

        :root {
            --tikeli-orange: #FF4A0D;
            --tikeli-black: #000000;
            --tikeli-white: #FFFFFF;
            --tikeli-gray: #F5F5F5;

            --dash-primary: #121212;
            --dash-primary-light: #fff3ed;
            --dash-primary-hover: #000000;
            --dash-accent: var(--tikeli-orange);
            --dash-secondary: #1e1e1e;
            --primary: #121212;
            --primary-light: #262626;
            --primary-dark: #000000;
            --accent: var(--tikeli-orange);
            --accent-dark: #E03E08;
        }

        .dash-btn-action.btn-primary,
        .btn-submit {
            background: var(--tikeli-orange, #FF4A0D) !important;
            border-color: #E03E08 !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 14px rgba(255, 74, 13, 0.3) !important;
        }

        .dash-btn-action.btn-primary:hover,
        .btn-submit:hover {
            background: #E03E08 !important;
            color: #ffffff !important;
            box-shadow: 0 6px 18px rgba(224, 62, 8, 0.4) !important;
        }

        .ctrl-brand-icon {
            width: 36px;
            height: 36px;
            background: var(--tikeli-orange, #FF4A0D);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(255, 74, 13, 0.35);
        }

        .ctrl-badge-admin {
            background: rgba(255, 74, 13, 0.18);
            color: var(--tikeli-orange, #FF4A0D);
            border: 1px solid rgba(255, 74, 13, 0.35);
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
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .ctrl-avatar {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #181818;
            border: 1px solid rgba(255, 74, 13, 0.4);
            color: var(--tikeli-orange, #FF4A0D);
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
            font-size: 0.7rem;
            color: #737373;
            text-transform: capitalize;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Navigation Menu & Sections */
        .ctrl-nav-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.25) transparent;
            padding-right: 4px;
            margin-bottom: 0.75rem;
        }

        .ctrl-nav-scroll::-webkit-scrollbar {
            width: 5px;
            display: block;
        }

        .ctrl-nav-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .ctrl-nav-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.25);
            border-radius: 999px;
        }

        .ctrl-nav-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.45);
        }

        .ctrl-section-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #737373;
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
            color: #737373;
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
            color: #737373;
            transition: all 0.18s ease;
        }

        .ctrl-menu a:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(3px);
        }

        .ctrl-menu a:hover i {
            color: #F5F5F5;
        }

        /* ÉLÉMENT ACTIF DANS LE SLIDE LATÉRAL : SOBRE & ÉLÉGANT TIKÉLI */
        .ctrl-menu a.active {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.12) !important;
            font-weight: 700 !important;
            border-left: none !important;
            border: none !important;
            box-shadow: none !important;
        }

        .ctrl-menu a.active i {
            color: var(--tikeli-orange, #FF4A0D) !important;
            filter: none !important;
        }

        .ctrl-active-dot {
            margin-left: auto;
            color: var(--tikeli-orange, #FF4A0D);
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
        }

        .badge-count {
            margin-left: auto;
            background: var(--tikeli-orange, #FF4A0D);
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
            color: #737373;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-btn-site:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border-color: rgba(255, 74, 13, 0.4);
        }

        .ctrl-btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-btn-logout:hover {
            background: #ef4444;
            color: #ffffff;
            border-color: #ef4444;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
        }

        /* Barre mobile et overlay coulissant */
        .ctrl-mobile-topbar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 99;
            background: #000000;
            padding: 0.75rem 1rem;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .ctrl-slide-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 19, 41, 0.65);
            backdrop-filter: blur(4px);
            z-index: 99;
        }

        @media (max-width: 991px) {
            .dash-pro-layout .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
            }

            .ctrl-mobile-topbar {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
                height: 100vh;
                height: 100dvh;
                max-height: 100dvh;
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

        /* ==============================================================================
           RÈGLES AVANCÉES DU MODE RÉTRACTABLE (COLLAPSIBLE SIDEBAR)
           ============================================================================== */
        .sidebar {
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.25s ease !important;
        }

        @media (min-width: 992px) {
            .dash-pro-layout .main-content {
                margin-left: 275px !important;
                width: calc(100% - 275px) !important;
                max-width: calc(100vw - 275px) !important;
                transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }
        }

        /* Bouton de rétractation / déploiement de la sidebar */
        .ctrl-collapse-btn {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #737373;
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.85rem;
            flex-shrink: 0;
            box-shadow: none;
        }

        .ctrl-collapse-btn:hover {
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px) scale(1.04);
            box-shadow: none;
        }

        .ctrl-collapse-btn:active {
            transform: translateY(0) scale(0.96);
        }

        .ctrl-collapse-btn i {
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (min-width: 992px) {
            body.sidebar-collapsed .sidebar {
                width: 76px !important;
                padding: 1.15rem 0.5rem !important;
            }

            body.sidebar-collapsed .main-content,
            body.sidebar-collapsed.dash-pro-layout .main-content {
                margin-left: 76px !important;
                width: calc(100% - 76px) !important;
                max-width: calc(100vw - 76px) !important;
                transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }

            body.sidebar-collapsed .dash-container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 1.5rem 2rem 3rem !important;
                box-sizing: border-box !important;
            }

            body.sidebar-collapsed .ctrl-brand {
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                padding-bottom: 0.75rem !important;
                gap: 8px !important;
            }

            body.sidebar-collapsed .ctrl-brand-logo {
                justify-content: center !important;
                margin-bottom: 0 !important;
            }

            body.sidebar-collapsed .ctrl-brand-logo>div:not(.ctrl-brand-icon),
            body.sidebar-collapsed .ctrl-badge-admin,
            body.sidebar-collapsed .ctrl-badge-pro {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-collapse-btn {
                width: 34px !important;
                height: 34px !important;
                background: rgba(255, 255, 255, 0.1) !important;
                color: #ffffff !important;
                border: 1px solid rgba(255, 255, 255, 0.2) !important;
                box-shadow: none !important;
                margin: 4px auto 0 !important;
            }

            body.sidebar-collapsed .ctrl-collapse-btn:hover {
                background: rgba(255, 255, 255, 0.18) !important;
                transform: scale(1.08) !important;
            }

            body.sidebar-collapsed .ctrl-collapse-btn i {
                transform: rotate(180deg);
                font-size: 0.95rem;
            }

            body.sidebar-collapsed .ctrl-user-card {
                padding: 0.5rem 0.25rem !important;
                justify-content: center !important;
            }

            body.sidebar-collapsed .ctrl-user-card>div:not(.ctrl-avatar),
            body.sidebar-collapsed .ctrl-user-info,
            body.sidebar-collapsed .ctrl-user-card>i.fa-chevron-right {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-section-label {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-menu a {
                padding: 0.75rem 0 !important;
                justify-content: center !important;
                border-radius: 9px !important;
                position: relative !important;
            }

            body.sidebar-collapsed .ctrl-menu a span:not(.badge-count),
            body.sidebar-collapsed .ctrl-active-dot {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-menu a i {
                font-size: 1.15rem !important;
                width: auto !important;
                margin: 0 !important;
            }

            body.sidebar-collapsed .ctrl-menu a .badge-count {
                position: absolute;
                top: 4px;
                right: 8px;
                padding: 1px 5px;
                font-size: 0.6rem;
                min-width: 14px;
            }

            /* Tooltip flottant au survol en mode rétracté */
            body.sidebar-collapsed .ctrl-menu a[data-tooltip]::before {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-footer a {
                padding: 0.6rem 0 !important;
                justify-content: center !important;
            }

            body.sidebar-collapsed .ctrl-footer a span {
                display: none !important;
            }
        }

        /* ==============================================================================
           TIKÉLI MINI TOOLTIP BUBBLE (PETITE BULLE DU TITRE DU BOUTON)
           ============================================================================== */
        .eventia-mini-tooltip {
            position: fixed;
            z-index: 99999;
            pointer-events: none;
            background: #000000;
            color: #ffffff;
            border: 1px solid rgba(255, 177, 46, 0.45);
            font-size: 0.76rem;
            font-weight: 700;
            font-family: var(--font-heading, 'Outfit', sans-serif);
            letter-spacing: 0.2px;
            padding: 5px 11px;
            border-radius: 7px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transform: translateX(6px);
            transition: opacity 0.14s ease, transform 0.14s ease, visibility 0.14s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .eventia-mini-tooltip::before {
            content: '';
            position: absolute;
            left: -5px;
            top: 50%;
            transform: translateY(-50%);
            border-width: 5px 5px 5px 0;
            border-style: solid;
            border-color: transparent rgba(255, 177, 46, 0.6) transparent transparent;
        }

        .eventia-mini-tooltip.is-visible {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }

        @media (max-width: 991px) {
            .ctrl-topbar-desktop {
                display: none !important;
            }

            .eventia-mini-tooltip {
                display: none !important;
            }
        }
    </style>
    <script>
        // Pré-application du mode rétracté pour éliminer tout scintillement
        if (localStorage.getItem('eventia_sidebar_collapsed') === '1') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    </script>
</head>

<body class="dash-pro-layout">
    <script>
        if (localStorage.getItem('eventia_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    </script>
    <!-- Barre Mobile avec bouton d'ouverture du slide sur le côté -->
    <div class="ctrl-mobile-topbar">
        <div
            style="display: flex; align-items: center; gap: 8px; color: #ffffff; font-weight: 800; font-size: 1rem; overflow: hidden; min-width: 0;">
            <a href="dashboard.php"
                style="display: inline-flex; align-items: center; text-decoration: none; flex-shrink: 0;">
                <img src="../images/logo.png" alt="Tikéli"
                    style="height: 26px; width: auto; max-width: 95px; object-fit: contain;">
            </a>
            <span style="font-size: 0.8rem; color: #737373; font-weight: 600;">• Admin</span>
        </div>

        <button type="button" onclick="toggleAdminSidebar(true)"
            style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #ffffff; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-bars-staggered"></i> Menu Admin
        </button>
    </div>

    <!-- Overlay sombre pour fermer le slide en cliquant à l'extérieur -->
    <div id="ctrlAdminOverlay" class="ctrl-slide-overlay" onclick="toggleAdminSidebar(false)"></div>

    <div class="dashboard-wrapper">
        <!-- ==============================================================================
             BARRE LATÉRALE DE CONTRÔLE ADMIN (SLIDE LATÉRAL / RÉTRACTABLE)
             ============================================================================== -->
        <aside class="sidebar" id="ctrlAdminSidebar">
            <!-- 1. Marque & Titre -->
            <div class="ctrl-brand">
                <a href="dashboard.php" class="ctrl-brand-logo"
                    style="text-decoration: none; display: flex; align-items: center;">
                    <img src="../images/logo.png" alt="Tikéli"
                        style="height: 34px; width: auto; max-width: 140px; object-fit: contain;">
                </a>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="button" class="ctrl-collapse-btn" onclick="toggleSidebarCollapse()"
                        title="Réduire / Agrandir la barre latérale (Ctrl+B)">
                        <i class="fa-solid fa-angles-left"></i>
                    </button>
                    <button type="button" onclick="toggleAdminSidebar(false)"
                        style="background: transparent; border: 0; color: #737373; font-size: 1.1rem; cursor: pointer; display: none;"
                        class="ctrl-close-slide-btn">&times;</button>
                </div>
            </div>

            <!-- 2. Profil Administrateur -->
            <div class="ctrl-user-card">
                <div class="ctrl-avatar"><?php echo htmlspecialchars($admin_initiales); ?></div>
                <div style="overflow: hidden; flex: 1;">
                    <span class="ctrl-user-name"
                        title="<?php echo htmlspecialchars($admin_fullname); ?>"><?php echo htmlspecialchars($admin_fullname); ?></span>
                    <span class="ctrl-user-role">
                        <i class="fa-solid fa-shield" style="font-size: 0.55rem; color: #FF4A0D;"></i> Administrateur
                    </span>
                </div>
            </div>

            <!-- 3. Navigation Segmentée -->
            <div class="ctrl-nav-scroll">
                <!-- SECTION 1 : VUE GLOBALE & ÉVÉNEMENTS -->
                <span class="ctrl-section-label">Supervision & Billetterie</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>"
                            data-title="Dashboard Global"
                            data-desc="Vue d'ensemble des statistiques, ventes globales, revenus et jauges en direct."
                            data-category="Supervision">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>Dashboard Global</span>
                            <?php if ($current_page === 'dashboard.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="evenements.php"
                            class="<?php echo in_array($current_page, ['evenements.php', 'creer-evenement.php', 'modifier-evenement.php'], true) ? 'active' : ''; ?>"
                            data-title="Tous les Événements"
                            data-desc="Superviser, valider, modifier ou suspendre les événements publiés sur la plateforme."
                            data-category="Billetterie">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span>Tous les Événements</span>
                            <?php if (in_array($current_page, ['evenements.php', 'creer-evenement.php', 'modifier-evenement.php'], true)): ?><span
                                    class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="salles.php" class="<?php echo $current_page === 'salles.php' ? 'active' : ''; ?>"
                            data-title="Salles & Complexes"
                            data-desc="Créer et configurer les salles de spectacle, jauges, zones tarifaires et équipements."
                            data-category="Infrastructures">
                            <i class="fa-solid fa-landmark"></i>
                            <span>Salles & Espaces</span>
                            <?php if ($current_page === 'salles.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="votes.php" class="<?php echo $current_page === 'votes.php' ? 'active' : ''; ?>"
                            data-title="Votes & Concours"
                            data-desc="Gestion des concours, candidats, sessions de vote payantes et classements."
                            data-category="Engagement">
                            <i class="fa-solid fa-ranking-star"></i>
                            <span>Votes & Concours</span>
                            <?php if ($current_page === 'votes.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="tickets.php" class="<?php echo $current_page === 'tickets.php' ? 'active' : ''; ?>"
                            data-title="Gestion des Billets"
                            data-desc="Consulter l'ensemble des billets émis, catégories de places et statuts."
                            data-category="Billetterie">
                            <i class="fa-solid fa-ticket"></i>
                            <span>Gestion des Billets</span>
                            <?php if ($current_page === 'tickets.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="commandes.php" class="<?php echo $current_page === 'commandes.php' ? 'active' : ''; ?>"
                            data-title="Commandes Clients"
                            data-desc="Historique des transactions d'achat, paniers validés et reçus électroniques."
                            data-category="Ventes">
                            <i class="fa-solid fa-cart-shopping"></i>
                            <span>Commandes Clients</span>
                            <?php if ($current_page === 'commandes.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="paiements.php" class="<?php echo $current_page === 'paiements.php' ? 'active' : ''; ?>"
                            data-title="Paiements Mobile Money"
                            data-desc="Suivi des transactions Wave, Orange, MTN, Moov et cartes bancaires."
                            data-category="Finances">
                            <i class="fa-solid fa-credit-card"></i>
                            <span>Paiements Mobile Money</span>
                            <?php if ($current_page === 'paiements.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 2 : APPROBATIONS & VALIDATIONS -->
                <span class="ctrl-section-label">Validation & Demandes</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="demandes.php" class="<?php echo $current_page === 'demandes.php' ? 'active' : ''; ?>"
                            data-title="Demandes d'Événements"
                            data-desc="Examiner, approuver ou refuser les nouveaux événements soumis par les promoteurs."
                            data-category="Validation">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Demandes d'Événements</span>
                            <?php if ($badge_demandes_all > 0): ?>
                                <span class="badge-count"><?php echo $badge_demandes_all; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'demandes.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="demandes-promoteurs.php"
                            class="<?php echo $current_page === 'demandes-promoteurs.php' ? 'active' : ''; ?>"
                            data-title="Dossiers Promoteurs"
                            data-desc="Vérifier les pièces justificatives et octroyer les droits d'organisateur officiel."
                            data-category="Validation">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Dossiers Promoteurs</span>
                            <?php if ($badge_promoter_reqs > 0): ?>
                                <span class="badge-count"><?php echo $badge_promoter_reqs; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'demandes-promoteurs.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 3 : FINANCES & RETRAITS -->
                <span class="ctrl-section-label">Trésorerie & Opérations</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="cotisations.php"
                            class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>"
                            data-title="Campagnes de Cotisation"
                            data-desc="Supervision des cagnottes, tontines et collectes de fonds solidaires."
                            data-category="Trésorerie">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                            <span>Campagnes de Cotisation</span>
                            <?php if ($current_page === 'cotisations.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="retraits.php" class="<?php echo $current_page === 'retraits.php' ? 'active' : ''; ?>"
                            data-title="Retraits Promoteurs"
                            data-desc="Validation et décaissement des gains des organisateurs après leurs événements."
                            data-category="Trésorerie">
                            <i class="fa-solid fa-money-bill-transfer"></i>
                            <span>Retraits Promoteurs</span>
                            <?php if ($badge_withdrawals > 0): ?>
                                <span class="badge-count"><?php echo $badge_withdrawals; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'retraits.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 4 : COMPTES & CONTRÔLE TERRAIN -->
                <span class="ctrl-section-label">Utilisateurs & Sécurité</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="utilisateurs.php"
                            class="<?php echo $current_page === 'utilisateurs.php' ? 'active' : ''; ?>"
                            data-title="Gestion des Comptes"
                            data-desc="Administrer les profils utilisateurs, suspendre ou réactiver des comptes."
                            data-category="Sécurité">
                            <i class="fa-solid fa-users"></i>
                            <span>Gestion des Comptes</span>
                            <?php if ($current_page === 'utilisateurs.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="profils.php" class="<?php echo $current_page === 'profils.php' ? 'active' : ''; ?>"
                            data-title="Profils & Permissions"
                            data-desc="Configuration des rôles personnalisés et matrice des privilèges d'accès."
                            data-category="Sécurité">
                            <i class="fa-solid fa-user-shield"></i>
                            <span>Profils & Permissions</span>
                            <?php if ($current_page === 'profils.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="promoteurs.php"
                            class="<?php echo $current_page === 'promoteurs.php' ? 'active' : ''; ?>"
                            data-title="Comptes Promoteurs"
                            data-desc="Annuaire des organisateurs vérifiés, commissions et volumes de ventes."
                            data-category="Partenaires">
                            <i class="fa-solid fa-user-tie"></i>
                            <span>Comptes Promoteurs</span>
                            <?php if ($current_page === 'promoteurs.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="verification.php"
                            class="<?php echo $current_page === 'verification.php' ? 'active' : ''; ?>"
                            data-title="Vérification Billets"
                            data-desc="Outil de contrôle d'accès universel pour tester et scanner les QR codes."
                            data-category="Terrain">
                            <i class="fa-solid fa-qrcode"></i>
                            <span>Vérification Billets</span>
                            <?php if ($current_page === 'verification.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="reclamations.php"
                            class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>"
                            data-title="Support & Réclamations"
                            data-desc="Gestion des signalements, litiges et demandes d'assistance des utilisateurs."
                            data-category="Support">
                            <i class="fa-solid fa-headset"></i>
                            <span>Support & Tickets</span>
                            <?php if ($badge_claims > 0): ?>
                                <span class="badge-count" style="background: #FF4A0D;"><?php echo $badge_claims; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'reclamations.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 5 : ORGANISATION & AUDIT -->
                <span class="ctrl-section-label">Organisation & Audit</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="taches.php" class="<?php echo $current_page === 'taches.php' ? 'active' : ''; ?>"
                            data-title="Toutes les Tâches"
                            data-desc="Suivi de la feuille de route, assignation des tâches et to-do list d'équipe."
                            data-category="Organisation">
                            <i class="fa-solid fa-list-check"></i>
                            <span>Toutes les Tâches</span>
                            <?php if ($badge_all_tasks > 0): ?>
                                <span class="badge-count"
                                    style="background: var(--accent);"><?php echo $badge_all_tasks; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'taches.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="mes-taches.php"
                            class="<?php echo $current_page === 'mes-taches.php' ? 'active' : ''; ?>"
                            data-title="Mes Tâches Personnelles"
                            data-desc="Consulter et marquer comme terminées vos tâches administratives assignées."
                            data-category="Organisation">
                            <i class="fa-solid fa-thumbtack"></i>
                            <span>Mes Tâches</span>
                            <?php if ($badge_my_tasks > 0): ?>
                                <span class="badge-count" style="background: #FF4A0D;"><?php echo $badge_my_tasks; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'mes-taches.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="activite.php" class="<?php echo $current_page === 'activite.php' ? 'active' : ''; ?>"
                            data-title="Journal d'Activité"
                            data-desc="Historique en temps réel des connexions, modifications et validations."
                            data-category="Audit">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            <span>Journal d'Activité</span>
                            <?php if ($current_page === 'activite.php'): ?><span class="ctrl-active-dot"><i
                                        class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 4. Pied de Page Sidebar -->
            <div class="ctrl-footer">
                <a href="../client/accueil.php" target="_blank" class="ctrl-btn-site" data-title="Voir le Site Public"
                    data-desc="Consulter la vitrine publique Tikéli et les événements en ligne."
                    data-category="Raccourci">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Voir le Site Public</span>
                </a>
                <a href="../deconnexion.php" class="ctrl-btn-logout" data-title="Déconnexion"
                    data-desc="Fermer votre session administrateur en toute sécurité." data-category="Sécurité"
                    onclick="return confirm('Voulez-vous vous déconnecter de l\'administration ?');">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>

        <!-- Petite bulle de titre au survol -->
        <div id="eventiaMiniTooltip" class="eventia-mini-tooltip"></div>

        <script>
            function toggleSidebarCollapse() {
                const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('eventia_sidebar_collapsed', isCollapsed ? '1' : '0');
            }

            // Raccourci clavier Ctrl+B / Cmd+B pour rétracter ou agrandir
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                    e.preventDefault();
                    toggleSidebarCollapse();
                }
            });

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

            // Affichage de la bulle de titre au survol des boutons
            document.addEventListener('DOMContentLoaded', function () {
                const tooltip = document.getElementById('eventiaMiniTooltip');
                if (!tooltip) return;

                const targets = document.querySelectorAll('.ctrl-menu a, .ctrl-footer a, .ctrl-user-card, .ctrl-collapse-btn');

                targets.forEach(el => {
                    el.addEventListener('mouseenter', function () {
                        if (window.innerWidth < 992) return;

                        const title = this.getAttribute('data-title') || this.getAttribute('title') || this.innerText.trim();
                        if (!title) return;

                        tooltip.textContent = title;

                        const rect = this.getBoundingClientRect();
                        const ttHeight = tooltip.offsetHeight || 26;
                        let topPos = rect.top + (rect.height / 2) - (ttHeight / 2);
                        if (topPos < 10) topPos = 10;
                        if (topPos + 40 > window.innerHeight) topPos = window.innerHeight - 45;

                        const leftPos = rect.right + 10;

                        tooltip.style.top = topPos + 'px';
                        tooltip.style.left = leftPos + 'px';
                        tooltip.classList.add('is-visible');
                    });

                    el.addEventListener('mouseleave', function () {
                        tooltip.classList.remove('is-visible');
                    });
                });
            });
        </script>

        <!-- ==============================================================================
             CONTENU PRINCIPAL DE LA PAGE ADMIN
             ============================================================================== -->
        <div class="main-content">