<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$order_id = 87;
$sec_token = get_or_create_resource_token($pdo, 'order', $order_id);

$_GET['token'] = $sec_token;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/client/paiement.php';

ob_start();
chdir(__DIR__ . '/../client');
include __DIR__ . '/../client/paiement.php';
$out = ob_get_clean();

echo "Output length: " . strlen($out) . PHP_EOL;
echo "Contains swiss-split-layout: " . (strpos($out, 'swiss-split-layout') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains swiss-h1: " . (strpos($out, 'swiss-h1') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains swiss-figure: " . (strpos($out, 'swiss-figure') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Concert Géant: " . (strpos($out, 'Concert Géant') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Wave Money: " . (strpos($out, 'Wave Money') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Orange Money: " . (strpos($out, 'Orange Money') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains MTN MoMo: " . (strpos($out, 'MTN MoMo') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Bictorys: " . (strpos($out, 'Bictorys') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains pay-modal-backdrop: " . (strpos($out, 'pay-modal-backdrop') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains modal-view-details: " . (strpos($out, 'modal-view-details') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains modal-view-success: " . (strpos($out, 'modal-view-success') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Your payment has been successfully proceed: " . (strpos($out, 'Your payment has been successfully proceed') !== false ? 'YES' : 'NO') . PHP_EOL;
