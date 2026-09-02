<?php
// Outil d'inspection temporaire (CLI)
$what = $argv[1] ?? '';
$ranges = [
    'de1'   => ['promoteur/demande-evenement.php', 95, 175],
    'de2'   => ['promoteur/demande-evenement.php', 380, 470],
    'de3'   => ['promoteur/demande-evenement.php', 470, 560],
    'adm'   => ['admin/demandes.php', 30, 70],
    'accueil' => ['client/accueil.php', 855, 975],
    'accjs' => ['client/accueil.php', 1690, 1805],
];
[$f, $a, $b] = $ranges[$what] ?? ['', 0, 0];
if ($f) {
    $lines = file($f);
    for ($i = $a - 1; $i < min($b, count($lines)); $i++) {
        echo ($i + 1) . ': ' . rtrim($lines[$i]) . "\n";
    }
}