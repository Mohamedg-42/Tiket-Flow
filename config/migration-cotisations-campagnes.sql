-- ==============================================================================
-- MIGRATION : Campagnes de cotisation (affichées comme des événements)
-- Chaque campagne a un montant à atteindre et une barre de progression.
-- À exécuter une seule fois (phpMyAdmin ou ligne de commande MySQL)
-- ==============================================================================

USE `ticket_platform`;

-- 1. Campagnes de cotisation (financement participatif d'événements/projets)
CREATE TABLE IF NOT EXISTS `cotisation_campagnes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL, -- Promoteur / organisateur de la campagne (ou NULL si créée par l'admin)
    `titre` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `image` VARCHAR(255) DEFAULT NULL, -- Nom du fichier dans uploads/events/ ou URL complète
    `montant_objectif` DECIMAL(12, 2) NOT NULL DEFAULT 0, -- Montant à atteindre
    `date_limite` DATE NULL, -- Date limite de contribution (optionnelle)
    `statut` ENUM('en_attente', 'active', 'terminee', 'annulee', 'refuse') NOT NULL DEFAULT 'en_attente',
    `commentaire_admin` TEXT NULL, -- Motif de refus éventuel (visible par le promoteur)
    `reviewed_at` DATETIME NULL, -- Date de traitement par l'admin
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_campagnes_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Lier chaque cotisation à sa campagne (NULL = contribution générale)
--    + colonnes de paiement Mobile Money (même mécanisme que les billets)
ALTER TABLE `cotisations`
    ADD COLUMN `campagne_id` INT NULL AFTER `user_id`,
    ADD COLUMN `methode` VARCHAR(30) NULL AFTER `statut`,
    ADD COLUMN `reference` VARCHAR(50) NULL AFTER `methode`,
    ADD COLUMN `transaction_id_api` VARCHAR(60) NULL AFTER `reference`,
    ADD COLUMN `date_paiement` DATETIME NULL AFTER `transaction_id_api`,
    ADD CONSTRAINT `fk_cotisations_campagne` FOREIGN KEY (`campagne_id`) REFERENCES `cotisation_campagnes`(`id`) ON DELETE SET NULL;

-- 3. Données de démonstration (statut 'active' = déjà validées par l'admin)
INSERT INTO `cotisation_campagnes` (`user_id`, `titre`, `description`, `image`, `montant_objectif`, `date_limite`, `statut`) VALUES
(NULL, 'Festival Nuits d''Abidjan 2026', 'Aidez-nous à organiser la plus grande édition du Festival Nuits d''Abidjan : scène géante, sonorisation, éclairage et artistes internationaux. Chaque contribution compte !', 'https://images.unsplash.com/photo-1470229722913-7c0e2dbbafd3?auto=format&fit=crop&w=800&q=80', 2500000.00, DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'active'),
(NULL, 'Concert caritatif pour les écoles de Yopougon', 'Nous finançons un concert caritatif dont les bénéfices serviront à équiper trois écoles primaires en fournitures et en matériel scolaire. Contribuez à un impact concret.', 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=800&q=80', 1000000.00, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active'),
(NULL, 'Grand Gala de Fin d''Année', 'Participez au financement du Grand Gala de Fin d''Année au Palais de la Culture : orchestre live, buffet et animation. Les contributeurs recevront des invitations VIP.', 'https://images.unsplash.com/photo-1519671482749-fd09be7ccebf?auto=format&fit=crop&w=800&q=80', 5000000.00, DATE_ADD(CURDATE(), INTERVAL 90 DAY), 'active');

-- 4. Quelques contributions de démonstration (statut 'payee') pour visualiser la progression
INSERT INTO `cotisations` (`campagne_id`, `nom`, `email`, `telephone`, `montant`, `statut`) VALUES
(1, 'Aya Traoré', 'aya.troure@example.com', '07 01 02 03 04', 50000.00, 'payee'),
(1, 'Koffi Mensah', 'koffi.mensah@example.com', '05 05 06 07 08', 125000.00, 'payee'),
(2, 'Fatou Diallo', 'fatou.diallo@example.com', '01 09 08 07 06', 25000.00, 'payee'),
(2, 'Serge Kouassi', NULL, '07 77 66 55 44', 10000.00, 'payee'),
(3, 'Mariam Bamba', 'mariam.bamba@example.com', '05 44 33 22 11', 300000.00, 'payee');