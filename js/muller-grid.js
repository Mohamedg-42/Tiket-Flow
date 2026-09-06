/**
 * Grille modulaire désactivée — Nettoyage complet des overlays résiduels
 */
(function () {
  'use strict';
  try {
    localStorage.removeItem('eventia_muller_grid');
    document.body.classList.remove('grid-on');
    var btn = document.getElementById('gridToggleBtn');
    if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
    document.querySelectorAll('.guides').forEach(function(g) {
      if (g && g.parentNode) g.parentNode.removeChild(g);
    });
  } catch (e) {}
})();
