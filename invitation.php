<?php
// ==============================================================================
// GESTION DES INVITATIONS SÉCURISÉES (invitation.php)
// Plateforme Tike WA — Système anti-exposition d'identifiants
// Exemple : /invitation.php?token=8fK72LmQ...
// ==============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/secure_token.php';
session_start();

$token = trim((string) ($_GET['token'] ?? ''));
$event_id = null;

if (!empty($token)) {
    // 1. Résolution du token pour les invitations ou événements associés
    $event_id = resolve_resource_token($pdo, $token, 'invitation');
    if (!$event_id) {
        $event_id = resolve_resource_token($pdo, $token, 'event');
    }

    if (!$event_id) {
        render_token_security_error(
            "Invitation introuvable",
            "Ce lien d'invitation est invalide, a expiré ou n'existe pas.",
            404,
            "client/accueil.php"
        );
    }

    // Redirection fluide vers la page de l'événement avec le token sécurisé
    header('Location: client/evenement.php?token=' . urlencode($token));
    exit();

} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Redirection automatique 301 pour masquer immédiatement l'ID
    $legacy_id = (int) $_GET['id'];
    $secure_token = get_or_create_resource_token($pdo, 'invitation', $legacy_id);
    header('Location: invitation.php?token=' . urlencode($secure_token), true, 301);
    exit();
} else {
    header('Location: client/accueil.php');
    exit();
}
