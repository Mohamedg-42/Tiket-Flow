<?php
// Batch tester sans escaping complexe
$directories = ['admin', 'promoteur', 'client', 'agent'];

$runner_template = '<?php
chdir(__DIR__ . "/../{DIR}");
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_SESSION["user_role"] = "admin";
$_SESSION["nom"] = "Admin";
$_SESSION["user_nom"] = "Admin";
$_SESSION["email"] = "admin@ticketflow.com";

ob_start();
try {
    include "{FILE}";
    ob_get_clean();
    echo "OK";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
}
';

$temp_runner = __DIR__ . '/_temp_runner.php';

echo "=== VÉRIFICATION COMPLÈTE DE TOUTES LES PAGES SUR POSTGRESQL ===\n\n";

foreach ($directories as $dir) {
    $files = glob(__DIR__ . "/../$dir/*.php");
    foreach ($files as $file) {
        $basename = basename($file);
        if (in_array($basename, ['supprimer-evenement.php', 'supprimer-salle.php', 'footer.php', 'header.php', 'deconnexion.php'], true)) {
            continue;
        }

        $code = str_replace(['{DIR}', '{FILE}'], [$dir, $basename], $runner_template);
        file_put_contents($temp_runner, $code);

        $output = shell_exec("php -f \"" . $temp_runner . "\" 2>&1");
        $output = trim($output);
        
        $status = ($output === 'OK') ? "✓ OK" : "! " . $output;
        echo sprintf("%-12s / %-30s : %s\n", $dir, $basename, $status);
    }
}

if (file_exists($temp_runner)) {
    unlink($temp_runner);
}
