<?php
// ==============================================================================
// TERMINAL DE VENTE RAPIDE GUICHET / GARE ROUTIÈRE (pos/index.php)
// Interface POS Ergonomique & Standard Typographique Suisse Müller-Brockmann
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification de connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ../connexion.php?redirect=pos');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'] ?? 'Guichetier';

// 1. Détermination de la gare et du guichet
$stations = $pdo->query("SELECT * FROM stations ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);

$station_id = filter_input(INPUT_GET, 'station_id', FILTER_VALIDATE_INT);
if (!$station_id && !empty($stations)) {
    $station_id = (int) $stations[0]['id'];
}

$current_station = null;
foreach ($stations as $st) {
    if ((int)$st['id'] === $station_id) {
        $current_station = $st;
        break;
    }
}

// Guichets de cette gare
$terminals = [];
if ($station_id) {
    $stmt_term = $pdo->prepare("SELECT * FROM pos_terminals WHERE station_id = ? ORDER BY id ASC");
    $stmt_term->execute([$station_id]);
    $terminals = $stmt_term->fetchAll(PDO::FETCH_ASSOC);
}
$current_terminal = !empty($terminals) ? $terminals[0] : null;

// 2. Vérification de session de caisse ouverte
$open_session = null;
if ($current_terminal) {
    $stmt_sess = $pdo->prepare("
        SELECT * FROM pos_sessions 
        WHERE pos_terminal_id = ? AND agent_id = ? AND statut = 'ouverte' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_sess->execute([$current_terminal['id'], $user_id]);
    $open_session = $stmt_sess->fetch(PDO::FETCH_ASSOC);
}

// 3. Événements disponibles pour la vente au guichet (publics et actifs)
$events_for_sale = $pdo->query("
    SELECT id, nom, date_evenement, heure, lieu, image 
    FROM events 
    WHERE statut = 'actif' AND (visibilite IS NULL OR visibilite = 'public')
    ORDER BY date_evenement ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Types de billets pour chaque événement
$tickets_by_event = [];
if (!empty($events_for_sale)) {
    $ev_ids = array_column($events_for_sale, 'id');
    $in_ev = implode(',', array_map('intval', $ev_ids));
    $stmt_tks = $pdo->query("SELECT * FROM ticket_types WHERE event_id IN ($in_ev) ORDER BY prix ASC");
    foreach ($stmt_tks->fetchAll(PDO::FETCH_ASSOC) as $tk) {
        $tickets_by_event[(int)$tk['event_id']][] = $tk;
    }
}

$is_station_suspended = ($current_station && $current_station['statut'] !== 'actif');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Terminal POS Guichet — Tike WA</title>
    
    <!-- Typographie Grotesque & Monospace technique (Style Suisse) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        :root {
            --pos-bg: #F8FAFC;
            --pos-card: #FFFFFF;
            --pos-text: #0F172A;
            --pos-muted: #64748B;
            --pos-border: #E2E8F0;
            --pos-orange: #FF4A0D;
            --pos-green: #059669;
            --pos-red: #DC2626;
            --font-sans: 'Inter', sans-serif;
            --font-display: 'Outfit', sans-serif;
            --font-mono: 'Space Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-sans);
            background: var(--pos-bg);
            color: var(--pos-text);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Topbar Guichet */
        .pos-header {
            background: #0F172A;
            color: #FFFFFF;
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-shrink: 0;
            border-bottom: 2px solid var(--pos-orange);
        }

        .pos-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pos-station-selector select {
            background: rgba(255, 255, 255, 0.1);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        /* Alerte Suspension */
        .pos-suspended-banner {
            background: #FEF2F2;
            border-bottom: 2px solid #DC2626;
            color: #991B1B;
            padding: 0.85rem 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.95rem;
        }

        /* Layout Écran Vente */
        .pos-main-layout {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            flex: 1;
            overflow: hidden;
        }

        @media (max-width: 900px) {
            .pos-main-layout {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }
        }

        .pos-catalog-pane {
            padding: 1.25rem;
            overflow-y: auto;
            border-right: 1px solid var(--pos-border);
        }

        .pos-checkout-pane {
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
            padding: 1.25rem;
            overflow-y: auto;
        }

        /* Tuiles Événements & Billets */
        .pos-event-card {
            background: #FFFFFF;
            border: 1.5px solid var(--pos-border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.15s ease;
        }
        .pos-event-card.selected {
            border-color: var(--pos-orange);
            box-shadow: 0 4px 14px rgba(255, 74, 13, 0.15);
        }

        .pos-ticket-btn {
            background: #F8FAFC;
            border: 1px solid var(--pos-border);
            border-radius: 8px;
            padding: 0.65rem 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            width: 100%;
            margin-top: 6px;
            transition: all 0.15s ease;
            text-align: left;
        }
        .pos-ticket-btn:hover:not(:disabled) {
            border-color: var(--pos-orange);
            background: #FFF2ED;
        }
        .pos-ticket-btn.active {
            border-color: var(--pos-orange);
            background: #FFF2ED;
            outline: 2px solid var(--pos-orange);
        }

        /* Boutons Rapides de Montant */
        .pos-quick-tender {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin: 8px 0;
        }
        .tender-btn {
            background: #F1F5F9;
            border: 1px solid #CBD5E1;
            padding: 8px;
            border-radius: 6px;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
        }
        .tender-btn:hover {
            background: #E2E8F0;
        }

        /* Reçu Thermique Imprimable */
        @media print {
            body * { visibility: hidden; }
            #printableReceipt, #printableReceipt * { visibility: visible; }
            #printableReceipt {
                position: fixed;
                left: 0;
                top: 0;
                width: 80mm;
                padding: 5mm;
                font-family: monospace;
                font-size: 12px;
                color: #000000;
                background: #ffffff;
            }
        }
    </style>
</head>
<body>

    <!-- Entête POS -->
    <header class="pos-header">
        <div class="pos-header-left">
            <span style="font-family: var(--font-display); font-size: 1.25rem; font-weight: 900; color: var(--pos-orange); letter-spacing: -0.5px;">
                Tike WA <span style="font-size: 0.8rem; color: #94A3B8; font-weight: 600;">• POS GUICHET</span>
            </span>

            <form method="GET" class="pos-station-selector">
                <select name="station_id" onchange="this.form.submit()">
                    <?php foreach ($stations as $st): ?>
                        <option value="<?php echo (int) $st['id']; ?>" <?php echo ((int)$st['id'] === $station_id) ? 'selected' : ''; ?>>
                            🏢 <?php echo htmlspecialchars($st['nom']); ?> (<?php echo htmlspecialchars($st['code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="text-align: right; font-size: 0.8rem;">
                <div style="font-weight: 700; color: #FFFFFF;"><?php echo htmlspecialchars($user_name); ?></div>
                <div style="font-family: var(--font-mono); color: #94A3B8; font-size: 0.72rem;">
                    <?php echo $current_terminal ? htmlspecialchars($current_terminal['nom_guichet']) : 'Guichet 1'; ?>
                </div>
            </div>

            <?php if ($open_session): ?>
                <button type="button" onclick="openCloseSessionModal()" 
                        style="background: #059669; color: #FFFFFF; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; cursor: pointer;">
                    <i class="fa-solid fa-lock"></i> Clôturer Caisse
                </button>
            <?php else: ?>
                <button type="button" onclick="openStartSessionModal()" 
                        style="background: var(--pos-orange); color: #FFFFFF; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; cursor: pointer;">
                    <i class="fa-solid fa-key"></i> Ouvrir Caisse
                </button>
            <?php endif; ?>

            <a href="../admin/gares.php" style="color: #94A3B8; text-decoration: none; font-size: 1.1rem;" title="Retour Administration">
                <i class="fa-solid fa-circle-xmark"></i>
            </a>
        </div>
    </header>

    <!-- Bannière si la gare est suspendue par l'administrateur -->
    <?php if ($is_station_suspended): ?>
        <div class="pos-suspended-banner">
            <div>
                <i class="fa-solid fa-ban" style="font-size: 1.2rem; margin-right: 8px;"></i>
                VENTES SUSPENDUES : Les opérations d'émission de billets pour cette gare routière sont actuellement arrêtées par l'administration.
            </div>
            <span style="font-family: var(--font-mono); font-size: 0.8rem; background: #B91C1C; color: #FFFFFF; padding: 3px 8px; border-radius: 4px;">
                CODE : FLUX_BLOQUÉ
            </span>
        </div>
    <?php endif; ?>

    <!-- Layout Principal POS -->
    <main class="pos-main-layout">
        <!-- Volet Gauche : Événements & Formules -->
        <section class="pos-catalog-pane">
            <h2 style="font-size: 1rem; font-weight: 800; text-transform: uppercase; color: var(--pos-muted); letter-spacing: 0.5px; margin-bottom: 1rem;">
                <i class="fa-solid fa-calendar-days" style="color: var(--pos-orange);"></i> Sélection de l'Événement & Billets
            </h2>

            <?php if (empty($events_for_sale)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--pos-muted);">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 0.5rem; display: block;"></i>
                    Aucun événement actif disponible à la vente physique.
                </div>
            <?php else: ?>
                <?php foreach ($events_for_sale as $ev): 
                    $tks = $tickets_by_event[(int)$ev['id']] ?? [];
                    ?>
                    <div class="pos-event-card" id="ev-card-<?php echo (int) $ev['id']; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                            <div>
                                <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--pos-text); margin: 0 0 4px;">
                                    <?php echo htmlspecialchars($ev['nom']); ?>
                                </h3>
                                <small style="font-family: var(--font-mono); color: var(--pos-muted); font-size: 0.78rem;">
                                    <i class="fa-regular fa-clock"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?> à <?php echo substr($ev['heure'], 0, 5); ?> · <?php echo htmlspecialchars($ev['lieu']); ?>
                                </small>
                            </div>
                        </div>

                        <!-- Formules de billets -->
                        <div style="margin-top: 8px;">
                            <?php if (empty($tks)): ?>
                                <small style="color: var(--pos-muted); font-style: italic;">Aucun tarif paramétré.</small>
                            <?php else: ?>
                                <?php foreach ($tks as $tk): 
                                    $dispo = max(0, (int)$tk['quantite'] - (int)$tk['quantite_vendue']);
                                    ?>
                                    <button type="button" class="pos-ticket-btn" 
                                            onclick="selectTicketForSale(<?php echo (int)$ev['id']; ?>, '<?php echo htmlspecialchars(addslashes($ev['nom'])); ?>', <?php echo (int)$tk['id']; ?>, '<?php echo htmlspecialchars(addslashes($tk['nom'])); ?>', <?php echo (float)$tk['prix']; ?>, <?php echo $dispo; ?>)"
                                            <?php echo ($dispo <= 0 || $is_station_suspended) ? 'disabled style="opacity: 0.5;"' : ''; ?>>
                                        <div>
                                            <strong style="display: block; font-size: 0.88rem;"><?php echo htmlspecialchars($tk['nom']); ?></strong>
                                            <small style="color: var(--pos-muted); font-size: 0.75rem;">Dispo: <?php echo $dispo; ?> place(s)</small>
                                        </div>
                                        <span style="font-family: var(--font-mono); font-size: 1rem; font-weight: 800; color: var(--pos-orange);">
                                            <?php echo number_format((float)$tk['prix'], 0, ',', ' '); ?> F
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Volet Droit : Panier & Encaissement Rapide -->
        <section class="pos-checkout-pane">
            <h2 style="font-size: 1rem; font-weight: 800; text-transform: uppercase; color: var(--pos-muted); letter-spacing: 0.5px; margin-bottom: 1rem;">
                <i class="fa-solid fa-cart-shopping" style="color: var(--pos-orange);"></i> Panier & Encaissement Guichet
            </h2>

            <!-- Résumé sélection -->
            <div id="posCartEmpty" style="text-align: center; padding: 2rem 1rem; color: var(--pos-muted); background: #F8FAFC; border-radius: 8px; border: 1.5px dashed var(--pos-border); margin-bottom: 1rem;">
                <i class="fa-solid fa-hand-pointer" style="font-size: 2rem; color: #CBD5E1; margin-bottom: 6px; display: block;"></i>
                Cliquez sur un type de billet à gauche pour démarrer la vente.
            </div>

            <div id="posCartFilled" style="display: none;">
                <div style="background: #F8FAFC; border: 1px solid var(--pos-border); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-size: 0.8rem; color: var(--pos-muted); font-weight: 700; text-transform: uppercase;" id="cartEventName">Événement</div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: var(--pos-text);" id="cartTicketName">Billet Standard</div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                        <span style="font-size: 0.85rem; color: var(--pos-muted);">Prix Unitaire :</span>
                        <strong style="font-family: var(--font-mono); font-size: 1rem;" id="cartUnitPrice">0 F</strong>
                    </div>
                </div>

                <!-- Sélecteur Quantité Rapide -->
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 4px;">Quantité :</label>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="tender-btn" onclick="setQty(1)">1</button>
                        <button type="button" class="tender-btn" onclick="setQty(2)">2</button>
                        <button type="button" class="tender-btn" onclick="setQty(5)">5</button>
                        <button type="button" class="tender-btn" onclick="setQty(10)">10</button>
                        <input type="number" id="pos_quantite" min="1" max="50" value="1" onchange="recalculatePosTotal()"
                               style="width: 70px; text-align: center; font-family: var(--font-mono); font-weight: 800; font-size: 1rem; border-radius: 6px; border: 1px solid var(--pos-border);">
                    </div>
                </div>

                <!-- Coordonnées Client Comptoir -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 2px;">Nom du Client</label>
                        <input type="text" id="pos_client_nom" value="Client Comptoir"
                               style="width: 100%; padding: 6px 8px; border-radius: 6px; border: 1px solid var(--pos-border); font-size: 0.85rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 2px;">Téléphone (Facultatif)</label>
                        <input type="tel" id="pos_client_tel" placeholder="Ex: 0700000000"
                               style="width: 100%; padding: 6px 8px; border-radius: 6px; border: 1px solid var(--pos-border); font-size: 0.85rem; font-family: var(--font-mono);">
                    </div>
                </div>

                <!-- Mode Paiement & Calcul de Monnaie -->
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 4px;">Mode de Règlement :</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                        <button type="button" id="btnPayEspeces" class="tender-btn" style="background: #0F172A; color: #FFFFFF;" onclick="selectPaymentMode('especes')">
                            <i class="fa-solid fa-money-bill-wave"></i> Espèces (Cash)
                        </button>
                        <button type="button" id="btnPayMobile" class="tender-btn" onclick="selectPaymentMode('mobile_money')">
                            <i class="fa-solid fa-mobile-screen"></i> Mobile Money
                        </button>
                    </div>

                    <div id="cashTenderBox">
                        <label style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 2px;">Montant Espèces Reçu :</label>
                        <input type="number" id="pos_montant_recu" placeholder="0" oninput="calculateChange()"
                               style="width: 100%; padding: 8px 10px; font-family: var(--font-mono); font-size: 1.1rem; font-weight: 800; border-radius: 6px; border: 1px solid var(--pos-border);">
                        
                        <div class="pos-quick-tender">
                            <button type="button" class="tender-btn" onclick="setExactTender()">Exact</button>
                            <button type="button" class="tender-btn" onclick="addTender(2000)">2 000</button>
                            <button type="button" class="tender-btn" onclick="addTender(5000)">5 000</button>
                            <button type="button" class="tender-btn" onclick="addTender(10000)">10 000</button>
                        </div>

                        <!-- Monnaie à rendre -->
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 8px 12px; border-radius: 6px; margin-top: 6px;">
                            <span style="font-size: 0.8rem; font-weight: 700; color: #065F46;">Monnaie à Rendre :</span>
                            <strong style="font-family: var(--font-mono); font-size: 1.2rem; color: #059669;" id="posChangeDisplay">0 F</strong>
                        </div>
                    </div>
                </div>

                <!-- Total & Bouton d'émission -->
                <div style="border-top: 1.5px dashed var(--pos-border); padding-top: 1rem; margin-top: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: var(--pos-muted);">Total Net :</span>
                        <strong style="font-family: var(--font-mono); font-size: 1.7rem; font-weight: 900; color: var(--pos-orange);" id="posTotalDisplay">0 FCFA</strong>
                    </div>

                    <button type="button" id="btnEmitTickets" onclick="submitPosTicketEmission()"
                            <?php echo ($is_station_suspended) ? 'disabled' : ''; ?>
                            style="width: 100%; background: var(--pos-orange); color: #FFFFFF; border: none; padding: 1rem; border-radius: 8px; font-weight: 800; font-size: 1.05rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fa-solid fa-print"></i> Émettre et Imprimer le Billet
                    </button>
                </div>
            </div>
        </section>
    </main>

    <!-- MODAL OUVERTURE DE CAISSE -->
    <div id="startSessionModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #FFFFFF; width: 100%; max-width: 420px; border-radius: 12px; padding: 2rem; position: relative;">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--pos-text); margin-bottom: 4px;">
                <i class="fa-solid fa-key" style="color: var(--pos-orange);"></i> Ouverture de Caisse Guichet
            </h3>
            <p style="font-size: 0.85rem; color: var(--pos-muted); margin-bottom: 1.25rem;">
                Indiquez le fond de caisse initial disponible dans votre tiroir au début de votre service.
            </p>
            <form onsubmit="handleStartSession(event)">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 4px;">Fond de Caisse Initial (FCFA) *</label>
                <input type="number" id="fond_caisse_input" value="0" min="0" required
                       style="width: 100%; padding: 0.75rem; font-family: var(--font-mono); font-size: 1.2rem; font-weight: 800; border-radius: 6px; border: 1.5px solid var(--pos-border); margin-bottom: 1.25rem;">
                
                <button type="submit" id="btnStartSessionSubmit"
                        style="width: 100%; background: var(--pos-orange); color: #FFFFFF; border: none; padding: 0.85rem; border-radius: 8px; font-weight: 800; font-size: 0.95rem; cursor: pointer;">
                    Valider l'Ouverture de Caisse
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL CLÔTURE DE CAISSE -->
    <div id="closeSessionModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #FFFFFF; width: 100%; max-width: 440px; border-radius: 12px; padding: 2rem; position: relative;">
            <button type="button" onclick="closeCloseSessionModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.2rem; color: var(--pos-muted); cursor: pointer;">&times;</button>
            <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--pos-text); margin-bottom: 4px;">
                <i class="fa-solid fa-lock" style="color: var(--pos-green);"></i> Clôture & Arrêté de Caisse
            </h3>
            <p style="font-size: 0.85rem; color: var(--pos-muted); margin-bottom: 1.25rem;">
                Comptez les espèces réelles en caisse pour enregistrer la clôture et calculer l'écart éventuel.
            </p>
            <form onsubmit="handleCloseSession(event)">
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 4px;">Espèces Réelles Comptées (FCFA) *</label>
                    <input type="number" id="montant_reel_input" required min="0" placeholder="0"
                           style="width: 100%; padding: 0.75rem; font-family: var(--font-mono); font-size: 1.2rem; font-weight: 800; border-radius: 6px; border: 1.5px solid var(--pos-border);">
                </div>
                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 4px;">Notes de Clôture (Facultatif)</label>
                    <textarea id="notes_cloture_input" placeholder="Observations, justificatifs..." style="width: 100%; padding: 6px; border-radius: 6px; border: 1px solid var(--pos-border); font-size: 0.85rem;"></textarea>
                </div>
                <button type="submit" id="btnCloseSessionSubmit"
                        style="width: 100%; background: #0F172A; color: #FFFFFF; border: none; padding: 0.85rem; border-radius: 8px; font-weight: 800; font-size: 0.95rem; cursor: pointer;">
                    Enregistrer la Clôture Définitive
                </button>
            </form>
        </div>
    </div>

    <!-- ZONE DE REÇU IMPRIMABLE (Format Ticket Thermique 80mm) -->
    <div id="printableReceipt" style="display: none;">
        <div style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 6px; margin-bottom: 8px;">
            <h2 style="font-size: 16px; margin: 0 0 2px;">TIKE WA</h2>
            <div style="font-size: 11px;">BILLETTERIE OFFICIELLE</div>
            <div style="font-size: 10px;" id="receiptStationName">Gare Routière</div>
            <div style="font-size: 10px;" id="receiptDate">Date: </div>
        </div>
        <div style="font-size: 11px; margin-bottom: 6px;">
            <div><strong>Commande :</strong> <span id="receiptNumCmd"></span></div>
            <div><strong>Client :</strong> <span id="receiptClient"></span></div>
            <div><strong>Événement :</strong> <span id="receiptEvent"></span></div>
            <div><strong>Billet :</strong> <span id="receiptTicketType"></span></div>
            <div><strong>Quantité :</strong> <span id="receiptQty"></span></div>
            <div><strong>Règlement :</strong> <span id="receiptPayMode"></span></div>
        </div>
        <div style="border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 6px 0; margin: 6px 0; display: flex; justify-content: space-between; font-weight: bold; font-size: 13px;">
            <span>TOTAL :</span>
            <span id="receiptTotal">0 F</span>
        </div>
        <div id="receiptTicketsCodes" style="text-align: center; margin: 10px 0; font-family: monospace; font-size: 12px; font-weight: bold;"></div>
        <div style="text-align: center; font-size: 9px; margin-top: 8px;">
            Billet non remboursable.<br>Merci de votre visite sur notre réseau !
        </div>
    </div>

<script>
    const stationId = <?php echo (int) $station_id; ?>;
    const terminalId = <?php echo $current_terminal ? (int)$current_terminal['id'] : 0; ?>;
    let currentSessionId = <?php echo $open_session ? (int)$open_session['id'] : 'null'; ?>;
    const isSuspended = <?php echo $is_station_suspended ? 'true' : 'false'; ?>;

    let selectedEvent = null;
    let selectedTicket = null;
    let paymentMode = 'especes';

    function selectTicketForSale(evId, evNom, tkId, tkNom, prix, dispo) {
        if (isSuspended) {
            alert("Les ventes sont actuellement suspendues pour cette gare.");
            return;
        }

        selectedEvent = { id: evId, nom: evNom };
        selectedTicket = { id: tkId, nom: tkNom, prix: prix, dispo: dispo };

        // Highlight
        document.querySelectorAll('.pos-ticket-btn').forEach(b => b.classList.remove('active'));
        event.currentTarget.classList.add('active');

        document.getElementById('posCartEmpty').style.display = 'none';
        document.getElementById('posCartFilled').style.display = 'block';

        document.getElementById('cartEventName').innerText = evNom;
        document.getElementById('cartTicketName').innerText = tkNom;
        document.getElementById('cartUnitPrice').innerText = prix.toLocaleString('fr-FR') + ' F';

        setQty(1);
    }

    function setQty(q) {
        document.getElementById('pos_quantite').value = q;
        recalculatePosTotal();
    }

    function recalculatePosTotal() {
        if (!selectedTicket) return;
        const q = parseInt(document.getElementById('pos_quantite').value) || 1;
        const total = selectedTicket.prix * q;
        document.getElementById('posTotalDisplay').innerText = total.toLocaleString('fr-FR') + ' FCFA';
        calculateChange();
    }

    function selectPaymentMode(mode) {
        paymentMode = mode;
        const btnEsp = document.getElementById('btnPayEspeces');
        const btnMob = document.getElementById('btnPayMobile');
        const cashBox = document.getElementById('cashTenderBox');

        if (mode === 'especes') {
            btnEsp.style.background = '#0F172A';
            btnEsp.style.color = '#FFFFFF';
            btnMob.style.background = '#F1F5F9';
            btnMob.style.color = '#0F172A';
            cashBox.style.display = 'block';
        } else {
            btnMob.style.background = '#0F172A';
            btnMob.style.color = '#FFFFFF';
            btnEsp.style.background = '#F1F5F9';
            btnEsp.style.color = '#0F172A';
            cashBox.style.display = 'none';
        }
    }

    function setExactTender() {
        if (!selectedTicket) return;
        const q = parseInt(document.getElementById('pos_quantite').value) || 1;
        const total = selectedTicket.prix * q;
        document.getElementById('pos_montant_recu').value = total;
        calculateChange();
    }

    function addTender(val) {
        document.getElementById('pos_montant_recu').value = val;
        calculateChange();
    }

    function calculateChange() {
        if (!selectedTicket) return;
        const q = parseInt(document.getElementById('pos_quantite').value) || 1;
        const total = selectedTicket.prix * q;
        const recu = parseFloat(document.getElementById('pos_montant_recu').value) || 0;
        const change = Math.max(0, recu - total);
        document.getElementById('posChangeDisplay').innerText = change.toLocaleString('fr-FR') + ' F';
    }

    // Émission du billet
    async function submitPosTicketEmission() {
        if (isSuspended) {
            alert("Opération impossible : point de vente suspendu.");
            return;
        }

        if (!selectedTicket || !selectedEvent) {
            alert("Veuillez sélectionner un billet.");
            return;
        }

        const q = parseInt(document.getElementById('pos_quantite').value) || 1;
        const clientNom = document.getElementById('pos_client_nom').value.trim() || 'Client Comptoir';
        const clientTel = document.getElementById('pos_client_tel').value.trim();
        const montantRecu = parseFloat(document.getElementById('pos_montant_recu').value) || 0;
        const total = selectedTicket.prix * q;

        if (paymentMode === 'especes' && montantRecu < total && montantRecu > 0) {
            alert("Le montant en espèces reçu est inférieur au total.");
            return;
        }

        const btn = document.getElementById('btnEmitTickets');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Émission en cours...';

        const formData = new FormData();
        formData.append('action', 'emit_tickets');
        formData.append('station_id', stationId);
        formData.append('session_id', currentSessionId || '');
        formData.append('event_id', selectedEvent.id);
        formData.append('ticket_type_id', selectedTicket.id);
        formData.append('quantite', q);
        formData.append('client_nom', clientNom);
        formData.append('client_telephone', clientTel);
        formData.append('mode_paiement', paymentMode);
        formData.append('montant_recu', montantRecu);

        try {
            const res = await fetch('../ajax/pos_operations.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                alert(data.message || "Erreur lors de l'émission.");
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-print"></i> Émettre et Imprimer le Billet';
                return;
            }

            // Préparation du reçu thermique
            document.getElementById('receiptStationName').innerText = "Gare : " + "<?php echo addslashes($current_station['nom'] ?? 'Guichet'); ?>";
            document.getElementById('receiptDate').innerText = "Date: " + new Date().toLocaleString('fr-FR');
            document.getElementById('receiptNumCmd').innerText = data.numero_commande;
            document.getElementById('receiptClient').innerText = data.client_nom;
            document.getElementById('receiptEvent').innerText = selectedEvent.nom;
            document.getElementById('receiptTicketType').innerText = selectedTicket.nom;
            document.getElementById('receiptQty').innerText = data.quantite;
            document.getElementById('receiptPayMode').innerText = (data.mode_paiement === 'especes' ? 'ESPÈCES' : 'MOBILE MONEY');
            document.getElementById('receiptTotal').innerText = data.total.toLocaleString('fr-FR') + ' FCFA';

            let codesHtml = '';
            data.tickets.forEach(t => {
                codesHtml += `<div>★ ${t.code_unique} ★</div>`;
            });
            document.getElementById('receiptTicketsCodes').innerHTML = codesHtml;

            // Déclenchement impression
            window.print();

            // Réinitialisation du formulaire
            alert("Billet(s) émis avec succès ! N° " + data.numero_commande);
            window.location.reload();

        } catch (err) {
            alert("Erreur de connexion au serveur.");
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-print"></i> Émettre et Imprimer le Billet';
        }
    }

    // Gestion Session
    function openStartSessionModal() {
        document.getElementById('startSessionModal').style.display = 'flex';
    }
    async function handleStartSession(e) {
        e.preventDefault();
        const fond = parseFloat(document.getElementById('fond_caisse_input').value) || 0;
        const formData = new FormData();
        formData.append('action', 'open_session');
        formData.append('pos_terminal_id', terminalId);
        formData.append('fond_caisse_ouverture', fond);

        const res = await fetch('../ajax/pos_operations.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message);
        }
    }

    function openCloseSessionModal() {
        document.getElementById('closeSessionModal').style.display = 'flex';
    }
    function closeCloseSessionModal() {
        document.getElementById('closeSessionModal').style.display = 'none';
    }
    async function handleCloseSession(e) {
        e.preventDefault();
        const montant = parseFloat(document.getElementById('montant_reel_input').value) || 0;
        const notes = document.getElementById('notes_cloture_input').value;

        const formData = new FormData();
        formData.append('action', 'close_session');
        formData.append('session_id', currentSessionId);
        formData.append('montant_reel_fermeture', montant);
        formData.append('notes_cloture', notes);

        const res = await fetch('../ajax/pos_operations.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert(`Caisse clôturée ! Théorique: ${data.theorique.toLocaleString()} F | Compté: ${data.montant_reel.toLocaleString()} F | Écart: ${data.ecart.toLocaleString()} F`);
            window.location.reload();
        } else {
            alert(data.message);
        }
    }
</script>

</body>
</html>
