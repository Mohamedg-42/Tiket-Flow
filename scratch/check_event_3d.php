<?php
ob_start();
$_GET['event_id'] = 10;
include __DIR__ . '/../ajax/salle_3d_data.php';
$output = ob_get_clean();

$data = json_decode($output, true);
echo "Total seats: " . count($data['seats'] ?? []) . "\n";
echo "First seat:\n";
print_r($data['seats'][0] ?? null);
