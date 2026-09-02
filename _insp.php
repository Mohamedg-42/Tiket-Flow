<?php
// Inspecteur temporaire : php _insp.php <fichier> <debut> <fin>
$f = $argv[1] ?? '';
$debut = (int)($argv[2] ?? 1);
$fin = (int)($argv[3] ?? 0);
$l = file($f);
if ($fin <= 0) $fin = count($l);
for ($i = $debut - 1; $i < min($fin, count($l)); $i++) {
    echo ($i + 1) . ': ' . rtrim($l[$i]) . "\n";
}
