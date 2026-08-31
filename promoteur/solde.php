<?php
// ==============================================================================
// SOLDE & RETRAITS INSTANTANÉS PROMOTEUR (promoteur/solde.php)
// Retrait Mobile Money immédiat et automatique sans attente de validation admin
// ==============================================================================

$page_title = "Solde & Retraits Instantanés - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

// 1. Récupération du profil promoteur et du solde actuel
$stmt_prom = $pdo->prepare("SELECT * FROM promoters WHERE user_id = ?");
$stmt_prom->execute([$user_id]);
$promoter = $stmt_prom->fetch();

$promoter_id   = $promoter ? (int)$promoter['id'] : 0;
$solde_actuel  = $promoter ? (float)$promoter['solde'] : 0.00;

// 2. Calcul du cumul des ventes et commissions
$stmt_ventes = $pdo->prepare("
    SELECT 
        COALESCE(SUM(t.prix), 0) AS total_ventes_brutes,
        COALESCE(SUM(t.prix * (e.commission_rate / 100)), 0) AS total_commissions
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise')
");
$stmt_ventes->execute([$user_id]);
$ventes_data = $stmt_ventes->fetch();

$total_ventes_brutes = (float)($ventes_data['total_ventes_brutes'] ?? 0);
$total_commissions   = (float)($ventes_data['total_commissions'] ?? 0);

// 3. Calcul des retraits effectués (payés automatiquement)
$stmt_ret = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN statut = 'paye' THEN montant ELSE 0 END), 0) AS total_retraits_payes
    FROM withdrawals 
    WHERE user_id = ?
");
$stmt_ret->execute([$user_id]);
$ret_data = $stmt_ret->fetch();

$total_retraits_payes = (float)($ret_data['total_retraits_payes'] ?? 0);

// 4. Traitement d'un RETRAIT INSTANTANÉ (sans permission de l'admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demande_retrait'])) {
    $montant   = (float)($_POST['montant'] ?? 0);
    $methode   = $_POST['methode'] ?? 'wave';
    $telephone = trim($_POST['numero_telephone'] ?? '');

    $methodes_valides = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

    if ($montant <= 0 || empty($telephone)) {
        $message = "Veuillez renseigner un montant valide et votre numéro Mobile Money.";
        $msg_type = "error";
    } elseif ($montant > $solde_actuel) {
        $message = "Le montant demandé (" . number_format($montant, 0, ',', ' ') . " FCFA) dépasse votre solde disponible (" . number_format($solde_actuel, 0, ',', ' ') . " FCFA).";
        $msg_type = "error";
    } elseif (!in_array($methode, $methodes_valides, true)) {
        $message = "Moyen de retrait non reconnu.";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // A. Débit immédiat du solde du promoteur
            $stmt_deduct = $pdo->prepare("UPDATE promoters SET solde = solde - ? WHERE user_id = ? AND solde >= ?");
            $stmt_deduct->execute([$montant, $user_id, $montant]);

            if ($stmt_deduct->rowCount() !== 1) {
                throw new Exception("Solde insuffisant pour effectuer ce virement.");
            }

            // B. Enregistrement direct du retrait avec statut 'paye' (Instantané)
            $ref_virement = 'VIR-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
            $commentaire_auto = "Virement Mobile Money instantané (" . $ref_virement . ")";

            $stmt_ins = $pdo->prepare("
                INSERT INTO withdrawals (user_id, promoter_id, montant, methode, numero_telephone, statut, commentaire_admin, created_at, reviewed_at) 
                VALUES (?, ?, ?, ?, ?, 'paye', ?, NOW(), NOW())
            ");
            $stmt_ins->execute([$user_id, $promoter_id, $montant, $methode, $telephone, $commentaire_auto]);

            $pdo->commit();

            $message = "✅ Virement instantané réussi ! Le montant de " . number_format($montant, 0, ',', ' ') . " FCFA a été transféré avec succès sur votre compte " . strtoupper(str_replace('_', ' ', $methode)) . " (" . htmlspecialchars($telephone) . ").";
            $msg_type = "success";

            // Mise à jour locale du solde et cumul
            $solde_actuel -= $montant;
            $total_retraits_payes += $montant;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Erreur lors du virement instantané : " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// 5. Historique des retraits effectués
$stmt_history = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
$stmt_history->execute([$user_id]);
$withdrawals_list = $stmt_history->fetchAll();
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Trésorerie & Virements Immédiats</span>
        <h1>Solde & Retraits Instantanés</h1>
        <p>Retirez vos gains à tout moment sans attente. Vos virements Mobile Money sont traités instantanément.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- 1. Cartes financières -->
<div class="stats-grid">
    <div class="stat-card" style="border-left: 4px solid #16a34a;">
        <div class="stat-icon icon-green"><i class="fa-solid fa-wallet"></i></div>
        <div class="stat-info">
            <span>Solde Disponible Retirable</span>
            <strong style="color: #16a34a; font-size: 1.5rem;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Retrait instantané 24/7</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-info">
            <span>Ventes Totales Brutes</span>
            <strong><?php echo number_format($total_ventes_brutes, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Chiffre d'affaires généré</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #f59e0b;">
        <div class="stat-icon icon-orange"><i class="fa-solid fa-percent"></i></div>
        <div class="stat-info">
            <span>Commissions Plateforme</span>
            <strong><?php echo number_format($total_commissions, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Frais de service (5%)</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #0284c7;">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-building-columns"></i></div>
        <div class="stat-info">
            <span>Total Retraits Encaissés</span>
            <strong><?php echo number_format($total_retraits_payes, 0, ',', ' '); ?> F</strong>
            <small style="color: #16a34a;"><i class="fa-solid fa-check"></i> Déjà transférés</small>
        </div>
    </div>
</div>

<!-- 2. Formulaire de Retrait Instantané -->
<div class="content-section" style="margin-top: 1.5rem; max-width: 650px;">
    <div class="section-title"><i class="fa-solid fa-bolt" style="color: #16a34a;"></i> Effectuer un Retrait Instantané</div>
    
    <?php if ($solde_actuel > 0): ?>
        <form method="POST" action="solde.php">
            <input type="hidden" name="demande_retrait" value="1">

            <div class="form-group">
                <label for="montant">Montant à transférer (FCFA) *</label>
                <input type="number" id="montant" name="montant" required min="1000" max="<?php echo (int)$solde_actuel; ?>" step="500" value="<?php echo (int)$solde_actuel; ?>">
                <small style="color: var(--muted); display: block; margin-top: 4px;">
                    Solde maximum transférable : <strong><?php echo number_format($solde_actuel, 0, ',', ' '); ?> FCFA</strong>
                </small>
            </div>

            <div class="form-group">
                <label for="methode">Opérateur Mobile Money de destination *</label>
                <select name="methode" id="methode" required>
                    <option value="wave">🌊 Wave Mobile Money (Recommandé - Sans frais)</option>
                    <option value="orange_money">🍊 Orange Money</option>
                    <option value="mtn_money">🟡 MTN Mobile Money</option>
                    <option value="moov_money">🟢 Moov Money</option>
                </select>
            </div>

            <div class="form-group">
                <label for="numero_telephone">Numéro de téléphone récepteur *</label>
                <input type="tel" id="numero_telephone" name="numero_telephone" required placeholder="Ex: 07 00 00 00 00" value="<?php echo htmlspecialchars($promoter['telephone_contact'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn-submit" style="background: #16a34a; font-size: 1.05rem; padding: 0.95rem;" onclick="return confirm('Confirmez-vous le virement immédiat vers votre compte Mobile Money ?')">
                <i class="fa-solid fa-money-bill-transfer"></i> Transférer les Fonds Immédiatement
            </button>
        </form>
    <?php else: ?>
        <div style="background: #f8faf9; border: 1px solid var(--line); border-radius: 8px; padding: 2rem; text-align: center; color: var(--muted);">
            <i class="fa-solid fa-piggy-bank" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.5rem; display: block;"></i>
            Votre solde disponible est actuellement de <strong>0 FCFA</strong>.<br>
            Dès vos prochaines ventes de billets, vous pourrez transférer vos fonds immédiatement.
        </div>
    <?php endif; ?>
</div>

<!-- 3. Historique des Retraits -->
<div class="content-section" style="margin-top: 2rem;">
    <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Historique de mes Virements (<?php echo count($withdrawals_list); ?>)</div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Date & Heure</th>
                    <th>Montant Transféré</th>
                    <th>Moyen & Numéro</th>
                    <th>Statut</th>
                    <th>Référence de Transaction</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($withdrawals_list) > 0): ?>
                    <?php foreach ($withdrawals_list as $w): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?></td>
                            <td>
                                <strong style="color: #16a34a; font-size: 1.05rem;">
                                    <?php echo number_format($w['montant'], 0, ',', ' '); ?> FCFA
                                </strong>
                            </td>
                            <td>
                                <strong style="text-transform: uppercase;"><?php echo htmlspecialchars(str_replace('_', ' ', $w['methode'])); ?></strong><br>
                                <small style="color: var(--muted);"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($w['numero_telephone']); ?></small>
                            </td>
                            <td>
                                <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Virement Effectué</span>
                            </td>
                            <td>
                                <small style="color: var(--navy); font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($w['commentaire_admin'] ?: 'VIR-' . $w['id']); ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Aucun virement effectué pour le moment.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
