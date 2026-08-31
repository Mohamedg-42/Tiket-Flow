<?php
// ==============================================================================
// DEMANDE DE CRÉATION D'ÉVÉNEMENT (promoteur/demande-evenement.php)
// Mise en page soignée, transparence de la commission plateforme et calculs en temps réel
// ==============================================================================

$page_title = "Proposer un Événement - Espace Promoteur";
include 'header.php';

$message = "";
$msg_type = "";

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom            = trim($_POST['nom'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $categorie      = trim($_POST['categorie'] ?? 'Concert');
    $date_evenement = $_POST['date_evenement'] ?? '';
    $heure          = $_POST['heure'] ?? '';
    $lieu           = trim($_POST['lieu'] ?? '');
    $infos_supp     = trim($_POST['infos_supplementaires'] ?? '');

    // Statut juridique & documents
    $type_personne  = $_POST['type_personne'] ?? 'physique';
    $nom_structure  = trim($_POST['nom_structure'] ?? '');
    $numero_rccm    = trim($_POST['numero_rccm'] ?? '');

    // Types de tickets
    $ticket_noms    = $_POST['ticket_nom'] ?? [];
    $ticket_prix    = $_POST['ticket_prix'] ?? [];
    $ticket_qtys    = $_POST['ticket_quantite'] ?? [];

    // Validation
    if (empty($nom) || empty($description) || empty($date_evenement) || empty($heure) || empty($lieu)) {
        $message = "Veuillez remplir tous les champs obligatoires de l'événement.";
        $msg_type = "error";
    } elseif ($type_personne === 'morale' && empty($nom_structure)) {
        $message = "Veuillez indiquer la raison sociale de votre entreprise ou association.";
        $msg_type = "error";
    } elseif (empty($ticket_noms) || count($ticket_noms) === 0) {
        $message = "Veuillez définir au moins un tarif de ticket pour votre événement.";
        $msg_type = "error";
    } else {
        $docs_dir = '../uploads/event_docs/';
        if (!is_dir($docs_dir)) {
            mkdir($docs_dir, 0777, true);
        }

        // 1. Upload de l'affiche
        $image_name = 'default.jpg';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_events = '../uploads/events/';
                if (!is_dir($upload_events)) {
                    mkdir($upload_events, 0777, true);
                }
                $image_name = 'event_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_events . $image_name);
            }
        }

        // 2. Upload du Document Justificatif
        $doc_justificatif = null;
        if (isset($_FILES['document_justificatif']) && $_FILES['document_justificatif']['error'] === UPLOAD_ERR_OK) {
            $ext_doc = strtolower(pathinfo($_FILES['document_justificatif']['name'], PATHINFO_EXTENSION));
            $allowed_docs = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext_doc, $allowed_docs, true)) {
                $doc_justificatif = 'justif_' . uniqid() . '.' . $ext_doc;
                move_uploaded_file($_FILES['document_justificatif']['tmp_name'], $docs_dir . $doc_justificatif);
            }
        }

        // 3. Upload de l'autorisation de manifestation
        $doc_autorisation = null;
        if (isset($_FILES['document_autorisation']) && $_FILES['document_autorisation']['error'] === UPLOAD_ERR_OK) {
            $ext_auth = strtolower(pathinfo($_FILES['document_autorisation']['name'], PATHINFO_EXTENSION));
            $allowed_docs = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext_auth, $allowed_docs, true)) {
                $doc_autorisation = 'auth_' . uniqid() . '.' . $ext_auth;
                move_uploaded_file($_FILES['document_autorisation']['tmp_name'], $docs_dir . $doc_autorisation);
            }
        }

        // 4. Structuration des types de tickets
        $tickets_data = [];
        for ($i = 0; $i < count($ticket_noms); $i++) {
            $t_nom  = trim($ticket_noms[$i]);
            $t_prix = (float)($ticket_prix[$i] ?? 0);
            $t_qty  = (int)($ticket_qtys[$i] ?? 0);

            if (!empty($t_nom) && $t_prix > 0 && $t_qty > 0) {
                $tickets_data[] = [
                    'nom'      => $t_nom,
                    'prix'     => $t_prix,
                    'quantite' => $t_qty
                ];
            }
        }

        if (empty($tickets_data)) {
            $message = "Veuillez configurer au moins un type de ticket valide (nom, prix supérieur à 0 et quantité positive).";
            $msg_type = "error";
        } else {
            try {
                $sql = "INSERT INTO event_requests (
                            user_id, nom, description, image, categorie, date_evenement, heure, lieu, 
                            infos_supplementaires, type_personne, nom_structure, numero_rccm, 
                            document_justificatif, document_autorisation, ticket_types_data, statut
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_SESSION['user_id'],
                    $nom,
                    $description,
                    $image_name,
                    $categorie,
                    $date_evenement,
                    $heure,
                    $lieu,
                    $infos_supp,
                    $type_personne,
                    $nom_structure,
                    $numero_rccm,
                    $doc_justificatif,
                    $doc_autorisation,
                    json_encode($tickets_data, JSON_UNESCAPED_UNICODE)
                ]);

                $message = "Votre proposition d'événement a été transmise à l'administrateur avec succès ! Vous pouvez suivre son approbation dans « Mes Événements ».";
                $msg_type = "success";

            } catch (PDOException $e) {
                $message = "Erreur lors de l'envoi de la demande : " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}
?>

<style>
    .step-card {
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        padding: 1.75rem 2rem;
        margin-bottom: 1.75rem;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
    }
    .step-card:hover {
        border-color: #cbd5e1;
        box-shadow: var(--shadow-md);
    }
    .step-header {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.85rem;
        border-bottom: 1px solid var(--line-light);
    }
    .step-number {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: var(--primary);
        color: #ffffff;
        display: grid;
        place-items: center;
        font-weight: 800;
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .step-title {
        margin: 0;
        font-size: 1.15rem;
        color: var(--navy);
    }
    .step-subtitle {
        color: var(--muted);
        font-size: 0.82rem;
        margin: 0;
    }
    .entity-toggle {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .entity-option {
        border: 2px solid var(--line);
        border-radius: var(--radius-md);
        padding: 1rem 1.25rem;
        cursor: pointer;
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        transition: var(--transition);
        background: #f8fafc;
    }
    .entity-option:hover {
        border-color: var(--primary-light);
        background: #ffffff;
    }
    .entity-option input {
        margin-top: 0.25rem;
        accent-color: var(--primary);
        width: auto;
    }
    .entity-option.active {
        border-color: var(--primary);
        background: var(--primary-soft);
    }
    .entity-option strong {
        display: block;
        color: var(--navy);
        font-size: 0.95rem;
        margin-bottom: 0.2rem;
    }
    .entity-option small {
        color: var(--muted);
        font-size: 0.78rem;
        line-height: 1.4;
        display: block;
    }
    .file-upload-box {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: var(--radius-md);
        padding: 1.25rem;
        transition: var(--transition);
    }
    .file-upload-box:hover {
        border-color: var(--primary);
        background: #ffffff;
    }
    .ticket-row-item {
        background: #f8fafc;
        border: 1px solid var(--line);
        border-radius: var(--radius-md);
        padding: 1rem 1.25rem;
        display: grid;
        grid-template-columns: 2fr 1.2fr 1fr auto;
        gap: 1rem;
        align-items: end;
        margin-bottom: 0.85rem;
        transition: var(--transition);
    }
    .ticket-row-item:hover {
        border-color: #cbd5e1;
        background: #ffffff;
    }
    .commission-info-box {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: var(--radius-md);
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .commission-info-icon {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #dbeafe;
        color: #1d4ed8;
        display: grid;
        place-items: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .summary-badge-bar {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border-radius: var(--radius-md);
        padding: 1.25rem 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1.25rem;
        margin-top: 1rem;
        margin-bottom: 1.5rem;
    }
    .summary-item small {
        color: #94a3b8;
        display: block;
        font-size: 0.73rem;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 0.2rem;
    }
    .summary-item strong {
        font-size: 1.2rem;
        color: #ffffff;
    }
</style>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Organisateur Partner</span>
        <h1><i class="fa-solid fa-calendar-plus" style="color: var(--primary);"></i> Proposer un Événement</h1>
        <p>Remplissez le formulaire étape par étape pour soumettre votre événement à la validation administrative.</p>
    </div>
    <a href="mes-evenements.php" class="btn-submit" style="width: auto; text-decoration: none; padding: 0.65rem 1.25rem; background: #ffffff; color: var(--navy); border: 1px solid var(--line);">
        <i class="fa-solid fa-list-check"></i> Mes Événements
    </a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <div>
            <?php echo htmlspecialchars($message); ?>
            <?php if ($msg_type === 'success'): ?>
                <div style="margin-top: 0.4rem;">
                    <a href="mes-evenements.php" style="font-weight: bold; color: inherit; text-decoration: underline;">Consulter l'état de validation de ma demande →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="max-width: 920px;">

    <!-- =========================================================================
         ÉTAPE 1 : STATUT JURIDIQUE & PIÈCES JUSTIFICATIVES
         ========================================================================= -->
    <div class="step-card">
        <div class="step-header">
            <div class="step-number">1</div>
            <div>
                <h2 class="step-title">Statut Juridique de l'Organisateur</h2>
                <p class="step-subtitle">Précisez sous quelle forme juridique vous organisez cette manifestation</p>
            </div>
        </div>

        <div class="entity-toggle">
            <label class="entity-option active" id="option-physique">
                <input type="radio" name="type_personne" value="physique" checked onchange="updateEntityType(this)">
                <div>
                    <strong><i class="fa-solid fa-user"></i> Personne Physique</strong>
                    <small>Particulier, artiste indépendant, organisateur individuel.</small>
                </div>
            </label>

            <label class="entity-option" id="option-morale">
                <input type="radio" name="type_personne" value="morale" onchange="updateEntityType(this)">
                <div>
                    <strong><i class="fa-solid fa-building"></i> Personne Morale</strong>
                    <small>Entreprise, société SARL/SAS, agence événementielle, association.</small>
                </div>
            </label>
        </div>

        <!-- Informations entreprise si personne morale -->
        <div id="fields-morale" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.25rem;">
            <div style="font-weight: bold; color: #166534; margin-bottom: 0.75rem; font-size: 0.9rem;">
                <i class="fa-solid fa-id-card"></i> Identifiants de la Structure
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label for="nom_structure">Raison sociale / Nom commercial *</label>
                    <input type="text" id="nom_structure" name="nom_structure" placeholder="Ex: Live Nation CI, Event Prod...">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="numero_rccm">Numéro RCCM / SIRET / Enregistrement</label>
                    <input type="text" id="numero_rccm" name="numero_rccm" placeholder="Ex: CI-ABJ-2026-B-9988">
                </div>
            </div>
        </div>

        <!-- Téléversement des justificatifs -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="file-upload-box">
                <div class="form-group" style="margin: 0;">
                    <label for="document_justificatif" id="label-justif">
                        <i class="fa-solid fa-file-pdf" style="color: var(--primary);"></i> Pièce d'identité (CNI / Passeport) *
                    </label>
                    <input type="file" id="document_justificatif" name="document_justificatif" accept=".pdf,.jpg,.jpeg,.png">
                    <small style="color: var(--muted); display: block; margin-top: 0.35rem; font-size: 0.76rem;">
                        Document officiel d'identification au format PDF ou image.
                    </small>
                </div>
            </div>

            <div class="file-upload-box">
                <div class="form-group" style="margin: 0;">
                    <label for="document_autorisation">
                        <i class="fa-solid fa-file-contract" style="color: #6366f1;"></i> Contrat de salle / Autorisation (Optionnel)
                    </label>
                    <input type="file" id="document_autorisation" name="document_autorisation" accept=".pdf,.jpg,.jpeg,.png">
                    <small style="color: var(--muted); display: block; margin-top: 0.35rem; font-size: 0.76rem;">
                        Accord de réservation de salle ou arrêté préfectoral.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         ÉTAPE 2 : INFORMATIONS DÉTAILLÉES DE L'ÉVÉNEMENT
         ========================================================================= -->
    <div class="step-card">
        <div class="step-header">
            <div class="step-number">2</div>
            <div>
                <h2 class="step-title">Informations de l'Événement</h2>
                <p class="step-subtitle">Décrivez le programme, la date, l'horaire et le lieu de la manifestation</p>
            </div>
        </div>

        <div class="form-group">
            <label for="nom"><i class="fa-solid fa-heading"></i> Nom complet de l'événement *</label>
            <input type="text" id="nom" name="nom" required placeholder="Ex: Mega Concert Live Abidjan 2026" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
            <div class="form-group">
                <label for="categorie"><i class="fa-solid fa-layer-group"></i> Catégorie *</label>
                <select id="categorie" name="categorie" required>
                    <option value="Concert">Concert / Musique</option>
                    <option value="Festival">Festival</option>
                    <option value="Spectacle">Spectacle / Humour / Théâtre</option>
                    <option value="Conférence">Conférence / Séminaire</option>
                    <option value="Sport">Sport & Tournoi</option>
                    <option value="Soirée">Soirée & Gala</option>
                    <option value="Autre">Autre événement</option>
                </select>
            </div>

            <div class="form-group">
                <label for="image"><i class="fa-solid fa-image"></i> Affiche officielle (Poster)</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
            </div>
        </div>

        <div class="form-group">
            <label for="description"><i class="fa-solid fa-align-left"></i> Description & Programme *</label>
            <textarea id="description" name="description" rows="4" required placeholder="Décrivez les artistes invités, les temps forts du spectacle, les conditions d'accès..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
            <div class="form-group">
                <label for="date_evenement"><i class="fa-regular fa-calendar"></i> Date de l'événement *</label>
                <input type="date" id="date_evenement" name="date_evenement" required value="<?php echo htmlspecialchars($_POST['date_evenement'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="heure"><i class="fa-regular fa-clock"></i> Heure de début *</label>
                <input type="time" id="heure" name="heure" required value="<?php echo htmlspecialchars($_POST['heure'] ?? ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="lieu"><i class="fa-solid fa-location-dot"></i> Salle / Lieu & Ville *</label>
            <input type="text" id="lieu" name="lieu" required placeholder="Ex: Palais de la Culture de Treichville, Abidjan" value="<?php echo htmlspecialchars($_POST['lieu'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="infos_supplementaires"><i class="fa-solid fa-circle-info"></i> Informations pratiques & Sécurité (Optionnel)</label>
            <textarea id="infos_supplementaires" name="infos_supplementaires" rows="2" placeholder="Accès parking, restrictions d'âge, consignes sanitaires..."><?php echo htmlspecialchars($_POST['infos_supplementaires'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- =========================================================================
         ÉTAPE 3 : TARIFICATION DE LA BILLETTERIE & COMMISSION DU SITE
         ========================================================================= -->
    <div class="step-card">
        <div class="step-header" style="justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.85rem;">
                <div class="step-number">3</div>
                <div>
                    <h2 class="step-title">Grille Tarifaire, Quotas & Commission</h2>
                    <p class="step-subtitle">Définissez vos tarifs et visualisez vos revenus nets estimés</p>
                </div>
            </div>

            <button type="button" onclick="addTicketRow()" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; font-size: 0.85rem; background: var(--primary);">
                <i class="fa-solid fa-plus"></i> Ajouter un tarif
            </button>
        </div>

        <!-- Encadré explicatif de la Commission Plateforme -->
        <div class="commission-info-box">
            <div class="commission-info-icon">
                <i class="fa-solid fa-percent"></i>
            </div>
            <div>
                <strong style="color: #1e3a8a; font-size: 0.95rem; display: block; margin-bottom: 0.2rem;">
                    Commission Plateforme Standard : 5.0% par billet vendu
                </strong>
                <p style="color: #3b82f6; font-size: 0.84rem; margin: 0; line-height: 1.4;">
                    La plateforme prélève automatiquement 5.0% de commission sur chaque ticket encaissé. Vous percevez <strong>95.0% du prix brut</strong> directement sur votre solde disponible pour retrait.
                </p>
            </div>
        </div>

        <div id="tickets-container">
            <!-- Ligne de tarif 1 -->
            <div class="ticket-row-item">
                <div>
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Catégorie</label>
                    <input type="text" name="ticket_nom[]" required placeholder="Ex: STANDARD" value="STANDARD" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Prix unitaire (FCFA)</label>
                    <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="5000" value="5000" oninput="calculateEventSummary()" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Places</label>
                    <input type="number" name="ticket_quantite[]" required min="1" placeholder="500" value="500" oninput="calculateEventSummary()" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
                </div>
                <div>
                    <button type="button" onclick="removeTicketRow(this)" style="background: #fee2e2; color: #ef4444; border: 0; border-radius: 6px; padding: 0.7rem 0.85rem; cursor: pointer;" title="Supprimer">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Barre de synthèse financière complète avec Commission et Gain Net -->
        <div class="summary-badge-bar">
            <div class="summary-item">
                <small>Capacité Totale</small>
                <strong id="summary-capacity">500 places</strong>
            </div>

            <div class="summary-item">
                <small>Recette Brute Max</small>
                <strong id="summary-gross">2 500 000 F</strong>
            </div>

            <div class="summary-item">
                <small>Commission Site (5%)</small>
                <strong id="summary-commission" style="color: #f59e0b;">125 000 F</strong>
            </div>

            <div class="summary-item">
                <small>Votre Gain Net Estimé (95%)</small>
                <strong id="summary-net" style="color: #10b981; font-size: 1.3rem;">2 375 000 FCFA</strong>
            </div>
        </div>

        <button type="submit" class="btn-submit" style="font-size: 1.1rem; padding: 1.1rem;">
            <i class="fa-solid fa-paper-plane"></i> Transmettre la Demande d'Événement à l'Admin
        </button>
    </div>
</form>

<script>
    const COMMISSION_RATE = 0.05; // 5%

    // 1. Basculement dynamique Personne Physique vs Personne Morale
    function updateEntityType(input) {
        const isMorale = (input.value === 'morale');
        const fieldsMorale = document.getElementById('fields-morale');
        const labelJustif = document.getElementById('label-justif');
        const optPhysique = document.getElementById('option-physique');
        const optMorale = document.getElementById('option-morale');

        if (isMorale) {
            optMorale.classList.add('active');
            optPhysique.classList.remove('active');
            fieldsMorale.style.display = 'block';
            labelJustif.innerHTML = '<i class="fa-solid fa-file-pdf" style="color: var(--primary);"></i> Registre de Commerce (RCCM) / Statuts (PDF, JPG) *';
        } else {
            optPhysique.classList.add('active');
            optMorale.classList.remove('active');
            fieldsMorale.style.display = 'none';
            labelJustif.innerHTML = '<i class="fa-solid fa-file-pdf" style="color: var(--primary);"></i> Pièce d\'identité (CNI / Passeport) (PDF, JPG) *';
        }
    }

    // 2. Ajout dynamique d'une ligne de tarif
    function addTicketRow() {
        const container = document.getElementById('tickets-container');
        const row = document.createElement('div');
        row.className = 'ticket-row-item';
        row.innerHTML = `
            <div>
                <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Catégorie</label>
                <input type="text" name="ticket_nom[]" required placeholder="Ex: VIP" value="VIP" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Prix unitaire (FCFA)</label>
                <input type="number" name="ticket_prix[]" required min="500" step="100" placeholder="15000" value="15000" oninput="calculateEventSummary()" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.8rem; font-weight: 700; color: var(--navy); display: block; margin-bottom: 4px;">Places</label>
                <input type="number" name="ticket_quantite[]" required min="1" placeholder="100" value="100" oninput="calculateEventSummary()" style="width: 100%; padding: 0.65rem; border: 1px solid var(--line); border-radius: 6px;">
            </div>
            <div>
                <button type="button" onclick="removeTicketRow(this)" style="background: #fee2e2; color: #ef4444; border: 0; border-radius: 6px; padding: 0.7rem 0.85rem; cursor: pointer;" title="Supprimer">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(row);
        calculateEventSummary();
    }

    function removeTicketRow(btn) {
        const rows = document.querySelectorAll('.ticket-row-item');
        if (rows.length > 1) {
            btn.closest('.ticket-row-item').remove();
            calculateEventSummary();
        } else {
            alert("Votre événement doit comporter au moins un type de billet.");
        }
    }

    // 3. Calcul en temps réel de la capacité, du brut, de la commission et du net
    function calculateEventSummary() {
        const priceInputs = document.querySelectorAll('input[name="ticket_prix[]"]');
        const qtyInputs   = document.querySelectorAll('input[name="ticket_quantite[]"]');

        let totalCapacity = 0;
        let totalGross    = 0;

        for (let i = 0; i < priceInputs.length; i++) {
            const price = Number(priceInputs[i].value) || 0;
            const qty   = Number(qtyInputs[i].value) || 0;

            totalCapacity += qty;
            totalGross    += (price * qty);
        }

        const totalCommission = totalGross * COMMISSION_RATE;
        const totalNet        = totalGross - totalCommission;

        document.getElementById('summary-capacity').textContent   = totalCapacity.toLocaleString('fr-FR') + ' places';
        document.getElementById('summary-gross').textContent      = totalGross.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('summary-commission').textContent = totalCommission.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('summary-net').textContent        = totalNet.toLocaleString('fr-FR') + ' FCFA';
    }

    // Calcul initial au chargement
    calculateEventSummary();
</script>

<?php include 'footer.php'; ?>
