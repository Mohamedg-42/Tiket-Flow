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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- QR Code Scanner Library -->
    <link rel="stylesheet" href="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.css">
    <!-- Style CSS -->
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="client-page">

<header class="client-header shared-client-header" style="background: #0f172a; color: #ffffff;">
    <a href="verification.php" class="client-brand" style="color: #38bdf8;">
        <i class="fa-solid fa-qrcode"></i> AGENT DE CONTRÔLE
    </a>

    <nav class="client-nav">
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
