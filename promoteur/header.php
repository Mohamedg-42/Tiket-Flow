<?php
// ==============================================================================
// CENTRE DE CONTRÔLE LATÉRAL PROMOTEUR (promoteur/header.php)
// Navigation moderne Dashboard Pro, profil actif, solde & raccourcis opérationnels
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

// Seul le rôle 'promoteur' ou 'admin' peut accéder
checkRole(['promoteur', 'admin'], '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'Centre de Contrôle Promoteur - Eventia';
$user_id = (int) $_SESSION['user_id'];

// 1. Récupération des informations et du solde du promoteur
$stmt_p = $pdo->prepare("
    SELECT u.nom AS user_nom, u.prenom AS user_prenom, u.email AS user_email,
           p.id AS promoter_id, p.nom_commercial, p.solde, p.statut
    FROM users u 
    LEFT JOIN promoters p ON p.user_id = u.id 
    WHERE u.id = ?
");
$stmt_p->execute([$user_id]);
$promoter_profile = $stmt_p->fetch(PDO::FETCH_ASSOC);

$solde_actuel = ($promoter_profile && isset($promoter_profile['solde'])) ? (float) $promoter_profile['solde'] : 0.00;

// Nom et Prénom du promoteur (priorité à la base puis session)
$user_prenom = trim($promoter_profile['user_prenom'] ?? ($_SESSION['user_prenom'] ?? ($_SESSION['prenom'] ?? '')));
$user_nom    = trim($promoter_profile['user_nom'] ?? ($_SESSION['user_nom'] ?? ($_SESSION['nom'] ?? '')));

if (!empty($user_prenom)) {
    $_SESSION['user_prenom'] = $user_prenom;
    $_SESSION['prenom'] = $user_prenom;
}
if (!empty($user_nom)) {
    $_SESSION['user_nom'] = $user_nom;
    $_SESSION['nom'] = $user_nom;
}

$user_full_name = trim($user_prenom . ' ' . $user_nom);
if (empty($user_full_name)) {
    $user_full_name = 'Promoteur';
}

$nom_commercial = trim($promoter_profile['nom_commercial'] ?? '');
$nom_affiche = $user_full_name;
$nom_court = !empty($user_full_name) ? $user_full_name : (!empty($nom_commercial) ? $nom_commercial : 'Promoteur');

// Initiales pour l'avatar : première lettre du prénom + première lettre du nom
$initiales = '';
if (!empty($user_prenom)) {
    $initiales .= mb_strtoupper(mb_substr($user_prenom, 0, 1, 'UTF-8'), 'UTF-8');
}
if (!empty($user_nom)) {
    $initiales .= mb_strtoupper(mb_substr($user_nom, 0, 1, 'UTF-8'), 'UTF-8');
}
if (empty($initiales)) {
    $words = explode(' ', trim($user_full_name));
    $initiales = strtoupper(substr($words[0] ?? 'P', 0, 1) . substr($words[1] ?? '', 0, 1));
}
if (empty($initiales)) {
    $initiales = 'PR';
}

// 2. Badge "Mes Demandes" : événements en attente + campagnes de cotisations en attente
$stmt_bd = $pdo->prepare("SELECT COUNT(*) FROM event_requests WHERE user_id = ? AND statut = 'en_attente'");
$stmt_bd->execute([$user_id]);
$badge_demandes_promoteur = (int) $stmt_bd->fetchColumn();

try {
    $stmt_bc = $pdo->prepare("SELECT COUNT(*) FROM cotisation_campagnes WHERE user_id = ? AND statut = 'en_attente'");
    $stmt_bc->execute([$user_id]);
    $badge_demandes_promoteur += (int) $stmt_bc->fetchColumn();
} catch (PDOException $e) {
    // Table non encore migrée
}

// 3. Badge "Réclamations" : tickets en attente ou en cours
$stmt_cl = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND statut IN ('en_attente', 'en_cours')");
$stmt_cl->execute([$user_id]);
$badge_claims_promoteur = (int) $stmt_cl->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../images/logo.png">

    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- FontAwesome 6.5.2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Styles CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard-pro.css">
    <!-- Eventia Brand Design System -->
    <link rel="stylesheet" href="../css/eventia-brand.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../css/responsive-pro.css">

    <style>
        /* ==============================================================================
           CENTRE DE CONTRÔLE LATÉRAL (SIDEBAR EVENTIA BLEU NUIT)
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
            background: linear-gradient(135deg, var(--tikeli-orange, #FF4A0D), #E03E08);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(255, 74, 13, 0.35);
        }

        .ctrl-badge-pro {
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

        /* Profil utilisateur dans le centre de contrôle */
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

        .ctrl-user-info {
            overflow: hidden;
            flex: 1;
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
            color: var(--tikeli-orange, #FF4A0D);
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Widget Solde Retirable */
        .ctrl-wallet-box {
            background: linear-gradient(135deg, rgba(255, 74, 13, 0.15), rgba(255, 74, 13, 0.05));
            border: 1px solid rgba(255, 74, 13, 0.35);
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
            margin-bottom: 1.15rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ctrl-wallet-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #ff8b60;
            letter-spacing: 0.5px;
            display: block;
        }

        .ctrl-wallet-amount {
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .ctrl-wallet-btn {
            background: var(--tikeli-orange, #FF4A0D);
            color: #ffffff;
            padding: 4px 9px;
            border-radius: 7px;
            font-size: 0.72rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .ctrl-wallet-btn:hover {
            background: #E03E08;
            transform: scale(1.03);
        }

        /* Navigation Menu & Sections */
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
            color: #737373;
            letter-spacing: 0.8px;
            padding: 0.6rem 0.5rem 0.35rem;
            display: block;
        }

        .ctrl-menu {
            list-style: none;
            padding: 0;
            margin: 0 0 0.65rem;
            display: flex;
            flex-direction: column;
            gap: 3px;
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

        /* ÉLÉMENT ACTIF DANS LE SLIDE LATÉRAL : SOBRE & ÉLÉGANT EVENTIA */
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

        /* Footer Barre Latérale */
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
            border-color: rgba(99, 102, 241, 0.4);
        }

        .ctrl-btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            color: #000000;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-btn-logout:hover {
            background: rgba(239, 68, 68, 0.12);
            color: #E5E5E5;
        }

        /* Barre mobile et overlay coulissant (identique à l'admin) */
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

            body.sidebar-collapsed .ctrl-brand-logo > div:not(.ctrl-brand-icon),
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

            body.sidebar-collapsed .ctrl-user-card > div:not(.ctrl-avatar),
            body.sidebar-collapsed .ctrl-user-info,
            body.sidebar-collapsed .ctrl-user-card > i.fa-chevron-right {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-section-label {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-wallet-box {
                padding: 0.5rem 0.25rem !important;
                text-align: center !important;
            }

            body.sidebar-collapsed .ctrl-wallet-box .ctrl-wallet-label,
            body.sidebar-collapsed .ctrl-wallet-box a,
            body.sidebar-collapsed .ctrl-wallet-box a span {
                display: none !important;
            }

            body.sidebar-collapsed .ctrl-wallet-amount {
                font-size: 0.72rem !important;
                letter-spacing: -0.5px;
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
           EVENTIA MINI TOOLTIP BUBBLE (PETITE BULLE DU TITRE DU BOUTON)
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
    <!-- Barre Mobile avec bouton d'ouverture du slide sur le côté (identique à l'admin) -->
    <div class="ctrl-mobile-topbar">
        <div style="display: flex; align-items: center; gap: 8px; color: #ffffff; font-weight: 800; font-size: 0.92rem; overflow: hidden; min-width: 0;">
            <a href="dashboard.php" style="display: inline-flex; align-items: center; text-decoration: none; flex-shrink: 0;">
                <img src="../images/logo.png" alt="Tikéli" style="height: 26px; width: auto; max-width: 95px; object-fit: contain;">
            </a>
            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.8rem; color: #737373;">
                • <?php echo htmlspecialchars($nom_court ?? $user_full_name ?? 'Promoteur'); ?>
            </span>
        </div>

        <button type="button" onclick="togglePromoterSidebar(true)" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: #ffffff; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-bars-staggered"></i> Menu Promoteur
        </button>
    </div>

    <!-- Overlay sombre pour fermer le slide en cliquant à l'extérieur -->
    <div id="ctrlPromoterOverlay" class="ctrl-slide-overlay" onclick="togglePromoterSidebar(false)"></div>

    <div class="dashboard-wrapper">
        <!-- ==============================================================================
             CENTRE DE CONTRÔLE LATÉRAL (SIDEBAR MODERNE / RÉTRACTABLE)
             ============================================================================== -->
        <aside class="sidebar" id="ctrlPromoterSidebar">
            <!-- 1. En-tête de Marque / Centre de Contrôle -->
            <div class="ctrl-brand">
                <a href="dashboard.php" class="ctrl-brand-logo" style="text-decoration: none; display: flex; align-items: center;">
                    <img src="../images/logo.png" alt="Tikéli" style="height: 34px; width: auto; max-width: 140px; object-fit: contain;">
                </a>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="button" class="ctrl-collapse-btn" onclick="toggleSidebarCollapse()" title="Réduire / Agrandir la barre latérale (Ctrl+B)">
                        <i class="fa-solid fa-angles-left"></i>
                    </button>
                    <button type="button" onclick="togglePromoterSidebar(false)" style="background: transparent; border: 0; color: #737373; font-size: 1.1rem; cursor: pointer; display: none;" class="ctrl-close-slide-btn">&times;</button>
                </div>
            </div>

            <!-- 2. Profil Promoteur Connecté -->
            <a href="profil.php" class="ctrl-user-card" data-title="Mon Profil Promoteur" data-desc="Consulter et mettre à jour vos coordonnées commerciales et statut KYC." data-category="Compte">
                <div class="ctrl-avatar"><?php echo htmlspecialchars($initiales); ?></div>
                <div class="ctrl-user-info">
                    <span class="ctrl-user-name" title="<?php echo htmlspecialchars($user_full_name); ?>"><?php echo htmlspecialchars($user_full_name); ?></span>
                    <span class="ctrl-user-role">
                        <?php if (!empty($nom_commercial)): ?>
                            <i class="fa-solid fa-briefcase" style="font-size: 0.55rem; color: #FF4A0D;"></i> <?php echo htmlspecialchars($nom_commercial); ?>
                        <?php else: ?>
                            <i class="fa-solid fa-circle" style="font-size: 0.45rem; color: #FF4A0D;"></i> Promoteur Certifié
                        <?php endif; ?>
                    </span>
                </div>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: #737373;"></i>
            </a>

            <!-- 3. Widget Trésorerie & Solde Retirable -->
            <div class="ctrl-wallet-box">
                <div>
                    <span class="ctrl-wallet-label">Solde Retirable</span>
                    <strong class="ctrl-wallet-amount"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> <span
                            style="font-size: 0.75rem; font-weight: 700;">FCFA</span></strong>
                </div>
                <a href="solde.php#virement-box" class="ctrl-wallet-btn" data-title="Demande de Retrait" data-desc="Transférer vos gains immédiatement vers Wave, Orange Money ou MTN Mobile Money." data-category="Trésorerie">
                    <i class="fa-solid fa-bolt"></i> Retirer
                </a>
            </div>

            <!-- 4. Navigation Segmentée par Pôles d'Activités -->
            <div class="ctrl-nav-scroll">
                <!-- SECTION 1 : PILOTAGE & ÉVÉNEMENTS -->
                <span class="ctrl-section-label">Pilotage & Événements</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="dashboard.php"
                            class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>" data-title="Tableau de Bord" data-desc="Suivi des ventes, revenus nets et jauges de vos événements en temps réel." data-category="Pilotage">
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Tableau de Bord</span>
                            <?php if ($current_page === 'dashboard.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="mes-evenements.php"
                            class="<?php echo in_array($current_page, ['mes-evenements.php', 'ticket-types.php'], true) ? 'active' : ''; ?>" data-title="Mes Événements" data-desc="Créer, modifier, tarifer et gérer vos événements et types de billets." data-category="Événements">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span>Mes Événements</span>
                            <?php if (in_array($current_page, ['mes-evenements.php', 'ticket-types.php'], true)): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="votes.php" class="<?php echo $current_page === 'votes.php' ? 'active' : ''; ?>" data-title="Concours & Votes" data-desc="Créer des concours de vote payants, ajouter des candidats et suivre le classement." data-category="Engagement">
                            <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i>
                            <span>Concours & Votes</span>
                            <?php if ($current_page === 'votes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="demande-evenement.php"
                            class="<?php echo $current_page === 'demande-evenement.php' ? 'active' : ''; ?>" data-title="Proposer un Événement" data-desc="Soumettre un nouvel événement à la validation de l'administration Eventia." data-category="Événements">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>Proposer un Événement</span>
                            <?php if ($current_page === 'demande-evenement.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="demandes.php" class="<?php echo $current_page === 'demandes.php' ? 'active' : ''; ?>" data-title="Mes Demandes de Validation" data-desc="Statut de validation de vos demandes d'événements et retours de modération." data-category="Suivi">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Mes Demandes</span>
                            <?php if ($badge_demandes_promoteur > 0): ?>
                                <span class="badge-count"><?php echo $badge_demandes_promoteur; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'demandes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 2 : FINANCES & COMMERCIAL -->
                <span class="ctrl-section-label">Finances & Recettes</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="mes-ventes.php"
                            class="<?php echo $current_page === 'mes-ventes.php' ? 'active' : ''; ?>" data-title="Ventes & Billetterie" data-desc="Liste des participants, commandes validées et export des listes d'acheteurs." data-category="Commercial">
                            <i class="fa-solid fa-receipt"></i>
                            <span>Ventes & Billetterie</span>
                            <?php if ($current_page === 'mes-ventes.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="cotisations.php"
                            class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>" data-title="Mes Cotisations" data-desc="Créer des cagnottes, tontines et collectes pour financer vos projets." data-category="Financement">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                            <span>Mes Cotisations</span>
                            <?php if ($current_page === 'cotisations.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="solde.php" class="<?php echo $current_page === 'solde.php' ? 'active' : ''; ?>" data-title="Solde & Retraits" data-desc="Consulter vos recettes nettes et demander un virement Mobile Money instantané." data-category="Trésorerie">
                            <i class="fa-solid fa-wallet"></i>
                            <span>Solde & Retraits</span>
                            <?php if ($current_page === 'solde.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 3 : OPÉRATIONS & SÉCURITÉ -->
                <span class="ctrl-section-label">Opérations Terrain</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="agents.php" class="<?php echo $current_page === 'agents.php' ? 'active' : ''; ?>" data-title="Agents de Contrôle" data-desc="Créer des comptes agents et les assigner pour scanner les QR codes aux entrées." data-category="Contrôle">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Agents de Contrôle</span>
                            <?php if ($current_page === 'agents.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 4 : MARQUE & SUPPORT -->
                <span class="ctrl-section-label">Image & Assistance</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="profil.php" class="<?php echo $current_page === 'profil.php' ? 'active' : ''; ?>" data-title="Mon Profil Public" data-desc="Gérer votre logo, description d'organisateur et coordonnées publiques." data-category="Profil">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Mon Profil Public</span>
                            <?php if ($current_page === 'profil.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="reclamations.php"
                            class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>" data-title="Support & Réclamations" data-desc="Contacter l'administration Eventia en cas de litige ou assistance technique." data-category="Support">
                            <i class="fa-solid fa-headset"></i>
                            <span>Support & Tickets</span>
                            <?php if ($badge_claims_promoteur > 0): ?>
                                <span class="badge-count"
                                    style="background: #FF4A0D;"><?php echo $badge_claims_promoteur; ?></span>
                            <?php endif; ?>
                            <?php if ($current_page === 'reclamations.php'): ?><span class="ctrl-active-dot"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 5. Pied du Centre de Contrôle -->
            <div class="ctrl-footer">
                <a href="../client/accueil.php" target="_blank" class="ctrl-btn-site" data-title="Voir le Site Public" data-desc="Consulter la vitrine publique Eventia et les événements en ligne." data-category="Raccourci">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Voir le Site Public</span>
                </a>
                <a href="../deconnexion.php" class="ctrl-btn-logout" data-title="Déconnexion" data-desc="Fermer votre session promoteur en toute sécurité." data-category="Sécurité"
                    onclick="return confirm('Voulez-vous vous déconnecter de votre espace promoteur ?');">
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
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                toggleSidebarCollapse();
            }
        });

        function togglePromoterSidebar(force) {
            const sidebar = document.getElementById('ctrlPromoterSidebar');
            const overlay = document.getElementById('ctrlPromoterOverlay');
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
        document.addEventListener('DOMContentLoaded', function() {
            const tooltip = document.getElementById('eventiaMiniTooltip');
            if (!tooltip) return;

            const targets = document.querySelectorAll('.ctrl-menu a, .ctrl-footer a, .ctrl-user-card, .ctrl-wallet-btn, .ctrl-collapse-btn');

            targets.forEach(el => {
                el.addEventListener('mouseenter', function() {
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

                el.addEventListener('mouseleave', function() {
                    tooltip.classList.remove('is-visible');
                });
            });
        });
        </script>

        <!-- ==============================================================================
             CONTENU PRINCIPAL DE LA PAGE
             ============================================================================== -->
        <div class="main-content">