 <?php
  require_once '../includes/auth.php';
  checkRole('admin', '../connexion.php');
  require_once '../config/database.php';

  if (isset($_GET['id'])) {
      $id = $_GET['id'];

      try {
          $sql = "DELETE FROM events WHERE id = ?";
          $stmt = $pdo->prepare($sql);
          $stmt->execute([$id]);
      } catch (PDOException $e) {
          // En cas d'erreur (ex: l'événement a des tickets liés), on peut gérer ici
      }
  }

  // On redirige vers la liste pour voir le résultat
  header("Location: evenements.php");
  exit();
  ?>