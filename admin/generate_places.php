<?php
// ==============================================================================
// GÉNÉRATEUR DE PLACES (admin/generate_places.php)
// Synchronise la table des places physiques avec les quantités des types de
// billets : crée uniquement les places manquantes, sans doublons.
// ==============================================================================

$admin_page_title = "Génération des Places - Administration";
include 'header.php';

$message = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        $pdo->beginTransaction();

        // 1. Récupérer tous les types de tickets qui ont une quantité définie
        $types   = $pdo->query("SELECT id, nom, quantite FROM ticket_types")->fetchAll();
        $total_generated = 0;

        foreach ($types as $type) {
            $type_id = (int)$type['id'];
            $qty     = (int)$type['quantite'];
            if ($qty <= 0) {
                continue;
            }

            // 2. Numéros déjà utilisés pour ce type (ex: "Place 3" -> 3)
            $stmt_existing = $pdo->prepare("SELECT numero FROM places WHERE ticket_type_id = ?");
            $stmt_existing->execute([$type_id]);
            $used = [];
            foreach ($stmt_existing->fetchAll() as $row) {
                if (preg_match('/(\d+)\s*$/', $row['numero'], $m)) {
                    $used[(int)$m[1]] = true;
                }
            }

            // 3. Insérer uniquement les places manquantes
            $stmt_insert = $pdo->prepare("INSERT INTO places (ticket_type_id, numero, statut) VALUES (?, ?, 'libre')");
            for ($i = 1; $i <= $qty; $i++) {
                if (isset($used[$i])) {
                    continue;
                }
                $stmt_insert->execute([$type_id, "Place " . $i]);
                $total_generated++;
            }
        }

        $pdo->commit();

        if ($total_generated > 0) {
            $message  = "✅ Succès : $total_generated place(s) ont été générées !";
            $msg_type = "success";
        } else {
            $message  = "ℹ️ Aucune place manquante : tout est déjà synchronisé.";
            $msg_type = "success";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message  = "❌ Erreur lors de la génération : " . $e->getMessage();
        $msg_type = "error";
    }
}

// Statistiques affichées sous le bouton
$nb_types   = (int)$pdo->query("SELECT COUNT(*) FROM ticket_types")->fetchColumn();
$nb_places  = (int)$pdo->query("SELECT COUNT(*) FROM places")->fetchColumn();
$nb_attendu = (int)$pdo->query("SELECT COALESCE(SUM(quantite), 0) FROM ticket_types")->fetchColumn();
?>
<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Maintenance Système</span>
        <h1><i class="fa-solid fa-couch"></i> Générateur de Places</h1>
        <p>Cet outil synchronise la table des places physiques avec les quantités de billets définies dans les types de tickets. Utile après la création d'un événement.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="margin-bottom: 1.5rem;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="content-section" style="max-width: 800px; margin: 0 auto;">
    <div style="background: #f8fafc; border: 1px solid var(--line); border-radius: 16px; padding: 2.5rem; box-shadow: var(--shadow-md); text-align: center;">
        <div style="width: 64px; height: 64px; background: #e0f2fe; color: var(--primary); border-radius: 50%; display: grid; place-items: center; font-size: 1.5rem; margin: 0 auto 1.5rem;">
            <i class="fa-solid fa-couch"></i>
        </div>

        <h2 style="color: var(--navy); margin-bottom: 1rem;">Initialiser les sièges</h2>
        <p style="color: var(--muted); margin-bottom: 2rem; line-height: 1.6;">
            L'outil va parcourir tous vos types de billets et créer les entrées correspondantes dans la table <strong>'places'</strong>.<br>
            Si des places existent déjà, il ne rajoutera que celles manquantes.
        </p>

        <!-- État actuel de la synchronisation -->
        <div style="display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; margin-bottom: 2rem; font-size: 0.9rem;">
            <div><strong style="font-size: 1.3rem; color: var(--navy);"><?php echo $nb_types; ?></strong><br><span style="color: var(--muted);">Types de billets</span></div>
            <div><strong style="font-size: 1.3rem; color: var(--navy);"><?php echo $nb_attendu; ?></strong><br><span style="color: var(--muted);">Places attendues</span></div>
            <div><strong style="font-size: 1.3rem; color: <?php echo $nb_places >= $nb_attendu ? '#16a34a' : '#f59e0b'; ?>;"><?php echo $nb_places; ?></strong><br><span style="color: var(--muted);">Places en base</span></div>
        </div>

        <form method="POST">
            <button type="submit" name="generate" class="btn-submit" style="width: auto; padding: 1rem 2.5rem; font-size: 1.1rem; background: var(--primary);" onclick="return confirm('Générer les places manquantes pour tous les types de billets ?');">
                <i class="fa-solid fa-gears"></i> Lancer la génération automatique
            </button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>