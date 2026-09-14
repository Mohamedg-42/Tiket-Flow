/**
 * ==============================================================================
 * OVERLAY INTERACTIF MÜLLER-BROCKMANN — Tike WA Platform (js/muller-grid.js)
 * Grille modulaire 12 colonnes + lignes de base 8px
 * Toggle : touche [G] ou bouton #gridToggleBtn
 * Persistance : localStorage['tikeli_muller_grid']
 * ==============================================================================
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'tikeli_muller_grid';
    var ACTIVE_CLASS = 'grid-on';
    var BTN_ACTIVE_CLASS = 'is-active';
    var BTN_ID = 'gridToggleBtn';

    // ─── Lire l'état sauvegardé ──────────────────────────────────────────────
    function getSavedState() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function saveState(active) {
        try {
            localStorage.setItem(STORAGE_KEY, active ? '1' : '0');
        } catch (e) {}
    }

    // ─── Créer le bouton flottant si absent ──────────────────────────────────
    function ensureButton() {
        var btn = document.getElementById(BTN_ID);
        if (btn) return btn;

        btn = document.createElement('button');
        btn.id = BTN_ID;
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('title', 'Basculer la grille Müller-Brockmann [G]');
        btn.innerHTML =
            '<span class="grid-key">G</span>' +
            '<span class="grid-label">Grille</span>';

        document.body.appendChild(btn);
        return btn;
    }

    // ─── Calculer et injecter les variables CSS de l'overlay colonnes ─────────
    function injectColVars() {
        var root = document.documentElement;
        var rootStyles = getComputedStyle(root);

        var colsVal   = parseInt(rootStyles.getPropertyValue('--cols').trim()) || 12;
        var gutterPx  = parseFloat(rootStyles.getPropertyValue('--gutter').trim()) || 24;
        var marginPx  = parseFloat(rootStyles.getPropertyValue('--margin').trim()) || 64;
        var colLineColor = rootStyles.getPropertyValue('--grid-line-color').trim() || 'rgba(228,0,43,0.18)';
        var colBgColor   = rootStyles.getPropertyValue('--grid-col-color').trim() || 'rgba(228,0,43,0.08)';

        // Largeur d'une colonne = (100% - 2×margin - (N-1)×gutter) / N
        var availWidth  = window.innerWidth - 2 * marginPx;
        var totalGutter = (colsVal - 1) * gutterPx;
        var colWidth    = (availWidth - totalGutter) / colsVal;

        // Chaque tranche = 1 colonne + 1 gouttière (sauf la dernière)
        var unitPx   = colWidth + gutterPx;

        // Génération du gradient répétant : colonne colorée | gouttière transparente
        var gradStops =
            colBgColor   + ' 0px, ' +
            colBgColor   + ' ' + colWidth + 'px, ' +
            colLineColor + ' ' + colWidth + 'px, ' +
            colLineColor + ' ' + (colWidth + 0.5) + 'px, ' +
            'transparent ' + (colWidth + 0.5) + 'px, ' +
            'transparent ' + unitPx + 'px';

        root.style.setProperty('--grid-col-bg', 'repeating-linear-gradient(to right, ' + gradStops + ')');
        root.style.setProperty('--grid-col-size', unitPx + 'px 100%');
    }

    // ─── Activation / Désactivation ──────────────────────────────────────────
    function activate(btn) {
        document.body.classList.add(ACTIVE_CLASS);
        btn.classList.add(BTN_ACTIVE_CLASS);
        btn.setAttribute('aria-pressed', 'true');
        injectColVars();
        saveState(true);
    }

    function deactivate(btn) {
        document.body.classList.remove(ACTIVE_CLASS);
        btn.classList.remove(BTN_ACTIVE_CLASS);
        btn.setAttribute('aria-pressed', 'false');
        saveState(false);
    }

    function toggle() {
        var btn = ensureButton();
        if (document.body.classList.contains(ACTIVE_CLASS)) {
            deactivate(btn);
        } else {
            activate(btn);
        }
    }

    // ─── Initialisation ──────────────────────────────────────────────────────
    function init() {
        var btn = ensureButton();

        // Clic sur le bouton
        btn.addEventListener('click', function () {
            toggle();
        });

        // Raccourci clavier [G] (pas dans un input/textarea/select)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'g' || e.key === 'G') {
                var tag = (document.activeElement || {}).tagName || '';
                if (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(tag) === -1) {
                    e.preventDefault();
                    toggle();
                }
            }
        });

        // Recalculer les variables CSS à chaque redimensionnement
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (document.body.classList.contains(ACTIVE_CLASS)) {
                    injectColVars();
                }
            }, 100);
        });

        // Restaurer l'état sauvegardé
        if (getSavedState()) {
            activate(btn);
        }
    }

    // Lancer dès que le DOM est prêt
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
