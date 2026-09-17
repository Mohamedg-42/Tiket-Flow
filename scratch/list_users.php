<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT id, nom, prenom, email, role, statut, est_verifie FROM users ORDER BY id ASC");
while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$u['id']} | {$u['email']} | Role: {$u['role']} | Statut: {$u['statut']}\n";
}
