<?php
// ==============================================================================
// GESTION DES RÉCLAMATIONS & TICKETS SUPPORT (admin/reclamations.php)
// Design Dashboard Pro - Traitement, arbitrage et réponses administratives
// ==============================================================================

$admin_page_title = "Gestion des Réclamations - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Répondre et changer le statut d'une réclamation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_claim'])) {
    $claim_id       = (int)$_POST['claim_id'];
    $reponse_admin  = trim($_POST['reponse_admin'] ?? '');
    $nouveau_statut = $_POST['nouveau_statut'] ?? 'resolue';

    $statuts_valides = ['en_attente', 'en_cours', 'resolue', 'fermee'];

    if (in_array($nouveau_statut, $statuts_valides, true)) {
        $stmt = $pdo->prepare("UPDATE claims SET reponse_admin = ?, statut = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$reponse_admin ?: null, $nouveau_statut, $claim_id]);

        $message = "La réponse a été enregistrée et le ticket est passé au statut « " . htmlspecialchars($nouveau_statut) . " ».";
        $msg_type = "success";
    }
}

// 2. Filtres
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'en_cours', 'resolue', 'fermee', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "
    SELECT c.*, u.nom AS auteur_nom, u.email AS auteur_email, u.role AS auteur_role, o.numero_commande
    FROM claims c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN orders o ON c.order_id = o.id
";

if ($tab !== 'tous') {
    $sql .= " WHERE c.statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC");
}
$claims_list = $stmt->fetchAll();

// KPIs
$nb_attente = (int)$pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_attente'")->fetchColumn();
$nb_cours   = (int)$pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_cours'")->fetchColumn();
$nb_resolue = (int)$pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'resolue'")->fetchColumn();
$nb_total   = (int)$pdo->query("SELECT COUNT(*) FROM claims")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-headset" style="color: #0284c7; font-size: 1.55rem;"></i>
                Support & Centre de Médiation
            </h1>
            <p>Traitez les réclamations des clients, répondez aux promoteurs et résolvez les litiges de commande.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES D'ÉTATS ACTIFS)
         ============================================================================== -->
    <div style="display: flex; gap: 0.4rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <a href="?tab=en_attente" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-clock" style="color: #f59e0b;"></i>
            <span>En Attente</span>
            <?php if ($nb_attente > 0): ?>
                <span style="background: #ef4444; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_attente; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=en_cours" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'en_cours' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-spinner" style="color: #0284c7;"></i>
            <span>En Cours</span>
            <?php if ($nb_cours > 0): ?>
                <span style="background: #0284c7; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_cours; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=resolue" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'resolue' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
            <span>Résolues</span>
        </a>

        <a href="?tab=tous" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Tous les Tickets (<?php echo $nb_total; ?>)</span>
        </a>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE TRAITEMENT (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">À Traiter Urgemment</span>
                <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-clock"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo $nb_attente; ?></div>
            <small style="color: #b45309; font-size: 0.75rem;">Sans réponse administrative</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Dossiers En Cours</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-spinner"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo $nb_cours; ?></div>
            <small style="color: #0284c7; font-size: 0.75rem;">Enquête ou médiation active</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Tickets Résolus</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo $nb_resolue; ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Clôturés avec satisfaction</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Réclamations</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-headset"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $nb_total; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Historique global de la plateforme</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. LISTE DES TICKETS & FORMULAIRE DE RÉPONSE RAPIDE
         ============================================================================== -->
    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
        <?php if (empty($claims_list)): ?>
            <div class="dash-card" style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-inbox" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucune réclamation dans cette catégorie.
            </div>
        <?php else: ?>
            <?php foreach ($claims_list as $cl): ?>
                <?php
                $is_open = ($cl['statut'] === 'en_attente' || $cl['statut'] === 'en_cours');
                $badge_st = [
                    'en_attente' => ['En attente', '#fef3c7', '#b45309'],
                    'en_cours'   => ['En cours', '#e0f2fe', '#0369a1'],
                    'resolue'    => ['Résolue', '#dcfce7', '#166534'],
                    'fermee'     => ['Fermée', '#f1f5f9', '#475569']
                ];
                [$st_label, $st_bg, $st_fg] = $badge_st[$cl['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                ?>
                <div class="dash-card" style="padding: 1.5rem; <?php echo ($cl['statut'] === 'en_attente') ? 'border-left: 4px solid #f59e0b;' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                                <span style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 2px 9px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">
                                    <?php echo $st_label; ?>
                                </span>
                                <span style="font-size: 0.8rem; color: var(--dash-muted);">
                                    Ticket #<?php echo $cl['id']; ?> · Ouvert le <?php echo date('d/m/Y à H:i', strtotime($cl['created_at'])); ?>
                                </span>
                            </div>

                            <h3 style="margin: 0 0 0.25rem; color: var(--dash-text); font-size: 1.15rem; font-weight: 800;">
                                <?php echo htmlspecialchars($cl['sujet']); ?>
                            </h3>

                            <div style="font-size: 0.82rem; color: var(--dash-muted);">
                                Émis par <strong><?php echo htmlspecialchars($cl['auteur_nom']); ?></strong>
                                (<span style="color: <?php echo $cl['auteur_role'] === 'promoteur' ? '#10b981' : '#0284c7'; ?>; font-weight: 700;"><?php echo ucfirst($cl['auteur_role']); ?></span> · <?php echo htmlspecialchars($cl['auteur_email']); ?>)
                                <?php if (!empty($cl['numero_commande'])): ?>
                                    · Réf. Commande : <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($cl['numero_commande']); ?></code>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="mailto:<?php echo htmlspecialchars($cl['auteur_email']); ?>?subject=Ticket %23<?php echo $cl['id']; ?> : <?php echo urlencode($cl['sujet']); ?>" class="dash-btn-action" style="font-size: 0.78rem; text-decoration: none;">
                            <i class="fa-solid fa-envelope"></i> Écrire par Email
                        </a>
                    </div>

                    <!-- Message de l'auteur -->
                    <div style="background: #f8fafc; border: 1px solid var(--dash-border); border-radius: 10px; padding: 1rem; margin-bottom: 1rem;">
                        <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; color: var(--dash-muted); display: block; margin-bottom: 0.35rem;">
                            Message du demandeur :
                        </span>
                        <p style="margin: 0; color: var(--dash-text); font-size: 0.9rem; line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($cl['message'])); ?>
                        </p>
                    </div>

                    <!-- Formulaire de réponse / Mise à jour statut -->
                    <form method="POST" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 1rem; margin: 0;">
                        <input type="hidden" name="claim_id" value="<?php echo $cl['id']; ?>">
                        <input type="hidden" name="reply_claim" value="1">

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                            <label style="font-size: 0.8rem; font-weight: 800; color: #166534; margin: 0;">
                                <i class="fa-solid fa-reply"></i> Réponse officielle de la plateforme Ticket Flow :
                            </label>

                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                <span style="font-size: 0.78rem; font-weight: 700; color: #166534;">Statut du ticket :</span>
                                <select name="nouveau_statut" style="padding: 0.3rem 0.65rem; border-radius: 6px; border: 1px solid #bbf7d0; font-size: 0.78rem; font-weight: 800; background: #ffffff; color: var(--dash-text);">
                                    <option value="en_cours" <?php echo $cl['statut'] === 'en_cours' ? 'selected' : ''; ?>>🔵 En cours d'analyse</option>
                                    <option value="resolue" <?php echo $cl['statut'] === 'resolue' ? 'selected' : ''; ?>>🟢 Résolue / Clôturée</option>
                                    <option value="fermee" <?php echo $cl['statut'] === 'fermee' ? 'selected' : ''; ?>>⚫ Fermée sans suite</option>
                                    <option value="en_attente" <?php echo $cl['statut'] === 'en_attente' ? 'selected' : ''; ?>>🟡 En attente</option>
                                </select>
                            </div>
                        </div>

                        <textarea name="reponse_admin" rows="2" placeholder="Rédigez la réponse ou les instructions à transmettre à l'utilisateur..." style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 8px; border: 1px solid #bbf7d0; font-size: 0.86rem; background: #ffffff; margin-bottom: 0.65rem; box-sizing: border-box;"><?php echo htmlspecialchars($cl['reponse_admin'] ?? ''); ?></textarea>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="dash-btn-action" style="background: #16a34a; color: #ffffff; padding: 0.45rem 1.15rem; font-size: 0.82rem; font-weight: 800;">
                                <i class="fa-solid fa-paper-plane"></i> Enregistrer et Notifier
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
