<?php
/**
 * AJAX Endpoint : Gestion de la Whitelist des invités (Promoteur & Admin)
 * ajax/whitelist_manage.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SmsService.php';

use Services\SmsService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Contrôle des droits : Promoteur ou Admin uniquement
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Action réservée aux organisateurs et administrateurs.']);
    exit();
}

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'promoteur';
$action    = trim($_POST['action'] ?? '');
$event_id  = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

if (!$event_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_EVENT_ID', 'message' => 'Identifiant d\'événement manquant.']);
    exit();
}

// Vérifier que l'utilisateur est bien le propriétaire de l'événement ou admin
$stmt = $pdo->prepare("SELECT id, user_id, nom, visibilite, access_token, requires_whitelist FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Événement introuvable.']);
    exit();
}

if ($user_role !== 'admin' && (int)$event['user_id'] !== $user_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Vous n\'êtes pas autorisé à modifier cet événement.']);
    exit();
}

try {
    switch ($action) {
        // ----------------------------------------------------------------------
        // 1. Basculer la visibilité (Public <-> Privé) & Générer le Token
        // ----------------------------------------------------------------------
        case 'toggle_visibility':
            $is_private = filter_var($_POST['is_private'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $req_wl     = filter_var($_POST['requires_whitelist'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $visibilite = $is_private ? 'prive' : 'public';
            $token      = $event['access_token'];

            if ($is_private && empty($token)) {
                $token = bin2hex(random_bytes(16)); // Jeton 32 caractères
            }

            $stmt_upd = $pdo->prepare("
                UPDATE events 
                SET visibilite = ?, access_token = ?, requires_whitelist = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt_upd->execute([$visibilite, $token, $req_wl ? 1 : 0, $event_id]);

            echo json_encode([
                'success'            => true,
                'visibilite'         => $visibilite,
                'access_token'       => $token,
                'requires_whitelist' => $req_wl,
                'message'            => $is_private ? 'L\'événement est désormais configuré en accès privé sécurisé.' : 'L\'événement est désormais public et visible.'
            ]);
            break;

        // ----------------------------------------------------------------------
        // 2. Ajouter un invité unique manuellement
        // ----------------------------------------------------------------------
        case 'add_guest':
            $nom       = trim($_POST['nom'] ?? '');
            $prenom    = trim($_POST['prenom'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $quota     = max(1, (int)($_POST['tickets_authorized'] ?? 1));

            if (empty($nom) || empty($telephone)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Le nom et le numéro de téléphone sont obligatoires.']);
                exit();
            }

            $phoneClean = SmsService::normalizePhone($telephone);

            // Vérifier s'il existe déjà
            $stmt_check = $pdo->prepare("SELECT id FROM guest_whitelists WHERE event_id = ? AND telephone = ?");
            $stmt_check->execute([$event_id, $phoneClean]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Mise à jour du quota
                $stmt_upd = $pdo->prepare("UPDATE guest_whitelists SET nom = ?, prenom = ?, email = ?, tickets_authorized = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_upd->execute([$nom, $prenom, $email, $quota, $existing['id']]);
                $guest_id = $existing['id'];
                $msg = "Invité mis à jour avec succès.";
            } else {
                $stmt_ins = $pdo->prepare("INSERT INTO guest_whitelists (event_id, nom, prenom, telephone, email, tickets_authorized, tickets_purchased, statut) VALUES (?, ?, ?, ?, ?, ?, 0, 'actif')");
                $stmt_ins->execute([$event_id, $nom, $prenom, $phoneClean, $email, $quota]);
                $guest_id = $pdo->lastInsertId();
                $msg = "Invité ajouté à la liste avec succès.";
            }

            echo json_encode([
                'success'  => true,
                'guest_id' => $guest_id,
                'message'  => $msg
            ]);
            break;

        // ----------------------------------------------------------------------
        // 3. Supprimer un invité de la liste
        // ----------------------------------------------------------------------
        case 'delete_guest':
            $guest_id = filter_input(INPUT_POST, 'guest_id', FILTER_VALIDATE_INT);
            if (!$guest_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'MISSING_GUEST_ID', 'message' => 'Identifiant invité manquant.']);
                exit();
            }

            $stmt_del = $pdo->prepare("DELETE FROM guest_whitelists WHERE id = ? AND event_id = ?");
            $stmt_del->execute([$guest_id, $event_id]);

            echo json_encode(['success' => true, 'message' => 'Invité retiré de la liste avec succès.']);
            break;

        // ----------------------------------------------------------------------
        // 4. Importation CSV en masse
        // ----------------------------------------------------------------------
        case 'import_csv':
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'UPLOAD_FAILED', 'message' => 'Erreur lors du téléversement du fichier CSV.']);
                exit();
            }

            $fileTmp = $_FILES['csv_file']['tmp_name'];
            $handle  = fopen($fileTmp, 'r');
            if (!$handle) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'CANNOT_READ', 'message' => 'Impossible de lire le fichier téléversé.']);
                exit();
            }

            // Détection du séparateur (, ou ;)
            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            $imported = 0;
            $updated  = 0;
            $skipped  = 0;
            $rowNum   = 0;

            // Détection de l'entête
            $header = fgetcsv($handle, 1000, $delimiter);
            $headerMap = [];
            if ($header) {
                foreach ($header as $idx => $col) {
                    $colClean = mb_strtolower(trim($col));
                    if (str_contains($colClean, 'nom') && !str_contains($colClean, 'prenom')) $headerMap['nom'] = $idx;
                    elseif (str_contains($colClean, 'prenom')) $headerMap['prenom'] = $idx;
                    elseif (str_contains($colClean, 'tel') || str_contains($colClean, 'phone')) $headerMap['tel'] = $idx;
                    elseif (str_contains($colClean, 'email') || str_contains($colClean, 'mail')) $headerMap['email'] = $idx;
                    elseif (str_contains($colClean, 'ticket') || str_contains($colClean, 'quota') || str_contains($colClean, 'place')) $headerMap['quota'] = $idx;
                }
            }

            // Si pas d'entêtes trouvées, mappage par défaut par position : 0=Nom, 1=Prénom, 2=Téléphone, 3=Email, 4=Quota
            if (!isset($headerMap['nom']) && !isset($headerMap['tel'])) {
                rewind($handle);
                $headerMap = ['nom' => 0, 'prenom' => 1, 'tel' => 2, 'email' => 3, 'quota' => 4];
            }

            $stmt_find = $pdo->prepare("SELECT id FROM guest_whitelists WHERE event_id = ? AND telephone = ?");
            $stmt_ins  = $pdo->prepare("INSERT INTO guest_whitelists (event_id, nom, prenom, telephone, email, tickets_authorized, tickets_purchased, statut) VALUES (?, ?, ?, ?, ?, ?, 0, 'actif')");
            $stmt_upd  = $pdo->prepare("UPDATE guest_whitelists SET nom = ?, prenom = ?, email = ?, tickets_authorized = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

            while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
                $rowNum++;
                $nom    = trim($row[$headerMap['nom'] ?? 0] ?? '');
                $prenom = trim($row[$headerMap['prenom'] ?? 1] ?? '');
                $tel    = trim($row[$headerMap['tel'] ?? 2] ?? '');
                $email  = trim($row[$headerMap['email'] ?? 3] ?? '');
                $quota  = max(1, (int)($row[$headerMap['quota'] ?? 4] ?? 1));

                if (empty($nom) || empty($tel)) {
                    $skipped++;
                    continue;
                }

                $phoneClean = SmsService::normalizePhone($tel);
                if (empty($phoneClean)) {
                    $skipped++;
                    continue;
                }

                $stmt_find->execute([$event_id, $phoneClean]);
                $exists = $stmt_find->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $stmt_upd->execute([$nom, $prenom, $email, $quota, $exists['id']]);
                    $updated++;
                } else {
                    $stmt_ins->execute([$event_id, $nom, $prenom, $phoneClean, $email, $quota]);
                    $imported++;
                }
            }
            fclose($handle);

            echo json_encode([
                'success'  => true,
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'message'  => "Importation terminée : {$imported} nouveaux invité(s), {$updated} mis à jour, {$skipped} ligne(s) ignorée(s)."
            ]);
            break;

        // ----------------------------------------------------------------------
        // 5. Lister les invités pour cet événement
        // ----------------------------------------------------------------------
        case 'list_guests':
            $stmt_list = $pdo->prepare("
                SELECT id, nom, prenom, telephone, email, tickets_authorized, tickets_purchased, statut, imported_at 
                FROM guest_whitelists 
                WHERE event_id = ? 
                ORDER BY id DESC
            ");
            $stmt_list->execute([$event_id]);
            $guests = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'count'   => count($guests),
                'guests'  => $guests
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'UNKNOWN_ACTION', 'message' => 'Action non reconnue.']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'DATABASE_ERROR',
        'message' => friendly_db_error($e, 'invitation', "Impossible de modifier la liste des invités.")
    ]);
}
