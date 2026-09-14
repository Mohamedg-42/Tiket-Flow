<?php
/**
 * AJAX Endpoint : Gestion des Gares Routières et Guichets POS (Admin)
 * ajax/station_manage.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Contrôle des droits : Admin uniquement pour la configuration
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Action réservée aux administrateurs.']);
    exit();
}

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        // ----------------------------------------------------------------------
        // 1. Changer le statut d'une gare (actif, suspendu, ferme)
        // ----------------------------------------------------------------------
        case 'set_station_status':
            $station_id = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
            $new_status = trim($_POST['status'] ?? '');

            if (!$station_id || !in_array($new_status, ['actif', 'suspendu', 'ferme'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Paramètres invalides.']);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE stations SET statut = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_status, $station_id]);

            $messages = [
                'actif'    => 'La gare a été activée. Les ventes de billets sont désormais ouvertes.',
                'suspendu' => 'La gare a été immédiatement suspendue. Les émissions de billets sont bloquées.',
                'ferme'    => 'La gare a été fermée définitivement.'
            ];

            echo json_encode([
                'success'    => true,
                'station_id' => $station_id,
                'status'     => $new_status,
                'message'    => $messages[$new_status]
            ]);
            break;

        // ----------------------------------------------------------------------
        // 2. Créer une nouvelle gare routière
        // ----------------------------------------------------------------------
        case 'create_station':
            $nom       = trim($_POST['nom'] ?? '');
            $code      = strtoupper(trim($_POST['code'] ?? ''));
            $ville     = trim($_POST['ville'] ?? 'Abidjan');
            $adresse   = trim($_POST['adresse'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');

            if (empty($nom) || empty($code)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Le nom et le code de la gare sont obligatoires.']);
                exit();
            }

            // Vérifier unicité du code
            $stmt_check = $pdo->prepare("SELECT id FROM stations WHERE code = ?");
            $stmt_check->execute([$code]);
            if ($stmt_check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'DUPLICATE_CODE', 'message' => 'Ce code de gare existe déjà.']);
                exit();
            }

            $stmt_ins = $pdo->prepare("
                INSERT INTO stations (nom, code, ville, adresse, telephone, statut) 
                VALUES (?, ?, ?, ?, ?, 'actif')
            ");
            $stmt_ins->execute([$nom, $code, $ville, $adresse, $telephone]);
            $station_id = (int) $pdo->lastInsertId();

            // Créer automatiquement le Guichet 1 par défaut
            $defaultGuichetCode = $code . '-G01';
            $stmt_pos = $pdo->prepare("INSERT INTO pos_terminals (station_id, code_guichet, nom_guichet, statut) VALUES (?, ?, 'Guichet 1', 'actif')");
            $stmt_pos->execute([$station_id, $defaultGuichetCode]);

            echo json_encode([
                'success'    => true,
                'station_id' => $station_id,
                'message'    => "La gare « {$nom} » et son premier guichet ont été créés avec succès."
            ]);
            break;

        // ----------------------------------------------------------------------
        // 3. Ajouter un guichet supplémentaire dans une gare
        // ----------------------------------------------------------------------
        case 'add_pos_terminal':
            $station_id  = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
            $code_guichet = strtoupper(trim($_POST['code_guichet'] ?? ''));
            $nom_guichet  = trim($_POST['nom_guichet'] ?? '');

            if (!$station_id || empty($code_guichet) || empty($nom_guichet)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Tous les champs sont obligatoires.']);
                exit();
            }

            $stmt_check = $pdo->prepare("SELECT id FROM pos_terminals WHERE code_guichet = ?");
            $stmt_check->execute([$code_guichet]);
            if ($stmt_check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'DUPLICATE_POS_CODE', 'message' => 'Ce code de guichet existe déjà.']);
                exit();
            }

            $stmt_pos = $pdo->prepare("INSERT INTO pos_terminals (station_id, code_guichet, nom_guichet, statut) VALUES (?, ?, ?, 'actif')");
            $stmt_pos->execute([$station_id, $code_guichet, $nom_guichet]);

            echo json_encode([
                'success' => true,
                'message' => "Guichet {$nom_guichet} ({$code_guichet}) ajouté avec succès."
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'UNKNOWN_ACTION', 'message' => 'Action inconnue.']);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => $e->getMessage()]);
}
