<?php
// Test d'intégration de l'affichage du menu mobile au clic sur l'événement

function assert_test($cond, $msg) {
    if ($cond) {
        echo "[PASS] $msg\n";
    } else {
        echo "[FAIL] $msg\n";
        exit(1);
    }
}

echo "=== Test : Clic Événement Mobile vs Desktop ===\n";
$html = file_get_contents(__DIR__ . '/../client/accueil.php');
assert_test(strpos($html, 'handleEventCardClick') !== false, "Fonction handleEventCardClick présente dans accueil.php");
assert_test(strpos($html, 'onclick="handleEventCardClick(') !== false, "Cartes événement déclenchent handleEventCardClick");
assert_test(strpos($html, 'mobile-sheet-handle') !== false, "Poignée tactile mobile-sheet-handle présente dans accueil.php");
assert_test(strpos($html, 'window.innerWidth <= 768') !== false, "Détection responsive mobile <= 768px active");

$css = file_get_contents(__DIR__ . '/../Css/accueil-client.css');
assert_test(strpos($css, 'mobileModalSlideUp') !== false, "Animation mobile slide-up bottom-sheet présente dans CSS");

echo "\n============================================\n";
echo "TOUS LES TESTS DU MENU MOBILE SONT VALIDÉS !\n";
echo "============================================\n";
