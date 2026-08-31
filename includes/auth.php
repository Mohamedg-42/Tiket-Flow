<?php
// ==============================================================================
// GESTION DE L'AUTHENTIFICATION ET DES RÔLES (includes/auth.php)
// Sécurise l'accès aux pages privées selon le rôle de l'utilisateur
// ==============================================================================

// 1. Démarrage sécurisé de la session si ce n'est pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Vérifie si un utilisateur est connecté et s'il possède le rôle requis.
 * Si ce n'est pas le cas, il est redirigé vers la page de connexion.
 *
 * @param string|array $roles_autorises Un rôle (ex: 'admin') ou un tableau de rôles (ex: ['admin', 'agent'])
 * @param string $redirect_url URL de redirection en cas d'accès refusé
 */
function checkRole($roles_autorises, $redirect_url = '../connexion.php') {
    // A. L'utilisateur est-il connecté ?
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header("Location: " . $redirect_url);
        exit();
    }

    // Si on a passé un seul rôle sous forme de texte, on le convertit en tableau
    if (!is_array($roles_autorises)) {
        $roles_autorises = [$roles_autorises];
    }

    // B. L'utilisateur a-t-il l'un des rôles requis ?
    $user_role = $_SESSION['user_role'] ?? '';

    if (!in_array($user_role, $roles_autorises, true)) {
        // Redirection avec paramètre d'erreur
        header("Location: " . $redirect_url . "?error=acces_interdit");
        exit();
    }
}

/**
 * Vérifie simplement si l'utilisateur est connecté (quel que soit son rôle).
 *
 * @param string $redirect_url
 */
function requireLogin($redirect_url = '../connexion.php') {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header("Location: " . $redirect_url);
        exit();
    }
}

/**
 * Fonction utilitaire : Savoir si un visiteur est connecté.
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}