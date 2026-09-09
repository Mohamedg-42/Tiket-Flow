<?php
// ==============================================================================
// DÉCONNEXION (deconnexion.php)
// Détruit la session et redirige vers la page de connexion
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    logActivity('deconnexion', 'user', (int)$_SESSION['user_id'], 'Déconnexion volontaire', (int)$_SESSION['user_id']);
}

// 1. On vide toutes les variables de session utilisateur
$_SESSION = [];

// 2. On régénère l'identifiant de session de manière propre
session_regenerate_id(true);

// 3. Message flash éphémère (consommé dès le premier affichage)
$_SESSION['logout_success'] = true;

// 4. On redirige vers la page d'accueil du site
header("Location: client/accueil.php");
exit();