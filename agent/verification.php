<?php
// ==============================================================================
// VÉRIFICATION DES BILLETS PAR L'AGENT (agent/verification.php)
// Contrôle d'accès strict : l'agent ne peut valider QUE les billets de l'événement assigné
// ==============================================================================

$page_title = "Vérification des Billets - Espace Agent";
include 'header.php';

$result = null;
$agent_id = (int)$_SESSION['user_id'];

// 1. Récupération des affectations de l'agent (Promoteur & Événements autorisés)
$stmt_my_assignments = $pdo->prepare("
    SELECT aa.*, e.id AS assigned_event_id, e.nom AS assigned_event_nom, e.date_evenement, e.lieu,
           COALESCE(p.nom_commercial, u_prom.nom) AS promoter_name
    FROM agent_assignments aa
    JOIN events e ON aa.event_id = e.id
    JOIN users u_prom ON aa.promoter_user_id = u_prom.id
    LEFT JOIN promoters p ON u_prom.id = p.user_id
    WHERE aa.agent_id = ?
");
$stmt_my_assignments->execute([$agent_id]);
$my_assignments = $stmt_my_assignments->fetchAll();
$authorized_event_ids = array_column($my_assignments, 'assigned_event_id');

// 2. Recherche et vérification du code ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code_unique'])) {
    $code = strtoupper(trim($_POST['code_unique'] ?? ''));

    if (!empty($code)) {
        $stmt = $pdo->prepare("
            SELECT t.*, e.id AS event_id, e.nom AS event_name, e.date_evenement, e.heure, e.lieu, 
                   COALESCE(t.client_nom, u.nom, 'Client') AS client_nom, 
                   COALESCE(t.client_email, u.email, '') AS client_email,
                   ag.nom AS agent_nom,
                   COALESCE(p.nom_commercial, u_prom.nom) AS promoter_name
            FROM tickets t 
            JOIN events e ON t.event_id = e.id 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN users ag ON t.validated_by = ag.id
            JOIN users u_prom ON e.user_id = u_prom.id
            LEFT JOIN promoters p ON u_prom.id = p.user_id
            WHERE t.code_unique = ?
        ");
        $stmt->execute([$code]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            $result = [
                'status' => 'invalid',
                'title'  => '❌ TICKET INVALIDE',
                'msg'    => 'Ce code de billet n’existe pas dans la base de données.',
                'color'  => '#ef4444'
            ];
        } elseif (!empty($authorized_event_ids) && !in_array((int)$ticket['event_id'], array_map('intval', $authorized_event_ids), true)) {
            // Le ticket existe mais n'appartient PAS à l'événement assigné à cet agent !
            $result = [
                'status' => 'wrong_event',
                'title'  => '🚫 ACCÈS REFUSÉ (MAUVAIS ÉVÉNEMENT)',
                'msg'    => 'Ce billet est émis pour un AUTRE événement (« ' . htmlspecialchars($ticket['event_name']) . ' »). Vous n\'êtes pas autorisé à le composter à cette porte.',
                'data'   => $ticket,
                'color'  => '#dc2626'
            ];
        } elseif ($ticket['statut'] === 'utilise') {
            $date_u = !empty($ticket['date_utilisation']) ? date('d/m/Y à H:i', strtotime($ticket['date_utilisation'])) : 'Date inconnue';
            $result = [
                'status' => 'already_used',
                'title'  => '⚠️ BILLET DÉJÀ UTILISÉ',
                'msg'    => 'Ce billet a déjà été validé à l\'entrée le ' . $date_u . ($ticket['agent_nom'] ? ' par ' . htmlspecialchars($ticket['agent_nom']) : '') . '.',
                'data'   => $ticket,
                'color'  => '#f59e0b'
            ];
        } elseif ($ticket['statut'] === 'annule') {
            $result = [
                'status' => 'cancelled',
                'title'  => '❌ BILLET ANNULÉ',
                'msg'    => 'Ce billet a été invalidé ou remboursé.',
                'data'   => $ticket,
                'color'  => '#64748b'
            ];
        } else {
            // Le ticket est bien 'vendu' et correspond à l'événement assigné
            $result = [
                'status' => 'valid',
                'title'  => '✅ BILLET VALIDE POUR CET ÉVÉNEMENT',
                'msg'    => 'Le billet est authentique et conforme pour l\'accès en salle.',
                'data'   => $ticket,
                'color'  => '#10b981'
            ];
        }
    }
}

// 3. Validation définitive du ticket (Passage à 'utilise')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_ticket_id'])) {
    $ticket_id = (int)$_POST['validate_ticket_id'];

    // Vérification de sécurité supplémentaire sur l'événement
    $stmt_check = $pdo->prepare("SELECT event_id FROM tickets WHERE id = ?");
    $stmt_check->execute([$ticket_id]);
    $tk_check = $stmt_check->fetch();

    if ($tk_check && (empty($authorized_event_ids) || in_array((int)$tk_check['event_id'], array_map('intval', $authorized_event_ids), true))) {
        $stmt_val = $pdo->prepare("
            UPDATE tickets 
            SET statut = 'utilise', date_utilisation = NOW(), validated_by = ? 
            WHERE id = ? AND statut = 'vendu'
        ");
        $stmt_val->execute([$agent_id, $ticket_id]);

        if ($stmt_val->rowCount() === 1) {
            $success_validation = "✅ Entrée validée avec succès ! Le billet est composté.";
        } else {
            $error_validation = "❌ Impossible de valider : le billet a déjà été utilisé.";
        }
    } else {
        $error_validation = "🚫 Vous n'êtes pas assigné au contrôle de cet événement.";
    }
}
?>

<main class="container" style="margin: clamp(1rem, 3vw, 2rem) auto; padding: 0 var(--container-padding);">
    <!-- En-tête avec rappel du promoteur et de l'événement assigné -->
    <div class="page-header" style="margin-bottom: var(--spacing-lg);">
        <div class="page-heading">
            <span class="page-kicker" style="font-size: var(--font-size-xs);"><i class="fa-solid fa-shield-halved"></i> Contrôle d'Accès Sécurisé</span>
            <h1 style="font-size: var(--font-size-3xl); margin-bottom: var(--spacing-sm);">Vérification des Entrées</h1>
            <p style="font-size: var(--font-size-sm); color: var(--muted);">Scannez le QR Code ou saisissez le numéro de billet pour autoriser l'accès.</p>
        </div>
        <div style="font-size: var(--font-size-xs); text-align: left; margin-top: var(--spacing-md);">
            <div style="margin-bottom: 0.5rem;">Agent : <strong style="color: var(--navy);"><?php echo htmlspecialchars($_SESSION['user_nom']); ?></strong></div>
            <?php if (count($my_assignments) > 0): ?>
                <span style="color: var(--primary); font-weight: 700; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-briefcase"></i> Promoteur : <?php echo htmlspecialchars($my_assignments[0]['promoter_name']); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Badge des postes assignés à l'agent -->
    <div style="background: #f0fdfa; border: 1px solid #99f6e4; border-radius: var(--radius-md); padding: clamp(0.75rem, 2vw, 1rem); margin-bottom: var(--spacing-lg); display: flex; flex-direction: column; gap: var(--spacing-sm);">
        <div style="display: flex; align-items: flex-start; gap: var(--spacing-md);">
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #ccfbf1; color: var(--primary); display: grid; place-items: center; font-size: 1.1rem; flex-shrink: 0;">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
            <div style="flex: 1; min-width: 0;">
                <strong style="color: #0f766e; font-size: var(--font-size-xs); text-transform: uppercase; display: block; margin-bottom: 0.5rem;">Votre poste de contrôle :</strong>
                <?php if (count($my_assignments) > 0): ?>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <?php foreach ($my_assignments as $as): ?>
                            <span style="display: inline-block; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; padding: clamp(0.4rem, 1vw, 0.5rem) clamp(0.6rem, 1.5vw, 0.8rem); font-weight: 700; font-size: var(--font-size-xs); color: var(--navy); word-break: break-word;">
                                <?php echo htmlspecialchars($as['assigned_event_nom']); ?> (<?php echo date('d/m/Y', strtotime($as['date_evenement'])); ?> - <?php echo htmlspecialchars($as['lieu']); ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span style="color: #ef4444; font-weight: 600; font-size: var(--font-size-xs);">Aucun événement assigné par un promoteur.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alertes après validation -->
    <?php if (isset($success_validation)): ?>
        <div class="alert alert-success" style="font-size: var(--font-size-sm); padding: var(--spacing-md); margin-bottom: var(--spacing-lg); border-radius: var(--radius-md);">
            <i class="fa-solid fa-circle-check"></i> <?php echo $success_validation; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_validation)): ?>
        <div class="alert alert-error" style="font-size: var(--font-size-sm); padding: var(--spacing-md); margin-bottom: var(--spacing-lg); border-radius: var(--radius-md);">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_validation; ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(clamp(280px, 45vw, 360px), 1fr)); gap: var(--spacing-lg); align-items: start;">
        <!-- 1. Scanner Caméra & Saisie manuelle -->
        <div style="width: 100%; max-width: 100%; box-sizing: border-box;">
            <!-- Caméra -->
            <div class="content-section" style="margin-bottom: var(--spacing-lg); width: 100%; box-sizing: border-box;">
                <div class="section-title" style="font-size: var(--font-size-lg);"><i class="fa-solid fa-camera"></i> Scanner QR Code</div>
                <div id="qr-reader" style="width: 100%; height: clamp(250px, 50vw, 320px); border-radius: 8px; overflow: hidden; margin-bottom: var(--spacing-md);"></div>
                <button type="button" id="btn-start-scanner" class="btn-submit" style="width: 100%; padding: clamp(0.65rem, 1.5vw, 0.85rem); font-size: var(--font-size-sm);">
                    <i class="fa-solid fa-camera"></i> Activer la Caméra
                </button>
            </div>

            <!-- Saisie Manuelle -->
            <div class="content-section" style="width: 100%; box-sizing: border-box;">
                <div class="section-title" style="font-size: var(--font-size-lg);"><i class="fa-solid fa-keyboard"></i> Saisie Manuelle</div>
                <form method="POST" action="verification.php" style="width: 100%; box-sizing: border-box;">
                    <div class="form-group" style="width: 100%; box-sizing: border-box;">
                        <label for="code_unique" style="font-size: var(--font-size-sm); font-weight: 600;">Code Unique (ex: TK-8F92A7K3)</label>
                        <input type="text" id="code_unique" name="code_unique" required placeholder="TK-..." style="text-transform: uppercase; font-family: monospace; font-size: clamp(0.9rem, 2.5vw, 1.15rem); letter-spacing: 1px; width: 100%; padding: clamp(0.65rem, 1.5vw, 0.85rem); box-sizing: border-box;">
                    </div>
                    <button type="submit" class="btn-submit" style="width: 100%; font-size: var(--font-size-sm); padding: clamp(0.65rem, 1.5vw, 0.85rem);">
                        <i class="fa-solid fa-magnifying-glass"></i> Vérifier le Ticket
                    </button>
                </form>
            </div>
        </div>

        <!-- 2. Résultat de la Vérification -->
        <div style="width: 100%; max-width: 100%; box-sizing: border-box;">
            <div class="content-section" style="min-height: auto; width: 100%; box-sizing: border-box;">
                <div class="section-title" style="font-size: var(--font-size-lg);"><i class="fa-solid fa-id-badge"></i> Résultat du Contrôle</div>

                <?php if ($result): ?>
                    <div style="border: 2px solid <?php echo $result['color']; ?>; background: #ffffff; border-radius: var(--radius-lg); padding: clamp(1rem, 2vw, 1.5rem); text-align: center; margin-top: var(--spacing-md); box-shadow: var(--shadow-md); width: 100%; box-sizing: border-box;">
                        <h2 style="color: <?php echo $result['color']; ?>; margin: 0 0 var(--spacing-sm); font-size: clamp(1.1rem, 4vw, 1.35rem); word-break: break-word;">
                            <?php echo $result['title']; ?>
                        </h2>
                        <p style="color: var(--navy); margin-bottom: var(--spacing-lg); font-size: var(--font-size-sm); font-weight: 500; word-break: break-word;">
                            <?php echo $result['msg']; ?>
                        </p>

                        <?php if (!empty($result['data'])): ?>
                            <?php $tk = $result['data']; ?>
                            <div style="background: #f8fafc; border: 1px solid var(--line); border-radius: 8px; padding: clamp(0.75rem, 2vw, 1rem); text-align: left; font-size: var(--font-size-xs); margin-bottom: var(--spacing-lg); width: 100%; box-sizing: border-box; overflow: hidden;">
                                <div style="margin-bottom: 0.5rem;">
                                    <span style="color: var(--muted); font-size: var(--font-size-xs); text-transform: uppercase; font-weight: bold;">Événement du billet :</span><br>
                                    <strong style="color: var(--navy); font-size: clamp(0.95rem, 2.5vw, 1.05rem); word-break: break-word;"><?php echo htmlspecialchars($tk['event_name']); ?></strong>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <span style="word-break: break-word;">Catégorie : <strong style="color: var(--primary);"><?php echo htmlspecialchars($tk['type_ticket']); ?></strong></span>
                                    <span style="word-break: break-word;">Prix : <strong><?php echo number_format($tk['prix'], 0, ',', ' '); ?> F</strong></span>
                                </div>
                                <div style="margin-bottom: 0.5rem; word-break: break-word;">
                                    <span style="color: var(--muted);">Détenteur :</span>
                                    <strong><?php echo htmlspecialchars($tk['client_nom']); ?></strong>
                                </div>
                                <div style="word-break: break-word;">
                                    <span style="color: var(--muted);">Date & Lieu :</span>
                                    <strong><?php echo date('d/m/Y', strtotime($tk['date_evenement'])); ?> à <?php echo substr($tk['heure'], 0, 5); ?></strong> (<?php echo htmlspecialchars($tk['lieu']); ?>)
                                </div>
                            </div>

                            <!-- Validation automatique si le ticket est 'valid' -->
                            <?php if ($result['status'] === 'valid'): ?>
                                <div style="text-align: center; padding: var(--spacing-md); background: #dcfce7; border-radius: 8px; margin-top: var(--spacing-md);">
                                    <p style="color: #166534; font-weight: 600; margin: 0 0 var(--spacing-md); display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: var(--font-size-sm);">
                                        <i class="fa-solid fa-spinner" style="animation: spin 2s linear infinite;"></i>
                                        Validation automatique en cours...
                                    </p>
                                </div>
                                <form id="auto-validate-form" method="POST" action="verification.php" style="display: none;">
                                    <input type="hidden" name="validate_ticket_id" value="<?php echo (int)$tk['id']; ?>">
                                </form>
                                <script>
                                    setTimeout(function() {
                                        document.getElementById('auto-validate-form').submit();
                                    }, 1500);
                                </script>
                                <style>
                                    @keyframes spin {
                                        0% { transform: rotate(0deg); }
                                        100% { transform: rotate(360deg); }
                                    }
                                </style>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; color: var(--muted); padding: clamp(2rem, 5vw, 3rem) var(--spacing-md);">
                        <i class="fa-solid fa-barcode" style="font-size: clamp(2rem, 8vw, 3.5rem); color: var(--line); margin-bottom: var(--spacing-md); display: block;"></i>
                        <h3 style="color: var(--navy); margin-bottom: 0.4rem; font-size: var(--font-size-lg); word-break: break-word;">En attente de scan</h3>
                        <p style="font-size: var(--font-size-sm); word-break: break-word;">Scannez un QR code ou saisissez un numéro pour vérifier l'accès.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Script Scanner HTML5-QRCode -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    const startBtn = document.getElementById('btn-start-scanner');
    let scanner = null;

    startBtn.addEventListener('click', function() {
        if (scanner) return;

        scanner = new Html5Qrcode('qr-reader');
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            function(decodedText) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'verification.php';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'code_unique';
                input.value = decodedText.trim();

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            },
            function() {}
        ).then(function() {
            startBtn.disabled = true;
            startBtn.innerHTML = '<i class="fa-solid fa-circle" style="color: #10b981;"></i> Scanner actif...';
        }).catch(function(err) {
            alert("Impossible d'accéder à la caméra. Veuillez utiliser la saisie manuelle.");
            scanner = null;
        });
    });
</script>

<?php include 'footer.php'; ?>
