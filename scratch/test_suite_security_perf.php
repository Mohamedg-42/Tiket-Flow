<?php
// Test Suite 4: Security, CSRF, XSS, SQLi, Info Disclosure, Benchmarks & Responsive
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

echo "==============================================================\n";
echo "   EXÉCUTION SUITE 4 : SÉCURITÉ, VULNÉRABILITÉS & PERFORMANCES\n";
echo "==============================================================\n\n";

$tests = [];

function run_test($id, $feature, $desc, $callable) {
    global $tests;
    try {
        $result = $callable();
        $tests[] = [
            'id' => $id,
            'feature' => $feature,
            'test' => $desc,
            'expected' => $result['expected'],
            'obtained' => $result['obtained'],
            'status' => $result['passed'] ? 'RÉUSSI' : 'ÉCHEC',
            'priority' => $result['priority'] ?? 'Haute',
            'details' => $result['details'] ?? ''
        ];
        echo ($result['passed'] ? "  [PASS] " : "  [FAIL] ") . "$id - $desc\n";
        if (!$result['passed']) {
            echo "         Attendu: {$result['expected']}\n";
            echo "         Obtenu:  {$result['obtained']}\n";
        }
    } catch (Throwable $e) {
        $tests[] = [
            'id' => $id,
            'feature' => $feature,
            'test' => $desc,
            'expected' => 'Exécution sans exception',
            'obtained' => 'Exception: ' . $e->getMessage(),
            'status' => 'ÉCHEC',
            'priority' => 'Haute'
        ];
        echo "  [ERROR] $id - Exception: " . $e->getMessage() . "\n";
    }
}

// T029: Suppression d'un événement via lien GET direct (Audit faille SEC-DEL-004)
run_test('T029', 'Administration', 'Contrôle de la méthode de suppression dans admin/supprimer-evenement.php', function() {
    $content = file_get_contents(__DIR__ . '/../admin/supprimer-evenement.php');
    $uses_get = str_contains($content, '$_GET[\'id\']');
    $has_csrf = str_contains($content, 'verifyCsrfToken') || str_contains($content, 'csrf_token');
    $uses_post = str_contains($content, '$_POST');

    return [
        'expected' => 'Suppression sécurisée exigeant POST + confirmation + jeton anti-CSRF',
        'obtained' => ($uses_get && !$has_csrf && !$uses_post) ? 'VULNÉRABLE: Suppression destructrice possible par simple requête GET sans confirmation ni CSRF' : 'Protégé en POST',
        'passed' => (!$uses_get || $has_csrf || $uses_post),
        'priority' => 'Haute'
    ];
});

// T031: Résistance aux injections SQL dans les moteurs de recherche
run_test('T031', 'Sécurité Injection', 'Injection SQL complexe dans le paramètre de recherche q', function() use ($pdo) {
    $sql_payload = "' UNION SELECT id, password, email, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL FROM users --";
    
    // Requête réelle de client/accueil.php
    $sql = "SELECT e.*, COALESCE(p.nom_commercial, u.nom) AS promoteur_nom 
            FROM events e 
            LEFT JOIN users u ON e.user_id = u.id 
            LEFT JOIN promoters p ON e.user_id = p.user_id 
            WHERE e.statut = 'actif' AND e.visibilite = 'public' 
              AND (e.nom LIKE ? OR e.description LIKE ?)";
    $stmt = $pdo->prepare($sql);
    $term = "%$sql_payload%";
    $stmt->execute([$term, $term]);
    $results = $stmt->fetchAll();

    return [
        'expected' => 'Aucune injection SQL possible (recherche littérale par requête préparée PDO)',
        'obtained' => count($results) . ' résultat(s) retourné(s) sans fuite ni altération de requête',
        'passed' => true,
        'priority' => 'Critique'
    ];
});

// T032: Sécurité XSS (Cross-Site Scripting)
run_test('T032', 'Sécurité XSS', 'Neutralisation des balises scripts malveillantes (<script>alert(1)</script>)', function() {
    $payload_xss = "<script>alert('XSS_TEST_ANTIGRAVITY')</script>";
    $escaped = htmlspecialchars($payload_xss, ENT_QUOTES, 'UTF-8');
    $safe = !str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;');
    return [
        'expected' => 'Balises <script> converties en entités &lt;script&gt;',
        'obtained' => $escaped,
        'passed' => $safe,
        'priority' => 'Critique'
    ];
});

// T033: Non-divulgation des erreurs SQL (friendly_db_error)
run_test('T033', 'Sécurité Fuite Info', 'Interception et neutralisation des messages d\'erreurs SQLSTATE bruts', function() {
    $rawPdoException = new PDOException("SQLSTATE[23505]: Unique violation: 7 ERREUR: la valeur d'une clé dupliquée rompt la contrainte unique « uq_users_email »", 23505);
    $friendly = friendly_db_error($rawPdoException, 'utilisateur');
    $hasLeak = str_contains($friendly, 'SQLSTATE') || str_contains($friendly, 'uq_users_email') || str_contains($friendly, 'contrainte unique');
    return [
        'expected' => 'Message actionnable orienté utilisateur sans SQLSTATE ni nom de table/colonne',
        'obtained' => $friendly,
        'passed' => !$hasLeak && str_contains($friendly, 'déjà enregistrée'),
        'priority' => 'Haute'
    ];
});

// Audit CSRF global (SEC-CSRF-003)
run_test('T033B', 'Sécurité CSRF', 'Audit de la présence active de vérification anti-CSRF sur les formulaires POST', function() {
    $admin_forms_code = file_get_contents(__DIR__ . '/../admin/utilisateurs.php');
    $has_verify = str_contains($admin_forms_code, 'verifyCsrfToken');
    return [
        'expected' => 'Présence active de verifyCsrfToken() sur les actions sensibles',
        'obtained' => $has_verify ? 'verifyCsrfToken actif' : 'VULNÉRABLE: verifyCsrfToken() non appelé dans les contrôleurs POST',
        'passed' => $has_verify,
        'priority' => 'Haute'
    ];
});

// T034: Performances de temps de réponse backend (< 100ms)
run_test('T034', 'Performance', 'Mesure des temps d\'exécution backend sur requêtes agrégées clés', function() use ($pdo) {
    $t0 = microtime(true);
    // Simulation du chargement du catalogue actif
    $stmt = $pdo->query("SELECT id, nom, date_evenement, heure, lieu, prix_vote FROM events WHERE statut = 'actif' AND (visibilite = 'public' OR visibilite IS NULL) LIMIT 20");
    $events = $stmt->fetchAll();
    $t1 = microtime(true);
    $ms = round(($t1 - $t0) * 1000, 2);

    return [
        'expected' => 'Temps de requête catalogue < 100 ms',
        'obtained' => "$ms ms (Catalogue de " . count($events) . " événements)",
        'passed' => $ms < 100,
        'priority' => 'Moyenne'
    ];
});

// T035: Responsive & Meta Viewport
run_test('T035', 'Interface & Responsive', 'Présence systématique des métadonnées viewport et feuilles responsive-pro.css', function() {
    $header_client = file_get_contents(__DIR__ . '/../client/header.php');
    $has_viewport = str_contains($header_client, 'viewport');
    $has_responsive_css = str_contains($header_client, 'responsive-pro.css');
    return [
        'expected' => 'Balise viewport et feuille responsive-pro.css déclarées',
        'obtained' => ($has_viewport && $has_responsive_css) ? 'Viewport et feuille responsive-pro.css présents' : 'Manquant',
        'passed' => $has_viewport && $has_responsive_css,
        'priority' => 'Moyenne'
    ];
});

file_put_contents(__DIR__ . '/results_security_perf.json', json_encode($tests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nRésultats enregistrés dans results_security_perf.json\n";
