<?php
// ==============================================================================
// EN-TÊTE CLIENT (client/header.php)
// Navigation principale dédiée exclusivement aux clients et visiteurs
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = $page_title ?? 'Eventia - Billetterie en ligne';
$body_class = $body_class ?? 'client-page';
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_role = $_SESSION['user_role'] ?? 'client';
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
    <!-- Style CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Responsive Professional CSS -->
    <link rel="stylesheet" href="../css/responsive-pro.css">
</head>
<body class="<?php echo htmlspecialchars($body_class); ?>">

<header class="client-header shared-client-header">
    <a href="accueil.php" class="client-brand">
        <i class="fa-solid fa-ticket"></i> EVENTIA
    </a>

    <nav class="client-nav">
        <a href="accueil.php"><i class="fa-solid fa-house"></i> Accueil</a>
        
        <?php if ($is_logged_in): ?>
            <?php if ($user_role === 'promoteur'): ?>
                <!-- Raccourci vers l'espace Promoteur -->
                <a href="../promoteur/dashboard.php" class="btn-espace-promoteur">
                    <i class="fa-solid fa-bullhorn"></i> Mon Espace Promoteur
                </a>
            <?php elseif ($user_role === 'admin'): ?>
                <!-- Raccourci vers l'espace Administration -->
                <a href="../admin/dashboard.php" class="btn-espace-admin">
                    <i class="fa-solid fa-shield-halved"></i> Espace Administration
                </a>
            <?php endif; ?>

            <!-- Menu visible uniquement pour les clients connectés -->
            <a href="mes-commandes.php"><i class="fa-solid fa-cart-shopping"></i> Mes Commandes</a>
            <a href="mes-tickets.php"><i class="fa-solid fa-qrcode"></i> Mes Tickets</a>
            <a href="reclamations.php"><i class="fa-solid fa-headset"></i> Support</a>
            <a href="../deconnexion.php" class="client-logout"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
        <?php else: ?>
            <!-- Menu pour les visiteurs non connectés -->
            <a href="../connexion.php"><i class="fa-solid fa-right-to-bracket"></i> Connexion</a>
            <a href="../inscription.php" class="client-register"><i class="fa-solid fa-user-plus"></i> Inscription</a>
        <?php endif; ?>
    </nav>
</header>
