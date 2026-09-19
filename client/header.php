<?php
// ==============================================================================
// EN-TÊTE CLIENT (client/header.php)
// Navigation principale dédiée exclusivement aux clients et visiteurs
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = $page_title ?? 'TikeWA - Billetterie en ligne';
$body_class = $body_class ?? 'client-page';
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_role = $_SESSION['user_role'] ?? 'client';
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
    <?php
    $sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($sn, '/client/') !== false) {
        $app_root = substr($sn, 0, strpos($sn, '/client/'));
    } elseif (strpos($sn, '/admin/') !== false) {
        $app_root = substr($sn, 0, strpos($sn, '/admin/'));
    } elseif (strpos($sn, '/promoteur/') !== false) {
        $app_root = substr($sn, 0, strpos($sn, '/promoteur/'));
    } else {
        $app_root = rtrim(dirname($sn), '/');
    }
    $app_root = rtrim($app_root, '/');
    $app_client_base = $app_root . '/client/';
    ?>
    <base href="<?php echo htmlspecialchars($app_client_base); ?>">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($app_root); ?>/images/favicon.png?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>">
    <!-- Google Fonts: Outfit, Inter & Space Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_root); ?>/css/style.css?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>">
    <!-- TikeWA Brand Design System -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_root); ?>/css/eventia-brand.css?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_root); ?>/css/responsive-pro.css?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>">
</head>
<body class="<?php echo htmlspecialchars($body_class); ?>">

<header class="client-header shared-client-header">
    <a href="<?php echo htmlspecialchars($app_root); ?>/client/accueil" class="client-brand" style="display: inline-flex; align-items: center; text-decoration: none; padding: 2px 0;">
        <img src="<?php echo htmlspecialchars($app_root); ?>/images/logo.png?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1.1.0'; ?>" alt="TikeWA" style="height: 44px; width: auto; max-width: 170px; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.15));">
    </a>

    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Ouvrir le menu">
        <i class="fa-solid fa-bars"></i>
    </button>

    <nav class="client-nav">
        <a href="<?php echo htmlspecialchars($app_root); ?>/client/accueil"><i class="fa-solid fa-house"></i> Accueil</a>
        
        <?php if ($is_logged_in): ?>
            <?php if ($user_role === 'promoteur'): ?>
                <!-- Raccourci vers l'espace Promoteur -->
                <a href="<?php echo htmlspecialchars($app_root); ?>/promoteur/dashboard" class="btn-espace-promoteur">
                    <i class="fa-solid fa-bullhorn"></i> Mon Espace Promoteur
                </a>
            <?php elseif ($user_role === 'admin'): ?>
                <!-- Raccourci vers l'espace Administration -->
                <a href="<?php echo htmlspecialchars($app_root); ?>/admin/dashboard" class="btn-espace-admin">
                    <i class="fa-solid fa-shield-halved"></i> Espace Administration
                </a>
            <?php endif; ?>

            <!-- Menu visible uniquement pour les clients connectés -->
            <?php if ($user_role === 'client'): ?>
                <a href="<?php echo htmlspecialchars($app_root); ?>/devenir-promoteur"><i class="fa-solid fa-bullhorn"></i> Devenir Promoteur</a>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($app_root); ?>/client/mes-commandes"><i class="fa-solid fa-cart-shopping"></i> Mes Commandes</a>
            <a href="<?php echo htmlspecialchars($app_root); ?>/client/mes-tickets"><i class="fa-solid fa-qrcode"></i> Mes Tickets</a>
            <a href="<?php echo htmlspecialchars($app_root); ?>/client/reclamations"><i class="fa-solid fa-headset"></i> Support</a>
            <a href="<?php echo htmlspecialchars($app_root); ?>/deconnexion" class="client-logout"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
        <?php else: ?>
            <!-- Menu pour les visiteurs non connectés -->
            <a href="<?php echo htmlspecialchars($app_root); ?>/devenir-promoteur"><i class="fa-solid fa-bullhorn"></i> Devenir Promoteur</a>
            <a href="<?php echo htmlspecialchars($app_root); ?>/connexion"><i class="fa-solid fa-right-to-bracket"></i> Connexion</a>
            <a href="<?php echo htmlspecialchars($app_root); ?>/inscription" class="client-register"><i class="fa-solid fa-user-plus"></i> Inscription</a>
        <?php endif; ?>
    </nav>
</header>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('mobile-menu-toggle');
        const nav = document.querySelector('.client-nav');
        const icon = toggleBtn?.querySelector('i');
        
        if (toggleBtn && nav) {
            toggleBtn.addEventListener('click', function() {
                nav.classList.toggle('active');
                if (nav.classList.contains('active')) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-xmark');
                } else {
                    icon.classList.remove('fa-xmark');
                    icon.classList.add('fa-bars');
                }
            });
        }
    });
</script>
