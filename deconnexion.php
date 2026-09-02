<?php
// ==============================================================================
// DÉCONNEXION (deconnexion.php)
// Détruit la session et redirige vers la page de connexion
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. On vide toutes les variables de session
$_SESSION = [];

// 2. Si un cookie de session existe, on le supprime
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. On détruit la session
session_destroy();

// 4. On redirige vers la page d'accueil du site avec message de déconnexion
header("Location: client/accueil.php?logout=1");
exit();