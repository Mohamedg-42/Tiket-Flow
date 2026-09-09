<?php
// Test de validation de la visibilité et du contraste des boutons 3D

function assert_check($condition, $msg) {
    if ($condition) {
        echo "[PASS] $msg\n";
    } else {
        echo "[FAIL] $msg\n";
        exit(1);
    }
}

echo "=== Test : Visibilité et Contraste du Bouton 3D & Éléments Rendu 3D ===\n";

$css_accueil = file_get_contents(__DIR__ . '/../Css/accueil-client.css');
$js_accueil = file_get_contents(__DIR__ . '/../js/accueil-client.js');
$css_style = file_get_contents(__DIR__ . '/../Css/style.css');

// 1. Bouton 3D interactif (.btn-scene-interactive)
assert_check(strpos($css_accueil, '.btn-scene-interactive') !== false, "Classe .btn-scene-interactive présente dans accueil-client.css");
assert_check(strpos($css_accueil, 'border: 2px solid #FF4A0D;') !== false, "Bordure vive 2px Tikéli orange sur .btn-scene-interactive");
assert_check(strpos($css_accueil, 'rgba(255, 74, 13, 0.32)') !== false, "Glow lumineux orange sur .btn-scene-interactive");
assert_check(strpos($css_accueil, '.btn-scene-icon-box') !== false, "Pillule d'icône dédiée 3D .btn-scene-icon-box");

// 2. Badge Rendu 3D (.scene-tag-badge)
assert_check(strpos($css_accueil, '.scene-tag-badge') !== false, "Classe .scene-tag-badge présente");
assert_check(strpos($css_accueil, 'background: #FF4A0D;') !== false, "Fond Tikéli orange sur .scene-tag-badge pour contraste net");
assert_check(strpos($css_accueil, 'color: #FFFFFF;') !== false, "Texte blanc contrasté sur .scene-tag-badge");

// 3. Onglet Rendu 3D actif dans la modale
assert_check(strpos($css_accueil, '.studio-tab-btn.active') !== false, "Onglet studio-tab-btn.active présent");
assert_check(strpos($css_accueil, 'background: #FF4A0D !important;') !== false, "Bouton Rendu 3D actif vivement mis en valeur en orange");

// 4. Boutons caméra 3D (.s3d-cam-btn)
assert_check(strpos($css_accueil, '.s3d-cam-btn') !== false, "Boutons caméra 3D présents");
assert_check(strpos($css_accueil, 'background: #1E293B;') !== false, "Boutons caméras sur fond ardoise sombre contrasté (au lieu de noir invisible)");

// 5. Checkbox et carte de choix 3D (.seat-choice-block)
assert_check(strpos($css_accueil, '.seat-choice-block') !== false, "Bloc d'option 3D .seat-choice-block présent");
assert_check(strpos($css_accueil, 'background: #FFF7ED;') !== false, "Fond chaleureux valorisant l'option 3D");

// 6. Markup JS
assert_check(strpos($js_accueil, 'Ouvrir le Rendu 3D Immersif') !== false, "Texte explicite du bouton 3D dans accueil-client.js");
assert_check(strpos($js_accueil, 'btn-scene-icon-box') !== false, "Structure riche avec icône 3D dans accueil-client.js");

// 7. Bouton changement de place en 3D (Mes tickets)
assert_check(strpos($css_style, '.btn-ticket-seat-change') !== false, "Bouton .btn-ticket-seat-change dans style.css");
assert_check(strpos($css_style, 'color: #FF4A0D;') !== false, "Couleur Tikéli orange sur le bouton 3D de mes billets");

echo "\n============================================\n";
echo "TOUS LES TESTS DE VISIBILITÉ DU BOUTON 3D SONT VALIDÉS !\n";
echo "============================================\n";
