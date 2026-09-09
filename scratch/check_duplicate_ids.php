<?php
$content = file_get_contents(__DIR__ . '/../client/accueil.php');
preg_match_all('/id="([^"]+)"/', $content, $m);
$counts = array_count_values($m[1]);
foreach ($counts as $id => $cnt) {
    if ($cnt > 1) {
        echo "$id => $cnt occurrences\n";
    }
}
