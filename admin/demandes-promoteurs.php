<?php
// ==============================================================================
// GESTION DES DEMANDES DE PROMOTEURS (admin/demandes-promoteurs.php)
// Design Dashboard Pro - Validation d'éligibilité légale et activation des organisateurs
// ==============================================================================

$admin_page_title = "Demandes d'éligibilité Promoteurs - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Traitement des actions (Approuver ou Refuser)
if (isset($_GET['id']) && isset($_GET['action'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    $stmt_req = $pdo->prepare("SELECT * FROM promoter_requests WHERE id = ?");
    $stmt_req->execute([$id]);
    $req = $stmt_req->fetch();

    if ($req) {
        $user_id = (int) $req['user_id'];
        $type_entite = $req['type_entite'] ?? 'physique';
        $nom_commercial = ($type_entite === 'morale' && !empty($req['raison_sociale'])) ? $req['raison_sociale'] : $req['nom_complet'];

        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            $stmt_u = $pdo->prepare("UPDATE users SET role = 'promoteur', est_verifie = 1, statut = 'actif' WHERE id = ?");
            $stmt_u->execute([$user_id]);

            $stmt_p = $pdo->prepare("
                INSERT INTO promoters (user_id, type_entite, nom_commercial, numero_registre, representant_legal, telephone_contact, email_contact, ville, statut, solde)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approuve', 0.00)
                ON DUPLICATE KEY UPDATE 
                    statut = 'approuve',
                    type_entite = VALUES(type_entite),
                    nom_commercial = VALUES(nom_commercial),
                    numero_registre = VALUES(numero_registre),
                    representant_legal = VALUES(representant_legal),
                    telephone_contact = VALUES(telephone_contact),
                    email_contact = VALUES(email_contact),
                    ville = VALUES(ville)
            ");
            $stmt_p->execute([
                $user_id,
                $type_entite,
                $nom_commercial,
                $req['numero_registre'] ?? null,
                $req['representant_legal'] ?? null,
                $req['telephone'] ?? null,
                $req['email'] ?? null,
                $req['ville'] ?? null
            ]);

            // Envoi de l'e-mail officiel d'activation au promoteur
            require_once __DIR__ . '/../includes/mailer.php';
            sendPromoterApprovalEmail($req['email'], $req['nom_complet'], $nom_commercial);

            logActivity('promoter.approve', 'user', $user_id, "Approbation du dossier promoteur #$id (« $nom_commercial ») par l'administrateur.");

            $message = "Le promoteur « " . htmlspecialchars($nom_commercial) . " » a été approuvé avec succès ! Son compte est désormais actif et un e-mail de confirmation lui a été envoyé.";
            $msg_type = "success";

        } elseif ($action === 'reject') {
            $motif = trim($_POST['motif_refus'] ?? 'Dossier incomplet ou critères non remplis');

            $stmt = $pdo->prepare("UPDATE promoter_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$motif, $id]);

            $stmt_u = $pdo->prepare("UPDATE users SET statut = 'inactif' WHERE id = ?");
            $stmt_u->execute([$user_id]);

            $stmt_p = $pdo->prepare("UPDATE promoters SET statut = 'refuse' WHERE user_id = ?");
            $stmt_p->execute([$user_id]);

            require_once __DIR__ . '/../includes/mailer.php';
            sendPromoterRejectionEmail($req['email'], $req['nom_complet'], $motif);

            logActivity('promoter.reject', 'user', $user_id, "Rejet du dossier promoteur #$id. Motif : $motif");

            $message = "La demande du promoteur a été refusée. Un e-mail de notification lui a été envoyé.";
            $msg_type = "error";
        }
    }
}

// 2. Filtre par statut (en_attente par défaut)
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'approuve', 'refuse', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "SELECT * FROM promoter_requests";
if ($tab !== 'tous') {
    $sql .= " WHERE statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY created_at DESC");
}
$requests = $stmt->fetchAll();

// KPIs
$nb_attente = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$nb_approuve = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'approuve'")->fetchColumn();
$nb_refuse = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'refuse'")->fetchColumn();
$nb_total = (int) $pdo->query("SELECT COUNT(*) FROM promoter_requests")->fetchColumn();
?>

<style>
/* ==============================================================================
   STYLES RESPONSIVE & SYSTÈME SUISSE - DOSSIERS PROMOTEURS
   ============================================================================== */
.dp-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.dp-header-actions {
    display: flex;
    gap: 0.65rem;
    align-items: center;
    flex-wrap: wrap;
}
.dp-tabs-bar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    background: #ffffff;
    padding: 0.5rem 0.65rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    overflow-x: auto;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.dp-tabs-bar::-webkit-scrollbar {
    display: none;
}
.dp-tab-item {
    text-decoration: none;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    font-size: 0.83rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
.dp-tab-item.active {
    background: #000000;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);
    border: 1px solid #000000;
}
.dp-tab-item.inactive {
    background: #F5F5F5;
    color: #737373;
    border: 1px solid #E5E5E5;
}
.dp-tab-item.inactive:hover {
    background: #E5E5E5;
    color: #000000;
}

.dp-kpis-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
}

.dp-card {
    background: #ffffff;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    position: relative;
    box-sizing: border-box;
}
.dp-card.border-pending {
    border-left: 4px solid #FF4A0D;
}
.dp-card.border-approved {
    border-left: 4px solid #FF4A0D;
}
.dp-card.border-rejected {
    border-left: 4px solid #000000;
}

.dp-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.15rem;
}
.dp-card-head-left {
    flex: 1;
    min-width: 0;
}
.dp-badges-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 0.35rem;
    flex-wrap: wrap;
}
.dp-badge-status {
    padding: 2px 9px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.dp-badge-entity {
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.dp-badge-date {
    color: var(--dash-muted, #737373);
    font-size: 0.78rem;
    font-family: 'Space Mono', monospace;
}
.dp-title {
    margin: 0.2rem 0;
    color: var(--dash-text, #000000);
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.3;
    word-break: break-word;
}
.dp-contact-meta {
    color: var(--dash-muted, #737373);
    font-size: 0.84rem;
    margin-top: 4px;
    line-height: 1.5;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.dp-contact-meta a {
    color: inherit;
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.dp-rccm-box {
    background: #F5F5F5;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 8px;
    padding: 0.5rem 0.85rem;
    font-size: 0.82rem;
    text-align: right;
    flex-shrink: 0;
}

.dp-eligibility-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.85rem;
    background: #F5F5F5;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
    font-size: 0.84rem;
}
.dp-elig-label {
    color: var(--dash-muted, #737373);
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.03em;
    margin-bottom: 2px;
}
.dp-elig-val {
    color: var(--dash-text, #000000);
    word-break: break-word;
}

.dp-desc-box {
    font-size: 0.85rem;
    margin-bottom: 1rem;
    line-height: 1.5;
    color: #000000;
    background: #FFFFFF;
    border: 1px solid #F5F5F5;
    padding: 0.75rem;
    border-radius: 8px;
    word-break: break-word;
}

.dp-extras-row {
    font-size: 0.82rem;
    color: var(--dash-muted, #737373);
    margin-bottom: 1rem;
    display: flex;
    gap: 1.25rem;
    flex-wrap: wrap;
    word-break: break-word;
}

.dp-docs-box {
    background: #ffffff;
    border: 1px dashed var(--dash-border, #E5E5E5);
    border-radius: 10px;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    display: flex;
    gap: 0.85rem;
    flex-wrap: wrap;
    align-items: center;
    font-size: 0.85rem;
}
.dp-docs-title {
    font-weight: 700;
    color: var(--dash-muted, #737373);
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.dp-doc-link {
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.82rem;
    transition: all 0.15s ease;
}
.dp-doc-link-cni {
    color: #FF4A0D;
    background: #FFF2ED;
    border: 1px solid #FFF2ED;
}
.dp-doc-link-cni:hover {
    background: #FFF2ED;
}
.dp-doc-link-ent {
    color: #000000;
    background: #FFF2ED;
    border: 1px solid #FFF2ED;
}
.dp-doc-link-ent:hover {
    background: #FFF2ED;
}

.dp-actions-row {
    display: flex;
    justify-content: flex-end;
    gap: 0.65rem;
    border-top: 1px solid var(--dash-border, #E5E5E5);
    padding-top: 1rem;
    align-items: center;
    flex-wrap: wrap;
}
.dp-btn-approve {
    background: #FF4A0D;
    color: #ffffff;
    padding: 0.55rem 1.25rem;
    font-size: 0.84rem;
    font-weight: 800;
    text-decoration: none;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.15s ease;
}
.dp-btn-approve:hover {
    background: #FF4A0D;
}
.dp-btn-reject {
    background: #F5F5F5;
    color: #000000;
    padding: 0.55rem 1rem;
    font-size: 0.84rem;
    font-weight: 800;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.15s ease;
}
.dp-btn-reject:hover {
    background: #E5E5E5;
}

.dp-reject-panel {
    display: none;
    margin-top: 0.85rem;
    background: #F5F5F5;
    border: 1px solid #F5F5F5;
    border-radius: 8px;
    padding: 1rem;
}
.dp-reject-input {
    width: 100%;
    padding: 0.6rem 0.85rem;
    border: 1px solid #F5F5F5;
    border-radius: 6px;
    font-size: 0.85rem;
    margin-bottom: 8px;
    box-sizing: border-box;
    font-family: inherit;
}
.dp-reject-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* ==============================================================================
   RESPONSIVE MEDIA QUERIES
   ============================================================================== */
@media (max-width: 960px) {
    .dp-kpis-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .dp-header-section {
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
    }
    .dp-header-actions {
        width: 100%;
        flex-direction: column;
    }
    .dp-header-actions a {
        width: 100%;
        justify-content: center;
        box-sizing: border-box;
    }
    .dp-card {
        padding: 1rem !important;
        border-radius: 10px;
    }
    .dp-card-head {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
    }
    .dp-rccm-box {
        text-align: left;
        width: 100%;
        box-sizing: border-box;
    }
    .dp-eligibility-grid {
        grid-template-columns: 1fr;
        gap: 0.75rem;
        padding: 0.85rem;
    }
    .dp-docs-box {
        flex-direction: column;
        align-items: stretch;
        gap: 0.6rem;
        padding: 0.85rem;
    }
    .dp-doc-link {
        width: 100%;
        box-sizing: border-box;
        justify-content: center;
        padding: 8px 12px;
    }
    .dp-actions-row {
        flex-direction: column;
        align-items: stretch;
        gap: 0.65rem;
    }
    .dp-btn-approve,
    .dp-btn-reject {
        width: 100%;
        box-sizing: border-box;
        justify-content: center;
        padding: 0.75rem 1rem;
        font-size: 0.88rem;
    }
    .dp-reject-actions {
        flex-direction: column;
    }
    .dp-reject-actions button {
        width: 100%;
        box-sizing: border-box;
        padding: 0.65rem 1rem;
    }
}

@media (max-width: 480px) {
    .dp-kpis-grid {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
    .dp-title {
        font-size: 1.15rem;
    }
    .dp-badges-row {
        gap: 6px;
    }
    .dp-badge-status, .dp-badge-entity {
        font-size: 0.7rem;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dp-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-id-card" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Dossiers d'Éligibilité Promoteurs
            </h1>
            <p>Vérifiez les pièces légales d'identité et de société avant d'autoriser la publication d'événements.</p>
        </div>

        <div class="dp-header-actions">
            <a href="export.php?type=demandes&tab=promoteurs" class="dash-btn-action"
                style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Exporter les dossiers promoteurs sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="promoteurs.php" class="dash-btn-action btn-primary"
                style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-user-tie"></i> Voir Tous les Promoteurs
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES AVEC DÉFILEMENT FLUIDE)
         ============================================================================== -->
    <div class="dp-tabs-bar">
        <a href="?tab=en_attente" class="dp-tab-item <?php echo $tab === 'en_attente' ? 'active' : 'inactive'; ?>">
            <i class="fa-solid fa-clock" style="<?php echo $tab === 'en_attente' ? 'color: #FF4A0D;' : 'color: #FF4A0D;'; ?>"></i>
            <span>En Attente d'Examen</span>
            <?php if ($nb_attente > 0): ?>
                <span
                    style="background: #000000; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_attente; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=approuve" class="dp-tab-item <?php echo $tab === 'approuve' ? 'active' : 'inactive'; ?>">
            <i class="fa-solid fa-circle-check" style="<?php echo $tab === 'approuve' ? 'color: #FF4A0D;' : 'color: #FF4A0D;'; ?>"></i>
            <span>Dossiers Approuvés</span>
        </a>

        <a href="?tab=refuse" class="dp-tab-item <?php echo $tab === 'refuse' ? 'active' : 'inactive'; ?>">
            <i class="fa-solid fa-ban" style="<?php echo $tab === 'refuse' ? 'color: #000000;' : 'color: #000000;'; ?>"></i>
            <span>Refusés</span>
        </a>

        <a href="?tab=tous" class="dp-tab-item <?php echo $tab === 'tous' ? 'active' : 'inactive'; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Tous les Dossiers (<?php echo $nb_total; ?>)</span>
        </a>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div class="dp-kpis-grid">
        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Dossiers
                    En Attente</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-clock"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;"><?php echo $nb_attente; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">À vérifier et valider</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Promoteurs
                    Validés</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;"><?php echo $nb_approuve; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Comptes organisateurs actifs</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #000000; text-transform: uppercase;">Dossiers
                    Rejetés</span>
                <span
                    style="background: #F5F5F5; color: #000000; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-ban"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #000000;"><?php echo $nb_refuse; ?></div>
            <small style="color: #000000; font-size: 0.75rem;">Non conformes</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span
                    style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total
                    Dossiers</span>
                <span
                    style="background: #F5F5F5; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-id-card"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $nb_total; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Historique d'inscriptions</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. LISTE DES DOSSIERS
         ============================================================================== -->
    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
        <?php if (empty($requests)): ?>
            <div class="dash-card" style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-id-card"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucune demande d'éligibilité dans cette catégorie.
            </div>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <?php
                $is_p = ($r['statut'] === 'en_attente');
                $is_appr = ($r['statut'] === 'approuve');
                $is_ref = ($r['statut'] === 'refuse');

                $border_class = $is_p ? 'border-pending' : ($is_appr ? 'border-approved' : ($is_ref ? 'border-rejected' : ''));

                $badge_st = [
                    'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D'],
                    'approuve' => ['Approuvé', '#FFF2ED', '#000000'],
                    'refuse' => ['Refusé', '#F5F5F5', '#000000']
                ];
                [$st_label, $st_bg, $st_fg] = $badge_st[$r['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                ?>
                <?php
                $type_entite = $r['type_entite'] ?? 'physique';
                $is_morale = ($type_entite === 'morale');
                $titre_card = ($is_morale && !empty($r['raison_sociale'])) ? $r['raison_sociale'] : $r['nom_complet'];

                // Helper pour localiser les fichiers justificatifs
                $get_doc_url = function ($file) {
                    if (empty($file) || $file === 'default.jpg')
                        return null;
                    if (file_exists(__DIR__ . '/../uploads/ids/' . $file))
                        return '../uploads/ids/' . $file;
                    if (file_exists(__DIR__ . '/../uploads/promoter_docs/' . $file))
                        return '../uploads/promoter_docs/' . $file;
                    return '../uploads/ids/' . $file;
                };
                $url_cni = $get_doc_url($r['piece_identite']);
                $url_ent = $get_doc_url($r['piece_entreprise'] ?? ($r['registre_commerce'] ?? null));
                ?>
                <div class="dp-card <?php echo $border_class; ?>">
                    <div class="dp-card-head">
                        <div class="dp-card-head-left">
                            <div class="dp-badges-row">
                                <span class="dp-badge-status" style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>;">
                                    <?php echo $st_label; ?>
                                </span>
                                <span class="dp-badge-entity" style="background: <?php echo $is_morale ? '#FFF2ED' : '#F5F5F5'; ?>; color: <?php echo $is_morale ? '#FF4A0D' : '#737373'; ?>;">
                                    <i class="fa-solid <?php echo $is_morale ? 'fa-building' : 'fa-user'; ?>"></i>
                                    <?php echo $is_morale ? 'Personne Morale (Entreprise)' : 'Personne Physique (Indépendant)'; ?>
                                </span>
                                <span class="dp-badge-date">
                                    Soumis le <?php echo date('d/m/Y à H:i', strtotime($r['created_at'])); ?>
                                </span>
                            </div>

                            <h3 class="dp-title">
                                <?php echo htmlspecialchars($titre_card); ?>
                            </h3>
                            <div class="dp-contact-meta">
                                <span>Déclarant : <strong><?php echo htmlspecialchars($r['nom_complet']); ?></strong></span>
                                <span>· Tél : <a href="tel:<?php echo htmlspecialchars($r['telephone']); ?>"><?php echo htmlspecialchars($r['telephone']); ?></a></span>
                                <span>· Email : <a href="mailto:<?php echo htmlspecialchars($r['email']); ?>"><?php echo htmlspecialchars($r['email']); ?></a></span>
                                <?php if (!empty($r['ville'])): ?>
                                    <span>· Ville : <strong><?php echo htmlspecialchars($r['ville']); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_morale): ?>
                            <div class="dp-rccm-box">
                                <div style="color: var(--dash-muted); font-size: 0.75rem;">N° RCCM / Registre :</div>
                                <strong
                                    style="color: var(--dash-text); font-family: 'Space Mono', monospace;"><?php echo htmlspecialchars($r['numero_registre'] ?? 'Non précisé'); ?></strong>
                                <?php if (!empty($r['representant_legal'])): ?>
                                    <div style="color: var(--dash-muted); font-size: 0.75rem; margin-top: 2px;">Représentant Légal :
                                        <strong><?php echo htmlspecialchars($r['representant_legal']); ?></strong></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Grille d'Éligibilité & Expérience -->
                    <div class="dp-eligibility-grid">
                        <div class="dp-eligibility-item">
                            <span class="dp-elig-label">Activité & Style</span>
                            <strong class="dp-elig-val"><?php echo htmlspecialchars($r['activite']); ?></strong>
                        </div>
                        <div class="dp-eligibility-item">
                            <span class="dp-elig-label">Expérience dans l'Événementiel</span>
                            <span class="dp-elig-val"><?php echo htmlspecialchars($r['experience']); ?></span>
                        </div>
                        <div class="dp-eligibility-item">
                            <span class="dp-elig-label">Volume Annuel Estimé</span>
                            <strong class="dp-elig-val" style="color: #FF4A0D;"><?php echo htmlspecialchars($r['volume_estime'] ?? 'Non spécifié'); ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($r['description'])): ?>
                        <div class="dp-desc-box">
                            <strong
                                style="color: var(--dash-text); display: block; margin-bottom: 4px; font-size: 0.78rem; text-transform: uppercase; font-family: 'Space Mono', monospace;">Projets
                                d'événements :</strong>
                            <?php echo nl2br(htmlspecialchars($r['description'])); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($r['reseaux_sociaux']) || !empty($r['autres_infos'])): ?>
                        <div class="dp-extras-row">
                            <?php if (!empty($r['reseaux_sociaux'])): ?>
                                <div><i class="fa-solid fa-link"></i> Web / Réseaux : <strong
                                        style="color: var(--dash-text);"><?php echo htmlspecialchars($r['reseaux_sociaux']); ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($r['autres_infos'])): ?>
                                <div><i class="fa-solid fa-circle-info"></i> Note : <?php echo htmlspecialchars($r['autres_infos']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Justificatifs légaux téléchargeables -->
                    <div class="dp-docs-box">
                        <span class="dp-docs-title">Documents Justificatifs :</span>

                        <?php if ($url_cni): ?>
                            <a href="<?php echo htmlspecialchars($url_cni); ?>" target="_blank" class="dp-doc-link dp-doc-link-cni">
                                <i class="fa-solid fa-id-card"></i> Pièce d'Identité (CNI/Passeport)
                            </a>
                        <?php else: ?>
                            <span style="color: var(--dash-muted); font-size: 0.8rem;"><i class="fa-solid fa-id-card"></i> CNI non fournie</span>
                        <?php endif; ?>

                        <?php if ($url_ent): ?>
                            <a href="<?php echo htmlspecialchars($url_ent); ?>" target="_blank" class="dp-doc-link dp-doc-link-ent">
                                <i class="fa-solid fa-file-contract"></i> Document d'Entreprise (RCCM / DFE)
                            </a>
                        <?php elseif ($is_morale): ?>
                            <span style="color: #000000; font-size: 0.8rem;"><i class="fa-solid fa-triangle-exclamation"></i> Document RCCM manquant</span>
                        <?php endif; ?>
                    </div>

                    <!-- Actions Administrateur -->
                    <?php if ($is_p): ?>
                        <div class="dp-actions-row">
                            <a href="?id=<?php echo $r['id']; ?>&action=approve"
                                onclick="return confirm('Confirmez-vous l\'approbation de ce promoteur ? Son compte sera activé et un e-mail lui sera envoyé.');"
                                class="dp-btn-approve">
                                <i class="fa-solid fa-check"></i> Valider et Activer le Compte Promoteur
                            </a>

                            <button type="button"
                                onclick="document.getElementById('reject-box-<?php echo $r['id']; ?>').style.display = 'block'"
                                class="dp-btn-reject">
                                <i class="fa-solid fa-xmark"></i> Refuser
                            </button>
                        </div>

                        <!-- Formulaire de refus dépliant -->
                        <div id="reject-box-<?php echo $r['id']; ?>" class="dp-reject-panel">
                            <form method="POST" action="demandes-promoteurs.php?id=<?php echo $r['id']; ?>&action=reject">
                                <label
                                    style="display: block; font-size: 0.82rem; font-weight: 700; color: #000000; margin-bottom: 6px;">Motif
                                    officiel du refus (notifié par e-mail au candidat) :</label>
                                <input type="text" name="motif_refus" required class="dp-reject-input"
                                    placeholder="Ex: Pièce d'identité expirée, Registre RCCM non conforme...">
                                <div class="dp-reject-actions">
                                    <button type="submit" class="dash-btn-action"
                                        style="background: #FF4A0D; color: #ffffff; padding: 0.55rem 1.15rem; font-size: 0.82rem; border-radius: 6px; border: none; cursor: pointer;">Confirmer
                                        le Rejet</button>
                                    <button type="button"
                                        onclick="document.getElementById('reject-box-<?php echo $r['id']; ?>').style.display = 'none'"
                                        class="dash-btn-action"
                                        style="background: #F5F5F5; color: #737373; padding: 0.55rem 0.85rem; font-size: 0.82rem; border-radius: 6px; border: none; cursor: pointer;">Annuler</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>