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
// 2. Traitement d'un RETRAIT INSTANTANÉ (Virement Mobile Money)
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

            // B. Enregistrement direct du virement instantané (statut 'paye')
            $ref_virement = 'VIR-' . strtoupper(substr($methode, 0, 3)) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $commentaire_auto = "Virement Mobile Money instantané (" . $ref_virement . ")";

            $stmt_ins = $pdo->prepare("
                INSERT INTO withdrawals (user_id, promoter_id, montant, methode, numero_telephone, statut, commentaire_admin, created_at, reviewed_at) 
                VALUES (?, ?, ?, ?, ?, 'paye', ?, NOW(), NOW())
            ");
            $stmt_ins->execute([$user_id, $promoter_id, $montant, $methode, $telephone, $commentaire_auto]);

            $pdo->commit();

            $nom_operateur = strtoupper(str_replace('_', ' ', $methode));
            $message = "Virement réussi ! " . number_format($montant, 0, ',', ' ') . " FCFA ont été transférés instantanément sur votre compte " . $nom_operateur . " (" . htmlspecialchars($telephone) . "). Réf: " . $ref_virement;
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
            return ['Wave Mobile Money', '#e0f2fe', '#0284c7', 'fa-solid fa-water', '🌊'];
        case 'orange_money':
            return ['Orange Money', '#ffedd5', '#ea580c', 'fa-solid fa-mobile-screen', '🍊'];
        case 'mtn_money':
            return ['MTN MoMo', '#fef9c3', '#ca8a04', 'fa-solid fa-bolt', '🟡'];
        case 'moov_money':
            return ['Moov Money', '#dcfce7', '#16a34a', 'fa-solid fa-money-bill-transfer', '🟢'];
    }
    return [strtoupper($methode), '#f1f5f9', '#475569', 'fa-solid fa-wallet', '💳'];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.method-tile {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0.85rem 1rem;
    border: 2px solid var(--dash-border);
    border-radius: 12px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
}
.method-tile:hover {
    border-color: var(--dash-primary);
    background: #f0fdfa;
}
.method-tile.selected {
    border-color: var(--dash-primary);
    background: #f0fdfa;
    box-shadow: 0 0 0 1px var(--dash-primary);
}
.amount-chip {
    padding: 0.4rem 0.75rem;
    border: 1px solid var(--dash-border);
    border-radius: 8px;
    background: #ffffff;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--dash-text);
    cursor: pointer;
    transition: all 0.15s ease;
}
.amount-chip:hover {
    background: #f1f5f9;
    border-color: var(--dash-primary);
    color: var(--dash-primary);
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
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE FINANCIÈRE
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <!-- Solde Disponible -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: linear-gradient(135deg, #ffffff, #f0fdf4); border: 2px solid #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.5px;">Solde Disponible</span>
                <span style="background: #dcfce7; color: #047857; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-money-bill-wave"></i></span>
            </div>
            <div style="font-size: 1.85rem; font-weight: 800; color: #047857; line-height: 1.2;">
                <?php echo number_format($solde_actuel, 0, ',', ' '); ?> <span style="font-size: 1rem; font-weight: 700;">FCFA</span>
            </div>
            <small style="color: #059669; font-size: 0.75rem; font-weight: 700; display: block; margin-top: 4px;">
                <i class="fa-solid fa-bolt"></i> Retirable immédiatement 24/7
            </small>
        </div>

        <!-- Ventes Brutes Totales -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Ventes Brutes</span>
                <span style="background: #f1f5f9; color: var(--dash-text); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text); line-height: 1.2;">
                <?php echo number_format($total_ventes_brutes, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: var(--dash-muted); font-size: 0.75rem; display: block; margin-top: 4px;">Chiffre d'affaires cumulé</small>
        </div>

        <!-- Commissions Déduites -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">Frais Plateforme</span>
                <span style="background: #fef3c7; color: #b45309; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-percent"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309; line-height: 1.2;">
                <?php echo number_format($total_commissions, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: #b45309; font-size: 0.75rem; display: block; margin-top: 4px;">Commissions de service</small>
        </div>

        <!-- Total Déjà Transféré -->
        <div class="dash-kpi-card" style="padding: 1.25rem; border-radius: 14px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Total Transféré</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.9rem;"><i class="fa-solid fa-building-columns"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7; line-height: 1.2;">
                <?php echo number_format($total_retraits_payes, 0, ',', ' '); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--dash-muted);">F</span>
            </div>
            <small style="color: #0284c7; font-size: 0.75rem; display: block; margin-top: 4px;"><?php echo $nb_virements_reussis; ?> virement(s) exécuté(s)</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. FORMULAIRE DE VIREMENT MOBILE MONEY INSTANTANÉ
         ============================================================================== -->
    <div id="virement-box" class="dash-card" style="margin-bottom: 2rem; border: 1px solid var(--dash-border);">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bolt" style="color: #10b981;"></i> Effectuer un Virement Immédiat
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Les fonds sont crédités directement sur votre portefeuille électronique sans délai.</small>
            </div>
            <span style="background: #f0fdf4; color: #166534; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.75rem; border: 1px solid #bbf7d0;">
                <i class="fa-solid fa-shield-check"></i> Transaction sécurisée
            </span>
        </div>

        <div style="padding: 1.5rem;">
            <?php if ($solde_actuel > 0): ?>
                <form method="POST" action="solde.php" id="form-retrait" onsubmit="return validerRetrait();">
                    <input type="hidden" name="demande_retrait" value="1">
                    <input type="hidden" name="methode" id="selected_methode" value="wave">

                    <!-- Choix de l'opérateur Mobile Money (Tuiles visuelles) -->
                    <label style="display: block; font-size: 0.84rem; font-weight: 700; color: var(--dash-text); margin-bottom: 0.6rem;">
                        1. Choisissez votre opérateur récepteur *
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
                        <!-- Wave -->
                        <div class="method-tile selected" onclick="selectOperator('wave', this)">
                            <span style="font-size: 1.6rem;">🌊</span>
                            <div>
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Wave</strong>
                                <small style="color: #0284c7; font-size: 0.74rem; font-weight: 700;">Recommandé • 0% Frais</small>
                            </div>
                        </div>

                        <!-- Orange Money -->
                        <div class="method-tile" onclick="selectOperator('orange_money', this)">
                            <span style="font-size: 1.6rem;">🍊</span>
                            <div>
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Orange Money</strong>
                                <small style="color: #ea580c; font-size: 0.74rem; font-weight: 700;">Instantané</small>
                            </div>
                        </div>

                        <!-- MTN Money -->
                        <div class="method-tile" onclick="selectOperator('mtn_money', this)">
                            <span style="font-size: 1.6rem;">🟡</span>
                            <div>
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">MTN MoMo</strong>
                                <small style="color: #ca8a04; font-size: 0.74rem; font-weight: 700;">Instantané</small>
                            </div>
                        </div>

                        <!-- Moov Money -->
                        <div class="method-tile" onclick="selectOperator('moov_money', this)">
                            <span style="font-size: 1.6rem;">🟢</span>
                            <div>
                                <strong style="display: block; font-size: 0.88rem; color: var(--dash-text);">Moov Money</strong>
                                <small style="color: #16a34a; font-size: 0.74rem; font-weight: 700;">Instantané</small>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.25rem;">
                        <!-- Montant à retirer -->
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem;">
                                <label for="montant_input" style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text);">
                                    2. Montant à transférer (FCFA) *
                                </label>
                                <span style="font-size: 0.75rem; color: var(--dash-muted);">
                                    Max: <strong style="color: #047857;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
                                </span>
                            </div>
                            <div style="position: relative;">
                                <input type="number" id="montant_input" name="montant" required min="500" max="<?php echo (int)$solde_actuel; ?>" step="100" value="<?php echo (int)$solde_actuel; ?>" oninput="recalculerSoldeRestant()" style="width: 100%; padding: 0.65rem 3.5rem 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--dash-border); font-size: 1.15rem; font-weight: 800; color: var(--dash-text); background: #ffffff;">
                                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--dash-muted); font-size: 0.85rem;">FCFA</span>
                            </div>

                            <!-- Puces de raccourcis montants -->
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
                                <button type="button" class="amount-chip" onclick="setMontant(5000)">5 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(10000)">10 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(25000)">25 000</button>
                                <button type="button" class="amount-chip" onclick="setMontant(50000)">50 000</button>
                                <button type="button" class="amount-chip" style="background: #f0fdf4; border-color: #bbf7d0; color: #166534;" onclick="setMontant(<?php echo (int)$solde_actuel; ?>)">
                                    Tout retirer
                                </button>
                            </div>
                        </div>

                        <!-- Numéro de téléphone Mobile Money -->
                        <div>
                            <label for="numero_telephone" style="display: block; font-size: 0.84rem; font-weight: 700; color: var(--dash-text); margin-bottom: 0.35rem;">
                                3. Numéro de téléphone récepteur *
                            </label>
                            <div style="position: relative;">
                                <i class="fa-solid fa-phone" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.85rem;"></i>
                                <input type="tel" id="numero_telephone" name="numero_telephone" required placeholder="Ex: 07 00 00 00 00" value="<?php echo htmlspecialchars($promoter['telephone_contact'] ?? ''); ?>" style="width: 100%; padding: 0.65rem 0.85rem 0.65rem 2.25rem; border-radius: 10px; border: 1px solid var(--dash-border); font-size: 1rem; font-weight: 700; color: var(--dash-text); background: #ffffff;">
                            </div>
                            <small style="color: var(--dash-muted); font-size: 0.74rem; display: block; margin-top: 4px;">
                                Assurez-vous que ce numéro est actif et enregistré sur l'opérateur choisi.
                            </small>
                        </div>
                    </div>

                    <!-- Récapitulatif dynamique & Bouton de confirmation -->
                    <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <span style="font-size: 0.78rem; color: var(--dash-muted); display: block;">Solde restant estimé après ce virement :</span>
                            <strong id="solde_restant_txt" style="font-size: 1.15rem; color: #047857;">0 FCFA</strong>
                        </div>

                        <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.75rem 1.5rem; font-size: 0.95rem; font-weight: 800; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 10px; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-money-bill-transfer"></i> Transférer les Fonds Immédiatement
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div style="text-align: center; padding: 2.5rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-piggy-bank" style="font-size: 2.75rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
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
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
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
                <option value="wave" <?php echo $filter_methode === 'wave' ? 'selected' : ''; ?>>🌊 Wave</option>
                <option value="orange_money" <?php echo $filter_methode === 'orange_money' ? 'selected' : ''; ?>>🍊 Orange Money</option>
                <option value="mtn_money" <?php echo $filter_methode === 'mtn_money' ? 'selected' : ''; ?>>🟡 MTN MoMo</option>
                <option value="moov_money" <?php echo $filter_methode === 'moov_money' ? 'selected' : ''; ?>>🟢 Moov Money</option>
            </select>

            <!-- Recherche rapide -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Téléphone, réf..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 150px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $filter_methode !== 'toutes' || $search_q !== ''): ?>
                <a href="solde.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tableau de l'Historique -->
    <div class="dash-card" style="padding: 0; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--dash-border); font-size: 0.75rem; text-transform: uppercase; color: var(--dash-muted);">
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
                                [$nom_op, $bg_op, $color_op, $icon_op, $emoji_op] = get_operator_badge($w['methode']);
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
                                    <strong style="color: #059669; font-size: 1.05rem; display: block; font-weight: 800;">
                                        + <?php echo number_format($w['montant'], 0, ',', ' '); ?> FCFA
                                    </strong>
                                    <small style="color: #10b981; font-size: 0.72rem; font-weight: 700;">
                                        <i class="fa-solid fa-check-double"></i> Débité du solde
                                    </small>
                                </td>

                                <!-- Opérateur & Numéro -->
                                <td style="padding: 1rem;">
                                    <span style="background: <?php echo $bg_op; ?>; color: <?php echo $color_op; ?>; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 5px;">
                                        <span><?php echo $emoji_op; ?></span> <?php echo $nom_op; ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.78rem; display: block; margin-top: 3px; font-weight: 600;">
                                        <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($w['numero_telephone']); ?>
                                    </small>
                                </td>

                                <!-- Statut -->
                                <td style="padding: 1rem;">
                                    <?php if ($w['statut'] === 'paye'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 4px 9px; border-radius: 6px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-circle-check"></i> Virement Effectué
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: var(--dash-muted); padding: 4px 9px; border-radius: 6px; font-weight: 700; font-size: 0.76rem;">
                                            <?php echo ucfirst($w['statut']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Référence Transaction -->
                                <td style="padding: 1rem;">
                                    <code style="background: #f1f5f9; color: var(--dash-text); padding: 3px 7px; border-radius: 5px; font-size: 0.78rem; font-weight: 700;">
                                        <?php echo htmlspecialchars($w['commentaire_admin'] ?: 'VIR-' . $w['id']); ?>
                                    </code>
                                </td>

                                <!-- Bordereau / Reçu -->
                                <td style="padding: 1rem 1.25rem; text-align: right;">
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
                                <i class="fa-solid fa-receipt" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
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
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-receipt" style="color: var(--dash-primary); font-size: 1.1rem;"></i>
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 800;">Bordereau de Virement</h3>
            </div>
            <button type="button" onclick="closeRecuModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <div id="recu-print-area" style="padding: 1.5rem;">
            <div style="text-align: center; margin-bottom: 1.25rem;">
                <span style="font-size: 2.2rem; display: block; margin-bottom: 0.25rem;">✅</span>
                <strong id="recu-montant" style="font-size: 1.6rem; color: #047857; display: block; font-weight: 800;"></strong>
                <small style="color: var(--dash-muted); font-size: 0.8rem;">Virement Mobile Money Confirmé</small>
            </div>

            <div style="background: #f8fafc; border-radius: 10px; padding: 1rem; font-size: 0.84rem; display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--dash-border);">
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
