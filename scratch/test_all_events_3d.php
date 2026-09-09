<?php
$eventIds = [1, 2, 4, 9, 10, 13];
foreach ($eventIds as $eid) {
    $res = file_get_contents("http://127.0.0.1/ticket-platform/ajax/salle_3d_data.php?event_id=$eid");
    $data = json_decode($res, true);
    echo "EVENT $eid: ";
    if (!$data) {
        echo "FAILED TO PARSE JSON: " . substr($res, 0, 100) . "\n";
    } else {
        echo "success=" . ($data['success'] ? 'true' : 'false') . ", salle=" . ($data['salle']['nom'] ?? 'none') . ", seats=" . count($data['seats'] ?? []) . ", ticket_types=" . count($data['ticket_types'] ?? []) . "\n";
    }
}
