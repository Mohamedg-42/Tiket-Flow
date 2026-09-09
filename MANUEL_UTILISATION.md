# 📘 Manuel d'Utilisation Officiel — Plateforme Tikéli

Ce guide complet détaille le fonctionnement, les parcours utilisateurs et les bonnes pratiques pour les 4 profils de la plateforme :
1. **[Le Client (Acheteur / Spectateur)](#1-manuel-de-lutilisateur-client)**
2. **[Le Promoteur (Organisateur d'Événements)](#2-manuel-du-promoteur-organisateur)**
3. **[L'Agent (Contrôleur d'Accès / Scanneur)](#3-manuel-de-lagent-contrôleur-daccès)**
4. **[L'Administrateur (Supervision Globale)](#4-manuel-de-ladministrateur)**

---

# 1. Manuel de l'Utilisateur Client

L'espace Client permet à tout utilisateur de découvrir des événements, de réserver des billets en ligne, de choisir ses places (en 2D ou en vue 3D interactive), de payer en toute sécurité via Mobile Money et de gérer ses billets dématérialisés.

### 1.1. Inscription et Connexion
- **Accès** : Rendez-vous sur la page d'accueil ou cliquez sur **Connexion** en haut à droite.
- **Création de compte** : Remplissez le formulaire avec votre nom, adresse email, numéro de téléphone et mot de passe (vous pouvez utiliser l'icône « œil » pour afficher/masquer votre mot de passe).
- **Achat invité (Guest Checkout)** : Si vous n'avez pas de compte, vous pouvez quand même réserver un billet en fournissant simplement votre nom, prénom, email et téléphone lors de la confirmation.

### 1.2. Recherche et Découverte d'Événements
- **Page d'accueil (`client/accueil.php`)** :
  - **Barre de recherche** : Saisissez un artiste, un titre de concert, un festival ou une ville.
  - **Filtres par catégorie** : Concerts, Festivals, Théâtre, Salons, Formations, etc.
  - **Bouton Coup de Cœur (Like)** : Cliquez sur le cœur d'une carte d'événement pour l'ajouter à vos favoris.

### 1.3. Processus de Réservation et Choix de Place
1. Sur la carte de l'événement choisi, cliquez sur le bouton orange **« Réserver »**.
2. La fenêtre interactive s'ouvre :
   - Consultez la description, la date, l'horaire et le lieu.
   - **Sélecteur de tarifs multiples** : Choisissez le nombre de places pour chaque catégorie (ex: *Standard*, *VIP*, *Pass Duo*) avec les boutons `+` et `-`.
   - **Choix de place numérotée (Plan 2D / Salle 3D)** :
     - Si l'événement dispose d'une salle avec plan, cochez « Choisir mes places ».
     - Si l'événement propose une salle 3D, cliquez sur **« Vue 3D de la salle »** pour naviguer dans l'espace virtuel avec la souris ou au doigt sur mobile, et cliquez directement sur les sièges souhaités.
3. Cliquez sur **« Confirmer la commande »**.

### 1.4. Paiement Sécurisé
1. Sélectionnez votre opérateur Mobile Money :
   - **Wave** (paiement instantané par QR Code ou push mobile)
   - **Orange Money**
   - **MTN MoMo**
   - **Moov Money**
2. Entrez votre numéro de téléphone de paiement et validez sur votre téléphone la demande de débit.
3. Dès validation, la page de confirmation s'affiche avec le reçu et vos accès.

### 1.5. Accès et Téléchargement des Billets
- Rendez-vous dans **« Mes Billets »** (`client/mes-tickets.php`).
- Pour chaque billet acheté :
  - Un **QR Code unique et sécurisé** est généré.
  - Cliquez sur **« Télécharger en PDF »** pour l'enregistrer sur votre smartphone ou l'imprimer.
  - Le jour de l'événement, présentez simplement le QR Code sur votre téléphone aux agents à l'entrée.

### 1.6. Modules Participatifs (Votes & Cotisations)
- **Voter pour un candidat/projet** : Rendez-vous dans l'onglet *Votes*, sélectionnez votre participant favori et effectuez votre vote (gratuit ou avec micro-paiement selon la configuration).
- **Participer à une cotisation/cagnotte** : Choisissez un montant suggéré (ex: 1 000 F, 5 000 F) ou saisissez un montant libre pour soutenir un projet.

### 1.7. Réclamations & Assistance
- En cas de difficulté avec un billet ou un paiement, accédez à **« Réclamations »** (`client/reclamations.php`), décrivez votre problème et joignez une pièce justificative si nécessaire. L'équipe d'assistance traite votre demande sous 24 à 48h.

---

# 2. Manuel du Promoteur (Organisateur)

Le Promoteur est le créateur d'événements. Son espace lui donne le plein contrôle sur ses billetteries, ses équipes de contrôle d'accès sur le terrain et ses revenus.

### 2.1. Accès et Tableau de Bord (`promoteur/dashboard.php`)
- Dès la connexion, visualisez :
  - Le total des **ventes réalisées** (chiffre d'affaires).
  - Le nombre total de **billets écoulés**.
  - Le **taux de remplissage** moyen de vos salles.
  - Les graphiques des ventes par jour et par catégorie.

### 2.2. Création et Publication d'un Événement (`promoteur/demande-evenement.php`)
1. Cliquez sur **« Créer un événement »**.
2. Remplissez les informations principales :
   - Titre, description détaillée, catégorie, date et heure de début/fin.
   - Lieu physique ou sélection d'une salle modélisée dans la plateforme (avec plan 3D préconfiguré).
   - Affiche officielle de l'événement (format JPG ou PNG haute résolution).
3. **Configuration des catégories de billets** :
   - Définissez vos tarifs (ex: *Prévente*, *Tarif Régulier*, *VIP*, *Backstage*).
   - Attribuez à chacun un prix (en FCFA) et un quota strict de places.
4. Soumettez l'événement :
   - Selon la politique de la plateforme, l'événement peut être soumis à la validation de l'administrateur avant sa mise en ligne publique.

### 2.3. Suivi des Ventes en Temps Réel (`promoteur/mes-ventes.php`)
- Suivez en direct chaque billet vendu : nom de l'acheteur, date d'achat, montant, moyen de paiement.
- **Export des données (`promoteur/export.php`)** : Téléchargez les listes d'émargement et les rapports de ventes au format Excel/CSV pour vos équipes comptables et logistiques.

### 2.4. Gestion des Agents de Contrôle (`promoteur/agents.php`)
1. Pour contrôler les accès le jour J sans donner accès à vos comptes financiers, créez des comptes **Agents de contrôle**.
2. Renseignez le nom, l'identifiant et le mot de passe de l'agent.
3. Assignez l'agent à un ou plusieurs de vos événements.
4. L'agent utilisera simplement son smartphone pour scanner les billets à l'entrée.

### 2.5. Suivi Financier et Demandes de Retrait (`promoteur/solde.php`)
- Consultez votre **solde disponible** déduit des éventuelles commissions de service.
- **Effectuer une demande de virement/retrait** :
  - Cliquez sur **« Demander un retrait »**.
  - Indiquez le montant désiré et le mode de réception (Wave, virement bancaire, Orange Money).
  - Suivez le statut de votre demande (*En attente*, *Approuvé*, *Transféré*).

---

# 3. Manuel de l'Agent (Contrôleur d'Accès)

L'Agent est chargé de vérifier la validité des billets à l'entrée de l'événement. Son interface est ultra-rapide, épurée et pensée pour les smartphones.

### 3.1. Connexion
- L'agent se connecte avec les identifiants fournis par le promoteur ou l'administrateur.
- Il est immédiatement redirigé vers l'écran de scan : [`agent/verification.php`](file:///c:/wamp64/www/ticket-platform/agent/verification.php).

### 3.2. Contrôle des Billets par Scan QR Code
1. Sur l'écran principal, autorisez l'accès à la caméra du smartphone.
2. Pointez la caméra vers le QR Code présenté par le spectateur (sur écran ou papier).
3. Le résultat s'affiche instantanément :
   - 🟢 **BILLET VALIDE (Vert)** : Le billet est authentique et n'a jamais été utilisé. Le nom du participant, la catégorie de billet et le numéro de siège (le cas échéant) s'affichent. Le spectateur peut entrer.
   - 🔴 **BILLET DÉJÀ UTILISÉ (Rouge / Alerte)** : Le billet a déjà été scanné précédemment. L'écran affiche l'heure exacte du premier scan pour éviter les fraudes (duplication de billet).
   - 🔴 **BILLET INVALIDE (Rouge)** : Le QR Code ne correspond à aucun billet de cet événement ou a été falsifié.

### 3.3. Saisie Manuelle de Secours
- Si la caméra ne fonctionne pas ou si l'écran du spectateur est brisé :
  - Cliquez sur le champ de saisie manuelle en bas de l'écran.
  - Saisissez la référence alphanumérique inscrite sous le QR Code du billet (ex: `TK-2026-XXXX`).
  - Cliquez sur **« Vérifier »**.

### 3.4. Historique des Contrôles (`agent/historique.php`)
- Consultez la liste de tous les billets que vous avez validés durant votre session, avec l'heure exacte de chaque validation.

---

# 4. Manuel de l'Administrateur

L'Administrateur a une vue d'ensemble sur l'ensemble de l'écosystème : gestion des utilisateurs, validation des événements, modélisation des salles, surveillance des flux financiers et sécurité.

### 4.1. Cockpit Central (`admin/dashboard.php`)
- **Indicateurs macroscopiques** : Total des billets vendus sur la plateforme, chiffre d'affaires global, volume des transactions Mobile Money, nombre de promoteurs et d'utilisateurs actifs.
- **Journal d'activité en direct (`admin/activite.php`)** : Connexions suspectes, transactions récentes, créations d'événements.

### 4.2. Modération des Événements (`admin/demandes-evenements.php` & `admin/evenements.php`)
- Visualisez les événements soumis par les promoteurs.
- Vérifiez la conformité des informations (prix réalistes, droits d'image, charte éthique).
- Cliquez sur **Approuver** pour publier l'événement sur la vitrine publique, ou **Refuser** avec un motif explicatif transmis au promoteur.
- Possibilité de modifier directement un événement ou d'ajuster les quotas de billets en cas de force majeure.

### 4.3. Validation des Promoteurs (`admin/demandes-promoteurs.php`)
- Examinez les dossiers des utilisateurs demandant à devenir organisateurs officiels (KYC, numéro d'enregistrement d'entreprise, coordonnées).
- Activez les comptes promoteurs vérifiés.

### 4.4. Configuration des Salles & Modélisation 3D (`admin/salles.php`)
- Gestion des infrastructures partenaires (Palais des Congrès, Stades, Salles de spectacle).
- Définition des blocs de sièges, des rangées et des numérotations.
- Liaison avec le moteur WebGL/Three.js pour permettre aux clients une sélection réaliste de leur point de vue sur scène.

### 4.5. Gestion Financière & Décaissements (`admin/paiements.php` & `admin/retraits.php`)
- **Suivi des paiements** : Journalisation de tous les flux reçus via Wave, Orange, MTN et Moov.
- **Traitement des retraits** : Validation des demandes de reversement des promoteurs après vérification de la bonne tenue de leurs événements.

### 4.6. Gestion des Utilisateurs & Rôles (`admin/utilisateurs.php` & `admin/profils.php`)
- Recherche d'utilisateurs par nom, email ou téléphone.
- Attribution et modification des privilèges (Client, Promoteur, Agent, Administrateur).
- **Création sécurisée de compte** : L'administrateur initialise le compte. L'utilisateur reçoit automatiquement un email sans mot de passe en clair, contenant un bouton de connexion directe et un bouton de définition / réinitialisation de son mot de passe (valable 24 heures).
- Blocage/Déblocage de comptes en cas de fraude.

### 4.7. Support, Tâches et Réclamations (`admin/reclamations.php` & `admin/taches.php`)
- Gestionnaire de tickets d'assistance interne pour affecter les réclamations aux membres de l'équipe et suivre leur résolution.

---

# 5. Récapitulatif des Accès Rapides

| Rôle | URL d'accès direct | Fonction principale |
| :--- | :--- | :--- |
| **Client** | `client/accueil.php` | Rechercher, acheter et télécharger ses billets |
| **Promoteur** | `promoteur/dashboard.php` | Publier des événements et piloter ses ventes |
| **Agent** | `agent/verification.php` | Scanner et valider les entrées sur le terrain |
| **Administrateur** | `admin/dashboard.php` | Superviser toute la plateforme et les finances |

---
*Document généré pour la plateforme Tikéli — Version 2.0*
