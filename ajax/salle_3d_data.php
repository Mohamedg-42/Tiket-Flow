<?php
// ==============================================================================
// ENDPOINT AJAX : DONNÉES 3D DE SALLE ET PLAN DE PLACES
// Fournit la structure géométrique 3D, les zones, tarifs et places pour le rendu 3D
// ==============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../config/database.php';

$salle_id = isset($_GET['salle_id']) && is_numeric($_GET['salle_id']) ? (int)$_GET['salle_id'] : filter_input(INPUT_GET, 'salle_id', FILTER_VALIDATE_INT);
$event_id = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

try {
    $salle = null;
    $event = null;
    $ticket_types = [];

    $exclude_ticket_id = isset($_GET['exclude_ticket_id']) && is_numeric($_GET['exclude_ticket_id']) ? (int)$_GET['exclude_ticket_id'] : filter_input(INPUT_GET, 'exclude_ticket_id', FILTER_VALIDATE_INT);
    $current_seat_code = null;
    if ($exclude_ticket_id) {
        $stmt_cur = $pdo->prepare("SELECT place_numero FROM tickets WHERE id = ?");
        $stmt_cur->execute([$exclude_ticket_id]);
        $current_seat_code = trim((string)$stmt_cur->fetchColumn());
    }

    // 1. Si un event_id est fourni, on récupère l'événement et sa salle associée
    if ($event_id) {
        $stmt_ev = $pdo->prepare("SELECT id, nom, description, date_evenement, heure, lieu, salle_id FROM events WHERE id = ?");
        $stmt_ev->execute([$event_id]);
        $event = $stmt_ev->fetch(PDO::FETCH_ASSOC);

        if ($event) {
            // Récupérer les types de billets et leurs tarifs
            $stmt_tt = $pdo->prepare("SELECT id, nom, description, prix, frais_place, quantite, quantite_vendue FROM ticket_types WHERE event_id = ? ORDER BY prix DESC");
            $stmt_tt->execute([$event_id]);
            $ticket_types = $stmt_tt->fetchAll(PDO::FETCH_ASSOC);

            // Retrouver la salle officielle associée à l'événement ou fallback automatique
            if (!empty($event['salle_id'])) {
                $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
                $stmt_s->execute([$event['salle_id']]);
                $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
            }
            if (!$salle && !empty($event['lieu'])) {
                $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE statut = 'active' AND ? ILIKE '%' || nom || '%' ORDER BY id ASC LIMIT 1");
                $stmt_s->execute([$event['lieu']]);
                $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
            }
            if (!$salle) {
                // Fallback sur la première salle active (salle de spectacle standard)
                $stmt_s = $pdo->query("SELECT * FROM salles WHERE statut = 'active' ORDER BY id ASC LIMIT 1");
                $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    // 2. Si pas d'événement ou salle non trouvée, charger par salle_id direct
    if (!$salle && $salle_id) {
        $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
        $stmt_s->execute([$salle_id]);
        $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Fallback sur une salle par défaut si non spécifié
    if (!$salle) {
        $stmt_s = $pdo->query("SELECT * FROM salles WHERE statut = 'active' ORDER BY id ASC LIMIT 1");
        $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
    }

    if (!$salle) {
        echo json_encode(['success' => false, 'message' => 'Aucune salle trouvée.']);
        exit();
    }

    // 4. Charger les zones de la salle
    $stmt_z = $pdo->prepare("SELECT * FROM salle_zones WHERE salle_id = ? ORDER BY elevation_3d ASC, id ASC");
    $stmt_z->execute([$salle['id']]);
    $zones = $stmt_z->fetchAll(PDO::FETCH_ASSOC);

    // Si la salle n'a pas encore de zones créées, générer des zones par défaut adaptées au modèle 3D
    if (empty($zones)) {
        $modele = $salle['modele_3d'] ?? 'theatre_italien';
        $default_zones = [];
        if ($modele === 'stade') {
            $default_zones = [
                ['nom_zone' => 'Fosse Pelouse (VIP)', 'capacite' => 2500, 'couleur' => '#ef4444', 'elevation_3d' => 0, 'position_3d' => 'vip_avant', 'tarif_indicatif' => 25000],
                ['nom_zone' => 'Tribune Officielle / Honneur', 'capacite' => 1500, 'couleur' => '#0d9488', 'elevation_3d' => 1, 'position_3d' => 'centre', 'tarif_indicatif' => 15000],
                ['nom_zone' => 'Tribune Est (Latérale)', 'capacite' => 4000, 'couleur' => '#3b82f6', 'elevation_3d' => 2, 'position_3d' => 'gauche', 'tarif_indicatif' => 10000],
                ['nom_zone' => 'Tribune Ouest (Latérale)', 'capacite' => 4000, 'couleur' => '#8b5cf6', 'elevation_3d' => 2, 'position_3d' => 'droite', 'tarif_indicatif' => 10000],
                ['nom_zone' => 'Virage Nord & Sud (Populaire)', 'capacite' => 8000, 'couleur' => '#f59e0b', 'elevation_3d' => 3, 'position_3d' => 'arriere', 'tarif_indicatif' => 5000],
            ];
        } elseif ($modele === 'arena') {
            $default_zones = [
                ['nom_zone' => 'Carré Or / VIP Fosse', 'capacite' => 800, 'couleur' => '#f59e0b', 'elevation_3d' => 0, 'position_3d' => 'vip_avant', 'tarif_indicatif' => 30000],
                ['nom_zone' => 'Parterre Central', 'capacite' => 1500, 'couleur' => '#0d9488', 'elevation_3d' => 1, 'position_3d' => 'centre', 'tarif_indicatif' => 15000],
                ['nom_zone' => 'Gradin Niveau 1 (360°)', 'capacite' => 2000, 'couleur' => '#3b82f6', 'elevation_3d' => 2, 'position_3d' => 'gradin_haut', 'tarif_indicatif' => 10000],
                ['nom_zone' => 'Gradin Supérieur (Vue Panoramique)', 'capacite' => 1700, 'couleur' => '#64748b', 'elevation_3d' => 3, 'position_3d' => 'arriere', 'tarif_indicatif' => 5000],
            ];
        } elseif ($modele === 'auditorium') {
            $default_zones = [
                ['nom_zone' => 'Premier Rang VIP', 'capacite' => 120, 'couleur' => '#f59e0b', 'elevation_3d' => 0, 'position_3d' => 'vip_avant', 'tarif_indicatif' => 25000],
                ['nom_zone' => 'Parterre Orchestre', 'capacite' => 450, 'couleur' => '#0d9488', 'elevation_3d' => 1, 'position_3d' => 'centre', 'tarif_indicatif' => 15000],
                ['nom_zone' => 'Amphithéâtre Gradins', 'capacite' => 300, 'couleur' => '#3b82f6', 'elevation_3d' => 2, 'position_3d' => 'gradin_haut', 'tarif_indicatif' => 10000],
            ];
        } else {
            // Théâtre à l'italienne & standard
            $default_zones = [
                ['nom_zone' => 'Fosse VIP / Carré Or', 'capacite' => 300, 'couleur' => '#f59e0b', 'elevation_3d' => 0, 'position_3d' => 'vip_avant', 'tarif_indicatif' => 25000],
                ['nom_zone' => 'Parterre Central', 'capacite' => 800, 'couleur' => '#0d9488', 'elevation_3d' => 1, 'position_3d' => 'centre', 'tarif_indicatif' => 15000],
                ['nom_zone' => 'Loges & Corbeille', 'capacite' => 150, 'couleur' => '#8b5cf6', 'elevation_3d' => 2, 'position_3d' => 'balcon', 'tarif_indicatif' => 30000],
                ['nom_zone' => 'Balcon & Galerie Haute', 'capacite' => 450, 'couleur' => '#3b82f6', 'elevation_3d' => 3, 'position_3d' => 'arriere', 'tarif_indicatif' => 5000],
            ];
        }

        foreach ($default_zones as $dz) {
            $stmt_ins = $pdo->prepare("INSERT INTO salle_zones (salle_id, nom_zone, capacite, couleur, elevation_3d, position_3d, tarif_indicatif) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$salle['id'], $dz['nom_zone'], $dz['capacite'], $dz['couleur'], $dz['elevation_3d'], $dz['position_3d'], $dz['tarif_indicatif']]);
        }

        $stmt_z->execute([$salle['id']]);
        $zones = $stmt_z->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Association des Tarifs de l'événement aux zones de la salle
    // Si des ticket_types existent pour l'événement, on lie chaque ticket_type à une zone selon prix ou nom
    $zone_tarifs = [];
    if (!empty($ticket_types)) {
        foreach ($zones as $idx => $z) {
            $matched_ticket = null;
            // Match par nom
            foreach ($ticket_types as $tt) {
                if (stripos($z['nom_zone'], $tt['nom']) !== false || stripos($tt['nom'], $z['nom_zone']) !== false) {
                    $matched_ticket = $tt;
                    break;
                }
            }
            // Match par index si non trouvé
            if (!$matched_ticket) {
                $matched_ticket = $ticket_types[$idx % count($ticket_types)];
            }

            $zone_tarifs[$z['id']] = [
                'ticket_type_id' => $matched_ticket['id'],
                'ticket_nom' => $matched_ticket['nom'],
                'prix' => (float)$matched_ticket['prix'],
                'frais_place' => (float)(!empty($matched_ticket['frais_place']) && (float)$matched_ticket['frais_place'] > 0 ? $matched_ticket['frais_place'] : 1000),
                'stock' => max(0, (int)$matched_ticket['quantite'] - (int)($matched_ticket['quantite_vendue'] ?? 0))
            ];
        }
    } else {
        // Mode studio Admin sans événement spécifique : utiliser tarif_indicatif
        foreach ($zones as $z) {
            $zone_tarifs[$z['id']] = [
                'ticket_type_id' => $z['id'],
                'ticket_nom' => $z['nom_zone'],
                'prix' => (float)($z['tarif_indicatif'] > 0 ? $z['tarif_indicatif'] : 10000),
                'frais_place' => 1000,
                'stock' => $z['capacite']
            ];
        }
    }

    // 6. Récupération des places déjà réservées pour cet événement
    $reserved_seat_keys = [];
    if ($event_id) {
        $stmt_res = $pdo->prepare("SELECT p.numero, p.ticket_type_id FROM places p 
                                   INNER JOIN ticket_types tt ON p.ticket_type_id = tt.id 
                                   WHERE tt.event_id = ? AND p.statut IN ('reserve', 'occupe', 'vendu')");
        $stmt_res->execute([$event_id]);
        while ($r = $stmt_res->fetch(PDO::FETCH_ASSOC)) {
            $reserved_seat_keys[trim($r['numero'])] = true;
        }

        // Ajouter aussi les places actuellement vendues dans tickets
        if (!empty($exclude_ticket_id)) {
            $stmt_t_seats = $pdo->prepare("SELECT place_numero FROM tickets WHERE event_id = ? AND statut = 'vendu' AND place_numero IS NOT NULL AND id != ?");
            $stmt_t_seats->execute([$event_id, $exclude_ticket_id]);
        } else {
            $stmt_t_seats = $pdo->prepare("SELECT place_numero FROM tickets WHERE event_id = ? AND statut = 'vendu' AND place_numero IS NOT NULL");
            $stmt_t_seats->execute([$event_id]);
        }
        while ($ts = $stmt_t_seats->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($ts['place_numero'])) {
                $reserved_seat_keys[trim($ts['place_numero'])] = true;
            }
        }

        // Si le billet courant a une place, on la retire des places occupées pour lui permettre de la visualiser
        if (!empty($current_seat_code)) {
            unset($reserved_seat_keys[$current_seat_code]);
        }
    }

    // 7. Génération de la matrice spatiale des sièges 3D
    // Construit les blocs de rangées avec coordonnées réelles 3D (X, Y, Z)
    $seats_3d = [];
    $seat_id_counter = 1;

    foreach ($zones as $z) {
        $z_id = $z['id'];
        $z_name = $z['nom_zone'];
        $z_color = $z['couleur'] ?: '#0d9488';
        $z_elev = (int)($z['elevation_3d'] ?? 0);
        $z_pos = $z['position_3d'] ?? 'centre';
        $tarif_info = $zone_tarifs[$z_id] ?? [
            'ticket_type_id' => $z_id,
            'ticket_nom' => $z_name,
            'prix' => 10000,
            'frais_place' => 1000,
            'stock' => 100
        ];

        // Nombre de rangées et sièges par rangée pour le rendu 3D
        $nb_rows = 4;
        $seats_per_row = 10;

        if ($z_pos === 'vip_avant') {
            $nb_rows = 3;
            $seats_per_row = 8;
        } elseif ($z_pos === 'centre') {
            $nb_rows = 5;
            $seats_per_row = 12;
        } elseif ($z_pos === 'balcon') {
            $nb_rows = 3;
            $seats_per_row = 10;
        } elseif ($z_pos === 'gradin_haut' || $z_pos === 'arriere') {
            $nb_rows = 6;
            $seats_per_row = 14;
        }

        $row_letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        for ($r = 0; $r < $nb_rows; $r++) {
            $row_label = $row_letters[$r % count($row_letters)];
            for ($s = 1; $s <= $seats_per_row; $s++) {
                $seat_code = substr($z_name, 0, 3) . '-' . $row_label . sprintf('%02d', $s);
                
                // Calcul de la position 3D spatiale (X, Y, Z)
                $x_offset = ($s - ($seats_per_row + 1) / 2) * 30;
                $y_offset = 0;
                $z_depth = 0;

                if ($z_pos === 'vip_avant') {
                    $z_depth = 60 + ($r * 34);
                    $y_offset = 0 + ($r * 8);
                } elseif ($z_pos === 'centre') {
                    $z_depth = 170 + ($r * 36);
                    $y_offset = 22 + ($r * 14);
                } elseif ($z_pos === 'gauche') {
                    $x_offset -= 180 + ($r * 10);
                    $z_depth = 130 + ($r * 36);
                    $y_offset = 32 + ($r * 18);
                } elseif ($z_pos === 'droite') {
                    $x_offset += 180 + ($r * 10);
                    $z_depth = 130 + ($r * 36);
                    $y_offset = 32 + ($r * 18);
                } elseif ($z_pos === 'balcon') {
                    $z_depth = 300 + ($r * 32);
                    $y_offset = 100 + ($r * 22);
                } else { // arriere / gradin_haut
                    $z_depth = 340 + ($r * 36);
                    $y_offset = 80 + ($r * 26);
                }

                $is_cur = (!empty($current_seat_code) && $seat_code === $current_seat_code);
                $is_taken = !$is_cur && isset($reserved_seat_keys[$seat_code]);

                $seats_3d[] = [
                    'id' => $seat_id_counter,
                    'code' => $seat_code,
                    'row' => $row_label,
                    'number' => $s,
                    'zone_id' => $z_id,
                    'zone_name' => $z_name,
                    'zone_color' => $z_color,
                    'elevation' => $z_elev,
                    'position_type' => $z_pos,
                    'x' => round($x_offset, 2),
                    'y' => round($y_offset, 2),
                    'z' => round($z_depth, 2),
                    'ticket_type_id' => $tarif_info['ticket_type_id'],
                    'ticket_nom' => $tarif_info['ticket_nom'],
                    'prix' => $tarif_info['prix'],
                    'frais_place' => $tarif_info['frais_place'],
                    'statut' => $is_cur ? 'actuelle' : ($is_taken ? 'reserve' : 'libre'),
                    'is_current' => $is_cur
                ];

                $seat_id_counter++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'salle' => [
            'id' => $salle['id'],
            'nom' => $salle['nom'],
            'ville' => $salle['ville'],
            'commune' => $salle['commune'],
            'capacite' => (int)$salle['capacite'],
            'type_salle' => $salle['type_salle'],
            'modele_3d' => $salle['modele_3d'] ?? 'theatre_italien',
            'type_rendu_3d' => $salle['type_rendu_3d'] ?? 'generateur_3d',
            'image_principale' => $salle['image_principale'] ?? 'salle_default.jpg',
            'galerie_photos' => !empty($salle['galerie_photos']) ? json_decode($salle['galerie_photos'], true) : [],
            'plan_image' => $salle['plan_image'] ?? null,
            'fichier_3d' => $salle['fichier_3d'] ?? null,
            'configuration' => $salle['configuration']
        ],
        'event' => $event ? [
            'id' => $event['id'],
            'nom' => $event['nom'],
            'date' => $event['date_evenement'],
            'heure' => $event['heure'],
            'lieu' => $event['lieu']
        ] : null,
        'zones' => $zones,
        'ticket_types' => $ticket_types,
        'seats' => $seats_3d,
        'total_seats_generated' => count($seats_3d)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
