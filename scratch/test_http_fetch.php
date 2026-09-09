<?php
// Test fetching via HTTP on localhost

$urls = [
    'http://localhost/ticket-platform/ajax/salle_3d_data.php?event_id=10',
    'http://127.0.0.1/ticket-platform/ajax/salle_3d_data.php?event_id=10',
    'http://localhost/ajax/salle_3d_data.php?event_id=10',
    'http://127.0.0.1/ajax/salle_3d_data.php?event_id=10',
];

$ctx = stream_context_create([
    'http' => [
        'timeout' => 3,
        'ignore_errors' => true
    ]
]);

foreach ($urls as $url) {
    echo "Testing $url ... ";
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        echo "FAILED to connect\n";
    } else {
        $status = $http_response_header[0] ?? 'No status';
        echo "Status: $status, Length: " . strlen($res) . "\n";
        if (str_contains($status, '200')) {
            $json = json_decode($res, true);
            echo "  JSON valid? " . ($json ? 'YES (success=' . ($json['success'] ? 'true' : 'false') . ')' : 'NO') . "\n";
            if ($json) {
                echo "  Seats: " . count($json['seats'] ?? []) . ", Ticket Types: " . count($json['ticket_types'] ?? []) . "\n";
            } else {
                echo "  Raw output start: " . substr($res, 0, 200) . "\n";
            }
        }
    }
}
