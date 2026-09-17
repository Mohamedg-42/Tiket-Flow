<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$sec_token = get_or_create_resource_token($pdo, 'order', 89);
$_GET['token'] = $sec_token;

$_POST = [
    'confirmer_simulation_bictorys' => '1',
    'transaction_id' => 'a4f71a2f-aab8-490d-8294-14edccaa706d'
];
$_SERVER['REQUEST_METHOD'] = 'POST';

chdir(__DIR__ . '/../client');
include __DIR__ . '/../client/paiement.php';
