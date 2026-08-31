<?php
// ==============================================================================
// VOTE / RETRAIT DE VOTE POUR UN ÉVÉNEMENT (client/vote-event.php)
// Endpoint AJAX : bascule le vote du visiteur courant et renvoie le nouveau compteur
// ==============================================================================

require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit();
}

$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
if (!$event_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Événement invalide.']);
    exit();
}

// Vérifier que l'événement existe et est actif
$stmt = $pdo->prepare("SELECT id FROM events WHERE id = ? AND statut = 'actif'");
$stmt->execute([$event_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Événement introuvable.']);
    exit();
}

// Identifiant du visiteur (connecté ou non) basé sur la session
$visitor_id = session_id();
$user_id    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Les promoteurs et administrateurs ne peuvent pas voter (réservé aux clients)
if ($user_id && in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Action réservée aux clients : votre compte ' . ($_SESSION['user_role'] ?? '') . ' ne peut pas voter.']);
    exit();
}

try {
    // Un client connecté ne peut voter qu'UNE seule fois par événement (peu importe la session).
    // Un visiteur non connecté est limité à un vote par session.
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$event_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND visitor_id = ?");
        $stmt->execute([$event_id, $visitor_id]);
    }
    $existing = $stmt->fetch();

    if ($existing) {
        // Retirer le vote
        $stmt = $pdo->prepare("DELETE FROM event_votes WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $voted = false;
    } else {
        // Ajouter le vote
        $stmt = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id) VALUES (?, ?, ?)");
        $stmt->execute([$event_id, $user_id, $visitor_id]);
        $voted = true;
    }

    // Nouveau compteur de votes pour cet événement
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE event_id = ?");
    $stmt->execute([$event_id]);
    $count = (int)$stmt->fetchColumn();

    echo json_encode(['voted' => $voted, 'votes' => $count]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de données : exécutez config/migration-votes-cotisations.sql pour créer la table event_votes.']);
}