-- ==============================================================================
-- MIGRATION : TABLE DES TOKENS SÉCURISÉS (secure_resource_tokens)
-- Système de masquage d'identifiants et liens cryptographiquement sécurisés
-- ==============================================================================

CREATE TABLE IF NOT EXISTS secure_resource_tokens (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    resource_type VARCHAR(50) NOT NULL,
    resource_id INT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    metadata JSONB NULL
);

-- Index pour accélérer la résolution par token (recherche principale)
CREATE UNIQUE INDEX IF NOT EXISTS idx_secure_tokens_token ON secure_resource_tokens (token);

-- Index pour retrouver les tokens existants d'une ressource
CREATE INDEX IF NOT EXISTS idx_secure_tokens_lookup ON secure_resource_tokens (resource_type, resource_id);

-- Index pour le filtrage de validité
CREATE INDEX IF NOT EXISTS idx_secure_tokens_active ON secure_resource_tokens (is_active, expires_at);
