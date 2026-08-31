-- ==============================================================================
-- MIGRATION : Workflow de validation des campagnes de cotisation
-- Les campagnes créées par un promoteur passent en 'en_attente' jusqu'à
-- l'approbation de l'admin (page admin/demandes.php, bulle « Cotisations »).
-- Les campagnes créées directement par l'admin restent 'active'.
-- À exécuter une seule fois.
-- ==============================================================================

USE `ticket_platform`;

ALTER TABLE `cotisation_campagnes`
    MODIFY `statut` ENUM('en_attente', 'active', 'terminee', 'annulee') NOT NULL DEFAULT 'en_attente',
    ADD COLUMN `commentaire_admin` TEXT NULL AFTER `statut`,
    ADD COLUMN `reviewed_at` DATETIME NULL AFTER `commentaire_admin`;

-- Les campagnes existantes (créées avant ce workflow) restent actives
UPDATE `cotisation_campagnes` SET `statut` = 'active' WHERE `statut` NOT IN ('active', 'terminee', 'annulee');
