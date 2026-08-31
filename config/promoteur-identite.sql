-- A executer une seule fois dans la base ticket_platform.
CREATE TABLE IF NOT EXISTS promoter_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type_personne ENUM('physique', 'morale') NOT NULL DEFAULT 'physique',
    identite_promoteur VARCHAR(180) NOT NULL,
    numero_identification VARCHAR(120) NOT NULL,
    adresse_promoteur VARCHAR(255) NOT NULL,
    responsable_nom VARCHAR(180) NULL,
    responsable_contact VARCHAR(120) NULL,
    nom_evenement VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    date_evenement DATE NOT NULL,
    heure TIME NOT NULL,
    lieu VARCHAR(180) NOT NULL,
    experience TEXT NOT NULL,
    budget DECIMAL(12,2) NOT NULL,
    capacite INT NOT NULL,
    statut ENUM('en_attente', 'acceptee', 'refusee') NOT NULL DEFAULT 'en_attente',
    commentaire_admin TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    CONSTRAINT fk_promoter_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
