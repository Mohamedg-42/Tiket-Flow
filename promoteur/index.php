<?php
// ==============================================================================
// POINT D'ENTRÉE PROMOTEUR (promoteur/index.php)
// Redirection vers le tableau de bord et prévention 403 Forbidden sur /promoteur/
// ==============================================================================

header('Location: dashboard');
exit();
