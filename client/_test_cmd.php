<?php
// Harnais de test temporaire (à supprimer) : simule la session du Client Démo (id 4)
session_start();
$_SESSION['user_id'] = 4;
$_SESSION['user_nom'] = 'Client Démo';
$_SESSION['user_email'] = 'client.demo@example.com';
$_SESSION['user_role'] = 'client';
require __DIR__ . '/mes-commandes.php';