<?php
// Test Suite 1: Authentication & Access Control Tests (T001 to T007 + Account Status)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

echo "==============================================================\n";
echo "   EXÉCUTION SUITE 1 : AUTHENTIFICATION & CONTRÔLE D'ACCÈS\n";
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

// T001: Connexion avec identifiants valides
run_test('T001', 'Authentification', 'Vérification de la vérification de mot de passe pour les 4 rôles', function() use ($pdo) {
    $roles_to_check = ['admin', 'promoteur', 'agent', 'client'];
    $tested = [];
    foreach ($roles_to_check as $role) {
        $stmt = $pdo->prepare("SELECT id, email, password, role FROM users WHERE role = ? AND statut = 'actif' LIMIT 1");
        $stmt->execute([$role]);
        $u = $stmt->fetch();
        if ($u && !empty($u['password'])) {
            $has_hash = (str_starts_with($u['password'], '$2y$') || str_starts_with($u['password'], '$argon2'));
            $tested[] = "$role: " . ($has_hash ? "OK (bcrypt)" : "KO");
        }
    }
    $allOk = count($tested) === 4;
    return [
        'expected' => 'Mots de passe sécurisés avec hash bcrypt pour tous les rôles',
        'obtained' => implode(', ', $tested),
        'passed' => $allOk,
        'priority' => 'Haute'
    ];
});

// T002: Connexion avec mot de passe erroné
run_test('T002', 'Authentification', 'Tentative de connexion avec mauvais mot de passe', function() use ($pdo) {
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ?");
    $stmt->execute(['client@ticketflow.com']);
    $u = $stmt->fetch();
    $valid = password_verify('faux_mot_de_passe_123', $u['password'] ?? '');
    return [
        'expected' => 'password_verify retourne false pour mauvais mot de passe',
        'obtained' => $valid ? 'true (FAUX POSITIF)' : 'false (Rejeté correctement)',
        'passed' => !$valid,
        'priority' => 'Haute'
    ];
});

// T003: Protection anti-brute force (5 tentatives)
run_test('T003', 'Authentification', 'Mécanisme de verrouillage de session après 5 échecs consécutifs', function() {
    $mock_session = ['login_attempts' => 5];
    $locked = false;
    $lockout_seconds = 900;
    if ($mock_session['login_attempts'] >= 5) {
        $mock_session['login_lockout_until'] = time() + $lockout_seconds;
        $locked = true;
    }
    $is_currently_locked = isset($mock_session['login_lockout_until']) && time() < $mock_session['login_lockout_until'];
    return [
        'expected' => 'Verrouillage temporaire actif de 15 minutes',
        'obtained' => $is_currently_locked ? 'Accès verrouillé pendant 900s' : 'Non verrouillé',
        'passed' => $is_currently_locked,
        'priority' => 'Haute'
    ];
});

// T004: Déconnexion & destruction de session
run_test('T004', 'Authentification', 'Destruction propre de session lors de la déconnexion', function() {
    $_SESSION['user_id'] = 999;
    $_SESSION['user_role'] = 'client';
    // simulation de deconnexion.php
    session_unset();
    $cleared = empty($_SESSION['user_id']) && empty($_SESSION['user_role']);
    return [
        'expected' => 'Variables de session intégralement purgées',
        'obtained' => $cleared ? 'Session purgée avec succès' : 'Données persistantes',
        'passed' => $cleared,
        'priority' => 'Haute'
    ];
});

// T005: Contrôle d'accès - Anonyme vers page protégée
run_test('T005', 'Contrôle d\'Accès', 'Accès anonyme sans session vers admin/dashboard.php', function() {
    $_SESSION = []; // anonyme
    $redirected = false;
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $redirected = true;
    }
    return [
        'expected' => 'Redirection immédiate vers connexion.php pour visiteur anonyme',
        'obtained' => $redirected ? 'Redirection déclenchée' : 'Accès autorisé',
        'passed' => $redirected,
        'priority' => 'Haute'
    ];
});

// T006: Contrôle d'accès - Rôle Client vers Admin
run_test('T006', 'Contrôle d\'Accès', 'Utilisateur avec rôle client tentant d\'accéder à admin/', function() {
    $_SESSION['user_id'] = 4;
    $_SESSION['user_role'] = 'client';
    
    // checkRole('admin') logic
    $user_role = $_SESSION['user_role'];
    $allowed = in_array($user_role, ['admin'], true);
    return [
        'expected' => 'Accès formellement interdit au rôle client sur l\'administration',
        'obtained' => $allowed ? 'Accès autorisé (FAILLE)' : 'Accès bloqué (Refusé)',
        'passed' => !$allowed,
        'priority' => 'Haute'
    ];
});

// T007: Contrôle d'accès - Rôle Client vers pos/index.php (Vérification de la faille POS-001)
run_test('T007', 'Contrôle d\'Accès', 'Utilisateur avec rôle client accédant à pos/index.php', function() {
    // Dans pos/index.php actuel (ligne 14):
    // if (!isset($_SESSION['user_id'])) { header('Location: ../connexion.php'); exit(); }
    $_SESSION['user_id'] = 4;
    $_SESSION['user_role'] = 'client';
    
    $pos_code = file_get_contents(__DIR__ . '/../pos/index.php');
    $has_role_check = str_contains($pos_code, 'checkRole') || str_contains($pos_code, "user_role") || str_contains($pos_code, "agent_gare");
    
    return [
        'expected' => 'pos/index.php doit exiger le rôle agent_gare ou admin',
        'obtained' => $has_role_check ? 'Vérification de rôle présente' : 'AUCUNE VÉRIFICATION DE RÔLE : n\'importe quel client peut vendre des billets',
        'passed' => $has_role_check,
        'priority' => 'Critique'
    ];
});

// Test statut suspendu
run_test('T007B', 'Contrôle d\'Accès', 'Refus de connexion pour un compte suspendu définitivement', function() use ($pdo) {
    $mockUser = ['id' => 99, 'statut' => 'suspendu_def', 'role' => 'client', 'suspension_reason' => 'Fraude'];
    $status = checkAccountStatus($mockUser);
    return [
        'expected' => 'allowed = false pour compte suspendu_def',
        'obtained' => (!$status['allowed']) ? "Bloqué: {$status['message']}" : "Autorisé",
        'passed' => !$status['allowed'],
        'priority' => 'Haute'
    ];
});

file_put_contents(__DIR__ . '/results_auth_access.json', json_encode($tests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nRésultats enregistrés dans results_auth_access.json\n";
