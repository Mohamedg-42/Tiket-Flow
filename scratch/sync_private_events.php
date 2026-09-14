<?php
require_once __DIR__ . '/../config/database.php';

echo "=== SYNCHRONISATION DES VISIBILITÉS DES ÉVÉNEMENTS & VOTES ===\n";

// 1. Trouver les événements issus de demandes privées et les corriger
$stmt = $pdo->query("
    SELECT er.id AS req_id, er.nom, er.visibilite AS req_visibilite, e.id AS event_id, e.visibilite AS event_visibilite, e.access_token
    FROM event_requests er
    JOIN events e ON (e.nom = er.nom AND e.user_id = er.user_id)
    WHERE er.visibilite = 'prive' AND (e.visibilite != 'prive' OR e.visibilite IS NULL OR e.access_token IS NULL OR e.access_token = '')
");
$to_fix = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Événements à corriger : " . count($to_fix) . "\n";

foreach ($to_fix as $row) {
    $token = !empty($row['access_token']) ? $row['access_token'] : bin2hex(random_bytes(16));
    $upd = $pdo->prepare("UPDATE events SET visibilite = 'prive', access_token = ? WHERE id = ?");
    $upd->execute([$token, $row['event_id']]);
    echo " -> Event ID {$row['event_id']} (« {$row['nom']} ») passé en PRIVÉ avec Token: {$token}\n";
}

// 2. Traitement direct pour "La Parade" et "LA VIDA" si besoin
$stmt_check = $pdo->query("SELECT id, nom, visibilite, access_token FROM events WHERE nom IN ('La Parade', 'LA VIDA')");
foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    if ($ev['visibilite'] !== 'prive' || empty($ev['access_token'])) {
        $token = !empty($ev['access_token']) ? $ev['access_token'] : bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE events SET visibilite = 'prive', access_token = ? WHERE id = ?")->execute([$token, $ev['id']]);
        echo " -> Confirmation Event ID {$ev['id']} (« {$ev['nom']} ») passé en PRIVÉ avec Token: {$token}\n";
    }
}

echo "=== SYNCHRONISATION TERMINÉE ===\n";
