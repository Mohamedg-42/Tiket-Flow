<?php
chdir(__DIR__ . '/../admin');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['user_nom'] = 'Admin Principal';
$_SESSION['nom'] = 'Admin Principal';
$_SESSION['email'] = 'admin@ticketflow.com';

ob_start();
try {
    include 'dashboard.php';
    $output = ob_get_clean();
    echo "SUCCESS: Dashboard chargé sans aucune erreur SQL ! Longueur rendu HTML: " . strlen($output) . " octets\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERREUR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}
