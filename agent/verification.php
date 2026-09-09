<?php
// ==============================================================================
// VÉRIFICATION DES BILLETS PAR L'AGENT (agent/verification.php)
// Interface de contrôle d'accès simple, rapide et aux couleurs Tikéli
// ==============================================================================

$page_title = "Contrôle d'Accès - Espace Agent";
include 'header.php';

$result = null;
$agent_id = (int) $_SESSION['user_id'];

// 1. Récupération des affectations de l'agent
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
                'title' => 'TICKET INCONNU',
                'msg' => 'Ce code de billet n’existe pas dans la base.',
                'color' => '#ef4444',
                'icon' => 'fa-circle-xmark'
            ];
        } elseif (!empty($authorized_event_ids) && !in_array((int) $ticket['event_id'], array_map('intval', $authorized_event_ids), true)) {
            $result = [
                'status' => 'wrong_event',
                'title' => 'MAUVAIS ÉVÉNEMENT',
                'msg' => 'Billet émis pour « ' . htmlspecialchars($ticket['event_name']) . ' ».',
                'data' => $ticket,
                'color' => '#ef4444',
                'icon' => 'fa-ban'
            ];
        } elseif ($ticket['statut'] === 'utilise') {
            $date_u = !empty($ticket['date_utilisation']) ? date('d/m/Y à H:i', strtotime($ticket['date_utilisation'])) : 'Date inconnue';
            $result = [
                'status' => 'already_used',
                'title' => 'DÉJÀ COMPOSTÉ',
                'msg' => 'Validé le ' . $date_u . ($ticket['agent_nom'] ? ' par ' . htmlspecialchars($ticket['agent_nom']) : '') . '.',
                'data' => $ticket,
                'color' => '#f59e0b',
                'icon' => 'fa-triangle-exclamation'
            ];
        } elseif ($ticket['statut'] === 'annule') {
            $result = [
                'status' => 'cancelled',
                'title' => 'BILLET ANNULÉ',
                'msg' => 'Ce billet a été remboursé ou annulé.',
                'data' => $ticket,
                'color' => '#ef4444',
                'icon' => 'fa-ban'
            ];
        } else {
            $result = [
                'status' => 'valid',
                'title' => 'ACCÈS AUTORISÉ',
                'msg' => 'Billet authentique et valide.',
                'data' => $ticket,
                'color' => '#10b981',
                'icon' => 'fa-circle-check'
            ];
        }
    }
}

// 3. Validation définitive du ticket (Passage à 'utilise')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_ticket_id'])) {
    $ticket_id = (int) $_POST['validate_ticket_id'];

    $stmt_check = $pdo->prepare("SELECT event_id FROM tickets WHERE id = ?");
    $stmt_check->execute([$ticket_id]);
    $tk_check = $stmt_check->fetch();

    if ($tk_check && (empty($authorized_event_ids) || in_array((int) $tk_check['event_id'], array_map('intval', $authorized_event_ids), true))) {
        $stmt_val = $pdo->prepare("
            UPDATE tickets 
            SET statut = 'utilise', date_utilisation = NOW(), validated_by = ? 
            WHERE id = ? AND statut = 'vendu'
        ");
        $stmt_val->execute([$agent_id, $ticket_id]);
    }
}

// 4. Nombre de scans aujourd'hui
$stmt_scans_today = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE validated_by = ? AND DATE(date_utilisation) = CURRENT_DATE");
$stmt_scans_today->execute([$agent_id]);
$kpi_scans_today = (int) $stmt_scans_today->fetchColumn();
?>

<script>
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
    window.scrollTo(0, 0);
</script>

<style>
    .agent-main-container {
        flex: 1;
        width: 100%;
        box-sizing: border-box;
        padding: clamp(0.75rem, 1.8vw, 1.25rem) clamp(0.75rem, 2.5vw, 1.5rem);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
    }
    .agent-card-wrapper {
        width: 100%;
        max-width: 820px;
        margin: 0 auto;
        box-sizing: border-box;
    }
    .agent-scanner-card {
        background: var(--eventia-white, #ffffff);
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 18px;
        padding: clamp(1rem, 2vw, 1.35rem);
        box-shadow: 0 4px 16px rgba(0,0,0,0.03);
        box-sizing: border-box;
    }
    .agent-card-header {
        background: #FAFAFA;
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 12px;
        padding: 0.55rem 0.85rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
    }
    .agent-event-info {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 180px;
        flex: 1;
        overflow: hidden;
    }
    .agent-event-icon {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        background: rgba(255, 74, 13, 0.12);
        color: var(--tikeli-orange, #FF4A0D);
        display: grid;
        place-items: center;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    .agent-event-label {
        color: var(--eventia-muted, #737373);
        font-size: 0.7rem;
        display: block;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        line-height: 1.1;
    }
    .agent-event-title {
        color: var(--eventia-navy, #000000);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
        font-size: 0.86rem;
    }
    .agent-badge-active {
        color: var(--tikeli-orange, #FF4A0D);
        font-weight: 800;
        font-size: 0.72rem;
        background: #FFF2ED;
        padding: 2px 7px;
        border-radius: 999px;
        border: 1px solid rgba(255, 74, 13, 0.25);
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #FF4A0D;
    }
    .agent-grid-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        align-items: stretch;
    }
    @media (max-width: 680px) {
        .agent-grid-layout {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }
    .agent-cam-col {
        display: flex;
        flex-direction: column;
    }
    .agent-controls-col {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.85rem;
    }
    #qr-reader {
        width: 100% !important;
        height: 180px;
        background: #000000;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 0.65rem;
        display: grid;
        place-items: center;
        color: #737373;
        position: relative;
    }
    #qr-reader video {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover;
        border-radius: 10px;
    }
    #qr-reader__scan_region {
        background: #000000;
    }
    .agent-section-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 800;
        color: var(--eventia-muted, #737373);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.4rem;
    }
    .agent-manual-form {
        display: flex;
        gap: 6px;
        align-items: stretch;
    }
    .agent-code-input {
        flex: 1;
        min-width: 0;
        padding: 0.6rem 0.75rem;
        border-radius: 8px;
        border: 1px solid var(--eventia-border, #E5E5E5);
        font-family: monospace;
        font-size: 0.88rem;
        font-weight: 800;
        text-transform: uppercase;
        color: var(--eventia-navy, #000000);
        background: var(--eventia-background, #F5F5F5);
    }
    .agent-code-btn {
        padding: 0.6rem 1rem;
        font-size: 0.84rem;
        font-weight: 800;
        white-space: nowrap;
        border-radius: 8px;
    }
    .agent-stats-box {
        background: var(--eventia-background, #F5F5F5);
        border: 1px solid var(--eventia-border, #E5E5E5);
        border-radius: 10px;
        padding: 0.75rem 0.85rem;
    }
</style>

<main class="agent-main-container">
    <div class="agent-card-wrapper">

        <?php if ($result): ?>
            <!-- ==============================================================================
                 ÉCRAN RÉSULTAT (COMPACT, CLAIR & VISUEL)
                 ============================================================================== -->
            <div style="background: var(--eventia-white, #ffffff); border: 2px solid <?php echo $result['color']; ?>; border-radius: 18px; padding: clamp(1.2rem, 3vw, 1.75rem); text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.06); max-width: 540px; margin: 0 auto; box-sizing: border-box;">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: <?php echo $result['status'] === 'valid' ? '#ecfdf5' : ($result['status'] === 'already_used' ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $result['color']; ?>; display: grid; place-items: center; font-size: 2rem; margin: 0 auto 0.85rem;">
                    <i class="fa-solid <?php echo $result['icon']; ?>"></i>
                </div>

                <h2 style="color: <?php echo $result['color']; ?>; margin: 0 0 0.35rem; font-size: 1.35rem; font-weight: 900; font-family: var(--font-heading, 'Outfit', sans-serif); letter-spacing: -0.02em;">
                    <?php echo $result['title']; ?>
                </h2>

                <p style="color: var(--eventia-navy, #000000); font-size: 0.88rem; font-weight: 600; margin: 0 0 1rem; line-height: 1.35;">
                    <?php echo $result['msg']; ?>
                </p>

                <?php if (!empty($result['data'])): ?>
                    <?php $tk = $result['data']; ?>
                    <div style="background: var(--eventia-background, #F5F5F5); border: 1px solid var(--eventia-border, #E5E5E5); border-radius: 10px; padding: 0.85rem 1rem; text-align: left; font-size: 0.84rem; margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; border-bottom: 1px dashed var(--eventia-border, #E5E5E5); padding-bottom: 0.45rem; gap: 8px;">
                            <span style="font-weight: 800; color: #FF4A0D; background: #FFF2ED; padding: 2px 7px; border-radius: 6px; font-size: 0.78rem;">
                                <i class="fa-solid fa-ticket" style="margin-right: 4px;"></i> <?php echo htmlspecialchars($tk['type_ticket']); ?>
                            </span>
                            <strong style="color: var(--eventia-navy, #000000); font-size: 0.95rem; font-family: var(--font-heading, 'Outfit', sans-serif);">
                                <?php echo number_format($tk['prix'], 0, ',', ' '); ?> F
                            </strong>
                        </div>

                        <div style="color: var(--eventia-navy, #000000); font-weight: 700; font-size: 0.9rem; margin-bottom: 0.25rem;">
                            <i class="fa-solid fa-user" style="color: var(--eventia-muted, #737373); font-size: 0.8rem; margin-right: 6px;"></i>
                            <?php echo htmlspecialchars($tk['client_nom']); ?>
                        </div>
                        <div style="color: var(--eventia-muted, #737373); font-size: 0.76rem; font-family: monospace; word-break: break-all;">
                            Code Billet : <strong style="color: var(--eventia-navy, #000000); font-size: 0.82rem;"><?php echo htmlspecialchars($tk['code_unique']); ?></strong>
                        </div>
                    </div>

                    <?php if ($result['status'] === 'valid'): ?>
                        <form id="auto-validate-form" method="POST" action="verification.php" style="margin-bottom: 0.65rem;">
                            <input type="hidden" name="validate_ticket_id" value="<?php echo (int) $tk['id']; ?>">
                            <button type="submit" class="eventia-btn-primary" style="width: 100%; padding: 0.75rem; font-size: 0.92rem; font-weight: 800; justify-content: center; border-radius: 10px;">
                                <i class="fa-solid fa-check"></i> Valider l'Entrée
                            </button>
                        </form>
                        <script>
                            setTimeout(function () {
                                document.getElementById('auto-validate-form').submit();
                            }, 1200);
                        </script>
                    <?php endif; ?>
                <?php endif; ?>

                <a href="verification.php" class="eventia-btn-secondary" style="width: 100%; padding: 0.7rem; font-size: 0.86rem; font-weight: 700; justify-content: center; text-decoration: none; display: inline-flex; border-radius: 10px; box-sizing: border-box;">
                    <i class="fa-solid fa-rotate-left"></i> Prêt pour le suivant
                </a>
            </div>

        <?php else: ?>
            <!-- ==============================================================================
                 CARTE PRINCIPALE AGENT (GRID 2 COLONNES DESKTOP / 1 COLONNE MOBILE)
                 ============================================================================== -->
            <div class="agent-scanner-card">

                <!-- En-tête : Poste de Contrôle & Statut -->
                <?php if (count($my_assignments) > 0): ?>
                    <div class="agent-card-header">
                        <div class="agent-event-info">
                            <span class="agent-event-icon">
                                <i class="fa-solid fa-calendar-check"></i>
                            </span>
                            <div style="overflow: hidden;">
                                <small class="agent-event-label">Poste de Contrôle</small>
                                <strong class="agent-event-title">
                                    <?php echo htmlspecialchars($my_assignments[0]['assigned_event_nom']); ?>
                                </strong>
                            </div>
                        </div>
                        <span class="agent-badge-active">
                            <span class="badge-dot"></span> Actif
                        </span>
                    </div>
                <?php endif; ?>

                <div class="agent-grid-layout">
                    <!-- Colonne Gauche : Caméra & Bouton Activation -->
                    <div class="agent-cam-col">
                        <div id="qr-reader">
                            <div style="text-align: center; padding: 1.25rem 1rem;">
                                <div style="width: 50px; height: 50px; border: 2px dashed rgba(255, 74, 13, 0.45); border-radius: 10px; display: grid; place-items: center; margin: 0 auto 0.5rem;">
                                    <i class="fa-solid fa-qrcode" style="font-size: 1.55rem; color: var(--tikeli-orange, #FF4A0D);"></i>
                                </div>
                                <span style="font-size: 0.8rem; color: #737373; font-weight: 500; display: block;">Placez le QR code au centre</span>
                            </div>
                        </div>

                        <button type="button" id="btn-start-scanner" class="eventia-btn-primary" style="width: 100%; padding: 0.75rem 1rem; font-size: 0.88rem; justify-content: center; font-weight: 800; border-radius: 10px; box-shadow: 0 4px 12px rgba(255, 74, 13, 0.22);">
                            <i class="fa-solid fa-camera"></i> Activer la Caméra
                        </button>
                    </div>

                    <!-- Colonne Droite : Saisie Manuelle / Douchette + Statistiques -->
                    <div class="agent-controls-col">
                        <div>
                            <label for="code_unique" class="agent-section-label">
                                <i class="fa-solid fa-barcode" style="margin-right: 4px; color: var(--tikeli-orange, #FF4A0D);"></i> Saisie Manuelle / Douchette Laser
                            </label>
                            <form method="POST" action="verification.php" class="agent-manual-form">
                                <input type="text" id="code_unique" name="code_unique" required placeholder="Code billet (ex: TK-8F92A7K3)"
                                    autocomplete="off"
                                    class="agent-code-input">
                                <button type="submit" class="eventia-btn-secondary agent-code-btn">
                                    Vérifier
                                </button>
                            </form>
                            <small style="display: block; font-size: 0.72rem; color: var(--eventia-muted, #737373); margin-top: 0.35rem;">
                                Compatible lecteur laser USB / Bluetooth & clavier.
                            </small>
                        </div>

                        <!-- Bloc Statistiques & Lien Historique -->
                        <div class="agent-stats-box">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <span style="font-size: 0.82rem; color: var(--eventia-navy, #000000); font-weight: 600;">
                                    <i class="fa-solid fa-circle-check" style="color: var(--tikeli-orange, #FF4A0D); margin-right: 4px;"></i>
                                    Validations aujourd'hui
                                </span>
                                <span style="font-size: 1.15rem; font-weight: 900; color: var(--eventia-navy, #000000); font-family: var(--font-heading, 'Outfit', sans-serif);">
                                    <?php echo $kpi_scans_today; ?>
                                </span>
                            </div>
                            <a href="historique.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; color: var(--tikeli-orange, #FF4A0D); font-weight: 700; text-decoration: none; margin-top: 0.4rem;">
                                <i class="fa-solid fa-clock-rotate-left"></i> Voir l'historique complet des scans &rarr;
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Audio Feedback Synthesizer (Web Audio API) -->
<script>
    function playAudioTone(type) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            if (type === 'valid') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
                osc.frequency.setValueAtTime(880, ctx.currentTime + 0.08); // A5
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } else {
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                osc.frequency.setValueAtTime(150, ctx.currentTime + 0.12);
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
                osc.start();
                osc.stop(ctx.currentTime + 0.4);
            }
        } catch(e) {}
    }

    <?php if ($result): ?>
        document.addEventListener('DOMContentLoaded', function() {
            playAudioTone('<?php echo $result['status'] === 'valid' ? 'valid' : 'error'; ?>');
        });
    <?php endif; ?>
</script>

<!-- Script Scanner HTML5-QRCode -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    const startBtn = document.getElementById('btn-start-scanner');
    let scanner = null;

    if (startBtn) {
        startBtn.addEventListener('click', function () {
            if (scanner) return;

            scanner = new Html5Qrcode('qr-reader');
            scanner.start(
                { facingMode: 'environment' },
                { fps: 12, qrbox: { width: 220, height: 220 } },
                function (decodedText) {
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
                function () { }
            ).then(function () {
                startBtn.disabled = true;
                startBtn.innerHTML = '<i class="fa-solid fa-circle-dot" style="color: #FF4A0D;"></i> Caméra Active — Scannez';
                startBtn.style.background = '#000000';
                startBtn.style.color = '#ffffff';
            }).catch(function (err) {
                alert("Impossible d'accéder à la caméra. Veuillez utiliser la saisie manuelle.");
                scanner = null;
            });
        });
    }

    // Réinitialiser la position de défilement au chargement
    window.addEventListener('DOMContentLoaded', function () {
        window.scrollTo(0, 0);
    });
</script>

<?php include 'footer.php'; ?>