# Guide Responsive Professionnel - Ticket Flow

## 📱 Vue d'ensemble

Ce projet utilise un système CSS responsive moderne et professionnel avec:
- **Breakpoints optimisés** pour tous les appareils (mobile, tablette, desktop)
- **Espacements adaptatifs** (clamp CSS)
- **Typographie fluide** qui s'ajuste automatiquement
- **Grilles intelligentes** qui se reorganisent
- **Navigation mobile-first**

---

## 🎯 Breakpoints Utilisés

```css
--breakpoint-xs: 320px    /* Très petits téléphones */
--breakpoint-sm: 480px    /* Petits téléphones */
--breakpoint-md: 768px    /* Tablettes en portrait */
--breakpoint-lg: 1024px   /* Tablettes en paysage / Petits écrans */
--breakpoint-xl: 1280px   /* Desktop standard */
--breakpoint-2xl: 1536px  /* Grand desktop */
```

---

## 🎨 Classes Responsive Disponibles

### 1. **Navigation Responsive**
```html
<!-- Affichage automatique du menu mobile sur petits écrans -->
<header class="client-header">
    <button class="client-nav-toggle">Menu</button>
    <nav class="client-nav"><!-- Navigation --></nav>
</header>
```
- ✅ Menu hamburger automatique sous 768px
- ✅ Navigation dérou­lante fluide sur mobile
- ✅ Navigation horizontale sur desktop

### 2. **Grilles Responsive**

#### Grille Standard (auto-fit)
```html
<div class="stats-grid">
    <div class="stat-card">Item 1</div>
    <div class="stat-card">Item 2</div>
</div>
```
- **Desktop**: 4 colonnes (230px minimum)
- **Tablette**: 3 colonnes (180px minimum)
- **Mobile**: 1 colonne

#### Grille de Cartes
```html
<div class="cards-container">
    <div class="card">...</div>
</div>
```
- **Desktop**: 3-4 cartes par ligne
- **Tablette**: 2 cartes par ligne
- **Mobile**: 1 carte par ligne

#### Grille à Deux Colonnes
```html
<div class="two-column-grid">
    <div>Colonne 1</div>
    <div>Colonne 2</div>
</div>
```
- **Desktop**: 2 colonnes côte à côte
- **Tablette & Mobile**: 1 colonne (empilée)

### 3. **Formulaires Responsive**

```html
<div class="form-row">
    <div class="form-group">
        <input type="text" />
    </div>
    <div class="form-group">
        <input type="email" />
    </div>
</div>
```
- **Desktop**: Champs côte à côte
- **Mobile**: Champs empilés (100% de largeur)

### 4. **Affichage Conditionnel**

```html
<!-- Visible uniquement sur mobile -->
<div class="show-mobile">Menu Mobile</div>

<!-- Masqué sur mobile -->
<div class="hide-mobile">Menu Desktop</div>

<!-- Visible uniquement sur tablette -->
<div class="show-tablet">Tablette seulement</div>
```

### 5. **Tableaux Responsive**

#### Tableau Standard
```html
<div class="table-responsive">
    <table><!-- Tableau --></table>
</div>
```
- Défilement horizontal sur mobile

#### Tableau Empilé
```html
<div class="table-responsive table-mobile-stack">
    <table><!-- Tableau --></table>
</div>
```
```html
<td data-label="Nom">Valeur</td>
```
- Empilage vertical avec labels sur mobile

### 6. **Espacements Adaptatifs**

```html
<!-- Padding adaptif -->
<div class="p-responsive">Contenu</div>

<!-- Padding horizontal adaptif -->
<div class="px-responsive">Contenu</div>

<!-- Padding vertical adaptif -->
<div class="py-responsive">Contenu</div>

<!-- Marge adaptative -->
<div class="m-responsive">Contenu</div>

<!-- Gap adaptif pour flexbox/grid -->
<div class="gap-responsive" style="display: flex;">Item</div>
```

Variables d'espacement:
- `--spacing-xs`: 0.5rem → 0.75rem
- `--spacing-sm`: 0.75rem → 1rem
- `--spacing-md`: 1rem → 1.5rem (défaut)
- `--spacing-lg`: 1.5rem → 2rem
- `--spacing-xl`: 2rem → 2.5rem

### 7. **Typographie Responsive**

```html
<h1>Titre principal</h1>  <!-- Ajuste 1.9rem → 2.5rem -->
<h2>Titre secondaire</h2>  <!-- Ajuste 1.6rem → 2rem -->
<h3>Sous-titre</h3>        <!-- Ajuste 1.3rem → 1.5rem -->
<p>Texte normal</p>        <!-- Ajuste 0.9rem → 1rem -->
<small>Texte petit</small>  <!-- Ajuste 0.65rem → 0.75rem -->
```

### 8. **Conteneurs & Largeurs**

```html
<!-- Conteneur centré avec max-width -->
<div class="container">Contenu</div>

<!-- Largeur pleine -->
<div class="w-full">100%</div>

<!-- Largeur adaptative -->
<div class="w-auto">Auto</div>

<!-- Max-width utilitaires -->
<div class="max-w-sm">384px</div>  <!-- Petit -->
<div class="max-w-lg">512px</div>  <!-- Grand -->
<div class="max-w-2xl">672px</div> <!-- Extra large -->
```

### 9. **Cartes (Cards)**

```html
<div class="cards-container">
    <div class="card">
        <img class="card-image" src="..." />
        <div class="card-body">
            <h3 class="card-title">Titre</h3>
            <p class="card-text">Description</p>
            <div class="card-actions">
                <button>Action</button>
            </div>
        </div>
    </div>
</div>
```

### 10. **Flexbox Responsive**

```html
<!-- Flex avec wrapping -->
<div class="flex-responsive">
    <div>Item 1</div>
    <div>Item 2</div>
</div>

<!-- Colonne sur mobile, ligne sur desktop -->
<div class="flex-column-mobile">
    <div>Gauche</div>
    <div>Droite</div>
</div>

<!-- Space-between responsive -->
<div class="flex-between">
    <div>Gauche</div>
    <div>Droite</div>
</div>
```

---

## 🔧 Utilisation dans le HTML

### Exemple Complet : Page Responsive
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive-pro.css">
</head>
<body>
    <header class="client-header">
        <div class="client-brand">TICKET FLOW</div>
        <button class="client-nav-toggle">Menu</button>
        <nav class="client-nav">
            <a href="#">Accueil</a>
            <a href="#">Événements</a>
        </nav>
    </header>

    <main class="container p-responsive">
        <h1>Bienvenue</h1>
        
        <div class="stats-grid">
            <div class="stat-card">Stats 1</div>
            <div class="stat-card">Stats 2</div>
        </div>

        <div class="two-column-grid">
            <div class="content-section">Colonne 1</div>
            <div class="content-section">Colonne 2</div>
        </div>

        <div class="cards-container">
            <div class="card">
                <img class="card-image" src="..." />
                <div class="card-body">
                    <h3 class="card-title">Événement</h3>
                    <p class="card-text">Description</p>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
```

---

## 📊 Points de Contrôle Recommandés

### Test Mobile
- iPhone SE (375px)
- iPhone 12/13 (390px)
- iPhone 14 Pro (393px)
- Samsung S21 (360px)

### Test Tablette
- iPad (768px)
- iPad Pro (1024px)
- Surface Go (800px)

### Test Desktop
- Écran 1280px (standard)
- Écran 1920px (large)
- Écran 2560px (ultra-large)

---

## 🎯 Variables CSS Personnalisables

Modifier dans `responsive-pro.css` :

```css
:root {
    --spacing-md: clamp(1rem, 3vw, 1.5rem);
    --font-size-base: clamp(0.9rem, 3vw, 1rem);
    --breakpoint-md: 768px;
}
```

---

## 🚀 Bonnes Pratiques

### ✅ À faire

1. **Utiliser les classes responsive** plutôt que des media queries personnalisées
2. **Tester sur appareils réels** (DevTools ne suffit pas toujours)
3. **Utiliser `clamp()`** pour la typographie et l'espacement
4. **Mobile-first**: Commencer par le style mobile
5. **Grouper les éléments** avec `cards-container`, `stats-grid`, etc.

### ❌ À éviter

1. Ne pas ajouter de media queries personnalisées si une classe existe
2. Ne pas utiliser des largeurs fixes (`width: 500px`)
3. Ne pas oublier `viewport` meta tag
4. Ne pas utiliser des animations lourdes sur mobile

---

## 📱 Checklist Responsive

- [ ] Header adaptatif (menu hamburger sur mobile)
- [ ] Contenu fluide (pas de débordement horizontal)
- [ ] Formulaires empilés sur mobile
- [ ] Images responsive (max-width: 100%)
- [ ] Tableaux scrollables ou empilés
- [ ] Boutons tactiles (min 44px hauteur)
- [ ] Text lisible (min 16px sur mobile)
- [ ] Espacements adaptatifs
- [ ] Pas de débordement sur tous les breakpoints
- [ ] Teste sur DevTools + appareils réels

---

## 🔗 Fichiers Inclus

- `Css/responsive-pro.css` - Système responsive complet
- `client/header.php` - Navigation responsive
- `admin/header.php` - Dashboard admin responsive
- `promoteur/header.php` - Dashboard promoteur responsive
- `agent/header.php` - Interface agent responsive

---

## 💡 Support & Questions

Pour toute question sur l'utilisation du système responsive:
1. Vérifiez que vous incluez `responsive-pro.css`
2. Utilisez les classes disponibles plutôt que CSS personnalisé
3. Testez avec les DevTools (F12 > Toggle device toolbar)
4. Vérifiez la console pour les erreurs CSS

---

**Dernière mise à jour**: Septembre 2026
**Version**: 1.0
