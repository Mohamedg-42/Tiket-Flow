# Directives de Design & Architecture — Grille Modulaire Müller-Brockmann

Pour tous les travaux de conception de pages web, d'interfaces et de refonte visuelle sur ce projet et les développements futurs, appliquer systématiquement les principes du **Style Typographique International (Müller-Brockmann Grid System)** :

## 1. Règle de Grille & Source Unique de Vérité (`:root`)
- Utiliser impérativement une **grille modulaire à 12 colonnes** avec gouttières homogènes (`--gutter`) et marges calculées (`--margin`).
- Verrouiller le **rythme vertical sur une ligne de base de 8px (baseline lock)**. La hauteur de ligne standard est de 24px (`--lh: 24px = 3 x 8px`).
- Tous les espacements (`padding`, `margin`, hauteurs d'images) doivent être des multiples entiers de la baseline (8px, 16px, 24px, 32px, 48px, 64px).

## 2. Bandes Subgrid (`.band` / `.swiss-band`)
- Découper les sections horizontales en bandes (`.band`) s'étendant sur toute la grille (`grid-column: 1 / -1`) et ré-exposant les colonnes avec `grid-template-columns: subgrid` (avec fallback `repeat(var(--cols), 1fr)`).
- Placer chaque élément explicitement par ses lignes de colonnes (`grid-column: <start> / <end>`), sans valeurs approximatives.

## 3. Typographie & Alignement Optique
- **Famille typographique** : Grotesque sans-serif pur (*Inter* ou *Helvetica*) pour le titrage et le corps de texte ; police monospace technique (*Space Mono*) pour les métadonnées, dates, étiquettes de catégories et kickers.
- **Alignement du texte** : Toujours aligné à gauche (*flush-left, ragged-right*).
- **Chiffres & données clés** : Mettre en valeur les prix, jauges, statistiques et dates avec de grands chiffres gras (*font-weight: 800/900*).
- **Alignement optique de l'encre** : Ajuster l'encre des glyphes des grands titres (`actualBoundingBoxLeft` via Canvas) pour que le tracé visible soit exactement tangent à la ligne de colonne.

## 4. Palette de Couleurs & Éthique Suisse
- Fond blanc papier franc (`#ffffff`), encre sombre contrastée (`#0f172a` ou `#111315`), texte atténué (`#64748b`).
- Accents stricts et ciblés : rouge suisse iconique (`#e4002b`) ou émeraude identitaire (`#0d9488`).
- Proscrire les dégradés bleu/violet artificiels ou les rendus flous : privilégier les lignes géométriques nettes (*hairlines* de 1px) et les espaces négatifs amples.

## 5. Calque d'Inspection Interactif
- Maintenir l'overlay interactif de la grille (classes `.guides`, `.cols`, `.rows`, activable via la touche `G` et le bouton de commande) dans le même conteneur (`.wrap`) que le contenu.
