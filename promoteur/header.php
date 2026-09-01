<?php
// ==============================================================================
// CENTRE DE CONTRÔLE LATÉRAL PROMOTEUR (promoteur/header.php)
// Navigation moderne Dashboard Pro, profil actif, solde & raccourcis opérationnels
// ==============================================================================

require_once '../config/database.php';
require_once '../includes/auth.php';

// Seul le rôle 'promoteur' ou 'admin' peut accéder
checkRole(['promoteur', 'admin'], '../connexion.php');

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'Centre de Contrôle Promoteur - Ticket Flow';
$user_id = (int) $_SESSION['user_id'];

// 1. Récupération des informations et du solde du promoteur
$stmt_p = $pdo->prepare("
    SELECT p.*, u.nom AS user_nom, u.email AS user_email 
    FROM promoters p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.user_id = ?
");
$stmt_p->execute([$user_id]);
$promoter_profile = $stmt_p->fetch(PDO::FETCH_ASSOC);

$solde_actuel = $promoter_profile ? (float) $promoter_profile['solde'] : 0.00;
$nom_affiche = !empty($promoter_profile['nom_commercial']) ? $promoter_profile['nom_commercial'] : ($promoter_profile['user_nom'] ?? ($_SESSION['nom'] ?? 'Promoteur'));

// Initiales pour l'avatar
$words = explode(' ', trim($nom_affiche));
$initiales = strtoupper(substr($words[0] ?? 'P', 0, 1) . substr($words[1] ?? '', 0, 1));

// 2. Badge "Mes Demandes" : événements en attente + campagnes de cotisations en attente
$stmt_bd = $pdo->prepare("SELECT COUNT(*) FROM event_requests WHERE user_id = ? AND statut = 'en_attente'");
$stmt_bd->execute([$user_id]);
$badge_demandes_promoteur = (int) $stmt_bd->fetchColumn();

try {
    $stmt_bc = $pdo->prepare("SELECT COUNT(*) FROM cotisation_campagnes WHERE user_id = ? AND statut = 'en_attente'");
    $stmt_bc->execute([$user_id]);
    $badge_demandes_promoteur += (int) $stmt_bc->fetchColumn();
} catch (PDOException $e) {
    // Table non encore migrée
}

// 3. Badge "Réclamations" : tickets en attente ou en cours
$stmt_cl = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND statut IN ('en_attente', 'en_cours')");
$stmt_cl->execute([$user_id]);
$badge_claims_promoteur = (int) $stmt_cl->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- FontAwesome 6.5.2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Styles CSS -->
    <link rel="stylesheet" href="../Css/style.css">
    <link rel="stylesheet" href="../Css/dashboard-pro.css">

    <style>
        /* ==============================================================================
           CENTRE DE CONTRÔLE LATÉRAL (SIDEBAR PRO)
           ============================================================================== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 275px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            padding: 1.25rem 1rem;
            background: #0b1329;
            color: #ffffff;
            border-right: 1px solid rgba(255, 255, 255, 0.07);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .ctrl-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.15rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-decoration: none;
        }

        .ctrl-brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.2px;
        }

        .ctrl-brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #0d9488, #0284c7);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.35);
        }

        .ctrl-badge-pro {
            background: rgba(13, 148, 136, 0.2);
            color: #2dd4bf;
            border: 1px solid rgba(45, 212, 191, 0.3);
            font-size: 0.65rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Profil utilisateur dans le centre de contrôle */
        .ctrl-user-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 0.65rem 0.75rem;
            margin-bottom: 0.85rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-user-card:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(45, 212, 191, 0.3);
        }

        .ctrl-avatar {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: linear-gradient(135deg, #3b82f6, #0d9488);
            color: #ffffff;
            font-weight: 800;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ctrl-user-info {
            overflow: hidden;
            flex: 1;
        }

        .ctrl-user-name {
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .ctrl-user-role {
            color: #10b981;
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Widget Solde Retirable */
        .ctrl-wallet-box {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(5, 150, 105, 0.06));
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
            margin-bottom: 1.15rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ctrl-wallet-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #6ee7b7;
            letter-spacing: 0.5px;
            display: block;
        }

        .ctrl-wallet-amount {
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .ctrl-wallet-btn {
            background: #10b981;
            color: #ffffff;
            padding: 4px 9px;
            border-radius: 7px;
            font-size: 0.72rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .ctrl-wallet-btn:hover {
            background: #059669;
            transform: scale(1.03);
        }

        /* Navigation Menu & Sections */
        .ctrl-nav-scroll {
            flex: 1;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding-right: 2px;
            margin-bottom: 0.75rem;
        }

        .ctrl-nav-scroll::-webkit-scrollbar {
            display: none;
        }

        .ctrl-section-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.8px;
            padding: 0.6rem 0.5rem 0.35rem;
            display: block;
        }

        .ctrl-menu {
            list-style: none;
            padding: 0;
            margin: 0 0 0.65rem;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .ctrl-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.55rem 0.75rem;
            border-radius: 9px;
            color: #94a3b8;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.18s ease;
            position: relative;
        }

        .ctrl-menu a i {
            font-size: 0.95rem;
            width: 18px;
            text-align: center;
            color: #64748b;
            transition: all 0.18s ease;
        }

        .ctrl-menu a:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(3px);
        }

        .ctrl-menu a:hover i {
            color: #2dd4bf;
        }

        .ctrl-menu a.active {
            color: #ffffff;
            background: linear-gradient(135deg, rgba(13, 148, 136, 0.9), rgba(2, 132, 199, 0.85));
            font-weight: 700;
            box-shadow: 0 3px 12px rgba(13, 148, 136, 0.3);
        }

        .ctrl-menu a.active i {
            color: #ffffff;
        }

        .ctrl-badge-count {
            margin-left: auto;
            background: #ef4444;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 999px;
        }

        /* Footer Barre Latérale */
        .ctrl-footer {
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ctrl-btn-site {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-btn-site:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border-color: rgba(45, 212, 191, 0.3);
        }

        .ctrl-btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0.5rem;
            border-radius: 8px;
            color: #f87171;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .ctrl-btn-logout:hover {
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
        }
    </style>
</head>

<body class="dash-pro-layout">
    <div class="dashboard-wrapper">
        <!-- ==============================================================================
             CENTRE DE CONTRÔLE LATÉRAL (SIDEBAR MODERNE)
             ============================================================================== -->
        <aside class="sidebar">
            <!-- 1. En-tête de Marque / Centre de Contrôle -->
            <div class="ctrl-brand">
                <a href="dashboard.php" class="ctrl-brand-logo" style="text-decoration: none;">
                    <div class="ctrl-brand-icon">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <span style="display: block; line-height: 1.1;">Ticket Flow</span>
                        <small
                            style="font-size: 0.62rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Control
                            Center</small>
                    </div>
                </a>
                <span class="ctrl-badge-pro">PRO</span>
            </div>

            <!-- 2. Profil Promoteur Connecté -->
            <a href="profil.php" class="ctrl-user-card" title="Voir mon profil public">
                <div class="ctrl-avatar"><?php echo htmlspecialchars($initiales); ?></div>
                <div class="ctrl-user-info">
                    <span class="ctrl-user-name"><?php echo htmlspecialchars($nom_affiche); ?></span>
                    <span class="ctrl-user-role">
                        <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> Promoteur Certifié
                    </span>
                </div>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; color: #475569;"></i>
            </a>

            <!-- 3. Widget Trésorerie & Solde Retirable -->
            <div class="ctrl-wallet-box">
                <div>
                    <span class="ctrl-wallet-label">Solde Retirable</span>
                    <strong class="ctrl-wallet-amount"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> <span
                            style="font-size: 0.75rem; font-weight: 700;">FCFA</span></strong>
                </div>
                <a href="solde.php#virement-box" class="ctrl-wallet-btn" title="Virement immédiat Mobile Money">
                    <i class="fa-solid fa-bolt"></i> Retirer
                </a>
            </div>

            <!-- 4. Navigation Segmentée par Pôles d'Activités -->
            <div class="ctrl-nav-scroll">
                <!-- SECTION 1 : PILOTAGE & ACTIVITÉ -->
                <span class="ctrl-section-label">Pilotage & Événements</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="dashboard.php"
                            class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Tableau de Bord</span>
                        </a>
                    </li>
                    <li>
                        <a href="mes-evenements.php"
                            class="<?php echo in_array($current_page, ['mes-evenements.php', 'ticket-types.php'], true) ? 'active' : ''; ?>">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span>Mes Événements</span>
                        </a>
                    </li>
                    <li>
                        <a href="votes.php" class="<?php echo $current_page === 'votes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-trophy" style="color: #ca8a04;"></i>
                            <span>Concours & Votes</span>
                        </a>
                    </li>
                    <li>
                        <a href="demande-evenement.php"
                            class="<?php echo $current_page === 'demande-evenement.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>Proposer un Événement</span>
                        </a>
                    </li>
                    <li>
                        <a href="demandes.php" class="<?php echo $current_page === 'demandes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Mes Demandes</span>
                            <?php if ($badge_demandes_promoteur > 0): ?>
                                <span class="ctrl-badge-count"><?php echo $badge_demandes_promoteur; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 2 : FINANCES & COMMERCIAL -->
                <span class="ctrl-section-label">Finances & Recettes</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="mes-ventes.php"
                            class="<?php echo $current_page === 'mes-ventes.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-receipt"></i>
                            <span>Ventes & Billetterie</span>
                        </a>
                    </li>
                    <li>
                        <a href="cotisations.php"
                            class="<?php echo $current_page === 'cotisations.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                            <span>Mes Cotisations</span>
                        </a>
                    </li>
                    <li>
                        <a href="solde.php" class="<?php echo $current_page === 'solde.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-wallet"></i>
                            <span>Solde & Retraits</span>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 3 : OPÉRATIONS & SÉCURITÉ -->
                <span class="ctrl-section-label">Opérations Terrain</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="agents.php" class="<?php echo $current_page === 'agents.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Agents de Contrôle</span>
                        </a>
                    </li>
                </ul>

                <!-- SECTION 4 : MARQUE & SUPPORT -->
                <span class="ctrl-section-label">Image & Assistance</span>
                <ul class="ctrl-menu">
                    <li>
                        <a href="profil.php" class="<?php echo $current_page === 'profil.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Mon Profil Public</span>
                        </a>
                    </li>
                    <li>
                        <a href="reclamations.php"
                            class="<?php echo $current_page === 'reclamations.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-headset"></i>
                            <span>Support & Tickets</span>
                            <?php if ($badge_claims_promoteur > 0): ?>
                                <span class="ctrl-badge-count"
                                    style="background: #0284c7;"><?php echo $badge_claims_promoteur; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 5. Pied du Centre de Contrôle -->
            <div class="ctrl-footer">
                <a href="../client/accueil.php" target="_blank" class="ctrl-btn-site">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Voir le Site Public</span>
                </a>
                <a href="../deconnexion.php" class="ctrl-btn-logout"
                    onclick="return confirm('Voulez-vous vous déconnecter de votre espace promoteur ?');">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>

        <!-- ==============================================================================
             CONTENU PRINCIPAL DE LA PAGE
             ============================================================================== -->
        <div class="main-content">