---
name: muller-brockmann-design-standard
description: Standard de design obligatoire basé sur la grille modulaire Müller-Brockmann et le Style Typographique International.
trigger: always_on
---

# Directives Permanentes — Müller-Brockmann Grid System

Ce standard s'applique à toute conception, ajout ou refonte d'interface sur le projet :

1. **Grille modulaire 12 colonnes & Baseline 8px** :
   - Variable CSS centrale `:root` (`--cols: 12`, `--bl: 8px`, `--lh: 24px`, `--gutter: 24px`).
   - Tout espacement et hauteur de bloc est un multiple de 8px.

2. **Bandes Subgrid** :
   - Tout élément de mise en page se place via des coordonnées de lignes strictes (`grid-column: start / end`) à l'intérieur d'un conteneur avec subgrid.

3. **Typographie Suisse Grotesque & Monospace** :
   - Titres et corps : *Inter* (flush-left, ragged-right).
   - Données, badges, folios, kickers : *Space Mono*.
   - Grands chiffres (`font-weight: 900`) pour les dates et prix.
   - Alignement optique temps réel de l'encre via Canvas.

4. **Palette et Clarté** :
   - Pureté des blancs, encre sombre haute lisibilité, accent rouge suisse ou émeraude précis, bordures fines de 1px.
