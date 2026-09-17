<?php
// ==============================================================================
// SUPPRESSION SÉCURISÉE D'UN ÉVÉNEMENT (admin/supprimer-evenement.php)
// Contrôle de permissions strictes, journalisation d'activité et gestion d'erreurs
// ==============================================================================

require_once '../includes/auth.php';
checkRole('admin', '../connexion.php');
requirePermission('events.delete', 'evenements.php');
require_once '../config/database.php';

$csrf_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token) || empty($session_token) || !hash_equals($session_token, $csrf_token)) {
    $_SESSION['event_message'] = "Requête non autorisée : jeton de sécurité CSRF invalide ou session expirée.";
    $_SESSION['event_msg_type'] = "error";
    header("Location: evenements.php");
    exit();
}

$id = filter_var($_POST['id'] ?? $_GET['id'] ?? 0, FILTER_VALIDATE_INT);

if ($id > 0) {
    try {
        $stmt_ev = $pdo->prepare("SELECT nom FROM events WHERE id = ?");
        $stmt_ev->execute([$id]);
        $ev_nom = $stmt_ev->fetchColumn();

        if ($ev_nom) {
            $sql = "DELETE FROM events WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            logActivity('event.delete', 'event', $id, "Suppression définitive de l'événement #$id (« $ev_nom »)");
            $_SESSION['event_message'] = "L'événement « " . htmlspecialchars($ev_nom) . " » a été supprimé avec succès.";
            $_SESSION['event_msg_type'] = "success";
        } else {
            $_SESSION['event_message'] = "Événement introuvable ou déjà supprimé.";
            $_SESSION['event_msg_type'] = "error";
        }
    } catch (PDOException $e) {
        $_SESSION['event_message'] = friendly_db_error($e, 'evenement', "Impossible de supprimer cet événement car des billets, commandes ou votes y sont déjà rattachés. Vous pouvez plutôt clôturer la billetterie ou passer son statut en « terminé ».");
        $_SESSION['event_msg_type'] = "error";
    }
}

header("Location: evenements.php");
exit();