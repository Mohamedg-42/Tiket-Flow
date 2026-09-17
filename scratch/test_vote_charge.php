<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/secure_token.php';

$voteToken = '227ozarKMOWxwjjwL5i2To4H';
$id = resolve_resource_token($pdo, $voteToken, 'vote_payment');
echo "Resolved vote payment id: $id\n";

$stmt = $pdo->prepare("
    SELECT vp.*, e.nom AS event_nom, e.visibilite AS event_visibilite, e.access_token AS event_access_token
    FROM vote_paiements vp
    JOIN events e ON e.id = vp.event_id
    WHERE vp.id = ?
");
$stmt->execute([$id]);
$vote_pay = $stmt->fetch();

echo "Vote pay found: " . ($vote_pay ? 'YES' : 'NO') . " | Montant: " . ($vote_pay['montant'] ?? '') . "\n";

$pay_secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'tikeli_pay_sec_9948271';
$vote_token = hash_hmac('sha256', $vote_pay['id'] . '|' . $vote_pay['montant'] . '|' . $vote_pay['created_at'], $pay_secret);
$cur_vote_token = get_or_create_resource_token($pdo, 'vote_payment', (int) $vote_pay['id']);

$callbackUrl = 'http://localhost/client/callback-vote.php?vote_paiement_id=' . $vote_pay['id'] . '&methode=bictorys&vote_token=' . $vote_token;
$errorUrl = 'http://localhost/client/paiement-vote.php?token=' . urlencode($cur_vote_token) . '&error=1';

$chargeParams = [
    'amount' => (int) round($vote_pay['montant']),
    'paymentReference' => 'VOTE_' . $vote_pay['id'],
    'successRedirectUrl' => $callbackUrl,
    'errorRedirectUrl' => $errorUrl,
    'country' => 'CI',
    'customer' => [
        'name' => 'Électeur',
        'phone' => '0701020304',
        'email' => 'electeur@test.com'
    ],
    'payment_type' => 'wave_money'
];

$charge = bictorys_create_charge($chargeParams);
echo "Charge success: " . ($charge['success'] ? 'YES' : 'NO') . "\n";
echo "Redirect URL: " . ($charge['redirectUrl'] ?? '') . "\n";
echo "Error (if any): " . ($charge['error'] ?? 'none') . "\n";
