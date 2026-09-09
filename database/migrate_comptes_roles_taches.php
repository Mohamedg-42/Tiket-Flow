<?php
// ==============================================================================
// SCRIPT DE MIGRATION — COMPTES, PROFILS, PERMISSIONS, TÂCHES & ACTIVITÉ
// Exécutable en CLI ou via include
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "🚀 Démarrage de la migration de la base de données...\n";

try {

    // 1. Évolution de la table `users`
    echo "1. Vérification et ajout des colonnes sur `users`...\n";
    
    // Vérifier les colonnes existantes
    $userCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('prenom', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `prenom` VARCHAR(100) NULL AFTER `nom`");
        echo "  + Colonne `prenom` ajoutée.\n";
    }

    if (!in_array('statut', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `statut` ENUM('actif', 'inactif', 'suspendu_temp', 'suspendu_def') NOT NULL DEFAULT 'actif' AFTER `est_verifie`");
        echo "  + Colonne `statut` ajoutée.\n";
    }

    if (!in_array('profile_id', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `profile_id` INT NULL AFTER `statut`");
        echo "  + Colonne `profile_id` ajoutée.\n";
    }

    if (!in_array('derniere_connexion', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `derniere_connexion` DATETIME NULL AFTER `updated_at`");
        echo "  + Colonne `derniere_connexion` ajoutée.\n";
    }

    if (!in_array('suspended_from', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `suspended_from` DATE NULL AFTER `derniere_connexion`");
        echo "  + Colonne `suspended_from` ajoutée.\n";
    }

    if (!in_array('suspended_until', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `suspended_until` DATE NULL AFTER `suspended_from`");
        echo "  + Colonne `suspended_until` ajoutée.\n";
    }

    if (!in_array('suspension_reason', $userCols, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `suspension_reason` TEXT NULL AFTER `suspended_until`");
        echo "  + Colonne `suspension_reason` ajoutée.\n";
    }

    // 2. Création de la table `permissions`
    echo "2. Création de la table `permissions`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `permissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(60) NOT NULL UNIQUE,
            `nom` VARCHAR(120) NOT NULL,
            `categorie` VARCHAR(60) NOT NULL,
            `description` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Création de la table `profiles`
    echo "3. Création de la table `profiles`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `profiles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nom` VARCHAR(100) NOT NULL UNIQUE,
            `description` TEXT NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Création de la table `profile_permissions`
    echo "4. Création de la table `profile_permissions`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `profile_permissions` (
            `profile_id` INT NOT NULL,
            `permission_id` INT NOT NULL,
            PRIMARY KEY (`profile_id`, `permission_id`),
            INDEX (`profile_id`),
            INDEX (`permission_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Création de la table `tasks`
    echo "5. Création de la table `tasks`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tasks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `created_by` INT NOT NULL,
            `titre` VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `priorite` ENUM('faible', 'normale', 'haute', 'urgente') NOT NULL DEFAULT 'normale',
            `statut` ENUM('a_faire', 'en_cours', 'termine', 'annule') NOT NULL DEFAULT 'a_faire',
            `date_debut` DATE NULL,
            `date_limite` DATE NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`user_id`),
            INDEX (`created_by`),
            INDEX (`statut`),
            INDEX (`priorite`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 6. Création de la table `activity_logs`
    echo "6. Création de la table `activity_logs`...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `action` VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(60) NULL,
            `target_id` INT NULL,
            `details` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`user_id`),
            INDEX (`action`),
            INDEX (`target_type`),
            INDEX (`target_id`),
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 7. Remplissage des permissions standard
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
        INSERT INTO `permissions` (`code`, `nom`, `categorie`, `description`) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            `nom` = VALUES(`nom`), 
            `categorie` = VALUES(`categorie`), 
            `description` = VALUES(`description`)
    ");

    foreach ($permissions_catalogue as $p) {
        $stmt_perm->execute($p);
    }
    echo "  + " . count($permissions_catalogue) . " permissions synchronisées.\n";

    // Récupérer la table de mapping [code => id]
    $perm_map = $pdo->query("SELECT `code`, `id` FROM `permissions`")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 8. Création des profils types
    echo "8. Enregistrement des profils types par défaut...\n";
    $default_profiles = [
        [
            'nom'         => 'Administrateur',
            'description' => 'Accès complet et supervision totale de la plateforme Tikéli.',
            'is_system'   => 1,
            'perms'       => array_keys($perm_map) // Toutes les permissions
        ],
        [
            'nom'         => 'Gestionnaire d\'événements',
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
        INSERT INTO `profiles` (`nom`, `description`, `is_system`) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `is_system` = VALUES(`is_system`)
    ");

    $stmt_link = $pdo->prepare("
        INSERT IGNORE INTO `profile_permissions` (`profile_id`, `permission_id`) 
        VALUES (?, ?)
    ");

    foreach ($default_profiles as $dp) {
        $stmt_prof->execute([$dp['nom'], $dp['description'], $dp['is_system']]);
        
        // ID du profil
        $prof_id = $pdo->query("SELECT `id` FROM `profiles` WHERE `nom` = " . $pdo->quote($dp['nom']))->fetchColumn();
        
        foreach ($dp['perms'] as $p_code) {
            if (isset($perm_map[$p_code])) {
                $stmt_link->execute([$prof_id, $perm_map[$p_code]]);
            }
        }
    }
    echo "  + Profils de base créés et droits associés.\n";

    // 9. Association initiale des comptes admin au profil Administrateur
    $admin_profile_id = (int)$pdo->query("SELECT `id` FROM `profiles` WHERE `nom` = 'Administrateur'")->fetchColumn();
    if ($admin_profile_id) {
        $pdo->exec("UPDATE `users` SET `profile_id` = $admin_profile_id WHERE `role` = 'admin' AND `profile_id` IS NULL");
        echo "  + Comptes administrateurs rattachés au profil 'Administrateur'.\n";
    }

    echo "✅ Migration réussie avec succès !\n";

} catch (Exception $e) {
    echo "❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}
