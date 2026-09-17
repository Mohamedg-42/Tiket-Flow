<?php
$f1 = json_decode(file_get_contents(__DIR__ . '/results_auth_access.json'), true);
$f2 = json_decode(file_get_contents(__DIR__ . '/results_forms.json'), true);
$f3 = json_decode(file_get_contents(__DIR__ . '/results_ticketing_db.json'), true);
$f4 = json_decode(file_get_contents(__DIR__ . '/results_security_perf.json'), true);

$all = array_merge($f1, $f2, $f3, $f4);

echo "=== SYNTHÈSE TOTALE DES TESTS EXÉCUTÉS ===\n";
$total = count($all);
$passed = count(array_filter($all, fn($t) => $t['status'] === 'RÉUSSI'));
$failed = count(array_filter($all, fn($t) => $t['status'] === 'ÉCHEC'));

echo "Total tests: $total\n";
echo "Réussis:     $passed\n";
echo "Échecs:      $failed\n\n";

foreach ($all as $t) {
    $mark = ($t['status'] === 'RÉUSSI') ? '✅' : '❌';
    echo "$mark [{$t['id']}] {$t['feature']} | {$t['test']} -> {$t['status']}\n";
}
