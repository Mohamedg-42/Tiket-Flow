 <?php
  include 'header.php';

  $message = "";
  $msg_type = "";

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $nom = $_POST['nom'];
      $description = $_POST['description'];
      $date = $_POST['date'];
      $heure = $_POST['heure'];
      $lieu = $_POST['lieu'];
      $statut = $_POST['statut'];
      $image = "default.jpg"; // On mettra la gestion d'image plus tard

      try {
          $sql = "INSERT INTO events (nom, description, image, date_evenement, heure, lieu, statut) VALUES (?, ?, ?, ?, ?, ?, ?)";
          $stmt = $pdo->prepare($sql);
          $stmt->execute([$nom, $description, $image, $date, $heure, $lieu, $statut]);

          $message = "✅ Événement ajouté avec succès !";
          $msg_type = "success";
      } catch (PDOException $e) {
          $message = "❌ Erreur : " . $e->getMessage();
          $msg_type = "error";
      }
  }
  ?>

  <div class="page-header">
      <div class="page-heading">
          <span class="page-kicker">Billetterie</span>
          <h1>Créer un Événement</h1>
          <p>Ajoutez les informations de votre prochain événement.</p>
      </div>
      <a href="evenements.php" class="back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Retour à la liste</a>
  </div>

  <div class="content-section event-form-section">
      <?php if($message): ?>
          <div class="alert alert-<?php echo $msg_type; ?>"><?php echo $message; ?></div>
      <?php endif; ?>

      <form method="POST">
          <div class="form-group">
              <label>Nom de l'événement</label>
              <input type="text" name="nom" required placeholder="Ex: Concert Magique d'Abidjan">
          </div>
          <div class="form-group">
              <label>Description</label>
              <textarea name="description" rows="4" placeholder="Détails sur l'événement..."></textarea>
          </div>
          <div class="form-row">
              <div class="form-group">
                  <label>Date</label>
                  <input type="date" name="date" required>
              </div>
              <div class="form-group">
                  <label>Heure</label>
                  <input type="time" name="heure" required>
              </div>
          </div>
          <div class="form-group">
              <label>Lieu</label>
              <input type="text" name="lieu" required placeholder="Ex: Palais de la Culture">
          </div>
          <div class="form-group">
              <label>Statut</label>
              <select name="statut">
                  <option value="actif">Actif</option>
                  <option value="inactif">Inactif</option>
                  <option value="terminé">Terminé</option>
              </select>
          </div>
          <button type="submit" class="btn-submit"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Enregistrer l'événement</button>
      </form>
  </div>

  <?php include 'footer.php'; ?>