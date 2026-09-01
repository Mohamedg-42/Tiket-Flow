-- ==============================================================================
-- MIGRATION : CANDIDATS DE VOTE (config/migration-candidats.sql)
-- Concours type "Miss" : le promoteur définit des candidats (photo + description)
-- et les clients votent pour un candidat précis.
-- À exécuter une seule fois.
-- ==============================================================================

USE `ticket_platform`;

-- 1. Candidats d'un événement
CREATE TABLE IF NOT EXISTS `event_candidats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `nom` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `photo` VARCHAR(255) DEFAULT NULL, -- fichier dans uploads/candidats/
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_candidat_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Lier un vote à un candidat (NULL = vote général pour l'événement)
ALTER TABLE `event_votes`
    ADD COLUMN `candidat_id` INT NULL AFTER `visitor_id`,
    ADD CONSTRAINT `fk_vote_candidat` FOREIGN KEY (`candidat_id`) REFERENCES `event_candidats`(`id`) ON DELETE CASCADE;

-- 3. Candidats définis dès la proposition (JSON, reportés à la validation admin)
ALTER TABLE `event_requests`
    ADD COLUMN `candidats_data` TEXT NULL AFTER `ticket_types_data`;

-- 4. Choix multiples pour les paiements de vote
ALTER TABLE `vote_paiements`
    ADD COLUMN `candidats_ids` TEXT NULL AFTER `candidat_id`;

