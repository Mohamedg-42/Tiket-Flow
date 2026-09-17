<?php
require_once __DIR__ . '/../config/database.php';

echo "=== TEST PDO LASTINSERTID ===\n";
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO activity_logs (action, target_type, details) VALUES ('test_audit', 'system', 'test insert')");
    $stmt->execute();
    $rawId = $pdo->lastInsertId();
    echo "Raw lastInsertId: " . var_export($rawId, true) . "\n";
    $seqId = $pdo->lastInsertId('activity_logs_id_seq');
    echo "Sequence lastInsertId: " . var_export($seqId, true) . "\n";
    $pdo->rollBack();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "\n=== TEST LASTINSERTID ON USERS TABLE ===\n";
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO users (nom, prenom, email, telephone, password, role) VALUES ('TestNom', 'TestPrenom', 'audit_test@example.com', '0102030405', 'secret', 'client')");
    $stmt->execute();
    $rawUserId = $pdo->lastInsertId();
    echo "Raw lastInsertId for users: " . var_export($rawUserId, true) . "\n";
    $pdo->rollBack();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Exception: " . $e->getMessage() . "\n";
}
