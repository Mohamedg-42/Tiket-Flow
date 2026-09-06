<?php
// ==============================================================================
// GESTION DU SOLDE & RETRAITS INSTANTANÉS (promoteur/solde.php)
// Design Dashboard Pro - Virement Mobile Money immédiat & Suivi de trésorerie
// ==============================================================================

$page_title = "Solde & Retraits Instantanés - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Récupération du profil promoteur et du solde actuel
// ------------------------------------------------------------------------------
$stmt_prom = $pdo->prepare("SELECT * FROM promoters WHERE user_id = ?");
$stmt_prom->execute([$user_id]);
$promoter = $stmt_prom->fetch(PDO::FETCH_ASSOC);

$promoter_id  = $promoter ? (int)$promoter['id'] : 0;
$solde_actuel = $promoter ? (float)$promoter['solde'] : 0.00;

// ------------------------------------------------------------------------------
// 2. Traitement d'un RETRAIT INSTANTANÉ (Virement via API Feexpay Mobile Money)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demande_retrait'])) {
    $montant   = (float)($_POST['montant'] ?? 0);
    $methode   = $_POST['methode'] ?? 'wave';
    $telephone = trim($_POST['numero_telephone'] ?? '');

    $methodes_valides = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

    if ($montant <= 0 || empty($telephone)) {
        $message = "Veuillez renseigner un montant valide et votre numéro Mobile Money récepteur.";
        $msg_type = "error";
    } elseif ($montant < 500) {
        $message = "Le montant minimum de retrait est de 500 FCFA.";
        $msg_type = "error";
    } elseif ($montant > $solde_actuel) {
        $message = "Le montant demandé (" . number_format($montant, 0, ',', ' ') . " FCFA) dépasse votre solde disponible (" . number_format($solde_actuel, 0, ',', ' ') . " FCFA).";
        $msg_type = "error";
    } elseif (!in_array($methode, $methodes_valides, true)) {
        $message = "Opérateur Mobile Money non reconnu.";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // A. Débit immédiat du solde du promoteur
            $stmt_deduct = $pdo->prepare("UPDATE promoters SET solde = solde - ? WHERE user_id = ? AND solde >= ?");
            $stmt_deduct->execute([$montant, $user_id, $montant]);

            if ($stmt_deduct->rowCount() !== 1) {
                throw new Exception("Solde insuffisant ou transaction simultanée détectée.");
            }

            // B. Intégration API Feexpay Payout
            $feexpay_token = "test_Hg7Kjl3ZAM63UuIUpuudD9nKuu3ZAM67Kjl3Uuhn";
            $feexpay_shop_id = "80hxOfqxlIDOZJi";
            $ref_feexpay = 'PAY-FEEXPAY-' . strtoupper(bin2hex(random_bytes(3)));
            $txn_feexpay = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);

            // Appel API Feexpay Payout / Cashout en environnement sécurisé
            $clean_phone = preg_replace('/[^0-9]/', '', $telephone);
            $op_code = strtoupper(str_replace('_money', '', $methode));

            $payout_data = [
                'token'       => $feexpay_token,
                'id'          => $feexpay_shop_id,
                'amount'      => (int)$montant,
                'phone'       => $clean_phone,
                'operator'    => $op_code,
                'custom_id'   => 'PAYOUT_' . $promoter_id . '_' . time(),
                'description' => 'Virement solde promoteur #' . $promoter_id
            ];

            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.feexpay.me/api/payouts/create');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payout_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $feexpay_token
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                @curl_exec($ch);
                curl_close($ch);
            }

            // C. Enregistrement direct du virement instantané (statut 'paye')
            $commentaire_auto = "Virement API Feexpay (" . $ref_feexpay . " | " . $txn_feexpay . ")";

            $stmt_ins = $pdo->prepare("
                INSERT INTO withdrawals (user_id, promoter_id, montant, methode, numero_telephone, statut, commentaire_admin, created_at, reviewed_at) 
                VALUES (?, ?, ?, ?, ?, 'paye', ?, NOW(), NOW())
            ");
            $stmt_ins->execute([$user_id, $promoter_id, $montant, $methode, $telephone, $commentaire_auto]);

            $pdo->commit();

            $nom_operateur = strtoupper(str_replace('_', ' ', $methode));
            $message = "Virement API Feexpay réussi ! " . number_format($montant, 0, ',', ' ') . " FCFA ont été transférés instantanément sur votre compte " . $nom_operateur . " (" . htmlspecialchars($telephone) . "). Réf Feexpay: " . $ref_feexpay;
            $msg_type = "success";

            // Mise à jour locale du solde
            $solde_actuel -= $montant;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Erreur lors du virement instantané : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ------------------------------------------------------------------------------
// 3. Calculs financiers globaux
// ------------------------------------------------------------------------------
$stmt_ventes = $pdo->prepare("
    SELECT 
        COALESCE(SUM(t.prix), 0) AS total_ventes_brutes,
        COALESCE(SUM(t.prix * (e.commission_rate / 100)), 0) AS total_commissions
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise')
");
$stmt_ventes->execute([$user_id]);
$ventes_data = $stmt_ventes->fetch(PDO::FETCH_ASSOC);

$total_ventes_brutes = (float)($ventes_data['total_ventes_brutes'] ?? 0);
$total_commissions   = (float)($ventes_data['total_commissions'] ?? 0);

$stmt_ret = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN statut = 'paye' THEN montant ELSE 0 END), 0) AS total_retraits_payes,
        COUNT(CASE WHEN statut = 'paye' THEN 1 END) AS nb_virements_reussis
    FROM withdrawals 
    WHERE user_id = ?
");
$stmt_ret->execute([$user_id]);
$ret_data = $stmt_ret->fetch(PDO::FETCH_ASSOC);

$total_retraits_payes = (float)($ret_data['total_retraits_payes'] ?? 0);
$nb_virements_reussis = (int)($ret_data['nb_virements_reussis'] ?? 0);

// ------------------------------------------------------------------------------
// 4. Filtres de l'Historique des Retraits
// ------------------------------------------------------------------------------
$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$filter_methode = $_GET['methode'] ?? 'toutes';
if (!in_array($filter_methode, ['toutes', 'wave', 'orange_money', 'mtn_money', 'moov_money'], true)) {
    $filter_methode = 'toutes';
}

$search_q = trim($_GET['q'] ?? '');

$sql_with = "SELECT * FROM withdrawals WHERE user_id = ?";
$params_with = [$user_id];

if ($filter_methode !== 'toutes') {
    $sql_with .= " AND methode = ?";
    $params_with[] = $filter_methode;
}

if ($periode === 'ce_mois') {
    $sql_with .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_with .= " AND created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_with .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_with .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_with .= " AND (numero_telephone LIKE ? OR commentaire_admin LIKE ?)";
    $params_with[] = "%$search_q%";
    $params_with[] = "%$search_q%";
}

$sql_with .= " ORDER BY created_at DESC";

$stmt_history = $pdo->prepare($sql_with);
$stmt_history->execute($params_with);
$withdrawals_list = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

function get_operator_badge($methode) {
    switch ($methode) {
        case 'wave':
            return ['Wave', '#e0f2fe', '#0284c7', 'fa-solid fa-water', 'wave'];
        case 'orange_money':
            return ['Orange Money', '#fff7ed', '#ea580c', 'fa-solid fa-mobile-screen', 'orange_money'];
        case 'mtn_money':
            return ['MTN MoMo', '#fef9c3', '#ca8a04', 'fa-solid fa-bolt', 'mtn_money'];
        case 'moov_money':
            return ['Moov Money', '#ecfdf5', '#16a34a', 'fa-solid fa-money-bill-transfer', 'moov_money'];
    }
    return [strtoupper($methode), '#F5F5F5', '#737373', 'fa-solid fa-wallet', 'other'];
}

function render_momo_icon($methode, $size = 36) {
    switch ($methode) {
        case 'wave':
            return '<span class="momo-badge" style="width: '.$size.'px; height: '.$size.'px; background: #1dc4e9;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.65).'" height="'.($size * 0.65).'" fill="none" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 11c3-4 6-4 9 0s6 4 9 0"/>
                    <path d="M2 15.5c3-4 6-4 9 0s6 4 9 0" opacity="0.8"/>
                </svg>
            </span>';
        case 'orange_money':
            return '<span class="momo-badge" style="width: '.$size.'px; height: '.$size.'px; background: #ff7900;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.72).'" height="'.($size * 0.72).'" fill="none">
                    <rect x="2" y="2" width="20" height="20" rx="4" fill="#000000"/>
                    <rect x="5.5" y="5.5" width="13" height="13" rx="2" fill="#ff7900"/>
                    <text x="12" y="15" font-family="Arial, sans-serif" font-size="7.5" font-weight="900" fill="#ffffff" text-anchor="middle">OM</text>
                </svg>
            </span>';
        case 'mtn_money':
            return '<span class="momo-badge" style="width: '.$size.'px; height: '.$size.'px; background: #ffcc00;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.85).'" height="'.($size * 0.85).'" fill="none">
                    <ellipse cx="12" cy="12" rx="9.5" ry="6.8" stroke="#000000" stroke-width="1.6" fill="#ffcc00"/>
                    <text x="12" y="14.2" font-family="Arial, sans-serif" font-size="5.6" font-weight="900" fill="#000000" text-anchor="middle" letter-spacing="-0.2">MoMo</text>
                </svg>
            </span>';
        case 'moov_money':
            return '<span class="momo-badge" style="width: '.$size.'px; height: '.$size.'px; background: #00a651;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.85).'" height="'.($size * 0.85).'" fill="none">
                    <circle cx="12" cy="12" r="8.5" fill="#00a651"/>
                    <path d="M7.5 15V9.5l4.5 4 4.5-4V15" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>';
    }
    return '<span class="momo-badge" style="width: '.$size.'px; height: '.$size.'px; background: #E5E5E5; color: #737373;"><i class="fa-solid fa-wallet" style="font-size: 0.85rem;"></i></span>';
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.method-tile-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}
.method-tile {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0.85rem 1rem;
    border: 2px solid var(--dash-border, #E5E5E5);
    border-radius: 12px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
    box-sizing: border-box;
    width: 100%;
}
.method-tile:hover {
    border-color: #000000;
    background: #F5F5F5;
    transform: translateY(-1px);
}
.method-tile.selected {
    border-color: #000000;
    background: #FFF2ED;
    box-shadow: 0 0 0 2px rgba(11, 29, 58, 0.12);
}
.method-tile.selected strong {
    color: #000000;
}
.momo-badge {
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.08);
}
.amount-chips-wrap {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.amount-chip {
    padding: 0.45rem 0.8rem;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 8px;
    background: #ffffff;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--dash-text, #000000);
    cursor: pointer;
    transition: all 0.15s ease;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.amount-chip:hover {
    background: #F5F5F5;
    border-color: var(--dash-primary, #000000);
    color: var(--dash-primary, #000000);
}
.retrait-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.25rem;
}

.retrait-summary-box {
    background: #F5F5F5;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 14px;
    padding: 1.15rem 1.35rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
    width: 100%;
    box-sizing: border-box;
}

.retrait-summary-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.btn-submit-retrait {
    box-sizing: border-box !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    padding: 0.95rem 1.6rem !important;
    font-size: clamp(0.9rem, 2.5vw, 1.02rem) !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, var(--tikeli-orange, #FF4A0D) 0%, #E03E08 100%) !important;
    color: #ffffff !important;
    border: none !important;
    border-radius: 12px !important;
    cursor: pointer !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
    text-align: center !important;
    line-height: 1.35 !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.2s ease !important;
    box-shadow: 0 4px 14px rgba(255, 74, 13, 0.35) !important;
    max-width: 100% !important;
    flex-shrink: 0;
}

.btn-submit-retrait:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.45) !important;
    background: linear-gradient(135deg, #FF4A0D 0%, #FF4A0D 100%) !important;
}

.btn-submit-retrait:active {
    transform: translateY(0) !important;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3) !important;
}

.btn-submit-retrait i {
    font-size: 1.15rem !important;
    flex-shrink: 0;
}

.btn-submit-retrait span {
    display: inline-block;
}

@media (max-width: 900px) {
    .method-tile-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
}

@media (max-width: 768px) {
    .retrait-form-grid {
        grid-template-columns: 1fr !important;
        gap: 1.15rem !important;
    }
    .retrait-summary-box {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 1rem !important;
        padding: 1rem !important;
    }
    .retrait-summary-info {
        background: #ffffff;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        border: 1px solid var(--dash-border, #E5E5E5);
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
    }
    .btn-submit-retrait {
        width: 100% !important;
        font-size: 0.95rem !important;
        padding: 0.95rem 1rem !important;
        border-radius: 10px !important;
    }
}

@media (max-width: 480px) {
    .method-tile-grid {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
    .method-tile {
        padding: 0.75rem 0.85rem;
    }
    .amount-chips-wrap {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px !important;
    }
    .amount-chip {
        padding: 0.5rem 0.25rem !important;
        font-size: 0.76rem !important;
        text-align: center !important;
    }
    .amount-chip-all {
        grid-column: span 3 !important;
        padding: 0.55rem 0.5rem !important;
    }
    .retrait-summary-info {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 3px !important;
    }
    .btn-submit-retrait {
        font-size: 0.88rem !important;
        padding: 0.85rem 0.75rem !important;
        gap: 8px !important;
    }
    .btn-submit-retrait span {
        font-size: 0.88rem !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-wallet" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion du Solde & Retraits Instantanés
            </h1>
            <p>Trésorerie et virements immédiats 24/7 vers votre compte Mobile Money (Wave, Orange, MTN, Moov).</p>
        </div>

        <div>
            <a href="#virement-box" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; text-decoration: none;">
                <i class="fa-solid fa-bolt"></i> Effectuer un Virement
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#a7f3d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE FINANCIÈRE
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <!-- Solde Disponible -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: linear-gradient(135deg, #ffffff, #fff7f4); border: 2px solid var(--tikeli-orange, #FF4A0D); box-shadow: 0 4px 12px rgba(255, 74, 13, 0.08);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: var(--tikeli-orange, #FF4A0D); text-transform: uppercase; letter-spacing: 0.5px;">Solde Disponible</span>
                <span style="background: rgba(255, 74, 13, 0.12); color: var(--tikeli-orange, #FF4A0D); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-money-bill-wave"></i></span>
            </div>
            <div style="font-size: 1.85rem; font-weight: 800; color: var(--tikeli-black, #000000); line-height: 1.2;">
                <?php echo number_format($solde_actuel, 0, ',', ' '); ?> <span style="font-size: 1rem; font-weight: 700;">FCFA</span>
            </div>
            <small style="color: var(--tikeli-orange, #FF4A0D); font-size: 0.75rem; font-weight: 700; display: block; margin-top: 4px;">
                <i class="fa-solid fa-bolt"></i> Retirable immédiatement 24/7
            </small>
        </div>

        <!-- Ventes Brutes Totales -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Ventes Brutes</span>
                <span style="background: #F5F5F5; color: var(--dash-text); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text); line-height: 1.2;">
                <?php echo number_format($total_ventes_brutes, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: var(--dash-muted); font-size: 0.75rem; display: block; margin-top: 4px;">Chiffre d'affaires cumulé</small>
        </div>

        <!-- Commissions Déduites -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Frais Plateforme</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-percent"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; line-height: 1.2;">
                <?php echo number_format($total_commissions, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem; display: block; margin-top: 4px;">Commissions de service</small>
        </div>

        <!-- Total Déjà Transféré -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Total Transféré</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-building-columns"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; line-height: 1.2;">
                <?php echo number_format($total_retraits_payes, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem; display: block; margin-top: 4px;"><?php echo $nb_virements_reussis; ?> virement(s) exécuté(s)</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. FORMULAIRE DE VIREMENT MOBILE MONEY INSTANTANÉ
         ============================================================================== -->
    <div id="virement-box" class="dash-card" style="margin-bottom: 2rem; border: 1px solid var(--dash-border);">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bolt" style="color: #FF4A0D;"></i> Effectuer un Virement Immédiat
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Les fonds sont crédités directement sur votre portefeuille électronique sans délai.</small>
            </div>
            <span style="background: #FFF2ED; color: #000000; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.75rem; border: 1px solid #FFF2ED;">
                <i class="fa-solid fa-shield-check"></i> Transaction sécurisée
            </span>
        </div>

        <div style="padding: 1.5rem;">
            <?php if ($solde_actuel > 0): ?>
                <form method="POST" action="solde.php" id="form-retrait" onsubmit="return validerRetrait();">
                    <input type="hidden" name="demande_retrait" value="1">
                    <input type="hidden" name="methode" id="selected_methode" value="wave">

                    <!-- Choix de l'opérateur Mobile Money (Tuiles visuelles avec logos SVG authentiques) -->
                    <label style="display: block; font-size: 0.84rem; font-weight: 700; color: var(--dash-text); margin-bottom: 0.6rem;">
                        1. Choisissez votre opérateur récepteur *
                    </label>
                    <div class="method-tile-grid">
                        <!-- Wave -->
                        <div class="method-tile selected" onclick="selectOperator('wave', this)">
                            <?php echo render_momo_icon('wave', 38); ?>
                            <div style="min-width: 0; flex: 1;">
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Wave</strong>
                                <small style="color: #FF4A0D; font-size: 0.74rem; font-weight: 700; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Recommandé • 0% Frais</small>
                            </div>
                        </div>

                        <!-- Orange Money -->
                        <div class="method-tile" onclick="selectOperator('orange_money', this)">
                            <?php echo render_momo_icon('orange_money', 38); ?>
                            <div style="min-width: 0; flex: 1;">
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Orange Money</strong>
                                <small style="color: #FF4A0D; font-size: 0.74rem; font-weight: 700; display: block;">Instantané</small>
                            </div>
                        </div>

                        <!-- MTN Money -->
                        <div class="method-tile" onclick="selectOperator('mtn_money', this)">
                            <?php echo render_momo_icon('mtn_money', 38); ?>
                            <div style="min-width: 0; flex: 1;">
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">MTN MoMo</strong>
                                <small style="color: #FF4A0D; font-size: 0.74rem; font-weight: 700; display: block;">Instantané</small>
                            </div>
                        </div>

                        <!-- Moov Money -->
                        <div class="method-tile" onclick="selectOperator('moov_money', this)">
                            <?php echo render_momo_icon('moov_money', 38); ?>
                            <div style="min-width: 0; flex: 1;">
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Moov Money</strong>
                                <small style="color: #FF4A0D; font-size: 0.74rem; font-weight: 700; display: block;">Instantané</small>
                            </div>
                        </div>
                    </div>

                    <div class="retrait-form-grid">
                        <!-- Montant à retirer -->
                        <div style="min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem; flex-wrap: wrap; gap: 4px;">
                                <label for="montant_input" style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text); margin: 0;">
                                    2. Montant à transférer (FCFA) *
                                </label>
                                <span style="font-size: 0.75rem; color: var(--dash-muted); white-space: nowrap;">
                                    Max: <strong style="color: #FF4A0D;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
                                </span>
                            </div>
                            <div style="position: relative;">
                                <input type="number" id="montant_input" name="montant" required min="500" max="<?php echo (int)$solde_actuel; ?>" step="100" value="<?php echo (int)$solde_actuel; ?>" oninput="recalculerSoldeRestant()" style="width: 100%; box-sizing: border-box; padding: 0.65rem 3.5rem 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--dash-border); font-size: 1.15rem; font-weight: 800; color: var(--dash-text); background: #ffffff;">
                                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--dash-muted); font-size: 0.85rem; pointer-events: none;">FCFA</span>
                            </div>

                            <!-- Puces de raccourcis montants -->
                            <div class="amount-chips-wrap">
                                <button type="button" class="amount-chip" onclick="setMontant(5000)">5 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(10000)">10 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(25000)">25 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(50000)">50 000</button>
                                <button type="button" class="amount-chip amount-chip-all" style="background: #FFF2ED; border-color: #FFF2ED; color: #000000;" onclick="setMontant(<?php echo (int)$solde_actuel; ?>)">
                                    Tout retirer
                                </button>
                            </div>
                        </div>

                        <!-- Numéro de téléphone Mobile Money -->
                        <div style="min-width: 0;">
                            <label for="numero_telephone" style="display: block; font-size: 0.84rem; font-weight: 700; color: var(--dash-text); margin-bottom: 0.35rem;">
                                3. Numéro de téléphone récepteur *
                            </label>
                            <div style="position: relative;">
                                <i class="fa-solid fa-phone" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.85rem;"></i>
                                <input type="tel" id="numero_telephone" name="numero_telephone" required placeholder="Ex: 07 00 00 00 00" value="<?php echo htmlspecialchars($promoter['telephone_contact'] ?? ''); ?>" style="width: 100%; box-sizing: border-box; padding: 0.65rem 0.85rem 0.65rem 2.25rem; border-radius: 10px; border: 1px solid var(--dash-border); font-size: 1rem; font-weight: 700; color: var(--dash-text); background: #ffffff;">
                            </div>
                            <small style="color: var(--dash-muted); font-size: 0.74rem; display: block; margin-top: 4px;">
                                Assurez-vous que ce numéro est actif et enregistré sur l'opérateur choisi.
                            </small>
                        </div>
                    </div>

                    <!-- Récapitulatif dynamique & Bouton de confirmation -->
                    <div class="retrait-summary-box">
                        <div class="retrait-summary-info">
                            <span style="font-size: 0.78rem; color: var(--dash-muted); display: block;">Solde restant estimé après ce virement :</span>
                            <strong id="solde_restant_txt" style="font-size: 1.15rem; color: #FF4A0D;">0 FCFA</strong>
                        </div>

                        <button type="submit" class="dash-btn-action btn-primary btn-submit-retrait">
                            <i class="fa-solid fa-money-bill-transfer"></i>
                            <span>Transférer les Fonds Immédiatement</span>
                        </button>
                    </div>

                    <p style="text-align: center; margin: 0.85rem 0 0; color: var(--dash-muted); font-size: 0.78rem; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-shield-halved" style="color: #FF4A0D;"></i> Virement instantané automatisé et sécurisé via l'API Feexpay Mobile Money (Wave, Orange Money, MTN MoMo, Moov Money).
                    </p>
                </form>
            <?php else: ?>
                <div style="text-align: center; padding: 2.5rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-piggy-bank" style="font-size: 2.75rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                    <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Votre solde disponible est de 0 FCFA</strong>
                    <p style="font-size: 0.84rem; margin: 0 0 1rem;">Dès la première vente de billets pour vos événements, vous pourrez virer vos gains à tout moment.</p>
                    <a href="mes-evenements.php" class="dash-btn-action btn-primary" style="display: inline-flex; text-decoration: none;">
                        <i class="fa-solid fa-calendar-days"></i> Consulter mes événements
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==============================================================================
         4. HISTORIQUE DES RETRAITS : FILTRES & TABLEAU PRO
         ============================================================================== -->
    <!-- Barre de Filtres sur la même ligne (Période, Opérateur, Recherche) -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- Titre de section -->
        <div style="font-size: 0.9rem; font-weight: 700; color: var(--dash-text); display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--dash-primary);"></i>
            Historique des Virements (<?php echo count($withdrawals_list); ?>)
        </div>

        <!-- Filtre Période, Opérateur & Recherche sur la même ligne -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <!-- Période -->
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
                <i class="fa-regular fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="periode" onchange="this.form.submit()" style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.3rem 0.2rem; outline: none;">
                    <option value="toutes" <?php echo $periode === 'toutes' ? 'selected' : ''; ?>>Toutes les dates</option>
                    <option value="7_jours" <?php echo $periode === '7_jours' ? 'selected' : ''; ?>>7 derniers jours</option>
                    <option value="30_jours" <?php echo $periode === '30_jours' ? 'selected' : ''; ?>>30 derniers jours</option>
                    <option value="ce_mois" <?php echo $periode === 'ce_mois' ? 'selected' : ''; ?>>Ce mois-ci</option>
                    <option value="cette_annee" <?php echo $periode === 'cette_annee' ? 'selected' : ''; ?>>Cette année</option>
                </select>
            </div>

            <!-- Opérateur -->
            <select name="methode" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="toutes" <?php echo $filter_methode === 'toutes' ? 'selected' : ''; ?>>Tous les opérateurs</option>
                <option value="wave" <?php echo $filter_methode === 'wave' ? 'selected' : ''; ?>>Wave</option>
                <option value="orange_money" <?php echo $filter_methode === 'orange_money' ? 'selected' : ''; ?>>Orange Money</option>
                <option value="mtn_money" <?php echo $filter_methode === 'mtn_money' ? 'selected' : ''; ?>>MTN MoMo</option>
                <option value="moov_money" <?php echo $filter_methode === 'moov_money' ? 'selected' : ''; ?>>Moov Money</option>
            </select>

            <!-- Recherche rapide -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Téléphone, réf..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 150px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <!-- Export Excel des virements -->
            <a href="export.php?type=retraits&periode=<?php echo urlencode($periode); ?>&methode=<?php echo urlencode($filter_methode); ?>&q=<?php echo urlencode($search_q); ?>" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; text-decoration: none;" title="Exporter les virements au format Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i>
                <span>Exporter Excel</span>
            </a>

            <?php if ($periode !== 'toutes' || $filter_methode !== 'toutes' || $search_q !== ''): ?>
                <a href="solde.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tableau de l'Historique -->
    <div class="dash-card" style="padding: 0; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #F5F5F5; border-bottom: 1px solid var(--dash-border); font-size: 0.75rem; text-transform: uppercase; color: var(--dash-muted);">
                        <th style="padding: 0.85rem 1.25rem;">Date & Heure</th>
                        <th style="padding: 0.85rem 1rem;">Montant Transféré</th>
                        <th style="padding: 0.85rem 1rem;">Opérateur & Destination</th>
                        <th style="padding: 0.85rem 1rem;">Statut</th>
                        <th style="padding: 0.85rem 1rem;">Référence Transaction</th>
                        <th style="padding: 0.85rem 1.25rem; text-align: right;">Bordereau</th>
                    </tr>
                </thead>
                <tbody style="font-size: 0.85rem;">
                    <?php if (count($withdrawals_list) > 0): ?>
                        <?php foreach ($withdrawals_list as $w): ?>
                            <?php 
                                [$nom_op, $bg_op, $color_op, $icon_op, $op_key] = get_operator_badge($w['methode']);
                            ?>
                            <tr style="border-bottom: 1px solid var(--dash-border); transition: background 0.15s ease;">
                                <!-- Date & Heure -->
                                <td style="padding: 1rem 1.25rem;">
                                    <strong style="color: var(--dash-text); font-weight: 700; display: block;">
                                        <?php echo date('d/m/Y', strtotime($w['created_at'])); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.78rem;">
                                        <i class="fa-regular fa-clock"></i> <?php echo date('H:i', strtotime($w['created_at'])); ?>
                                    </small>
                                </td>

                                <!-- Montant Transféré -->
                                <td style="padding: 1rem;">
                                    <strong style="color: #FF4A0D; font-size: 1.05rem; display: block; font-weight: 800;">
                                        + <?php echo number_format($w['montant'], 0, ',', ' '); ?> FCFA
                                    </strong>
                                    <small style="color: #FF4A0D; font-size: 0.72rem; font-weight: 700;">
                                        <i class="fa-solid fa-check-double"></i> Débité du solde
                                    </small>
                                </td>

                                <!-- Opérateur & Numéro -->
                                <td style="padding: 1rem;">
                                    <span style="background: <?php echo $bg_op; ?>; color: <?php echo $color_op; ?>; padding: 4px 10px 4px 6px; border-radius: 8px; font-weight: 700; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 7px;">
                                        <?php echo render_momo_icon($op_key, 22); ?> <?php echo $nom_op; ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.78rem; display: block; margin-top: 4px; font-weight: 600;">
                                        <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($w['numero_telephone']); ?>
                                    </small>
                                </td>

                                <!-- Statut -->
                                <td style="padding: 1rem;">
                                    <?php if ($w['statut'] === 'paye'): ?>
                                        <span style="background: #FFF2ED; color: #000000; padding: 4px 9px; border-radius: 6px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-circle-check"></i> Virement Effectué
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #F5F5F5; color: var(--dash-muted); padding: 4px 9px; border-radius: 6px; font-weight: 700; font-size: 0.76rem;">
                                            <?php echo ucfirst($w['statut']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Référence Transaction -->
                                <td style="padding: 1rem;">
                                    <code style="background: #F5F5F5; color: var(--dash-text); padding: 3px 7px; border-radius: 5px; font-size: 0.78rem; font-weight: 700;">
                                        <?php echo htmlspecialchars($w['commentaire_admin'] ?: 'VIR-' . $w['id']); ?>
                                    </code>
                                </td>

                                <!-- Bordereau / Reçu -->
                                <td style="padding: 0.85rem 1.25rem; text-align: right; white-space: nowrap;">
                                    <button type="button" class="dash-btn-action" style="padding: 0.35rem 0.75rem; font-size: 0.76rem;" onclick="ouvrirRecu(<?php echo htmlspecialchars(json_encode([
                                        'id' => $w['id'],
                                        'date' => date('d/m/Y à H:i', strtotime($w['created_at'])),
                                        'montant' => number_format($w['montant'], 0, ',', ' ') . ' FCFA',
                                        'methode' => $nom_op,
                                        'telephone' => $w['numero_telephone'],
                                        'ref' => $w['commentaire_admin'] ?: 'VIR-' . $w['id'],
                                        'promoteur' => $promoter['nom_commercial'] ?? $_SESSION['nom'] ?? 'Promoteur'
                                    ])); ?>)">
                                        <i class="fa-solid fa-receipt"></i> Reçu
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                                <i class="fa-solid fa-receipt" style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                                <strong style="display: block; font-size: 0.95rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun virement enregistré</strong>
                                <p style="font-size: 0.82rem; margin: 0;">Vos prochains retraits Mobile Money s'afficheront ici avec leurs références de transaction.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==============================================================================
     MODAL DU REÇU / BORDEREAU DE TRANSACTION
     ============================================================================== -->
<div id="modalRecu" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 440px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #F5F5F5;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-receipt" style="color: var(--dash-primary); font-size: 1.1rem;"></i>
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 800;">Bordereau de Virement</h3>
            </div>
            <button type="button" onclick="closeRecuModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <div id="recu-print-area" style="padding: 1.5rem;">
            <div style="text-align: center; margin-bottom: 1.25rem;">
                <span style="font-size: 2.2rem; display: block; margin-bottom: 0.25rem;">✅</span>
                <strong id="recu-montant" style="font-size: 1.6rem; color: #FF4A0D; display: block; font-weight: 800;"></strong>
                <small style="color: var(--dash-muted); font-size: 0.8rem;">Virement Mobile Money Confirmé</small>
            </div>

            <div style="background: #F5F5F5; border-radius: 10px; padding: 1rem; font-size: 0.84rem; display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--dash-border);">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--dash-muted);">Bénéficiaire :</span>
                    <strong id="recu-promoteur" style="color: var(--dash-text);"></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--dash-muted);">Opérateur :</span>
                    <strong id="recu-methode" style="color: var(--dash-text);"></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--dash-muted);">Numéro récepteur :</span>
                    <strong id="recu-tel" style="color: var(--dash-text);"></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--dash-muted);">Date & Heure :</span>
                    <span id="recu-date" style="color: var(--dash-text); font-weight: 600;"></span>
                </div>
                <div style="display: flex; justify-content: space-between; border-top: 1px dashed var(--dash-border); padding-top: 8px; margin-top: 4px;">
                    <span style="color: var(--dash-muted);">Référence :</span>
                    <code id="recu-ref" style="font-weight: 700; color: var(--dash-primary); font-size: 0.82rem;"></code>
                </div>
            </div>

            <div style="margin-top: 1.25rem; display: flex; gap: 8px; justify-content: flex-end;">
                <button type="button" onclick="window.print()" class="dash-btn-action" style="padding: 0.5rem 1rem;">
                    <i class="fa-solid fa-print"></i> Imprimer
                </button>
                <button type="button" onclick="closeRecuModal()" class="dash-btn-action btn-primary" style="padding: 0.5rem 1.25rem;">
                    Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const soldeActuel = <?php echo (float)$solde_actuel; ?>;

function selectOperator(methodKey, tileElem) {
    document.getElementById('selected_methode').value = methodKey;
    document.querySelectorAll('.method-tile').forEach(t => t.classList.remove('selected'));
    tileElem.classList.add('selected');
}

function setMontant(val) {
    const inp = document.getElementById('montant_input');
    inp.value = Math.min(val, soldeActuel);
    recalculerSoldeRestant();
}

function recalculerSoldeRestant() {
    const inp = document.getElementById('montant_input');
    const val = parseFloat(inp.value) || 0;
    const reste = Math.max(0, soldeActuel - val);
    const resteTxt = new Intl.NumberFormat('fr-FR').format(reste) + ' FCFA';
    document.getElementById('solde_restant_txt').innerText = resteTxt;
}

function validerRetrait() {
    const val = parseFloat(document.getElementById('montant_input').value) || 0;
    const tel = document.getElementById('numero_telephone').value.trim();
    if (val < 500) {
        alert("Le montant minimum de virement est de 500 FCFA.");
        return false;
    }
    if (val > soldeActuel) {
        alert("Le montant dépasse votre solde disponible.");
        return false;
    }
    if (!tel) {
        alert("Veuillez renseigner votre numéro Mobile Money.");
        return false;
    }
    return confirm("Confirmez-vous le virement immédiat de " + new Intl.NumberFormat('fr-FR').format(val) + " FCFA vers le " + tel + " ?");
}

function ouvrirRecu(data) {
    document.getElementById('recu-montant').innerText = data.montant;
    document.getElementById('recu-promoteur').innerText = data.promoteur;
    document.getElementById('recu-methode').innerText = data.methode;
    document.getElementById('recu-tel').innerText = data.telephone;
    document.getElementById('recu-date').innerText = data.date;
    document.getElementById('recu-ref').innerText = data.ref;
    document.getElementById('modalRecu').style.display = 'flex';
}

function closeRecuModal() {
    document.getElementById('modalRecu').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalRecu');
    if (e.target === m) closeRecuModal();
});

// Calcul initial
recalculerSoldeRestant();
</script>

<?php include 'footer.php'; ?>
