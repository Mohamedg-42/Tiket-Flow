        <!-- Pied de page du contenu principal -->
        <footer class="main-footer" style="padding: 1.25rem 2rem; border-top: 1px solid var(--eventia-border, #E5E5E5); background: var(--eventia-white, #ffffff); color: var(--eventia-muted, #737373); font-size: 0.82rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: auto;">
            <div>
                &copy; <?php echo date('Y'); ?> <strong style="color: var(--eventia-navy, #000000);">Tikéli</strong>. Tous droits réservés.
                <span style="margin: 0 8px; color: #E5E5E5;">•</span>
                <span style="color: var(--eventia-muted, #737373);">Espace Administration Sécurisé</span>
            </div>
            <div style="display: flex; gap: 14px; align-items: center;">
                <a href="../client/accueil.php" target="_blank" style="color: var(--eventia-navy, #000000); text-decoration: none; font-weight: 700; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-arrow-up-right-from-square" style="color: var(--eventia-amber-dark, #FF4A0D);"></i> Voir le site
                </a>
                <span style="color: var(--eventia-border, #E5E5E5);">|</span>
                <span style="color: var(--eventia-muted, #737373); font-size: 0.76rem;">v2.4 Tikéli Pro</span>
            </div>
        </footer>
    </div> <!-- Fin de .main-content -->
</div> <!-- Fin de .dashboard-wrapper -->

<script>
function togglePassVisibility(inputId, iconElem) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        if (iconElem) {
            iconElem.classList.remove('fa-eye');
            iconElem.classList.add('fa-eye-slash');
        }
    } else {
        inp.type = 'password';
        if (iconElem) {
            iconElem.classList.remove('fa-eye-slash');
            iconElem.classList.add('fa-eye');
        }
    }
}
</script>
</body>
</html>