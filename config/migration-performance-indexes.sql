-- ==============================================================================
-- MIGRATION : INDEX DE PERFORMANCE SQL (config/migration-performance-indexes.sql)
-- Optimisation des clés étrangères, recherches, statuts et agrégations temporelles
-- ==============================================================================

-- 1. Table payments
CREATE INDEX IF NOT EXISTS idx_payments_order_id ON payments (order_id);
CREATE INDEX IF NOT EXISTS idx_payments_statut_date ON payments (statut, date_paiement);
CREATE INDEX IF NOT EXISTS idx_payments_reference ON payments (reference);
CREATE INDEX IF NOT EXISTS idx_payments_methode_statut ON payments (LOWER(methode), statut);

-- 2. Table vote_paiements
CREATE INDEX IF NOT EXISTS idx_vote_paiements_event_statut ON vote_paiements (event_id, statut);
CREATE INDEX IF NOT EXISTS idx_vote_paiements_candidat_statut ON vote_paiements (candidat_id, statut);
CREATE INDEX IF NOT EXISTS idx_vote_paiements_reference ON vote_paiements (reference);
CREATE INDEX IF NOT EXISTS idx_vote_paiements_statut ON vote_paiements (statut);

-- 3. Demandes administratives (scannées pour les badges du header sur chaque page admin)
CREATE INDEX IF NOT EXISTS idx_event_requests_statut ON event_requests (statut);
CREATE INDEX IF NOT EXISTS idx_promoter_requests_statut ON promoter_requests (statut);
CREATE INDEX IF NOT EXISTS idx_withdrawals_statut ON withdrawals (statut);
CREATE INDEX IF NOT EXISTS idx_claims_statut ON claims (statut);
CREATE INDEX IF NOT EXISTS idx_tasks_user_statut ON tasks (user_id, statut);

-- 4. Cotisations & Campagnes
CREATE INDEX IF NOT EXISTS idx_cotisations_statut ON cotisations (statut);
CREATE INDEX IF NOT EXISTS idx_cotisation_campagnes_statut_vis ON cotisation_campagnes (statut, visibilite);

-- 5. Tickets & Commandes (recherche et agrégations de ventes)
CREATE INDEX IF NOT EXISTS idx_tickets_date_achat ON tickets (date_achat);
CREATE INDEX IF NOT EXISTS idx_tickets_statut_event ON tickets (statut, event_id);
CREATE INDEX IF NOT EXISTS idx_orders_statut_created ON orders (statut, created_at DESC);

-- 6. Salles et zones
CREATE INDEX IF NOT EXISTS idx_salle_zones_salle_id ON salle_zones (salle_id);

-- 7. Événements (candidats et concours)
CREATE INDEX IF NOT EXISTS idx_event_candidats_event_votes ON event_candidats (event_id);
