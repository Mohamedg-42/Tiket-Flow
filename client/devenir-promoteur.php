<?php
// ==============================================================================
// REDIRECTION PROPRE VERS LA PAGE OFFICIELLE CANDIDATURE PROMOTEUR
// Un promoteur est un organisateur autonome et non un sous-rôle client.
// ==============================================================================

$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header("Location: ../devenir-promoteur" . $qs, true, 301);
exit();