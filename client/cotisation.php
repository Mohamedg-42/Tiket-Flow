<?php
// ==============================================================================
// TRAITEMENT DES COTISATIONS (client/cotisation.php)
// Enregistre la contribution d'un client (connecté ou invité) puis redirige
// vers le paiement Mobile Money — même flux que l'achat de tickets
// ==============================================================================

require_once '../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// Les promoteurs et administrateurs ne peuvent pas contribuer (réservé aux clients)
if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
    $_SESSION['cotisation_message'] = "Les contributions sont réservées aux clients. Votre compte " . ($_SESSION['user_role'] ?? '') . " ne peut pas cotiser.";
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_id      = $is_logged_in ? (int)$_SESSION['user_id'] : null;

// Coordonnées : pré-remplies automatiquement pour les clients connectés,
// saisies dans le formulaire par les visiteurs
$nom         = trim($_POST['nom'] ?? ($_SESSION['user_nom'] ?? ''));
$email       = trim($_POST['email'] ?? ($_SESSION['user_email'] ?? ''));
$telephone   = trim($_POST['telephone'] ?? '');
$montant     = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);
$campagne_id = filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT) ?: null;

// Validation des données
if ($nom === '' || !$montant || $montant < 500) {
    $_SESSION['cotisation_message'] = "Veuillez renseigner votre nom et un montant valide (minimum 500 FCFA).";
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}

// Vérification de la campagne (si une campagne est visée)
$campagne_titre = null;
if ($campagne_id !== null) {
    try {
        $stmt_camp = $pdo->prepare("SELECT titre FROM cotisation_campagnes WHERE id = ? AND statut = 'active'");
        $stmt_camp->execute([$campagne_id]);
        $campagne_titre = $stmt_camp->fetchColumn();

        if ($campagne_titre === false) {
            $_SESSION['cotisation_message'] = "Cette campagne de cotisation n'est plus disponible.";
            $_SESSION['cotisation_type']    = 'error';
            header('Location: accueil.php?onglet=cotisations');
            exit();
        }
    } catch (PDOException $e) {
        // Table cotisation_campagnes pas encore migrée : contribution générale
        $campagne_id = null;
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO cotisations (campagne_id, user_id, nom, email, telephone, montant, statut) 
        VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([
        $campagne_id,
        $user_id,
        $nom,
        $email ?: null,
        $telephone ?: null,
        $montant
    ]);

    // Redirection vers le paiement Mobile Money (comme pour les billets)
    header('Location: paiement-cotisation.php?cotisation_id=' . (int)$pdo->lastInsertId());
    exit();

} catch (PDOException $e) {
    $_SESSION['cotisation_message'] = "Erreur base de données : exécutez config/migration-cotisations-campagnes.sql pour créer les tables.";
    $_SESSION['cotisation_type']    = 'error';
    header('Location: accueil.php?onglet=cotisations');
    exit();
}