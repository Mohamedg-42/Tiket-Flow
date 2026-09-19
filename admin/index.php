<?php
// ==============================================================================
// POINT D'ENTRÉE ADMINISTRATION (admin/index.php)
// Redirection vers le tableau de bord et prévention 403 Forbidden sur /admin/
// ==============================================================================

header('Location: dashboard');
exit();
