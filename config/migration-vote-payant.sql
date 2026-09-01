-- ==============================================================================
-- MIGRATION : VOTE PAYANT (config/migration-vote-payant.sql)
-- Le promoteur peut imposer un montant à payer pour voter sur un événement.

-- Le paiement est enregistré dans vote_paiements puis le vote dans event_votes.

USE `ticket_platform`;

-- 1. Prix du vote sur les événements publiés
ALTER TABLE `events`
    ADD COLUMN `prix_vote` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `lieu`;

-- 2. Prix du vote défini dès la proposition de l'événement (reporté à la validation)
ALTER TABLE `event_requests`
    ADD COLUMN `prix_vote` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `lieu`;

-- 3. Paiements de vote (chaque paiement = un vote compté)
CREATE TABLE IF NOT EXISTS `vote_paiements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `user_id` INT NULL,
    `visitor_id` VARCHAR(64) NULL,
    `montant` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `methode` VARCHAR(30) NULL,
    `reference` VARCHAR(50) NULL,
    `transaction_id_api` VARCHAR(60) NULL,
    `statut` ENUM('paye', 'en_attente', 'annule') NOT NULL DEFAULT 'en_attente',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_vp_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;