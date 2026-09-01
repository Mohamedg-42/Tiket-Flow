-- ==============================================================================
-- MIGRATION : Ajout de la table 'places' pour la gestion des sièges
-- Ce script permet de gérer les places physiques pour chaque type de ticket
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `places` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_type_id` INT NOT NULL,
    `numero` VARCHAR(20) NOT NULL, -- Ex: 'A1', 'A2', 'B1', etc.
    `statut` ENUM('libre', 'reserve', 'vendu') NOT NULL DEFAULT 'libre',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_places_ticket_type` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index pour accélérer la recherche des places libres par type de ticket
CREATE INDEX `idx_places_type_statut` ON `places` (`ticket_type_id`, `statut`);
