<?php
// ==============================================================================
// CONFIGURATION SMTP — GMAIL (config/smtp.php)
// Envoi via Gmail avec un "Mot de passe d'application" (App Password).
//
// ÉTAPES DE CONFIGURATION GMAIL :
//   1. Connectez-vous à votre compte Google
//   2. Activez la validation en 2 étapes : https://myaccount.google.com/security
//   3. Générez un "Mot de passe d'application" :
//      https://myaccount.google.com/apppasswords
//      → Sélectionnez "Autre (nom personnalisé)" → "TicketFlow" → Générer
//   4. Copiez le mot de passe de 16 caractères dans SMTP_PASS ci-dessous
// ==============================================================================

// ▶ Remplissez ces deux lignes avec vos identifiants Gmail :
define('SMTP_USER',      getenv('SMTP_USER')      ?: 'garbamohamed4220@gmail.com');
define('SMTP_PASS',      getenv('SMTP_PASS')       ?: 'hrnvxvkkxynsdvma');

// — Paramètres Gmail fixes (ne pas modifier) —
define('SMTP_HOST',      getenv('SMTP_HOST')       ?: 'smtp.gmail.com');
define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE',    getenv('SMTP_SECURE')     ?: 'tls');  // Gmail utilise STARTTLS sur le port 587

// — Expéditeur affiché dans les emails reçus —
define('SMTP_FROM',      getenv('SMTP_FROM')       ?: 'garbamohamed4220@gmail.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME')  ?: 'Ticket Flow');