-- ==============================================================================
-- MIGRATION : Votes sur les événements + Cotisations
-- À exécuter une seule fois (phpMyAdmin ou ligne de commande MySQL)
-- ==============================================================================

USE `ticket_platform`;

-- 1. Votes des visiteurs sur les événements (un vote par visiteur et par événement)
CREATE TABLE IF NOT EXISTS `event_votes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `user_id` INT NULL,
    `visitor_id` VARCHAR(128) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_votes_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uniq_vote_visitor` (`event_id`, `visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_votes_event` ON `event_votes` (`event_id`);

-- 2. Cotisations / contributions des clients
CREATE TABLE IF NOT EXISTS `cotisations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `nom` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NULL,
    `telephone` VARCHAR(30) NULL,
    `montant` DECIMAL(12, 2) NOT NULL,
    `statut` ENUM('en_attente', 'payee', 'annule') NOT NULL DEFAULT 'en_attente',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;