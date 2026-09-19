<?php
// ==============================================================================
// PIED DE PAGE SIMPLE (client/footer.php)
// Footer minimal et sobre
// ==============================================================================
?>
<footer class="shared-client-footer" style="text-align: center; padding: 2rem 1rem; color: var(--muted); border-top: 1px solid var(--line); margin-top: 3rem; font-size: 0.88rem;">
    <span>&copy; <?php echo date('Y'); ?> <strong>TikeWA</strong> — Billetterie simple et sécurisée.</span>
</footer>
<script>
// Neutralisation universelle de la déviation des ancres de page provoquée par <base href>
document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;
    const hash = a.getAttribute('href');
    if (hash && hash.length > 1 && hash !== '#') {
        const target = document.querySelector(hash);
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try {
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', hash);
                }
            } catch (_) {}
        }
    }
});
</script>
</body>
</html>
