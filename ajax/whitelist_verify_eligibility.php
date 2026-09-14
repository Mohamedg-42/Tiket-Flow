<?php
/**
 * AJAX Endpoint : Vérification d'éligibilité sur la Whitelist d'un événement privé
 * ajax/whitelist_verify_eligibility.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SmsService.php';

use Services\SmsService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Méthode non autorisée.']);
    exit();
}

$event_id  = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$telephone = trim($_POST['telephone'] ?? '');
$token     = trim($_POST['token'] ?? '');

if (!$event_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_EVENT_ID', 'message' => 'Identifiant d\'événement manquant.']);
    exit();
}

try {
    // 1. Récupération de l'événement et contrôle de la visibilité / whitelist
    $stmt = $pdo->prepare("SELECT id, nom, visibilite, access_token, requires_whitelist, statut FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Événement introuvable.']);
        exit();
    }

    if ($event['statut'] !== 'actif') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'EVENT_INACTIVE', 'message' => 'Cet événement n\'est plus ouvert aux réservations.']);
        exit();
    }

    // 2. Si l'événement est privé, vérifier la validité du token d'accès
    if ($event['visibilite'] === 'prive') {
        if (empty($token) || $token !== $event['access_token']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'INVALID_ACCESS_TOKEN', 'message' => 'Lien d\'accès privé non valide ou expiré.']);
            exit();
        }
    }

    // 3. Si l'événement ne requiert pas de whitelist, tout acheteur est éligible
    if (empty($event['requires_whitelist'])) {
        echo json_encode([
            'success'            => true,
            'requires_whitelist' => false,
            'message'            => 'Événement public, aucune restriction sur les acheteurs.'
        ]);
        exit();
    }

    // 4. Contrôle du numéro sur la liste d'invités autorisés
    $phoneClean = SmsService::normalizePhone($telephone);
    if (empty($phoneClean)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_PHONE', 'message' => 'Veuillez saisir un numéro de téléphone valide.']);
        exit();
    }

    // Recherche flexible (numéro brut ou normalisé avec ou sans +225)
    $rawPhone = preg_replace('/[^\d]/', '', $telephone);
    $stmt_wl = $pdo->prepare("
        SELECT * FROM guest_whitelists 
        WHERE event_id = ? 
          AND (
              telephone = ? 
              OR telephone = ? 
              OR telephone LIKE ?
          )
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_wl->execute([$event_id, $phoneClean, $telephone, '%' . substr($rawPhone, -8)]);
    $guest = $stmt_wl->fetch(PDO::FETCH_ASSOC);

    if (!$guest) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'NOT_WHITELISTED',
            'message' => 'Désolé, ce numéro ne figure pas sur la liste des invités autorisés pour cet événement privé.'
        ]);
        exit();
    }

    if ($guest['statut'] === 'bloque') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'ACCESS_BLOCKED',
            'message' => 'Votre accès à la billetterie de cet événement a été temporairement suspendu.'
        ]);
        exit();
    }

    $authQuota = (int) $guest['tickets_authorized'];
    $usedQuota = (int) $guest['tickets_purchased'];
    $remaining = max(0, $authQuota - $usedQuota);

    if ($remaining <= 0) {
        http_response_code(403);
        echo json_encode([
            'success'            => false,
            'error'              => 'QUOTA_EXHAUSTED',
            'tickets_authorized' => $authQuota,
            'tickets_purchased'  => $usedQuota,
            'message'            => "Vous avez déjà commandé la totalité de vos billets autorisés ({$usedQuota} / {$authQuota})."
        ]);
        exit();
    }

    // 5. Acheteur éligible !
    echo json_encode([
        'success'            => true,
        'eligible'           => true,
        'requires_whitelist' => true,
        'guest_id'           => (int) $guest['id'],
        'nom'                => $guest['nom'],
        'prenom'             => $guest['prenom'] ?? '',
        'telephone'          => $phoneClean,
        'quota_restant'      => $remaining,
        'quota_total'        => $authQuota,
        'message'            => "Félicitations ! Vous êtes bien invité(e) pour cet événement. Quota disponible : {$remaining} billet(s)."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Erreur technique lors de la vérification.']);
}
