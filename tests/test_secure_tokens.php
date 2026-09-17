<?php
// ==============================================================================
// SUITE DE TESTS AUTOMATISÉS DE SÉCURITÉ (tests/test_secure_tokens.php)
// Vérifie le bon fonctionnement du masquage d'ID par tokens cryptographiques
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$total = 0;
$passed = 0;

function run_test(string $name, callable $fn) {
    global $total, $passed;
    $total++;
    try {
        $res = $fn();
        if ($res === true) {
            echo "  [PASS] $name\n";
            $passed++;
        } else {
            echo "  [FAIL] $name : résultat inattendu\n";
        }
    } catch (Throwable $e) {
        echo "  [FAIL] $name : Exception: " . $e->getMessage() . "\n";
    }
}

echo "==============================================================\n";
echo "   EXÉCUTION DES TESTS DE SÉCURITÉ - SYSTÈME DE TOKENS\n";
echo "==============================================================\n\n";

// ------------------------------------------------------------------------------
// TEST 1 : Génération de token aléatoire et entropie
// ------------------------------------------------------------------------------
echo "--- 1. Génération et cryptographie ---\n";
run_test("Génération d'un token Base62 de 24 caractères", function() {
    $token = generate_secure_random_token(24);
    return strlen($token) === 24 && preg_match('/^[A-Za-z0-9]{24}$/', $token) === 1;
});

run_test("Deux tokens successifs sont distincts (imprévisibilité CSPRNG)", function() {
    $t1 = generate_secure_random_token(24);
    $t2 = generate_secure_random_token(24);
    return $t1 !== $t2;
});

// ------------------------------------------------------------------------------
// TEST 2 : Token valide
// ------------------------------------------------------------------------------
echo "\n--- 2. Token valide ---\n";
$realId = 999125;
$testToken = get_or_create_resource_token($pdo, 'pharmacie', $realId);

run_test("Création et résolution d'un token valide", function() use ($pdo, $testToken, $realId) {
    $resolved = resolve_resource_token($pdo, $testToken, 'pharmacie');
    return $resolved === $realId;
});

run_test("Idempotence : get_or_create renvoie le même token actif", function() use ($pdo, $testToken, $realId) {
    $again = get_or_create_resource_token($pdo, 'pharmacie', $realId);
    return $again === $testToken;
});

// ------------------------------------------------------------------------------
// TEST 3 : Token inexistant
// ------------------------------------------------------------------------------
echo "\n--- 3. Token inexistant ---\n";
run_test("Token aléatoire non enregistré retourne null", function() use ($pdo) {
    $fakeToken = "TokenInexistant123456789";
    $resolved = resolve_resource_token($pdo, $fakeToken, 'pharmacie');
    return $resolved === null;
});

// ------------------------------------------------------------------------------
// TEST 4 : Token modifié / altéré
// ------------------------------------------------------------------------------
echo "\n--- 4. Token modifié / falsifié ---\n";
run_test("Token altéré par substitution d'un caractère retourne null", function() use ($pdo, $testToken) {
    $alteredToken = substr($testToken, 0, -1) . ($testToken[-1] === 'A' ? 'B' : 'A');
    $resolved = resolve_resource_token($pdo, $alteredToken, 'pharmacie');
    return $resolved === null;
});

run_test("Token tronqué invalide retourne null (validation regex stricte)", function() use ($pdo) {
    $shortToken = "ab12"; // Trop court (< 12 caractères)
    $resolved = resolve_resource_token($pdo, $shortToken, 'pharmacie');
    return $resolved === null;
});

// ------------------------------------------------------------------------------
// TEST 5 : Token expiré
// ------------------------------------------------------------------------------
echo "\n--- 5. Token expiré ---\n";
run_test("Token temporaire déjà expiré retourne null", function() use ($pdo) {
    // Insérer manuellement un token dont la date est dans le passé
    $expiredToken = generate_secure_random_token(24);
    $pastDate = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $pdo->prepare("
        INSERT INTO secure_resource_tokens (token, resource_type, resource_id, is_active, expires_at)
        VALUES (?, 'temp_test', 1234, TRUE, ?)
    ");
    $stmt->execute([$expiredToken, $pastDate]);

    $resolved = resolve_resource_token($pdo, $expiredToken, 'temp_test');
    return $resolved === null;
});

// ------------------------------------------------------------------------------
// TEST 6 : Token révoqué / désactivé
// ------------------------------------------------------------------------------
echo "\n--- 6. Token révoqué / désactivé ---\n";
run_test("Révocation d'un token puis tentative de résolution", function() use ($pdo) {
    $tok = get_or_create_resource_token($pdo, 'test_revocation', 555);
    $resolvedBefore = resolve_resource_token($pdo, $tok, 'test_revocation');
    if ($resolvedBefore !== 555) return false;

    $revoked = revoke_resource_token($pdo, $tok);
    if (!$revoked) return false;

    $resolvedAfter = resolve_resource_token($pdo, $tok, 'test_revocation');
    return $resolvedAfter === null;
});

// ------------------------------------------------------------------------------
// TEST 7 : Tentative d'injection SQL
// ------------------------------------------------------------------------------
echo "\n--- 7. Résistance aux injections SQL ---\n";
run_test("Payload SQL ' OR 1=1 -- bloqué par validation regex", function() use ($pdo) {
    $payload = "' OR 1=1 --";
    $resolved = resolve_resource_token($pdo, $payload, 'pharmacie');
    return $resolved === null;
});

run_test("Payload SQL complexe bloqué sans erreur SQL", function() use ($pdo) {
    $payload = "8fK72LmQ' UNION SELECT password FROM users --";
    $resolved = resolve_resource_token($pdo, $payload, 'pharmacie');
    return $resolved === null;
});

// ------------------------------------------------------------------------------
// TEST 8 : Accès sans authentification & respect des permissions
// ------------------------------------------------------------------------------
echo "\n--- 8. Respect des permissions et droits d'accès ---\n";
run_test("Le token promoteur résout l'identifiant promoteur exact sans altération de session", function() use ($pdo) {
    // Promoteur existant
    $promoterUid = (int) $pdo->query("SELECT user_id FROM promoters LIMIT 1")->fetchColumn();
    $token = get_or_create_resource_token($pdo, 'promoter', $promoterUid);
    $resolved = resolve_resource_token($pdo, $token, 'promoter');
    return $resolved === $promoterUid;
});

run_test("Un token de type 'ticket' ne peut pas être résolu comme ressource 'promoter' (cloisonnement)", function() use ($pdo) {
    $ticketId = (int) $pdo->query("SELECT id FROM tickets LIMIT 1")->fetchColumn();
    $tToken = get_or_create_resource_token($pdo, 'ticket', $ticketId);
    $resolvedCross = resolve_resource_token($pdo, $tToken, 'promoter');
    return $resolvedCross === null; // Cloisonnement strict des types
});

// ------------------------------------------------------------------------------
// TEST 9 : Vérification des pages modifiées (Promoteur, Billets, Téléchargement)
// ------------------------------------------------------------------------------
echo "\n--- 9. Intégration sur les ressources réelles ---\n";
run_test("Résolution d'un token pour un événement réel", function() use ($pdo) {
    $evId = (int) $pdo->query("SELECT id FROM events LIMIT 1")->fetchColumn();
    $evToken = get_or_create_resource_token($pdo, 'event', $evId);
    $resolved = resolve_resource_token($pdo, $evToken, 'event');
    return $resolved === $evId;
});

run_test("Résolution d'un token pour une commande réelle", function() use ($pdo) {
    $ordId = (int) $pdo->query("SELECT id FROM orders LIMIT 1")->fetchColumn();
    $ordToken = get_or_create_resource_token($pdo, 'order', $ordId);
    $resolved = resolve_resource_token($pdo, $ordToken, 'order');
    return $resolved === $ordId;
});

echo "\n==============================================================\n";
echo "   BILAN DES TESTS : $passed / $total réussis (" . round(($passed / $total) * 100) . "%)\n";
echo "==============================================================\n";

if ($passed !== $total) {
    exit(1);
}
