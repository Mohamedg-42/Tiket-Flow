<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'nom' => 'Test Inscription',
    'email' => 'test_autocheck_' . time() . '@mail.com',
    'telephone' => '0707070707',
    'role' => 'client',
    'password' => '123456'
];

ob_start();
include 'inscription.php';
$html = ob_get_clean();

if (strpos($html, 'Compte créé avec succès') !== false) {
    echo "✅ TEST REUSSI : Inscription validée et utilisateur inséré en base !\n";
} else {
    echo "❌ TEST ECHOUE :\n";
    echo $html;
}
