<?php
// ==============================================================================
// EN-TÊTE ESPACE AGENT (agent/header.php)
// Navigation pour les agents de contrôle et vérification
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

// Seul un agent ou un admin peut accéder
checkRole(['agent', 'admin'], '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'Espace Agent - Tikéli';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#FF4A0D">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- QR Code Scanner Library -->
    <link rel="stylesheet" href="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Tikéli Brand Design System -->
    <link rel="stylesheet" href="../css/eventia-brand.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../css/responsive-pro.css">
    <style>
        /* Responsive Navigation Agent */
        @media (max-width: 767px) {
            .client-nav-toggle {
                display: inline-flex !important;
            }
            .client-nav {
                position: fixed;
                top: 60px;
                left: 0;
                right: 0;
                background: #000000 !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.12);
                flex-direction: column;
                align-items: stretch !important;
                padding: 1rem 1.25rem 1.5rem !important;
                gap: 0.65rem !important;
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5);
                display: none !important;
                z-index: 1000;
            }
            .client-nav.nav-open,
            .client-nav.active {
                display: flex !important;
            }
            .client-nav a {
                width: 100%;
                padding: 0.75rem 1rem !important;
                border-radius: 10px !important;
                box-sizing: border-box;
            }
        }
        @media (min-width: 768px) {
            .client-nav-toggle {
                display: none !important;
            }
            .client-nav {
                display: flex !important;
            }
        }
    </style>
</head>

<body class="client-page" style="background-color: var(--tikeli-gray, #F5F5F5); min-height: 100vh; display: flex; flex-direction: column;">

    <header class="client-header shared-client-header"
        style="background: var(--tikeli-black, #000000); color: #ffffff; border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.85rem clamp(1rem, 4vw, 2.5rem); display: flex; align-items: center; justify-content: space-between; position: relative; z-index: 100; box-shadow: 0 4px 20px rgba(0,0,0,0.12);">
        <a href="verification.php" class="client-brand" style="text-decoration: none; display: inline-flex; align-items: center; gap: 10px;">
            <img src="../images/logo.png" alt="Tikéli" style="height: 34px; width: auto; max-width: 120px; object-fit: contain;">
            <span style="font-size: 0.72rem; font-weight: 800; background: rgba(255, 74, 13, 0.18); color: var(--tikeli-orange, #FF4A0D); border: 1px solid rgba(255, 74, 13, 0.35); padding: 2px 7px; border-radius: 6px; letter-spacing: 0.5px;">AGENT</span>
        </a>

        <button type="button" class="client-nav-toggle" onclick="toggleClientNav(event)" aria-label="Ouvrir le menu"
            aria-controls="clientNav" aria-expanded="false"
            style="border-color: rgba(255,255,255,0.25); background: rgba(255,255,255,0.08); color: #ffffff;">
            <i class="fa-solid fa-bars"></i>
        </button>

        <nav class="client-nav" id="clientNav" style="display: flex; align-items: center; gap: 8px;">
            <a href="verification.php" class="<?php echo $current_page === 'verification.php' ? 'active' : ''; ?>"
                style="display: inline-flex; align-items: center; gap: 7px; padding: 0.5rem 0.9rem; border-radius: 8px; font-size: 0.86rem; font-weight: 600; text-decoration: none; color: <?php echo $current_page === 'verification.php' ? '#ffffff' : '#737373'; ?>; background: <?php echo $current_page === 'verification.php' ? 'rgba(255,255,255,0.1)' : 'transparent'; ?>; border-bottom: <?php echo $current_page === 'verification.php' ? '2px solid var(--tikeli-orange, #FF4A0D)' : '2px solid transparent'; ?>;">
                <i class="fa-solid fa-camera" style="<?php echo $current_page === 'verification.php' ? 'color: var(--tikeli-orange, #FF4A0D);' : ''; ?>"></i> Scanner / Vérifier
            </a>
            <a href="historique.php" class="<?php echo $current_page === 'historique.php' ? 'active' : ''; ?>"
                style="display: inline-flex; align-items: center; gap: 7px; padding: 0.5rem 0.9rem; border-radius: 8px; font-size: 0.86rem; font-weight: 600; text-decoration: none; color: <?php echo $current_page === 'historique.php' ? '#ffffff' : '#737373'; ?>; background: <?php echo $current_page === 'historique.php' ? 'rgba(255,255,255,0.1)' : 'transparent'; ?>; border-bottom: <?php echo $current_page === 'historique.php' ? '2px solid var(--tikeli-orange, #FF4A0D)' : '2px solid transparent'; ?>;">
                <i class="fa-solid fa-clock-rotate-left" style="<?php echo $current_page === 'historique.php' ? 'color: var(--tikeli-orange, #FF4A0D);' : ''; ?>"></i> Historique des scans
            </a>
            <a href="../deconnexion.php" class="client-logout" style="color: #E5E5E5; background: rgba(239, 68, 68, 0.12); padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-right-from-bracket"></i> Déconnexion
            </a>
        </nav>
    </header>

    <script>
        // ========== HAMBURGER MENU - SIMPLIFIÉ ET FIABLE ==========

        // État du menu
        let menuOpen = false;

        // Toggle du menu
        function toggleClientNav(event) {
            if (event) event.stopPropagation();

            const nav = document.getElementById('clientNav');
            const btn = document.querySelector('.client-nav-toggle');

            if (!nav || !btn) return;

            menuOpen = !menuOpen;

            if (menuOpen) {
                // Ouvrir le menu
                nav.classList.add('nav-open', 'active');
                btn.setAttribute('aria-expanded', 'true');
                btn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                document.body.style.overflow = 'hidden';
            } else {
                // Fermer le menu
                closeMenu();
            }
        }

        // Fonction pour fermer le menu
        function closeMenu() {
            const nav = document.getElementById('clientNav');
            const btn = document.querySelector('.client-nav-toggle');

            if (!nav) return;

            menuOpen = false;
            nav.classList.remove('nav-open', 'active');

            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.innerHTML = '<i class="fa-solid fa-bars"></i>';
            }

            document.body.style.overflow = '';
        }

        // Au chargement complet du DOM
        document.addEventListener('DOMContentLoaded', function () {
            const nav = document.getElementById('clientNav');
            const btn = document.querySelector('.client-nav-toggle');
            const navLinks = document.querySelectorAll('.client-nav a');

            if (!nav || !btn) return;

            // Fermer au clic sur un lien du menu
            navLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    // Autoriser la navigation
                    setTimeout(() => {
                        closeMenu();
                    }, 100);
                });
            });

            // Fermer le menu au clic en dehors
            document.addEventListener('click', function (event) {
                if (menuOpen) {
                    // Si le clic n'est pas sur le header/nav/btn
                    const header = document.querySelector('.client-header');
                    if (header && !header.contains(event.target) && !nav.contains(event.target)) {
                        closeMenu();
                    }
                }
            });

            // Fermer au redimensionnement
            window.addEventListener('resize', function () {
                if (window.innerWidth > 767 && menuOpen) {
                    closeMenu();
                }
            });
        });
    </script>