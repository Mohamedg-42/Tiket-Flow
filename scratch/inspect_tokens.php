<?php
require_once __DIR__ . '/../config/database.php';

$res = $pdo->query("SELECT id, nom, visibilite, access_token FROM events LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "EVENTS:\n";
print_r($res);

$res = $pdo->query("SELECT id, titre, visibilite, access_token FROM cotisation_campagnes LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "COTISATIONS:\n";
print_r($res);

$res = $pdo->query("SELECT id, user_id, nom_commercial FROM promoters LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "PROMOTERS:\n";
print_r($res);

$res = $pdo->query("SELECT id, order_id, code_unique FROM tickets LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "TICKETS:\n";
print_r($res);
