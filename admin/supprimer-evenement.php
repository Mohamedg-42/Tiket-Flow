<?php
// ==============================================================================
// SUPPRESSION SÉCURISÉE D'UN ÉVÉNEMENT (admin/supprimer-evenement.php)
// Contrôle de permissions strictes, journalisation d'activité et gestion d'erreurs
// ==============================================================================

require_once '../includes/auth.php';
checkRole('admin', '../connexion');
requirePermission('events.delete', 'evenements.php');
require_once '../config/database.php';

$csrf_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token) || empty($session_token) || !hash_equals($session_token, $csrf_token)) {
    $_SESSION['event_message'] = "Requête non autorisée : jeton de sécurité CSRF invalide ou session expirée.";
    $_SESSION['event_msg_type'] = "error";
    header("Location: evenements");
    exit();
}

$id = filter_var($_POST['id'] ?? $_GET['id'] ?? 0, FILTER_VALIDATE_INT);

if ($id > 0) {
    try {
        $stmt_ev = $pdo->prepare("SELECT nom FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt_ev->execute([$id]);
        $ev_nom = $stmt_ev->fetchColumn();

        if ($ev_nom) {
            $sql = "UPDATE events SET statut = 'supprime', deleted_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            logActivity('event.soft_delete', 'event', $id, "Suppression logique de l'événement #$id (« $ev_nom »)");
            $_SESSION['event_message'] = "L'événement « " . htmlspecialchars($ev_nom) . " » a été supprimé avec succès.";
            $_SESSION['event_msg_type'] = "success";
        } else {
            $_SESSION['event_message'] = "Événement introuvable ou déjà supprimé.";
            $_SESSION['event_msg_type'] = "error";
        }
    } catch (PDOException $e) {
        $_SESSION['event_message'] = friendly_db_error($e, 'evenement', "Erreur lors de la suppression de l'événement.");
        $_SESSION['event_msg_type'] = "error";
    }
}

header("Location: evenements");
exit();