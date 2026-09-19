<?php
// ==============================================================================
// POINT D'ENTRÉE GARE (gare/index.php)
// Redirection vers le guichet de vente et prévention 403 Forbidden sur /gare/
// ==============================================================================

header('Location: vente');
exit();
