<?php
// ==============================================================================
// DÉMONSTRATION RESSOURCE PROTÉGÉE (pharmacie.php)
// Conforme aux exigences : récupération du token, recherche en base,
// résolution de l'ID réel, affichage ou 404 si falsifié/expiré/inexistant.
// ==============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/secure_token.php';
session_start();

$token = trim((string) ($_GET['token'] ?? ''));
$pharmacie_id = null;

if (!empty($token)) {
    // 1. Récupérer et rechercher le token en base de données avec requête préparée PDO
    $pharmacie_id = resolve_resource_token($pdo, $token, 'pharmacie');

    // 2. Si le token est inexistant, invalide, falsifié ou expiré -> 404
    if (!$pharmacie_id) {
        render_token_security_error(
            "Ressource introuvable",
            "La pharmacie demandée est introuvable ou le lien sécurisé a expiré.",
            404,
            "client/accueil.php"
        );
    }
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Redirection automatique 301 pour masquer immédiatement l'ID de l'URL
    $legacy_id = (int) $_GET['id'];
    $secure_token = get_or_create_resource_token($pdo, 'pharmacie', $legacy_id);
    header('Location: pharmacie.php?token=' . urlencode($secure_token), true, 301);
    exit();
} else {
    render_token_security_error(
        "Paramètre manquant",
        "Aucun token d'accès sécurisé n'a été fourni.",
        400,
        "client/accueil.php"
    );
}

// 3. Retrouver l'ID réel et afficher la ressource correspondante
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacie Partenaire #<?php echo (int) $pharmacie_id; ?> — Tike WA</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2rem; max-width: 500px; width: 100%; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .badge { display: inline-block; background: #0d9488; color: #ffffff; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; }
        h1 { font-size: 1.4rem; margin-bottom: 0.5rem; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.6; }
        .token-box { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 0.85rem; font-family: monospace; font-size: 0.85rem; word-break: break-all; margin: 1rem 0; color: #38bdf8; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">Accès Sécurisé par Token</span>
        <h1>Pharmacie Partenaire</h1>
        <p>Cette ressource a été résolue en base de données de façon transparente et cryptographique.</p>
        <div class="token-box">
            Token public : <?php echo htmlspecialchars($token); ?><br>
            ID réel en base : <?php echo (int) $pharmacie_id; ?> (Non exposé dans l'URL)
        </div>
        <p><small style="color: #64748b;">Protection anti-énumération active & Requêtes préparées PDO.</small></p>
    </div>
</body>
</html>
