<?php
// ==============================================================================
// POINT D'ENTRÉE AGENT (agent/index.php)
// Redirection vers le contrôle d'accès et prévention 403 Forbidden sur /agent/
// ==============================================================================

header('Location: verification');
exit();
