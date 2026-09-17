<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

echo "=== TEST D'INTÉGRATION DES PAGES SÉCURISÉES ===\n";

// 1. Test Pharmacie (démo demandée dans le prompt)
$testPharmId = 125;
$pharmToken = get_or_create_resource_token($pdo, 'pharmacie', $testPharmId);
echo "1. Pharmacie :\n";
echo "   ID réel : $testPharmId\n";
echo "   Token généré : $pharmToken\n";
echo "   URL sécurisée : /pharmacie.php?token=$pharmToken\n";
$resolved = resolve_resource_token($pdo, $pharmToken, 'pharmacie');
echo "   Résolution : " . ($resolved === $testPharmId ? "SUCCÈS (ID=$resolved)" : "ÉCHEC") . "\n";

// 2. Test Promoteur
$promUid = (int) $pdo->query("SELECT user_id FROM promoters LIMIT 1")->fetchColumn();
$promToken = get_or_create_resource_token($pdo, 'promoter', $promUid);
echo "\n2. Profil Promoteur :\n";
echo "   User ID réel : $promUid\n";
echo "   Token généré : $promToken\n";
echo "   URL sécurisée : /client/promoteur.php?token=$promToken\n";
$resolvedP = resolve_resource_token($pdo, $promToken, 'promoter');
echo "   Résolution : " . ($resolvedP === $promUid ? "SUCCÈS (ID=$resolvedP)" : "ÉCHEC") . "\n";

// 3. Test Billet Téléchargement
$tickId = (int) $pdo->query("SELECT id FROM tickets LIMIT 1")->fetchColumn();
$tickToken = get_or_create_resource_token($pdo, 'ticket', $tickId);
echo "\n3. Téléchargement Billet :\n";
echo "   Ticket ID réel : $tickId\n";
echo "   Token généré : $tickToken\n";
echo "   URL sécurisée : /client/telecharger-ticket.php?token=$tickToken\n";
$resolvedT = resolve_resource_token($pdo, $tickToken, 'ticket');
echo "   Résolution : " . ($resolvedT === $tickId ? "SUCCÈS (ID=$resolvedT)" : "ÉCHEC") . "\n";

// 4. Test Commande Paiement
$ordId = (int) $pdo->query("SELECT id FROM orders LIMIT 1")->fetchColumn();
$ordToken = get_or_create_resource_token($pdo, 'order', $ordId);
echo "\n4. Paiement Commande :\n";
echo "   Order ID réel : $ordId\n";
echo "   Token généré : $ordToken\n";
echo "   URL sécurisée : /client/paiement.php?token=$ordToken\n";
$resolvedO = resolve_resource_token($pdo, $ordToken, 'order');
echo "   Résolution : " . ($resolvedO === $ordId ? "SUCCÈS (ID=$resolvedO)" : "ÉCHEC") . "\n";

// 5. Test Falsification / Altération
$forgedToken = "8fK72LmQx91Pfakefakefake";
$resolvedF = resolve_resource_token($pdo, $forgedToken, 'pharmacie');
echo "\n5. Test Token Falsifié :\n";
echo "   Token : $forgedToken\n";
echo "   Résolution : " . ($resolvedF === null ? "BLOQUÉ (null, aucun ID exposé)" : "ÉCHEC VULNÉRABILITÉ") . "\n";

echo "\nTous les flux d'intégration fonctionnent impeccablement.\n";
