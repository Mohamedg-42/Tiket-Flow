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
$page_title = $page_title ?? 'Espace Agent - Ticket Flow';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0d9488">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- QR Code Scanner Library -->
    <link rel="stylesheet" href="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../css/responsive-pro.css">
</head>
<body class="client-page">

<header class="client-header shared-client-header" style="background: #0f172a; color: #ffffff;">
    <a href="verification.php" class="client-brand" style="color: #38bdf8;">
        <i class="fa-solid fa-qrcode"></i> AGENT DE CONTRÔLE
    </a>

    <button type="button" class="client-nav-toggle" onclick="toggleClientNav(event)" aria-label="Ouvrir le menu" aria-controls="clientNav" aria-expanded="false" style="border-color: rgba(255,255,255,0.25); background: rgba(255,255,255,0.08); color: #ffffff;">
        <i class="fa-solid fa-bars"></i>
    </button>

    <nav class="client-nav" id="clientNav">
        <a href="verification.php" class="<?php echo $current_page === 'verification.php' ? 'active' : ''; ?>" style="color: #ffffff;">
            <i class="fa-solid fa-camera"></i> Scanner / Vérifier
        </a>
        <a href="historique.php" class="<?php echo $current_page === 'historique.php' ? 'active' : ''; ?>" style="color: #ffffff;">
            <i class="fa-solid fa-clock-rotate-left"></i> Historique des scans
        </a>
        <a href="../deconnexion.php" class="client-logout">
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
            nav.classList.add('nav-open');
            btn.setAttribute('aria-expanded', 'true');
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
        nav.classList.remove('nav-open');
        
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
        }
        
        document.body.style.overflow = '';
    }
    
    // Au chargement complet du DOM
    document.addEventListener('DOMContentLoaded', function() {
        const nav = document.getElementById('clientNav');
        const btn = document.querySelector('.client-nav-toggle');
        const navLinks = document.querySelectorAll('.client-nav a');
        
        if (!nav || !btn) return;
        
        // Fermer au clic sur un lien du menu
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // Autoriser la navigation
                setTimeout(() => {
                    closeMenu();
                }, 100);
            });
        });
        
        // Fermer le menu au clic en dehors
        document.addEventListener('click', function(event) {
            if (menuOpen) {
                // Si le clic n'est pas sur le header/nav/btn
                const header = document.querySelector('.client-header');
                if (header && !header.contains(event.target) && !nav.contains(event.target)) {
                    closeMenu();
                }
            }
        });
        
        // Fermer au redimensionnement
        window.addEventListener('resize', function() {
            if (window.innerWidth > 767 && menuOpen) {
                closeMenu();
            }
        });
    });
</script>
