  <?php
  // On inclut le fichier de connexion qu'on vient de créer
  require_once 'config/database.php';

  if (isset($pdo)) {
      echo "✅ Bravo ! La connexion à la base de données a réussi.";
  } else {
      echo "❌ Quelque chose ne va pas.";
  }
  ?>