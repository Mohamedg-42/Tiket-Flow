<?php
$host = '127.0.0.1';
$port = '5432';
$user = 'postgres';
$pass = '123';
$dbname = 'ticket_platform';

echo "1. Connecting to PostgreSQL root...\n";
$pdo_root = new PDO("pgsql:host={$host};port={$port};dbname=postgres;connect_timeout=3", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "2. Re-creating database '$dbname'...\n";
$pdo_root->exec("DROP DATABASE IF EXISTS \"{$dbname}\";");
$pdo_root->exec("CREATE DATABASE \"{$dbname}\" ENCODING 'UTF8';");
echo "✓ Fresh database created!\n";

echo "3. Connecting to '$dbname'...\n";
$pdo_app = new PDO("pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=3", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo_app->exec('
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Table users
CREATE TABLE "users" (
    "id" SERIAL PRIMARY KEY,
    "nom" VARCHAR(100) NOT NULL,
    "prenom" VARCHAR(100) NULL,
    "email" VARCHAR(150) NOT NULL UNIQUE,
    "telephone" VARCHAR(30) NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "role" VARCHAR(50) NOT NULL DEFAULT \'client\',
    "est_verifie" SMALLINT NOT NULL DEFAULT 0,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'actif\',
    "profile_id" INTEGER NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "derniere_connexion" TIMESTAMP WITHOUT TIME ZONE NULL,
    "suspended_from" DATE NULL,
    "suspended_until" DATE NULL,
    "suspension_reason" TEXT NULL
);

-- Table profiles
CREATE TABLE "profiles" (
    "id" SERIAL PRIMARY KEY,
    "nom" VARCHAR(100) NOT NULL,
    "description" TEXT NULL,
    "is_system" SMALLINT NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table permissions
CREATE TABLE "permissions" (
    "id" SERIAL PRIMARY KEY,
    "code" VARCHAR(60) NOT NULL,
    "nom" VARCHAR(120) NOT NULL,
    "categorie" VARCHAR(60) NOT NULL,
    "description" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table profile_permissions
CREATE TABLE "profile_permissions" (
    "profile_id" INTEGER NOT NULL,
    "permission_id" INTEGER NOT NULL,
    PRIMARY KEY ("profile_id", "permission_id")
);

-- Table events
CREATE TABLE "events" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NULL,
    "nom" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "image" VARCHAR(255) NULL DEFAULT \'default.jpg\',
    "venue_image" VARCHAR(255) NULL DEFAULT \'default_venue.jpg\',
    "categorie" VARCHAR(100) NOT NULL DEFAULT \'Concert\',
    "date_evenement" DATE NOT NULL,
    "heure" TIME NOT NULL,
    "lieu" VARCHAR(255) NOT NULL,
    "salle_id" INTEGER NULL,
    "prix_vote" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "type_vote" VARCHAR(50) NOT NULL DEFAULT \'concours\',
    "vote_question" VARCHAR(255) NULL,
    "commission_rate" NUMERIC(5,2) NOT NULL DEFAULT 5.00,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'actif\',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table ticket_types
CREATE TABLE "ticket_types" (
    "id" SERIAL PRIMARY KEY,
    "event_id" INTEGER NOT NULL,
    "nom" VARCHAR(100) NOT NULL,
    "description" TEXT NULL,
    "prix" NUMERIC(10,2) NOT NULL,
    "frais_place" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    "quantite" INTEGER NOT NULL,
    "quantite_vendue" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table orders
CREATE TABLE "orders" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NULL,
    "client_nom" VARCHAR(150) NULL,
    "client_email" VARCHAR(150) NULL,
    "client_telephone" VARCHAR(30) NULL,
    "numero_commande" VARCHAR(50) NOT NULL,
    "montant_total" NUMERIC(12,2) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table order_items
CREATE TABLE "order_items" (
    "id" SERIAL PRIMARY KEY,
    "order_id" INTEGER NOT NULL,
    "ticket_type_id" INTEGER NOT NULL,
    "quantite" INTEGER NOT NULL,
    "prix_unitaire" NUMERIC(10,2) NOT NULL,
    "sous_total" NUMERIC(10,2) NOT NULL,
    "frais_place" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    "places_numero" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table tickets
CREATE TABLE "tickets" (
    "id" SERIAL PRIMARY KEY,
    "order_id" INTEGER NULL,
    "ticket_type_id" INTEGER NULL,
    "event_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "client_nom" VARCHAR(150) NULL,
    "client_email" VARCHAR(150) NULL,
    "client_telephone" VARCHAR(30) NULL,
    "type_ticket" VARCHAR(100) NOT NULL,
    "place_numero" VARCHAR(20) NULL,
    "prix" NUMERIC(10,2) NOT NULL,
    "code_unique" VARCHAR(30) NOT NULL,
    "qr_code" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'vendu\',
    "date_achat" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "date_utilisation" TIMESTAMP WITHOUT TIME ZONE NULL,
    "validated_by" INTEGER NULL
);

-- Table payments
CREATE TABLE "payments" (
    "id" SERIAL PRIMARY KEY,
    "order_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "methode" VARCHAR(50) NOT NULL,
    "reference" VARCHAR(100) NULL,
    "transaction_id_api" VARCHAR(255) NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "raw_response" TEXT NULL,
    "date_paiement" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table agent_assignments
CREATE TABLE "agent_assignments" (
    "id" SERIAL PRIMARY KEY,
    "agent_id" INTEGER NOT NULL,
    "promoter_user_id" INTEGER NOT NULL,
    "event_id" INTEGER NOT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table activity_logs
CREATE TABLE "activity_logs" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NULL,
    "action" VARCHAR(100) NOT NULL,
    "target_type" VARCHAR(60) NULL,
    "target_id" INTEGER NULL,
    "details" TEXT NULL,
    "ip_address" VARCHAR(45) NULL,
    "user_agent" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table claims
CREATE TABLE "claims" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "order_id" INTEGER NULL,
    "ticket_id" INTEGER NULL,
    "sujet" VARCHAR(200) NOT NULL,
    "message" TEXT NOT NULL,
    "reponse_admin" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table cotisation_campagnes
CREATE TABLE "cotisation_campagnes" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NULL,
    "titre" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "image" VARCHAR(255) NULL,
    "montant_objectif" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "date_limite" DATE NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "commentaire_admin" TEXT NULL,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table cotisations
CREATE TABLE "cotisations" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NULL,
    "campagne_id" INTEGER NULL,
    "nom" VARCHAR(150) NOT NULL,
    "email" VARCHAR(150) NULL,
    "telephone" VARCHAR(30) NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "methode" VARCHAR(30) NULL,
    "reference" VARCHAR(50) NULL,
    "transaction_id_api" VARCHAR(60) NULL,
    "date_paiement" TIMESTAMP WITHOUT TIME ZONE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table event_candidats
CREATE TABLE "event_candidats" (
    "id" SERIAL PRIMARY KEY,
    "event_id" INTEGER NOT NULL,
    "nom" VARCHAR(150) NOT NULL,
    "description" TEXT NULL,
    "photo" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table event_likes
CREATE TABLE "event_likes" (
    "id" SERIAL PRIMARY KEY,
    "event_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "visitor_id" VARCHAR(128) NOT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table event_requests
CREATE TABLE "event_requests" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "nom" VARCHAR(200) NOT NULL,
    "description" TEXT NOT NULL,
    "image" VARCHAR(255) NULL DEFAULT \'default.jpg\',
    "categorie" VARCHAR(100) NOT NULL DEFAULT \'Concert\',
    "date_evenement" DATE NOT NULL,
    "heure" TIME NOT NULL,
    "lieu" VARCHAR(255) NOT NULL,
    "prix_vote" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "type_vote" VARCHAR(50) NOT NULL DEFAULT \'concours\',
    "vote_question" VARCHAR(255) NULL,
    "infos_supplementaires" TEXT NULL,
    "ticket_types_data" TEXT NULL,
    "candidats_data" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    "type_personne" VARCHAR(50) NOT NULL DEFAULT \'physique\',
    "nom_structure" VARCHAR(200) NULL,
    "numero_rccm" VARCHAR(100) NULL,
    "document_justificatif" VARCHAR(255) NULL,
    "document_autorisation" VARCHAR(255) NULL
);

-- Table event_votes
CREATE TABLE "event_votes" (
    "id" SERIAL PRIMARY KEY,
    "event_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "visitor_id" VARCHAR(128) NOT NULL,
    "candidat_id" INTEGER NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table places
CREATE TABLE "places" (
    "id" SERIAL PRIMARY KEY,
    "ticket_type_id" INTEGER NOT NULL,
    "numero" VARCHAR(20) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'libre\',
    "pos_x" NUMERIC(5,2) NULL DEFAULT 0.00,
    "pos_y" NUMERIC(5,2) NULL DEFAULT 0.00,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table promoter_requests
CREATE TABLE "promoter_requests" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "type_entite" VARCHAR(50) NOT NULL DEFAULT \'physique\',
    "raison_sociale" VARCHAR(180) NULL,
    "numero_registre" VARCHAR(100) NULL,
    "representant_legal" VARCHAR(150) NULL,
    "nom_complet" VARCHAR(150) NOT NULL,
    "telephone" VARCHAR(30) NOT NULL,
    "ville" VARCHAR(100) NULL,
    "email" VARCHAR(150) NOT NULL,
    "activite" VARCHAR(255) NOT NULL,
    "experience" TEXT NOT NULL,
    "volume_estime" VARCHAR(100) NULL,
    "piece_identite" VARCHAR(255) NOT NULL DEFAULT \'default.jpg\',
    "piece_entreprise" VARCHAR(255) NULL,
    "description" TEXT NOT NULL,
    "reseaux_sociaux" VARCHAR(255) NULL,
    "autres_infos" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL
);

-- Table promoter_transactions
CREATE TABLE "promoter_transactions" (
    "id" SERIAL PRIMARY KEY,
    "promoter_id" INTEGER NOT NULL,
    "order_id" INTEGER NULL,
    "withdrawal_id" INTEGER NULL,
    "amount" NUMERIC(12,2) NOT NULL,
    "type" VARCHAR(50) NOT NULL,
    "description" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table promoters
CREATE TABLE "promoters" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "type_entite" VARCHAR(50) NOT NULL DEFAULT \'physique\',
    "nom_commercial" VARCHAR(150) NOT NULL,
    "numero_registre" VARCHAR(100) NULL,
    "representant_legal" VARCHAR(150) NULL,
    "description" TEXT NULL,
    "telephone_contact" VARCHAR(30) NULL,
    "email_contact" VARCHAR(150) NULL,
    "adresse" VARCHAR(255) NULL,
    "ville" VARCHAR(100) NULL,
    "site_web" VARCHAR(255) NULL,
    "reseaux_sociaux" VARCHAR(255) NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'approuve\',
    "solde" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table tasks
CREATE TABLE "tasks" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "created_by" INTEGER NOT NULL,
    "titre" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "priorite" VARCHAR(50) NOT NULL DEFAULT \'normale\',
    "statut" VARCHAR(50) NOT NULL DEFAULT \'a_faire\',
    "date_debut" DATE NULL,
    "date_limite" DATE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table vote_paiements
CREATE TABLE "vote_paiements" (
    "id" SERIAL PRIMARY KEY,
    "event_id" INTEGER NOT NULL,
    "candidat_id" INTEGER NULL,
    "candidats_ids" TEXT NULL,
    "user_id" INTEGER NULL,
    "visitor_id" VARCHAR(64) NULL,
    "telephone" VARCHAR(30) NULL,
    "montant" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "methode" VARCHAR(30) NULL,
    "reference" VARCHAR(50) NULL,
    "transaction_id_api" VARCHAR(60) NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table withdrawals
CREATE TABLE "withdrawals" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "promoter_id" INTEGER NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "methode" VARCHAR(50) NOT NULL,
    "numero_telephone" VARCHAR(30) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'en_attente\',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL
);

-- Table salles
CREATE TABLE "salles" (
    "id" SERIAL PRIMARY KEY,
    "nom" VARCHAR(255) NOT NULL,
    "ville" VARCHAR(100) NOT NULL DEFAULT \'Abidjan\',
    "commune" VARCHAR(100) NULL,
    "adresse" VARCHAR(255) NULL,
    "capacite" INTEGER NOT NULL DEFAULT 0,
    "type_salle" VARCHAR(50) NOT NULL DEFAULT \'salle_spectacle\',
    "configuration" VARCHAR(50) NOT NULL DEFAULT \'placement_libre\',
    "modele_3d" VARCHAR(50) NOT NULL DEFAULT \'theatre_italien\',
    "type_rendu_3d" VARCHAR(50) DEFAULT \'generateur_3d\',
    "image_principale" VARCHAR(255) NULL,
    "galerie_photos" TEXT NULL,
    "plan_image" VARCHAR(255) NULL,
    "fichier_3d" VARCHAR(255) NULL,
    "config_3d_json" TEXT NULL,
    "description" TEXT NULL,
    "contact_responsable" VARCHAR(150) NULL,
    "telephone_responsable" VARCHAR(50) NULL,
    "prix_location_indicatif" NUMERIC(12,2) NULL DEFAULT 0.00,
    "equipements" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT \'active\',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table salle_zones
CREATE TABLE "salle_zones" (
    "id" SERIAL PRIMARY KEY,
    "salle_id" INTEGER NOT NULL,
    "nom_zone" VARCHAR(100) NOT NULL,
    "capacite" INTEGER NOT NULL DEFAULT 0,
    "couleur" VARCHAR(30) DEFAULT \'#0d9488\',
    "elevation_3d" INTEGER DEFAULT 0,
    "position_3d" VARCHAR(50) DEFAULT \'centre\',
    "tarif_indicatif" NUMERIC(10,2) DEFAULT 0.00,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_salle_zones_salle FOREIGN KEY (salle_id) REFERENCES salles(id) ON DELETE CASCADE
);

-- Table password_resets
CREATE TABLE "password_resets" (
    "id" SERIAL PRIMARY KEY,
    "email" VARCHAR(150) NOT NULL,
    "token" VARCHAR(255) NOT NULL UNIQUE,
    "created_at" TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    "expires_at" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    "used" SMALLINT NOT NULL DEFAULT 0
);
');
echo "✓ All PostgreSQL tables created successfully!\n";

echo "4. Inserting initial users and seed data...\n";
// Passwords hashed: password is '123456' for demo accounts
$pwd_admin = password_hash('admin123', PASSWORD_DEFAULT);
$pwd_demo = password_hash('123456', PASSWORD_DEFAULT);

$stmt_user = $pdo_app->prepare("
INSERT INTO users (id, nom, prenom, email, telephone, password, role, est_verifie, statut, profile_id) VALUES
(1, 'Super Administrateur', 'Eventia', 'admin@ticketflow.com', '+2250700000001', ?, 'admin', 1, 'actif', 1),
(2, 'Promoteur Ivoire Events', 'Jean', 'promoteur@ticketflow.com', '+2250700000002', ?, 'promoteur', 1, 'actif', NULL),
(3, 'Agent Contrôleur', 'Koffi', 'agent@ticketflow.com', '+2250700000003', ?, 'agent', 1, 'actif', NULL),
(4, 'Client Démo', 'Aya', 'client@ticketflow.com', '+2250700000004', ?, 'client', 1, 'actif', NULL),
(5, 'Administrateur Principal', 'Admin', 'admin@eventia.ci', '+2250700000000', ?, 'admin', 1, 'actif', 1)
ON CONFLICT (id) DO NOTHING;
");
$stmt_user->execute([$pwd_admin, $pwd_demo, $pwd_demo, $pwd_demo, $pwd_admin]);

// Insert promoters
$pdo_app->exec("
INSERT INTO promoters (id, user_id, type_entite, nom_commercial, description, telephone_contact, email_contact, ville, statut, solde) VALUES
(1, 2, 'entreprise', 'Ivoire Events Spectacles', 'Leader événementiel en Côte d''Ivoire', '+2250700000002', 'contact@ivoire-events.ci', 'Abidjan', 'approuve', 750000.00)
ON CONFLICT (id) DO NOTHING;
");

// Insert sample salles
$pdo_app->exec("
INSERT INTO salles (id, nom, ville, commune, adresse, capacite, type_salle, configuration, modele_3d, type_rendu_3d, description, statut) VALUES
(1, 'Palais de la Culture - Salle Anoumabo', 'Abidjan', 'Treichville', 'Boulevard de Marseille', 4000, 'auditorium', 'mixte', 'theatre_italien', 'generateur_3d', 'La plus grande salle de spectacle couverte de Côte d''Ivoire.', 'active'),
(2, 'Stade Félix Houphouët-Boigny', 'Abidjan', 'Plateau', 'Boulevard de la République', 35000, 'stade', 'places_numerotees', 'stade', 'generateur_3d', 'Grand stade historique d''Abidjan.', 'active'),
(3, 'Dôme Arena de Cocody', 'Abidjan', 'Cocody', 'Riviera Golf', 8000, 'salle_spectacle', 'places_numerotees', 'arena', 'generateur_3d', 'Arena moderne pour concerts et tournois.', 'active'),
(4, 'Esplanade Lagunaire Sofitel', 'Abidjan', 'Cocody', 'Boulevard Hassan II', 2500, 'plein_air', 'placement_libre', 'plein_air', 'generateur_3d', 'Espace plein air en bordure de lagune.', 'active')
ON CONFLICT (id) DO NOTHING;
");

// Insert sample salle_zones
$pdo_app->exec("
INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur, elevation_3d, position_3d, tarif_indicatif) VALUES
(1, 'Fosse & Parterre', 2500, '#0d9488', 0, 'centre', 5000.00),
(1, 'Balcon VIP', 500, '#38bdf8', 12, 'centre', 25000.00),
(1, 'Loges Officielles', 200, '#eab308', 16, 'gauche', 50000.00),
(1, 'Gradins Latéraux', 800, '#6366f1', 8, 'droite', 10000.00)
ON CONFLICT DO NOTHING;
");

// Insert sample events
$pdo_app->exec("
INSERT INTO events (id, user_id, nom, description, categorie, date_evenement, heure, lieu, salle_id, prix_vote, statut) VALUES
(1, 2, 'Festival des Musiques Urbaines d''Anoumabo (FEMUA)', 'Le grand festival international de musique à Abidjan.', 'Festival', '2026-11-20', '19:00:00', 'Palais de la Culture - Salle Anoumabo', 1, 500.00, 'actif'),
(2, 2, 'Grand Concert Live Magic System', 'Célébration des 25 ans de carrière de Magic System en live.', 'Concert', '2026-12-15', '20:30:00', 'Palais de la Culture - Salle Anoumabo', 1, 0.00, 'actif')
ON CONFLICT (id) DO NOTHING;
");

// Insert ticket_types
$pdo_app->exec("
INSERT INTO ticket_types (id, event_id, nom, description, prix, frais_place, quantite, quantite_vendue) VALUES
(1, 1, 'STANDARD (Fosse)', 'Accès parterre et fosse générale', 5000.00, 0.00, 3000, 45),
(2, 1, 'VIP (Balcon Assis)', 'Place assise réservée avec vue panoramique', 25000.00, 2000.00, 500, 12),
(3, 2, 'PASS STANDARD', 'Accès concert live', 10000.00, 0.00, 2000, 80),
(4, 2, 'PASS VIP & COCKTAIL', 'Accès loge + cocktail dinatoire', 50000.00, 5000.00, 200, 30)
ON CONFLICT (id) DO NOTHING;
");

// Reset sequences
$tables = ['users', 'profiles', 'permissions', 'events', 'ticket_types', 'orders', 'order_items', 'tickets', 'payments', 'salles', 'salle_zones', 'password_resets', 'promoters'];
foreach ($tables as $tbl) {
    $pdo_app->exec("SELECT setval(pg_get_serial_sequence('\"$tbl\"', 'id'), COALESCE((SELECT MAX(id) FROM \"$tbl\"), 1), true);");
}

echo "5. Updating config/database.php...\n";
$config_content = "<?php
// ==============================================================================
// FICHIER DE CONNEXION POSTGRESQL (config/database.php)
// Plateforme Eventia — Connecté à PostgreSQL
// ==============================================================================

\$host    = '{$host}';
\$port    = '{$port}';
\$db      = '{$dbname}';
\$user    = '{$user}';
\$pass    = '{$pass}';

\$dsn = \"pgsql:host=\$host;port=\$port;dbname=\$db;\";

\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    \$pdo = new PDO(\$dsn, \$user, \$pass, \$options);

    // Mise à jour automatique du statut des événements terminés
    \$pdo->exec(\"
        UPDATE events 
        SET statut = 'termine' 
        WHERE statut = 'actif' 
          AND (
              date_evenement < CURRENT_DATE 
              OR (date_evenement = CURRENT_DATE AND heure <= CURRENT_TIME)
          )
    \");

} catch (\\PDOException \$e) {
    die(\"❌ Erreur de connexion à PostgreSQL : \" . \$e->getMessage());
}
";

file_put_contents(__DIR__ . '/../config/database.php', $config_content);
echo "✓ config/database.php configured for PostgreSQL!\n";
echo "SUCCESS_POSTGRES_READY\n";
