<?php
// ==============================================================================
// SCRIPT DE MIGRATION — COMPTES, PROFILS, PERMISSIONS, TÂCHES & ACTIVITÉ
// Version PostgreSQL (portée depuis l'ancienne version MySQL, incompatible avec
// la base de production). Idempotent — safe à ré-exécuter à tout moment.
// Exécutable en CLI : php migrate_comptes_roles_taches.php
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "🚀 Démarrage de la migration de la base de données (PostgreSQL)...\n";

function pgColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function pgAddColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
    if (!pgColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN \"$column\" $definition");
        echo "  + Colonne `$column` ajoutée sur `$table`.\n";
    } else {
        echo "  = Colonne `$column` déjà présente sur `$table`.\n";
    }
}

try {

    // 1. Évolution de la table `users`
    echo "1. Vérification et ajout des colonnes sur `users`...\n";
    pgAddColumnIfNotExists($pdo, 'users', 'prenom', "VARCHAR(100) NULL");
    pgAddColumnIfNotExists($pdo, 'users', 'statut', "VARCHAR(30) NOT NULL DEFAULT 'actif'");
    pgAddColumnIfNotExists($pdo, 'users', 'profile_id', "INTEGER NULL");
    pgAddColumnIfNotExists($pdo, 'users', 'derniere_connexion', "TIMESTAMP WITHOUT TIME ZONE NULL");
    pgAddColumnIfNotExists($pdo, 'users', 'suspended_from', "DATE NULL");
    pgAddColumnIfNotExists($pdo, 'users', 'suspended_until', "DATE NULL");
    pgAddColumnIfNotExists($pdo, 'users', 'suspension_reason', "TEXT NULL");

    // 2. Création de la table `permissions`
    echo "2. Création de la table `permissions`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id SERIAL PRIMARY KEY,
            code VARCHAR(60) NOT NULL UNIQUE,
            nom VARCHAR(120) NOT NULL,
            categorie VARCHAR(60) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 3. Création de la table `profiles`
    echo "3. Création de la table `profiles`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profiles (
            id SERIAL PRIMARY KEY,
            nom VARCHAR(100) NOT NULL UNIQUE,
            description TEXT NULL,
            is_system SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 4. Création de la table `profile_permissions`
    echo "4. Création de la table `profile_permissions`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profile_permissions (
            profile_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (profile_id, permission_id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_profile_permissions_profile ON profile_permissions(profile_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_profile_permissions_permission ON profile_permissions(permission_id)");

    // 5. Création de la table `tasks`
    echo "5. Création de la table `tasks`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            created_by INTEGER NOT NULL,
            titre VARCHAR(200) NOT NULL,
            description TEXT NULL,
            priorite VARCHAR(20) NOT NULL DEFAULT 'normale',
            statut VARCHAR(20) NOT NULL DEFAULT 'a_faire',
            date_debut DATE NULL,
            date_limite DATE NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_user_id ON tasks(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_created_by ON tasks(created_by)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_statut ON tasks(statut)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_priorite ON tasks(priorite)");

    // 6. Création de la table `activity_logs`
    echo "6. Création de la table `activity_logs`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(60) NULL,
            target_id INTEGER NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_target_type ON activity_logs(target_type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_target_id ON activity_logs(target_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON activity_logs(created_at)");

    // 6.1 Contraintes d'unicité requises par les upserts ON CONFLICT ci-dessous.
    // (les tables existaient déjà sans ces index sur cette base — les CREATE TABLE
    // IF NOT EXISTS ci-dessus n'ont donc pas pu les poser eux-mêmes.)
    echo "6.1 Vérification des index d'unicité (code, nom)...\n";
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_permissions_code ON permissions(code)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_profiles_nom ON profiles(nom)");

    // 7. Remplissage / synchronisation des permissions standard
    echo "7. Enregistrement du catalogue standard des permissions...\n";
    $permissions_catalogue = [
        // Catégorie : Utilisateurs & Sécurité
        ['users.view',        'Consulter les utilisateurs',       'Utilisateurs', 'Afficher la liste et le détail des comptes'],
        ['users.create',      'Créer des comptes',                'Utilisateurs', 'Créer de nouveaux utilisateurs et collaborateurs'],
        ['users.edit',        'Modifier des comptes',             'Utilisateurs', 'Modifier les informations, rôles et profils des utilisateurs'],
        ['users.status',      'Activer / Suspendre des comptes',  'Utilisateurs', 'Gérer les suspensions temporaires ou définitives'],
        ['users.delete',      'Supprimer des comptes',            'Utilisateurs', 'Supprimer définitivement des utilisateurs du système'],

        // Catégorie : Profils & Permissions
        ['profiles.view',     'Consulter les profils',            'Profils & Rôles', 'Consulter la liste des profils métiers et permissions'],
        ['profiles.manage',   'Gérer les profils et permissions', 'Profils & Rôles', 'Créer, modifier et configurer les profils et droits'],

        // Catégorie : Événements
        ['events.view',       'Consulter les événements',         'Événements', 'Accéder à la liste complète des événements'],
        ['events.create',     'Créer un événement',               'Événements', 'Créer un nouvel événement officiel ou pour un tiers'],
        ['events.edit',       'Modifier un événement',            'Événements', 'Modifier les dates, lieux, descriptions et informations'],
        ['events.validate',   'Valider les événements soumis',    'Événements', 'Approuver ou refuser les demandes d’événements des promoteurs'],
        ['events.delete',     'Supprimer / Annuler un événement', 'Événements', 'Supprimer ou annuler un événement'],
        ['venues.manage',     'Gérer les salles & places',        'Événements', 'Configurer les plans de salle, places et jauges'],

        // Catégorie : Billetterie & Contrôle
        ['tickets.view',      'Consulter les billets',            'Billetterie', 'Voir la liste des billets émis et vendus'],
        ['tickets.manage',    'Gérer les types de billets',       'Billetterie', 'Configurer les catégories et prix des billets'],
        ['tickets.scan',      'Vérifier & Scanner les billets',   'Billetterie', 'Accéder à l’interface de vérification et scan QR code'],

        // Catégorie : Ventes & Finances
        ['orders.view',       'Consulter les commandes',          'Finances', 'Consulter l’historique des commandes et réservations'],
        ['payments.view',     'Consulter les paiements',          'Finances', 'Consulter les transactions Mobile Money et reçus'],
        ['commissions.view',  'Consulter les commissions',        'Finances', 'Superviser les commissions prélevées par la plateforme'],
        ['withdrawals.manage','Gérer les retraits promoteurs',    'Finances', 'Approuver, refuser et valider les demandes de retrait'],

        // Catégorie : Promoteurs
        ['promoters.view',    'Consulter les promoteurs',         'Promoteurs', 'Voir la liste et les fiches des promoteurs'],
        ['promoters.manage',  'Gérer et approuver les promoteurs','Promoteurs', 'Valider ou suspendre les dossiers d’éligibilité'],

        // Catégorie : Cotisations & Concours
        ['cotisations.manage','Gérer les cotisations',            'Cotisations & Votes', 'Créer et administrer les campagnes de financement participatif'],
        ['votes.manage',      'Gérer les votes & concours',       'Cotisations & Votes', 'Superviser les classements et votes'],

        // Catégorie : Tâches
        ['tasks.view_assigned','Voir mes tâches attribuées',      'Tâches', 'Consulter les tâches qui me sont personnellement assignées'],
        ['tasks.update_own',  'Mettre à jour mes tâches',         'Tâches', 'Changer le statut de ses propres tâches'],
        ['tasks.manage',      'Gérer toutes les tâches (Admin)',  'Tâches', 'Créer, attribuer, modifier et supprimer toutes les tâches'],

        // Catégorie : Journal & Audit
        ['activity.view',     'Consulter le journal d’activité',  'Journal & Audit', 'Voir l’historique des actions et audits du système'],
    ];

    $stmt_perm = $pdo->prepare("
        INSERT INTO permissions (code, nom, categorie, description)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (code) DO UPDATE SET
            nom = EXCLUDED.nom,
            categorie = EXCLUDED.categorie,
            description = EXCLUDED.description
    ");

    foreach ($permissions_catalogue as $p) {
        $stmt_perm->execute($p);
    }
    echo "  + " . count($permissions_catalogue) . " permissions synchronisées.\n";

    // Récupérer la table de mapping [code => id]
    $perm_map = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 8. Création des profils types
    echo "8. Enregistrement des profils types par défaut...\n";
    $default_profiles = [
        [
            'nom'         => 'Administrateur',
            'description' => 'Accès complet et supervision totale de la plateforme Tike WA.',
            'is_system'   => 1,
            'perms'       => array_keys($perm_map) // Toutes les permissions
        ],
        [
            'nom'         => "Gestionnaire d'événements",
            'description' => 'Gestion opérationnelle des événements, modération et configuration des salles.',
            'is_system'   => 1,
            'perms'       => [
                'events.view', 'events.create', 'events.edit', 'events.validate', 'venues.manage',
                'tickets.view', 'tickets.manage', 'promoters.view', 'cotisations.manage', 'votes.manage',
                'tasks.view_assigned', 'tasks.update_own'
            ]
        ],
        [
            'nom'         => 'Vérificateur',
            'description' => 'Contrôle des accès, vérification des billets et scan aux entrées.',
            'is_system'   => 1,
            'perms'       => [
                'tickets.view', 'tickets.scan', 'events.view', 'tasks.view_assigned', 'tasks.update_own'
            ]
        ],
        [
            'nom'         => 'Comptable',
            'description' => 'Suivi financier, ventes, paiements, commissions et validation des retraits.',
            'is_system'   => 1,
            'perms'       => [
                'orders.view', 'payments.view', 'commissions.view', 'withdrawals.manage', 'events.view',
                'tasks.view_assigned', 'tasks.update_own'
            ]
        ]
    ];

    $stmt_prof = $pdo->prepare("
        INSERT INTO profiles (nom, description, is_system)
        VALUES (?, ?, ?)
        ON CONFLICT (nom) DO UPDATE SET
            description = EXCLUDED.description,
            is_system = EXCLUDED.is_system
    ");

    $stmt_get_id = $pdo->prepare("SELECT id FROM profiles WHERE nom = ?");

    $stmt_link = $pdo->prepare("
        INSERT INTO profile_permissions (profile_id, permission_id)
        VALUES (?, ?)
        ON CONFLICT (profile_id, permission_id) DO NOTHING
    ");

    foreach ($default_profiles as $dp) {
        $stmt_prof->execute([$dp['nom'], $dp['description'], $dp['is_system']]);

        $stmt_get_id->execute([$dp['nom']]);
        $prof_id = $stmt_get_id->fetchColumn();

        foreach ($dp['perms'] as $p_code) {
            if (isset($perm_map[$p_code])) {
                $stmt_link->execute([$prof_id, $perm_map[$p_code]]);
            }
        }
    }
    echo "  + Profils de base créés et droits associés.\n";

    // 9. Association initiale des comptes admin au profil Administrateur
    $stmt_admin_id = $pdo->query("SELECT id FROM profiles WHERE nom = 'Administrateur'");
    $admin_profile_id = (int) $stmt_admin_id->fetchColumn();
    if ($admin_profile_id) {
        $stmt_assign = $pdo->prepare("UPDATE users SET profile_id = ? WHERE role = 'admin' AND profile_id IS NULL");
        $stmt_assign->execute([$admin_profile_id]);
        echo "  + Comptes administrateurs rattachés au profil 'Administrateur'.\n";
    }

    echo "✅ Migration réussie avec succès !\n";

} catch (Exception $e) {
    echo "❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}
