<?php
$lines = file(__DIR__ . '/../client/accueil.php');
foreach ($lines as $no => $l) {
    if (stripos($l, 'events') !== false && (stripos($l, 'SELECT') !== false || stripos($l, 'FROM') !== false || stripos($l, 'WHERE') !== false)) {
        echo ($no+1) . ': ' . trim($l) . "\n";
    }
}
