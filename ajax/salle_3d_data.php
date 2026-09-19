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

            // Retrouver la salle officielle enregistrée associée à l'événement
            if (!empty($event['salle_id'])) {
                $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE id = ? AND statut = 'active'");
                $stmt_s->execute([$event['salle_id']]);
                $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
                // Si la salle n'était pas active ou id non trouvé, essayer sans contrainte statut
                if (!$salle) {
                    $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
                    $stmt_s->execute([$event['salle_id']]);
                    $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
                }
            }

            // Détection par correspondance intelligente sur le lieu
            if (!$salle && !empty($event['lieu'])) {
                $lieuLower = mb_strtolower($event['lieu']);
                $allSalles = $pdo->query("SELECT * FROM salles WHERE statut = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allSalles as $candSalle) {
                    $nomLower = mb_strtolower($candSalle['nom']);
                    // Vérifier si le début correspond (ex: Palais de la Culture)
                    if (str_starts_with($lieuLower, 'palais de la culture') && str_starts_with($nomLower, 'palais de la culture')) {
                        $salle = $candSalle;
                        break;
                    }
                    if (str_contains($lieuLower, 'palais des sport') && str_contains($nomLower, 'dome arena')) {
                        $salle = $candSalle;
                        break;
                    }
                    if (str_contains($lieuLower, 'houphouet') && str_contains($nomLower, 'houphouet')) {
                        $salle = $candSalle;
                        break;
                    }
                    if (str_contains($nomLower, $lieuLower) || str_contains($lieuLower, $nomLower)) {
                        $salle = $candSalle;
                        break;
                    }
                }
            }

            // Si l'événement n'a aucune salle répertoriée et aucun salle_id direct n'est demandé
            if (!$salle && !$salle_id) {
                echo json_encode([
                    'success' => false,
                    'has_salle_3d' => false,
                    'message' => "Cet événement est en placement libre. Aucune salle avec modélisation 3D n'est associée."
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }

    // 2. Si pas d'événement ou salle non trouvée, charger par salle_id direct
    if (!$salle && $salle_id) {
        $stmt_s = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
        $stmt_s->execute([$salle_id]);
        $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Fallback uniquement si aucun event_id ni salle_id n'a été spécifié (ex: démonstration admin)
    if (!$salle && !$event_id) {
        $stmt_s = $pdo->query("SELECT * FROM salles WHERE statut = 'active' ORDER BY id ASC LIMIT 1");
        $salle = $stmt_s->fetch(PDO::FETCH_ASSOC);
    }

    if (!$salle) {
        echo json_encode([
            'success' => false,
            'has_salle_3d' => false,
            'message' => 'Aucune salle répertoriée trouvée.'
        ], JSON_UNESCAPED_UNICODE);
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

    // 5. Association & Palette des Tarifs de l'événement
    $palette_tarifs = [
        0 => '#f59e0b', // Or / Ambre prestige (VVIP / VVVP / Carré Or)
        1 => '#FF4A0D', // Orange signature TikeWA (VIP / Honneur)
        2 => '#0d9488', // Émeraude identitaire suisse (STANDARD / Parterre)
        3 => '#6366f1', // Indigo / Bleu moderne (Économique / Étudiant / Bronze)
        4 => '#8b5cf6', // Violet
        5 => '#3b82f6', // Azur
    ];
    if (!empty($ticket_types)) {
        foreach ($ticket_types as $idx => &$tt) {
            $tt['couleur'] = $palette_tarifs[$idx % count($palette_tarifs)];
        }
        unset($tt);
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
    // Distribution équilibrée garantissant des places pour TOUS les tarifs de l'événement
    $seats_3d = [];
    $seat_id_counter = 1;
    $num_t = count($ticket_types);

    foreach ($zones as $z) {
        $z_id = $z['id'];
        $z_name = $z['nom_zone'];
        $z_color = $z['couleur'] ?: '#0d9488';
        $z_elev = (int)($z['elevation_3d'] ?? 0);
        $z_pos = $z['position_3d'] ?? 'centre';

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

            // Détermination du tarif spécifique pour cette rangée
            if ($num_t > 0) {
                if ($num_t === 1) {
                    $matched_tt = $ticket_types[0];
                } else {
                    $z_name_lower = mb_strtolower($z_name);
                    $matched_tt = null;

                    // A. Détection prioritaire par mot-clé de zone
                    if (preg_match('/\b(vvip|vvvp|loge|loges|prestige|or|carré or|vip prestige)\b/ui', $z_name_lower)) {
                        $matched_tt = $ticket_types[0];
                    } elseif (preg_match('/\b(vip|honneur|tribune officielle|balcon vip)\b/ui', $z_name_lower) && !preg_match('/\b(vvip|vvvp)\b/ui', $z_name_lower)) {
                        $matched_tt = ($num_t >= 3 && isset($ticket_types[1])) ? $ticket_types[1] : $ticket_types[0];
                    } elseif (preg_match('/\b(standard|populaire|gradin|gradins|pelouse|lateral|latéraux|virage|virages)\b/ui', $z_name_lower)) {
                        $matched_tt = $ticket_types[$num_t - 1];
                    }

                    // B. Partitionnement étagé des rangées selon la proximité de la scène
                    if (!$matched_tt) {
                        $fraction = $r / max(1, $nb_rows);
                        $t_index = (int) floor($fraction * $num_t);
                        $t_index = max(0, min($num_t - 1, $t_index));
                        $matched_tt = $ticket_types[$t_index];
                    }
                }

                $seat_ticket_id = $matched_tt['id'];
                $seat_ticket_nom = $matched_tt['nom'];
                $seat_prix = (float)$matched_tt['prix'];
                $seat_frais = (float)(!empty($matched_tt['frais_place']) && (float)$matched_tt['frais_place'] > 0 ? $matched_tt['frais_place'] : 1000);
                $seat_color = $matched_tt['couleur'] ?? $z_color;
            } else {
                // Mode studio admin sans événement
                $seat_ticket_id = $z_id;
                $seat_ticket_nom = $z_name;
                $seat_prix = (float)($z['tarif_indicatif'] > 0 ? $z['tarif_indicatif'] : 10000);
                $seat_frais = 1000;
                $seat_color = $z_color;
            }

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
                    'zone_color' => $seat_color,
                    'elevation' => $z_elev,
                    'position_type' => $z_pos,
                    'x' => round($x_offset, 2),
                    'y' => round($y_offset, 2),
                    'z' => round($z_depth, 2),
                    'ticket_type_id' => $seat_ticket_id,
                    'ticket_nom' => $seat_ticket_nom,
                    'prix' => $seat_prix,
                    'frais_place' => $seat_frais,
                    'statut' => $is_cur ? 'actuelle' : ($is_taken ? 'reserve' : 'libre'),
                    'is_current' => $is_cur
                ];

                $seat_id_counter++;
            }
        }
    }

    // Garantie absolue : Si un tarif n'a reçu aucune place, lui allouer une rangée
    if (!empty($ticket_types) && count($ticket_types) > 1) {
        $seats_by_tt = [];
        foreach ($seats_3d as $idx => $s) {
            $seats_by_tt[$s['ticket_type_id']][] = $idx;
        }
        foreach ($ticket_types as $tt) {
            $tid = $tt['id'];
            if (empty($seats_by_tt[$tid])) {
                $max_tid = null;
                $max_count = 0;
                foreach ($seats_by_tt as $k => $indices) {
                    if (count($indices) > $max_count) {
                        $max_count = count($indices);
                        $max_tid = $k;
                    }
                }
                if ($max_tid && $max_count > 10) {
                    $to_reassign = array_splice($seats_by_tt[$max_tid], -10);
                    foreach ($to_reassign as $s_idx) {
                        $seats_3d[$s_idx]['ticket_type_id'] = $tt['id'];
                        $seats_3d[$s_idx]['ticket_nom'] = $tt['nom'];
                        $seats_3d[$s_idx]['prix'] = (float)$tt['prix'];
                        $seats_3d[$s_idx]['frais_place'] = (float)(!empty($tt['frais_place']) ? $tt['frais_place'] : 1000);
                        $seats_3d[$s_idx]['zone_color'] = $tt['couleur'];
                    }
                    $seats_by_tt[$tid] = $to_reassign;
                }
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
        'message' => friendly_db_error($e, 'salle', "Impossible de charger les données 3D de la salle.")
    ]);
}
