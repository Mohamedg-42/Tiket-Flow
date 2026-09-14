<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';

echo "=== 1. TEST PHONE FORMATTING ===\n";
$p1 = bictorys_format_phone('0701020304', 'CI');
$p2 = bictorys_format_phone('+225 05 06 07 08 09', 'CI');
$p3 = bictorys_format_phone('221770000000', 'SN');
echo "CI format: $p1 (expected +2250701020304)\n";
echo "CI format 2: $p2 (expected +2250506070809)\n";
echo "SN format: $p3 (expected +221770000000)\n";
assert($p1 === '+2250701020304');
assert($p2 === '+2250506070809');
assert($p3 === '+221770000000');
echo "OK Phone formatting!\n\n";

echo "=== 2. TEST BICTORYS CREATE CHARGE ===\n";
$ref = 'TEST-' . uniqid();
$params = [
    'amount'             => 1000,
    'paymentReference'   => $ref,
    'successRedirectUrl' => 'https://tikewa.com/client/callback.php?test=1',
    'errorRedirectUrl'   => 'https://tikewa.com/client/paiement.php?test=1',
    'country'            => 'CI',
    'customer'           => [
        'name'  => 'Amadou Koné',
        'phone' => '0701020304',
        'email' => 'amadou.kone@example.com'
    ]
];
$res = bictorys_create_charge($params);
echo "Result success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
echo "Charge ID / Tx ID: " . $res['transactionId'] . "\n";
echo "Checkout link: " . $res['redirectUrl'] . "\n";
assert($res['success'] === true);
assert(!empty($res['redirectUrl']));
echo "OK Bictorys create charge!\n\n";

echo "=== 3. TEST WEBHOOK VERIFICATION HELPER ===\n";
$fakeRaw = json_encode(['event' => 'charge.success', 'paymentReference' => $ref, 'status' => 'success']);
$headers = ['x-secret-key' => 'test'];
// In test env with empty secret, bictorys_verify_webhook returns true in test mode
$is_valid = bictorys_verify_webhook($fakeRaw, $headers);
echo "Webhook verify result (mode test): " . ($is_valid ? 'VALID' : 'INVALID') . "\n";
assert($is_valid === true);
echo "OK Webhook verification!\n\n";

echo "=== ALL AUTOMATED TESTS PASSED SUCCESSFULLY! ===\n";
