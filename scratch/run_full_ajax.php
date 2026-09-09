<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_GET['event_id'] = 10;
ob_start();
require __DIR__ . '/../ajax/salle_3d_data.php';
$output = ob_get_clean();

$data = json_decode($output, true);
if (!$data) {
    echo "FAILED! Raw output:\n";
    echo $output;
} else {
    echo "SUCCESS! Valid JSON returned.\n";
    echo "Success status: " . ($data['success'] ? 'true' : 'false') . "\n";
    echo "Salle: " . $data['salle']['nom'] . "\n";
    echo "Event: " . ($data['event']['nom'] ?? 'null') . "\n";
    echo "Seats count: " . count($data['seats']) . "\n";
    echo "Ticket types count: " . count($data['ticket_types']) . "\n";
}
