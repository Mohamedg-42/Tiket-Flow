<?php
// ==============================================================================
// ASSISTANT D'INSTALLATION & CONNEXION POSTGRESQL (installer_postgres.php)
// Configure automatiquement la base de données PostgreSQL pour Tikéli
// ==============================================================================

// Verrou de sécurité : interdire l'accès à l'installateur sur un système opérationnel (SEC-003)
if (file_exists(__DIR__ . '/config/database.php')) {
    http_response_code(403);
    die("<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>403 - Installateur Verrouillé</title><style>body{font-family:system-ui,sans-serif;padding:3rem;background:#f8fafc;color:#0f172a;text-align:center;}h1{color:#ef4444;}</style></head><body><h1>Installateur Verrouillé</h1><p>Pour des raisons de sécurité, l'assistant d'installation a été verrouillé car la plateforme Tikéli est déjà configurée.</p><p><a href='connexion.php'>Accéder à la connexion</a> · <a href='client/accueil.php'>Accueil</a></p></body></html>");
}
$msg_type = "";
$step_completed = false;

$host = $_POST['host'] ?? '127.0.0.1';
$port = $_POST['port'] ?? '5432';
$user = $_POST['user'] ?? 'postgres';
$pass = $_POST['pass'] ?? '';
$dbname = $_POST['dbname'] ?? 'ticket_platform';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_connect'])) {
    if ($pass === '') {
        $message = "Veuillez saisir le mot de passe de votre utilisateur PostgreSQL.";
        $msg_type = "error";
    } else {
        try {
            // 1. Test de connexion au serveur PostgreSQL (base 'postgres' par défaut)
            $dsn_root = "pgsql:host={$host};port={$port};dbname=postgres;connect_timeout=3";
            $pdo_root = new PDO($dsn_root, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // 2. Création de la base de données si elle n'existe pas
            $stmt_check = $pdo_root->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt_check->execute([$dbname]);
            $db_exists = $stmt_check->fetchColumn();

            if (!$db_exists) {
                // Créer la base
                $pdo_root->exec("CREATE DATABASE \"{$dbname}\" ENCODING 'UTF8';");
            }

            // 3. Connexion à la nouvelle base de données
            $dsn_app = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=3";
            $pdo_app = new PDO($dsn_app, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // 4. Exécution du script SQL complet PostgreSQL
            $sql_file = __DIR__ . '/database/postgres_complete.sql';
            if (file_exists($sql_file)) {
                $sql_content = file_get_contents($sql_file);
                // Exécuter par blocs pour éviter les soucis de mémoire
                $pdo_app->exec($sql_content);
            }

            // 5. Mise à jour automatique de config/database.php
            $config_content = "<?php
// ==============================================================================
// FICHIER DE CONNEXION POSTGRESQL (config/database.php)
// Généré automatiquement par l'Assistant Tikéli
// ==============================================================================

\$host    = '" . addslashes($host) . "';
\$port    = '" . addslashes($port) . "';
\$db      = '" . addslashes($dbname) . "';
\$user    = '" . addslashes($user) . "';
\$pass    = '" . addslashes($pass) . "';

\$dsn = \"pgsql:host=\$host;port=\$port;dbname=\$db;\";

\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    \$pdo = new PDO(\$dsn, \$user, \$pass, \$options);
} catch (\\PDOException \$e) {
    die(\"❌ Erreur de connexion à PostgreSQL : \" . \$e->getMessage());
}
";

            file_put_contents(__DIR__ . '/config/database.php', $config_content);

            $message = "Connexion réussie ! La base de données « $dbname » a été initialisée et config/database.php est à jour.";
            $msg_type = "success";
            $step_completed = true;

        } catch (PDOException $e) {
            $err_msg = $e->getMessage();
            if (strpos($err_msg, 'password authentication failed') !== false) {
                $message = "Mot de passe incorrect pour l'utilisateur « $user ». Veuillez vérifier le mot de passe défini lors de l'installation de PostgreSQL.";
            } elseif (strpos($err_msg, 'connection to server') !== false) {
                $message = "Impossible de joindre le serveur PostgreSQL sur $host:$port. Vérifiez que le service PostgreSQL est démarré.";
            } else {
                $message = "Erreur PostgreSQL : " . $err_msg;
            }
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion PostgreSQL - Tikéli</title>
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 Pro Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy: #0b1d3a;
            --amber: #ffb12e;
            --teal: #0d9488;
            --border: #e2e8f0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0b1d3a 0%, #1e293b 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #0f172a;
        }
        .setup-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            max-width: 540px;
            width: 100%;
            padding: 2.25rem;
            box-sizing: border-box;
        }
        .brand-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }
        .brand-logo {
            font-family: 'Outfit', sans-serif;
            font-size: 1.7rem;
            font-weight: 900;
            color: var(--navy);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem 0.95rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 0.92rem;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 3px rgba(255, 177, 46, 0.2);
        }
        .btn-submit {
            width: 100%;
            padding: 0.9rem;
            border-radius: 12px;
            background: var(--amber);
            color: var(--navy);
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            margin-top: 1.25rem;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 177, 46, 0.35);
        }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            font-size: 0.86rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.45;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }
        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="brand-header">
            <div class="brand-logo">
                <i class="fa-solid fa-database" style="color: #336791;"></i>
                <span>TIKÉLI <span style="color: var(--amber);">×</span> PostgreSQL</span>
            </div>
            <h2 style="margin: 0.5rem 0 0.25rem; font-size: 1.25rem; font-family: 'Outfit', sans-serif; color: var(--navy);">
                Connexion PostgreSQL Locale
            </h2>
            <p style="margin: 0; font-size: 0.85rem; color: #64748b;">
                Entrez votre mot de passe PostgreSQL pour connecter et initialiser votre plateforme.
            </p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" style="margin-top: 2px;"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($step_completed): ?>
            <div style="text-align: center; padding: 1rem 0;">
                <a href="connexion.php" class="btn-submit" style="text-decoration: none; background: #0d9488; color: #ffffff;">
                    <i class="fa-solid fa-arrow-right"></i> Accéder à Tikéli (Connexion)
                </a>
            </div>
        <?php else: ?>
            <form method="POST" action="installer_postgres.php">
                <input type="hidden" name="action_connect" value="1">

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label for="host"><i class="fa-solid fa-server" style="color: #336791;"></i> Hôte</label>
                        <input type="text" id="host" name="host" value="<?php echo htmlspecialchars($host); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="port"><i class="fa-solid fa-network-wired" style="color: #336791;"></i> Port</label>
                        <input type="text" id="port" name="port" value="<?php echo htmlspecialchars($port); ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label for="user"><i class="fa-solid fa-user" style="color: #336791;"></i> Utilisateur</label>
                        <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($user); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="dbname"><i class="fa-solid fa-database" style="color: #336791;"></i> Base de données</label>
                        <input type="text" id="dbname" name="dbname" value="<?php echo htmlspecialchars($dbname); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="pass"><i class="fa-solid fa-key" style="color: var(--amber);"></i> Mot de passe PostgreSQL *</label>
                    <div style="position: relative; width: 100%;">
                        <input type="password" id="pass" name="pass" placeholder="Mot de passe créé à l'installation" autofocus required style="width: 100%; box-sizing: border-box; padding-right: 2.5rem;">
                        <button type="button" onclick="togglePassVisibility('pass', this.querySelector('i'))" aria-label="Afficher"
                            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #64748b; cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: 0.9rem;">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <small style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 4px;">
                        C'est le mot de passe que vous avez choisi lors de l'installation de PostgreSQL pour l'utilisateur <code>postgres</code>.
                    </small>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-bolt"></i> Connecter & Initialiser la Base
                </button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid var(--border); padding-top: 1rem;">
            <a href="connexion.php" style="color: #64748b; font-size: 0.82rem; text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Retour à la page de connexion
            </a>
        </div>
    </div>

    <script>
    function togglePassVisibility(inputId, iconElem) {
        const inp = document.getElementById(inputId);
        if (!inp) return;
        if (inp.type === 'password') {
            inp.type = 'text';
            if (iconElem) {
                iconElem.classList.remove('fa-eye');
                iconElem.classList.add('fa-eye-slash');
            }
        } else {
            inp.type = 'password';
            if (iconElem) {
                iconElem.classList.remove('fa-eye-slash');
                iconElem.classList.add('fa-eye');
            }
        }
    }
    </script>
</body>
</html>
