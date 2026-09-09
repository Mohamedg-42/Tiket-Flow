<?php
require_once __DIR__ . '/../config/database.php';
$_GET['event_id'] = 10;
ob_start();
include __DIR__ . '/../ajax/salle_3d_data.php';
$json = ob_get_clean();
$data = json_decode($json, true);
echo "TOTAL SEATS: " . count($data['seats']) . "\n";
echo "SAMPLE SEATS:\n";
for ($i = 0; $i < min(15, count($data['seats'])); $i++) {
    $s = $data['seats'][$i];
    echo "ID={$s['id']}, code={$s['code']}, row={$s['row']}, num={$s['number']}, x={$s['x']}, y={$s['y']}, z={$s['z']}, ticket_type={$s['ticket_type_id']}, zone={$s['zone_name']}\n";
}
