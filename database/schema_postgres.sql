-- =====================================================================
-- TIKÉLI PLATFORM — SCHEMA POSTGRESQL COMPLET
-- Généré le 2026-09-03 00:41:21
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

DROP TABLE IF EXISTS "users" CASCADE;
CREATE TABLE "users" (
    "id" SERIAL NOT NULL,
    "nom" VARCHAR(100) NOT NULL,
    "prenom" VARCHAR(100) NULL,
    "email" VARCHAR(150) NOT NULL,
    "telephone" VARCHAR(30) NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "role" VARCHAR(50) NOT NULL DEFAULT 'client',
    "est_verifie" BOOLEAN NOT NULL DEFAULT FALSE,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'actif',
    "profile_id" INTEGER NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "derniere_connexion" TIMESTAMP WITHOUT TIME ZONE NULL,
    "suspended_from" DATE NULL,
    "suspended_until" DATE NULL,
    "suspension_reason" TEXT NULL,
    CONSTRAINT "pk_users" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "profiles" CASCADE;
CREATE TABLE "profiles" (
    "id" SERIAL NOT NULL,
    "nom" VARCHAR(100) NOT NULL,
    "description" TEXT NULL,
    "is_system" BOOLEAN NOT NULL DEFAULT FALSE,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_profiles" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "permissions" CASCADE;
CREATE TABLE "permissions" (
    "id" SERIAL NOT NULL,
    "code" VARCHAR(60) NOT NULL,
    "nom" VARCHAR(120) NOT NULL,
    "categorie" VARCHAR(60) NOT NULL,
    "description" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_permissions" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "profile_permissions" CASCADE;
CREATE TABLE "profile_permissions" (
    "profile_id" INTEGER NOT NULL,
    "permission_id" INTEGER NOT NULL,
    CONSTRAINT "pk_profile_permissions" PRIMARY KEY ("profile_id", "permission_id")
);

DROP TABLE IF EXISTS "events" CASCADE;
CREATE TABLE "events" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NULL,
    "nom" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "image" VARCHAR(255) NULL DEFAULT 'default.jpg',
    "venue_image" VARCHAR(255) NULL DEFAULT 'default_venue.jpg',
    "categorie" VARCHAR(100) NOT NULL DEFAULT 'Concert',
    "date_evenement" DATE NOT NULL,
    "heure" TIME NOT NULL,
    "lieu" VARCHAR(255) NOT NULL,
    "prix_vote" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "type_vote" VARCHAR(50) NOT NULL DEFAULT 'concours',
    "vote_question" VARCHAR(255) NULL,
    "commission_rate" NUMERIC(5,2) NOT NULL DEFAULT 5.00,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'actif',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_events" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "ticket_types" CASCADE;
CREATE TABLE "ticket_types" (
    "id" SERIAL NOT NULL,
    "event_id" INTEGER NOT NULL,
    "nom" VARCHAR(100) NOT NULL,
    "description" TEXT NULL,
    "prix" NUMERIC(10,2) NOT NULL,
    "frais_place" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    "quantite" INTEGER NOT NULL,
    "quantite_vendue" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_ticket_types" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "orders" CASCADE;
CREATE TABLE "orders" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NULL,
    "client_nom" VARCHAR(150) NULL,
    "client_email" VARCHAR(150) NULL,
    "client_telephone" VARCHAR(30) NULL,
    "numero_commande" VARCHAR(50) NOT NULL,
    "montant_total" NUMERIC(12,2) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_orders" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "order_items" CASCADE;
CREATE TABLE "order_items" (
    "id" SERIAL NOT NULL,
    "order_id" INTEGER NOT NULL,
    "ticket_type_id" INTEGER NOT NULL,
    "quantite" INTEGER NOT NULL,
    "prix_unitaire" NUMERIC(10,2) NOT NULL,
    "sous_total" NUMERIC(10,2) NOT NULL,
    "frais_place" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    "places_numero" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_order_items" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "tickets" CASCADE;
CREATE TABLE "tickets" (
    "id" SERIAL NOT NULL,
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
    "statut" VARCHAR(50) NOT NULL DEFAULT 'vendu',
    "date_achat" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "date_utilisation" TIMESTAMP WITHOUT TIME ZONE NULL,
    "validated_by" INTEGER NULL,
    CONSTRAINT "pk_tickets" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "payments" CASCADE;
CREATE TABLE "payments" (
    "id" SERIAL NOT NULL,
    "order_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "methode" VARCHAR(50) NOT NULL,
    "reference" VARCHAR(100) NULL,
    "transaction_id_api" VARCHAR(255) NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "raw_response" TEXT NULL,
    "date_paiement" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_payments" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "agent_assignments" CASCADE;
CREATE TABLE "agent_assignments" (
    "id" SERIAL NOT NULL,
    "agent_id" INTEGER NOT NULL,
    "promoter_user_id" INTEGER NOT NULL,
    "event_id" INTEGER NOT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_agent_assignments" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "activity_logs" CASCADE;
CREATE TABLE "activity_logs" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NULL,
    "action" VARCHAR(100) NOT NULL,
    "target_type" VARCHAR(60) NULL,
    "target_id" INTEGER NULL,
    "details" TEXT NULL,
    "ip_address" VARCHAR(45) NULL,
    "user_agent" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_activity_logs" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "claims" CASCADE;
CREATE TABLE "claims" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "order_id" INTEGER NULL,
    "ticket_id" INTEGER NULL,
    "sujet" VARCHAR(200) NOT NULL,
    "message" TEXT NOT NULL,
    "reponse_admin" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_claims" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "cotisation_campagnes" CASCADE;
CREATE TABLE "cotisation_campagnes" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NULL,
    "titre" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "image" VARCHAR(255) NULL,
    "montant_objectif" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "date_limite" DATE NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "commentaire_admin" TEXT NULL,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_cotisation_campagnes" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "cotisations" CASCADE;
CREATE TABLE "cotisations" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NULL,
    "campagne_id" INTEGER NULL,
    "nom" VARCHAR(150) NOT NULL,
    "email" VARCHAR(150) NULL,
    "telephone" VARCHAR(30) NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "methode" VARCHAR(30) NULL,
    "reference" VARCHAR(50) NULL,
    "transaction_id_api" VARCHAR(60) NULL,
    "date_paiement" TIMESTAMP WITHOUT TIME ZONE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_cotisations" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "event_candidats" CASCADE;
CREATE TABLE "event_candidats" (
    "id" SERIAL NOT NULL,
    "event_id" INTEGER NOT NULL,
    "nom" VARCHAR(150) NOT NULL,
    "description" TEXT NULL,
    "photo" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_event_candidats" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "event_likes" CASCADE;
CREATE TABLE "event_likes" (
    "id" SERIAL NOT NULL,
    "event_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "visitor_id" VARCHAR(128) NOT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_event_likes" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "event_requests" CASCADE;
CREATE TABLE "event_requests" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "nom" VARCHAR(200) NOT NULL,
    "description" TEXT NOT NULL,
    "image" VARCHAR(255) NULL DEFAULT 'default.jpg',
    "categorie" VARCHAR(100) NOT NULL DEFAULT 'Concert',
    "date_evenement" DATE NOT NULL,
    "heure" TIME NOT NULL,
    "lieu" VARCHAR(255) NOT NULL,
    "prix_vote" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "type_vote" VARCHAR(50) NOT NULL DEFAULT 'concours',
    "vote_question" VARCHAR(255) NULL,
    "infos_supplementaires" TEXT NULL,
    "ticket_types_data" TEXT NULL,
    "candidats_data" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    "type_personne" VARCHAR(50) NOT NULL DEFAULT 'physique',
    "nom_structure" VARCHAR(200) NULL,
    "numero_rccm" VARCHAR(100) NULL,
    "document_justificatif" VARCHAR(255) NULL,
    "document_autorisation" VARCHAR(255) NULL,
    CONSTRAINT "pk_event_requests" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "event_votes" CASCADE;
CREATE TABLE "event_votes" (
    "id" SERIAL NOT NULL,
    "event_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "visitor_id" VARCHAR(128) NOT NULL,
    "candidat_id" INTEGER NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_event_votes" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "information_requests" CASCADE;
CREATE TABLE "information_requests" (
    "id" SERIAL NOT NULL,
    "promoter_id" INTEGER NOT NULL,
    "user_id" INTEGER NULL,
    "nom_demandeur" VARCHAR(100) NOT NULL,
    "email_demandeur" VARCHAR(150) NOT NULL,
    "telephone_demandeur" VARCHAR(30) NULL,
    "message" TEXT NOT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_information_requests" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "places" CASCADE;
CREATE TABLE "places" (
    "id" SERIAL NOT NULL,
    "ticket_type_id" INTEGER NOT NULL,
    "numero" VARCHAR(20) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'libre',
    "pos_x" NUMERIC(5,2) NULL DEFAULT 0.00,
    "pos_y" NUMERIC(5,2) NULL DEFAULT 0.00,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_places" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "promoter_requests" CASCADE;
CREATE TABLE "promoter_requests" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "type_entite" VARCHAR(50) NOT NULL DEFAULT 'physique',
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
    "piece_identite" VARCHAR(255) NOT NULL DEFAULT 'default.jpg',
    "piece_entreprise" VARCHAR(255) NULL,
    "description" TEXT NOT NULL,
    "reseaux_sociaux" VARCHAR(255) NULL,
    "autres_infos" TEXT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    CONSTRAINT "pk_promoter_requests" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "promoter_transactions" CASCADE;
CREATE TABLE "promoter_transactions" (
    "id" SERIAL NOT NULL,
    "promoter_id" INTEGER NOT NULL,
    "order_id" INTEGER NULL,
    "withdrawal_id" INTEGER NULL,
    "amount" NUMERIC(12,2) NOT NULL,
    "type" VARCHAR(50) NOT NULL,
    "description" VARCHAR(255) NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_promoter_transactions" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "promoters" CASCADE;
CREATE TABLE "promoters" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "type_entite" VARCHAR(50) NOT NULL DEFAULT 'physique',
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
    "statut" VARCHAR(50) NOT NULL DEFAULT 'approuve',
    "solde" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    "commission_rate" NUMERIC(5,2) NOT NULL DEFAULT 5.00,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_promoters" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "tasks" CASCADE;
CREATE TABLE "tasks" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "created_by" INTEGER NOT NULL,
    "titre" VARCHAR(200) NOT NULL,
    "description" TEXT NULL,
    "priorite" VARCHAR(50) NOT NULL DEFAULT 'normale',
    "statut" VARCHAR(50) NOT NULL DEFAULT 'a_faire',
    "date_debut" DATE NULL,
    "date_limite" DATE NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_tasks" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "vote_paiements" CASCADE;
CREATE TABLE "vote_paiements" (
    "id" SERIAL NOT NULL,
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
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pk_vote_paiements" PRIMARY KEY ("id")
);

DROP TABLE IF EXISTS "withdrawals" CASCADE;
CREATE TABLE "withdrawals" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "promoter_id" INTEGER NULL,
    "montant" NUMERIC(12,2) NOT NULL,
    "methode" VARCHAR(50) NOT NULL,
    "numero_telephone" VARCHAR(30) NOT NULL,
    "statut" VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    "commentaire_admin" TEXT NULL,
    "created_at" TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    CONSTRAINT "pk_withdrawals" PRIMARY KEY ("id")
);

-- ==============================================================================
-- INDEX DE PERFORMANCE (Optimisation des requêtes & élimination des Seq Scans)
-- ==============================================================================
CREATE INDEX IF NOT EXISTS idx_places_ticket_type_id ON places(ticket_type_id);
CREATE INDEX IF NOT EXISTS idx_places_statut ON places(statut);
CREATE INDEX IF NOT EXISTS idx_places_type_statut ON places(ticket_type_id, statut);
CREATE INDEX IF NOT EXISTS idx_tickets_user_id ON tickets(user_id);
CREATE INDEX IF NOT EXISTS idx_tickets_order_id ON tickets(order_id);
CREATE INDEX IF NOT EXISTS idx_tickets_event_id ON tickets(event_id);
CREATE INDEX IF NOT EXISTS idx_tickets_ticket_type_id ON tickets(ticket_type_id);
CREATE INDEX IF NOT EXISTS idx_tickets_code_unique ON tickets(code_unique);
CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id);
CREATE INDEX IF NOT EXISTS idx_orders_statut ON orders(statut);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_promoter_trans_promoter ON promoter_transactions(promoter_id);
CREATE INDEX IF NOT EXISTS idx_events_statut_date ON events(statut, date_evenement);
CREATE INDEX IF NOT EXISTS idx_events_user_id ON events(user_id);
CREATE INDEX IF NOT EXISTS idx_events_categorie ON events(categorie);
CREATE INDEX IF NOT EXISTS idx_ticket_types_event_id ON ticket_types(event_id);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_promoters_user_id ON promoters(user_id);
CREATE INDEX IF NOT EXISTS idx_event_votes_event_id ON event_votes(event_id);
CREATE INDEX IF NOT EXISTS idx_event_candidats_event ON event_candidats(event_id);
CREATE INDEX IF NOT EXISTS idx_cotisations_campagne ON cotisations(campagne_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at DESC);
