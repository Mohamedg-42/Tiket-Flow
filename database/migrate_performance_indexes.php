<?php
/**
 * Migration d'optimisation des performances : Ajout des index stratégiques PostgreSQL
 * Plateforme Tikéli
 * 
 * Évite les Sequential Scans sur les tables critiques :
 * places, tickets, orders, order_items, events, users, etc.
 */

require_once __DIR__ . '/../config/database.php';

$indexes = [
    // Places (table volumineuse : +3500 lignes)
    'idx_places_ticket_type_id' => 'CREATE INDEX IF NOT EXISTS idx_places_ticket_type_id ON places(ticket_type_id)',
    'idx_places_statut' => 'CREATE INDEX IF NOT EXISTS idx_places_statut ON places(statut)',
    'idx_places_type_statut' => 'CREATE INDEX IF NOT EXISTS idx_places_type_statut ON places(ticket_type_id, statut)',

    // Tickets & Order Items
    'idx_tickets_user_id' => 'CREATE INDEX IF NOT EXISTS idx_tickets_user_id ON tickets(user_id)',
    'idx_tickets_order_id' => 'CREATE INDEX IF NOT EXISTS idx_tickets_order_id ON tickets(order_id)',
    'idx_tickets_event_id' => 'CREATE INDEX IF NOT EXISTS idx_tickets_event_id ON tickets(event_id)',
    'idx_tickets_ticket_type_id' => 'CREATE INDEX IF NOT EXISTS idx_tickets_ticket_type_id ON tickets(ticket_type_id)',
    'idx_tickets_code_unique' => 'CREATE INDEX IF NOT EXISTS idx_tickets_code_unique ON tickets(code_unique)',
    'idx_order_items_order_id' => 'CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id)',

    // Commandes & Transactions
    'idx_orders_user_id' => 'CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id)',
    'idx_orders_statut' => 'CREATE INDEX IF NOT EXISTS idx_orders_statut ON orders(statut)',
    'idx_orders_created_at' => 'CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at DESC)',
    'idx_promoter_trans_promoter' => 'CREATE INDEX IF NOT EXISTS idx_promoter_trans_promoter ON promoter_transactions(promoter_id)',

    // Événements & Types de billets
    'idx_events_statut_date' => 'CREATE INDEX IF NOT EXISTS idx_events_statut_date ON events(statut, date_evenement)',
    'idx_events_user_id' => 'CREATE INDEX IF NOT EXISTS idx_events_user_id ON events(user_id)',
    'idx_events_categorie' => 'CREATE INDEX IF NOT EXISTS idx_events_categorie ON events(categorie)',
    'idx_ticket_types_event_id' => 'CREATE INDEX IF NOT EXISTS idx_ticket_types_event_id ON ticket_types(event_id)',

    // Utilisateurs & Promoteurs
    'idx_users_email' => 'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
    'idx_users_role' => 'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)',
    'idx_promoters_user_id' => 'CREATE INDEX IF NOT EXISTS idx_promoters_user_id ON promoters(user_id)',

    // Votes, Cotisations & Logs
    'idx_event_votes_event_id' => 'CREATE INDEX IF NOT EXISTS idx_event_votes_event_id ON event_votes(event_id)',
    'idx_event_candidats_event' => 'CREATE INDEX IF NOT EXISTS idx_event_candidats_event ON event_candidats(event_id)',
    'idx_cotisations_campagne' => 'CREATE INDEX IF NOT EXISTS idx_cotisations_campagne ON cotisations(campagne_id)',
    'idx_activity_logs_user_id' => 'CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id)',
    'idx_activity_logs_created' => 'CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at DESC)',
];

echo "🚀 Début de l'application des index de performance...\n";

$count = 0;
foreach ($indexes as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "  [OK] Index $name appliqué.\n";
        $count++;
    } catch (Exception $e) {
        echo "  [ERREUR] Index $name : " . $e->getMessage() . "\n";
    }
}

echo "✅ $count index de performance configurés avec succès sur PostgreSQL.\n";
