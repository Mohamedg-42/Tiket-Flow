-- ==============================================================================
-- MIGRATION : AJOUT DES SLUGS D'URLS CONVIVIALES
-- ==============================================================================

-- 1. Ajout de la colonne slug pour les événements et billetteries
ALTER TABLE events ADD COLUMN IF NOT EXISTS slug VARCHAR(255) NULL;
CREATE INDEX IF NOT EXISTS idx_events_slug ON events(slug);

-- 2. Ajout de la colonne slug pour les campagnes de cotisations (si la table existe)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'cotisation_campagnes') THEN
        ALTER TABLE cotisation_campagnes ADD COLUMN IF NOT EXISTS slug VARCHAR(255) NULL;
        CREATE INDEX IF NOT EXISTS idx_cotisations_slug ON cotisation_campagnes(slug);
    END IF;
END $$;
