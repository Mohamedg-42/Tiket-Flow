-- ==============================================================================
-- BASE DE DONNÉES : ticket_platform
-- Plateforme simple de vente, gestion et vérification de tickets
-- Conçu pour PHP natif / PDO (Niveau Développeur Junior)
-- ==============================================================================

-- 1. Création de la base de données avec encodage UTF-8 complet (pour les accents et emojis)
CREATE DATABASE IF NOT EXISTS `ticket_platform` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `ticket_platform`;

-- On désactive temporairement les vérifications de clés étrangères pour reconstruire proprement
SET FOREIGN_KEY_CHECKS = 0;

-- Suppression des anciennes tables si elles existent pour repartir sur une base saine
DROP TABLE IF EXISTS `claims`;
DROP TABLE IF EXISTS `information_requests`;
DROP TABLE IF EXISTS `withdrawals`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `ticket_types`;
DROP TABLE IF EXISTS `event_requests`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `promoter_requests`;
DROP TABLE IF EXISTS `promoters`;
DROP TABLE IF EXISTS `promoteur_identite`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- TABLE 1 : users (Utilisateurs de la plateforme)
-- Rôles disponibles : admin, promoteur, agent, client
-- ==============================================================================
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nom` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `telephone` VARCHAR(30) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('client', 'promoteur', 'agent', 'admin') NOT NULL DEFAULT 'client',
    `est_verifie` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 2 : promoters (Profil public et portefeuille financier des promoteurs)
-- Liée à la table users par user_id
-- ==============================================================================
CREATE TABLE `promoters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `nom_commercial` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `telephone_contact` VARCHAR(30) NULL,
    `email_contact` VARCHAR(150) NULL,
    `adresse` VARCHAR(255) NULL,
    `site_web` VARCHAR(255) NULL,
    `reseaux_sociaux` VARCHAR(255) NULL,
    `statut` ENUM('en_attente', 'approuve', 'refuse', 'suspendu') NOT NULL DEFAULT 'approuve',
    `solde` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_promoter_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 3 : promoter_requests (Demandes d'éligibilité pour devenir promoteur)
-- ==============================================================================
CREATE TABLE `promoter_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `nom_complet` VARCHAR(150) NOT NULL,
    `telephone` VARCHAR(30) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `activite` VARCHAR(255) NOT NULL,
    `experience` TEXT NOT NULL,
    `piece_identite` VARCHAR(255) NOT NULL DEFAULT 'default.jpg',
    `description` TEXT NOT NULL,
    `reseaux_sociaux` VARCHAR(255) NULL,
    `autres_infos` TEXT NULL,
    `statut` ENUM('en_attente', 'approuve', 'refuse', 'suspendu') NOT NULL DEFAULT 'en_attente',
    `commentaire_admin` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL,
    CONSTRAINT `fk_promoter_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 4 : events (Événements approuvés et visibles sur la plateforme)
-- Contient le taux de commission défini par l'administrateur
-- ==============================================================================
CREATE TABLE `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL, -- Promoteur organisateur (ou NULL si créé par l'admin)
    `nom` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `image` VARCHAR(255) DEFAULT 'default.jpg',
    `categorie` VARCHAR(100) NOT NULL DEFAULT 'Concert',
    `date_evenement` DATE NOT NULL,
    `heure` TIME NOT NULL,
    `lieu` VARCHAR(255) NOT NULL,
    `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 5.00, -- 5.00 = 5%
    `statut` ENUM('en_attente', 'approuve', 'refuse', 'actif', 'termine', 'annule') NOT NULL DEFAULT 'actif',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_events_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 5 : event_requests (Demandes de création d'événements par les promoteurs)
-- ==============================================================================
CREATE TABLE `event_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL, -- Le promoteur qui propose l'événement
    `nom` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `image` VARCHAR(255) DEFAULT 'default.jpg',
    `categorie` VARCHAR(100) NOT NULL DEFAULT 'Concert',
    `date_evenement` DATE NOT NULL,
    `heure` TIME NOT NULL,
    `lieu` VARCHAR(255) NOT NULL,
    `infos_supplementaires` TEXT NULL,
    `type_personne` ENUM('physique', 'morale') NOT NULL DEFAULT 'physique',
    `nom_structure` VARCHAR(200) NULL,
    `numero_rccm` VARCHAR(100) NULL,
    `document_justificatif` VARCHAR(255) NULL,
    `document_autorisation` VARCHAR(255) NULL,
    `ticket_types_data` TEXT NULL, -- Types de tickets envisagés (format texte ou JSON)
    `statut` ENUM('en_attente', 'approuve', 'refuse') NOT NULL DEFAULT 'en_attente',
    `commentaire_admin` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL,
    CONSTRAINT `fk_event_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 6 : ticket_types (Types de billets et tarifs pour chaque événement)
-- ==============================================================================
CREATE TABLE `ticket_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `nom` VARCHAR(100) NOT NULL, -- Ex: VIP, STANDARD, PASS 2 JOURS
    `description` TEXT NULL,
    `prix` DECIMAL(10, 2) NOT NULL,
    `quantite` INT NOT NULL, -- Quantité totale disponible
    `quantite_vendue` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ticket_types_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 7 : orders (Commandes passées par les clients)
-- ==============================================================================
CREATE TABLE `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL, -- Le client acheteur (ou NULL si achat invité sans compte)
    `client_nom` VARCHAR(150) NULL,
    `client_email` VARCHAR(150) NULL,
    `client_telephone` VARCHAR(30) NULL,
    `numero_commande` VARCHAR(50) NOT NULL UNIQUE,
    `montant_total` DECIMAL(12, 2) NOT NULL,
    `statut` ENUM('en_attente', 'payee', 'echouee', 'annulee') NOT NULL DEFAULT 'en_attente',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 8 : order_items (Lignes d'une commande : détails des tickets achetés)
-- ==============================================================================
CREATE TABLE `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `ticket_type_id` INT NOT NULL,
    `quantite` INT NOT NULL,
    `prix_unitaire` DECIMAL(10, 2) NOT NULL,
    `sous_total` DECIMAL(10, 2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_ticket_type` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 9 : payments (Transactions Mobile Money : Wave, Orange, MTN, Moov)
-- ==============================================================================
CREATE TABLE `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `user_id` INT NULL,
    `montant` DECIMAL(12, 2) NOT NULL,
    `methode` ENUM('wave', 'orange_money', 'mtn_money', 'moov_money') NOT NULL,
    `reference` VARCHAR(100) NULL UNIQUE,
    `transaction_id_api` VARCHAR(255) NULL,
    `statut` ENUM('en_attente', 'paye', 'echoue', 'annule') NOT NULL DEFAULT 'en_attente',
    `raw_response` TEXT NULL,
    `date_paiement` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 10 : tickets (Billets uniques générés après paiement confirmé)
-- ==============================================================================
CREATE TABLE `tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NULL,
    `ticket_type_id` INT NULL,
    `event_id` INT NOT NULL,
    `user_id` INT NULL, -- Le client propriétaire (ou NULL si invité)
    `client_nom` VARCHAR(150) NULL,
    `client_email` VARCHAR(150) NULL,
    `client_telephone` VARCHAR(30) NULL,
    `type_ticket` VARCHAR(100) NOT NULL,
    `prix` DECIMAL(10, 2) NOT NULL,
    `code_unique` VARCHAR(30) NOT NULL UNIQUE, -- Ex: TK-8F92A7K3
    `qr_code` TEXT NULL, -- URL ou chemin de l'image QR Code
    `statut` ENUM('disponible', 'vendu', 'utilise', 'annule') NOT NULL DEFAULT 'vendu',
    `date_achat` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_utilisation` DATETIME NULL,
    `validated_by` INT NULL, -- L'agent de contrôle qui a validé le ticket
    CONSTRAINT `fk_tickets_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tickets_ticket_type` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tickets_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 11 : withdrawals (Demandes de retrait financier des promoteurs)
-- ==============================================================================
CREATE TABLE `withdrawals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL, -- Promoteur demandeur
    `promoter_id` INT NULL,
    `montant` DECIMAL(12, 2) NOT NULL,
    `methode` ENUM('wave', 'orange_money', 'mtn_money', 'moov_money') NOT NULL,
    `numero_telephone` VARCHAR(30) NOT NULL,
    `statut` ENUM('en_attente', 'approuve', 'refuse', 'paye') NOT NULL DEFAULT 'en_attente',
    `commentaire_admin` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL,
    CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_withdrawals_promoter` FOREIGN KEY (`promoter_id`) REFERENCES `promoters`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 12 : information_requests (Demandes d'informations sur un promoteur)
-- ==============================================================================
CREATE TABLE `information_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `promoter_id` INT NOT NULL, -- ID utilisateur du promoteur concerné
    `user_id` INT NULL, -- NULL si visiteur anonyme ou ID si client connecté
    `nom_demandeur` VARCHAR(100) NOT NULL,
    `email_demandeur` VARCHAR(150) NOT NULL,
    `telephone_demandeur` VARCHAR(30) NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_info_req_promoter` FOREIGN KEY (`promoter_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_info_req_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLE 13 : claims (Réclamations des clients et des promoteurs)
-- ==============================================================================
CREATE TABLE `claims` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL, -- Utilisateur ayant déposé la réclamation
    `order_id` INT NULL,
    `ticket_id` INT NULL,
    `sujet` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `reponse_admin` TEXT NULL,
    `statut` ENUM('en_attente', 'en_cours', 'resolue', 'fermee') NOT NULL DEFAULT 'en_attente',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_claims_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_claims_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_claims_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- INDEX DE PERFORMANCE (Optimisation des recherches fréquentes)
-- ==============================================================================
CREATE INDEX `idx_events_date` ON `events` (`date_evenement`);
CREATE INDEX `idx_events_statut` ON `events` (`statut`);
CREATE INDEX `idx_tickets_code` ON `tickets` (`code_unique`);
CREATE INDEX `idx_tickets_statut` ON `tickets` (`statut`);
CREATE INDEX `idx_orders_user` ON `orders` (`user_id`);
CREATE INDEX `idx_payments_order` ON `payments` (`order_id`);

-- ==============================================================================
-- DONNÉES DE DÉMARRAGE ET DE TEST
-- Mot de passe pour tous les comptes de test :
-- admin -> admin123
-- promoteur -> promoteur123
-- agent -> agent123
-- client -> client123
-- ==============================================================================

-- 1. Utilisateurs de test
INSERT INTO `users` (`id`, `nom`, `email`, `telephone`, `password`, `role`, `est_verifie`) VALUES
(1, 'Super Administrateur', 'admin@ticketflow.com', '+2250700000001', '$2y$12$Vam2CnarHwDuI1XjUaKzC.OkGGTthEWjhVR/AvA5Ej0ihIcJdFUiC', 'admin', 1),
(2, 'Promoteur Ivoire Events', 'promoteur@ticketflow.com', '+2250700000002', '$2y$12$1l4ozAo1pi6LnWX/UQcwuOtKIGZaP/lRcQTRnSATGPEO/niOGpxCi', 'promoteur', 1),
(3, 'Agent Contrôleur', 'agent@ticketflow.com', '+2250700000003', '$2y$12$yzR3m9rnNvTl2kG9JKN5UuLkp0RJEAQjjuF9Kv0WCNGfZ8kfLpUhi', 'agent', 1),
(4, 'Client Démo', 'client@ticketflow.com', '+2250700000004', '$2y$12$yhdr2xr7qrcY8e8BooJ45e58H.8Ys62LmB7xj2iPWjulNlmBVsMhi', 'client', 1);

-- 2. Profil promoteur
INSERT INTO `promoters` (`id`, `user_id`, `nom_commercial`, `description`, `telephone_contact`, `email_contact`, `adresse`, `site_web`, `reseaux_sociaux`, `statut`, `solde`) VALUES
(1, 2, 'Ivoire Events Spectacles', 'Agence événementielle spécialisée dans les concerts et festivals à Abidjan.', '+2250700000002', 'contact@ivoire-events.ci', 'Cocody Angré, Abidjan', 'https://ivoire-events.ci', '@ivoireevents', 'approuve', 950000.00);

-- 3. Événement de démonstration
INSERT INTO `events` (`id`, `user_id`, `nom`, `description`, `image`, `categorie`, `date_evenement`, `heure`, `lieu`, `commission_rate`, `statut`) VALUES
(1, 2, 'Concert Géant Abidjan Live 2026', 'Le plus grand rassemblement musical de l\'année au Palais de la Culture avec des artistes internationaux.', 'default.jpg', 'Concert', '2026-11-15', '20:00:00', 'Palais de la Culture, Treichville, Abidjan', 5.00, 'actif');

-- 4. Types de tickets pour cet événement
INSERT INTO `ticket_types` (`id`, `event_id`, `nom`, `description`, `prix`, `quantite`, `quantite_vendue`) VALUES
(1, 1, 'STANDARD', 'Accès général à la fosse et gradins standards', 5000.00, 500, 10),
(2, 1, 'VIP', 'Accès espace VIP, cocktail de bienvenue et place assise réservée', 15000.00, 100, 2);

-- 5. Exemple de commande et ticket valide pour tester le QR Code et l'Agent
INSERT INTO `orders` (`id`, `user_id`, `numero_commande`, `montant_total`, `statut`) VALUES
(1, 4, 'CMD-20260827-001', 15000.00, 'payee');

INSERT INTO `order_items` (`id`, `order_id`, `ticket_type_id`, `quantite`, `prix_unitaire`, `sous_total`) VALUES
(1, 1, 2, 1, 15000.00, 15000.00);

INSERT INTO `payments` (`id`, `order_id`, `user_id`, `montant`, `methode`, `reference`, `transaction_id_api`, `statut`) VALUES
(1, 1, 4, 15000.00, 'wave', 'WAVE-TEST-REF-99881', 'TXN-99881234', 'paye');

INSERT INTO `tickets` (`id`, `order_id`, `ticket_type_id`, `event_id`, `user_id`, `type_ticket`, `prix`, `code_unique`, `qr_code`, `statut`) VALUES
(1, 1, 2, 1, 4, 'VIP', 15000.00, 'TK-8F92A7K3', 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=TK-8F92A7K3', 'vendu');
