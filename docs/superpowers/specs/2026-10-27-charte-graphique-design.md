# hockey-staff — Charte graphique et principes d'interface

Date : 2026-10-27 · Statut : validé
Complète `2026-10-27-hockey-staff-domain-design.md` (§3 contraintes, §9 lots). S'applique à tous les écrans,
dès le lot 1.

## 1. Intention

L'application est l'outil matériel du club **Corsaires de Nantes**. Elle doit être immédiatement reconnaissable
comme un outil du club, avec la même identité que l'application sœur **hockey-vestiaire**
(`/Users/gpiecq/development/web/hockey-vestiaire`), mais **plus claire** : vestiaire est conçu pour un écran
d'affichage sur fond navy, hockey-staff est un outil de saisie et de consultation utilisé longtemps, sur mobile
au bord de la glace comme sur ordinateur au bureau.

## 2. Objectifs / non-objectifs / anti-objectifs

**Objectifs**
- Même identité que vestiaire : logo, police Montserrat, palette de bleus, rayures, menu bas sur mobile.
- Ambiance claire : fond clair, contenu blanc, bleu club pour la structure.
- Une seule charte appliquée partout (un seul layout, des composants réutilisés), mobile-first.
- Listes de données toujours paginées ; listes déroulantes toujours en autocomplétion (hors petites listes
  fixes, voir §6).

**Non-objectifs**
- Mode sombre.
- Framework CSS (Bootstrap, Tailwind…) : CSS maison, comme vestiaire.
- Spécifique Hotwire Native (path configuration, masquage du menu web dans l'app native) : lot 3.

**Anti-objectifs**
- Interface sombre façon écran d'affichage (c'est précisément ce que l'utilisateur ne veut pas).
- Un logo modifié, recoloré ou redessiné.
- Une liste qui charge tout d'un coup, ou un `<select>` natif de 200 entrées.

## 3. Identité

**Nom affiché** : « Corsaires · Matériel » (menu, `<title>` des pages sous la forme `<Page> · Corsaires · Matériel`,
écran de connexion). L'identifiant technique du projet reste `hockey-staff`.

**Logo** : `hockey-vestiaire/assets/images/logo-corsaires.png` (PNG 500×500, fond transparent), repris **à
l'identique** dans `assets/images/logo-corsaires.png`.

**Favicon**, généré depuis ce même logo :
- `public/favicon.ico` (16, 32, 48 px) ;
- `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/apple-touch-icon.png` (180 px) ;
- balises `<link rel="icon">` et `<link rel="apple-touch-icon">` dans le layout, à la place du favicon Symfony
  par défaut.
- Générés **une fois** (ImageMagick ou `sips`, selon ce qui est disponible) et **commités** comme fichiers
  binaires ; pas de script de génération dans le build.

Le logo et les polices sont **copiés une fois et commités** dans hockey-staff : aucune dépendance au dépôt
vestiaire à l'exécution ni en CI.

**Police** : Montserrat auto-hébergée, fichiers repris de vestiaire (`assets/fonts/montserrat-latin-{500,700,800}-normal.woff2`),
`font-display: swap`. Graisses : 500 texte courant, 700 libellés et valeurs, 800 titres, boutons, menu.
Pas de chargement depuis un CDN.

## 4. Palette (variante « B — Bleu club »)

| Jeton CSS | Valeur | Usage |
|---|---|---|
| `--navy` | `#091626` | boutons d'action principaux, entrée de menu active, badges forts, rayures |
| `--blue` | `#2368bc` | fond du menu (latéral et bas), liens actifs, focus, page courante de la pagination |
| `--band` | `#1c4e8b` | en-têtes de tableau, textes secondaires forts (liens) |
| `--light` | `#5aa2ec` | contour de focus, accents sur fond bleu |
| `--bg` | `#eef3f9` | fond de page |
| `--surface` | `#ffffff` | cartes, tableaux, champs |
| `--line` | `#b7cbe4` | bordures de champs |
| `--line-soft` | `#e1e8f1` | séparateurs de lignes de tableau |
| `--text` | `#0d1f36` | texte principal |
| `--muted` | `#5b6b7e` | texte secondaire, métadonnées |
| `--danger` | `#7a1f2b` | erreurs, suppression (repris de vestiaire) |
| `--success` | `#1f6b3a` | messages de succès *(choix de Claude)* |

Rayures décoratives (héritées de vestiaire) : `repeating-linear-gradient(90deg, var(--navy) 0 8px, transparent 8px 13px)`,
bande de 6 px sous l'en-tête mobile et en haut du menu latéral.

Maquettes validées : `docs/superpowers/specs/maquettes/2026-10-27-charte-mobile.html` (option B retenue),
`docs/superpowers/specs/maquettes/2026-10-27-charte-ordinateur.html` (ordinateur, menu latéral).

## 5. Mise en page

**Mobile (< 1024 px)**
- En-tête bleu (`--blue`) : logo 38 px + « CORSAIRES / Matériel » en capitales 800, rayures navy dessous.
- Menu fixé en bas (comme vestiaire), fond blanc, entrées bleues, entrée active sur fond `--blue` texte blanc,
  hauteur ≥ 52 px, `padding-bottom: env(safe-area-inset-bottom)`.
- Au plus 5 entrées : les rubriques principales visibles selon le rôle, la dernière « Plus » ouvre le menu
  complet en panneau. *(Choix de Claude : quelles entrées sont en direct — en lot 1 : Tableau de bord,
  Catalogue, Imports, Plus.)*
- Contenu en une colonne, marges 1 rem.

**Ordinateur (≥ 1024 px)**
- Menu latéral gauche fixe, largeur ~200 px, fond `--blue` : logo + nom en haut, rubriques groupées avec
  intitulés de groupe en petites capitales, entrée active sur fond `--navy`, « Déconnexion » en bas.
- Barre supérieure blanche fine : utilisateur connecté (nom · rôle).
- Contenu sur fond `--bg`, largeur de lecture confortable.

**Structure du menu** (complète dès maintenant ; une entrée n'apparaît que lorsque son lot est livré et que le
rôle y a droit) :

| Groupe | Entrées | Lot |
|---|---|---|
| — | Tableau de bord | 1 |
| Catalogue | Produits, Imports, Saisons, Fournisseurs (marques et catégories accessibles depuis Fournisseurs/Produits ou en entrées propres, au choix du builder) | 1 |
| Stock | Stock, Mouvements, Lieux, Étiquettes | 2 |
| Club | Équipes, Joueurs | 4 |
| Inventaires | Inventaires | 5 |
| Valeur | Valeur du stock | 6 |
| Admin | Utilisateurs | 1 |

Pas d'entrée « bientôt disponible » : les rubriques des lots futurs sont absentes du menu tant qu'elles ne
sont pas livrées.

**Visibilité par rôle en lot 1** (découle de la spec domaine §6 et des voters du plan) :

| Entrée | Lecture seule | Resp. matériel | Manager | Admin |
|---|---|---|---|---|
| Tableau de bord, Produits, Saisons, Fournisseurs (consultation) | ✓ | ✓ | ✓ | ✓ |
| Imports | | | ✓ | ✓ |
| Utilisateurs | | | | ✓ |

Menu bas mobile en lot 1 : Tableau de bord, Produits, Imports (si Manager+), Plus. Les boutons de création et
d'édition n'apparaissent que si le voter l'autorise.

**Tableau de bord en lot 1** *(choix de Claude)* : saison courante, nombre de produits et de variantes actives,
dernier import (statut, date) pour Manager+, raccourcis (rechercher un produit, nouvel import). Il s'enrichira
dans les lots suivants (stock, valeur).

## 6. Composants et règles

**Boutons** : hauteur ≥ 44 px, rayon 8 px, texte 800. Principal : fond `--navy`, texte blanc. Secondaire :
fond blanc, bordure `--line`, texte `--band`. Danger : fond `--danger`. Un seul bouton principal par écran.

**Champs** : fond blanc, bordure 2 px `--line`, rayon 8 px, hauteur ≥ 44 px, police ≥ 16 px sur mobile (pas de
zoom iOS). Libellé au-dessus en 700. Erreur sous le champ en `--danger`.

**Listes de choix**
- **Petite liste fixe** (≤ 5 valeurs connues d'avance, ex. rôle utilisateur, mode de suivi
  Sérialisé/Quantité) → **boutons radio** stylés en tuiles cliquables.
- **Toute autre liste déroulante** (catégorie, marque, fournisseur, saison, produit…) → **autocomplétion** :
  champ où l'on tape, suggestions filtrées, correspondance surlignée, navigation clavier. Chargement depuis le
  serveur quand la liste peut dépasser quelques dizaines d'entrées *(seuil laissé au builder)*.
- Outil : `symfony/ux-autocomplete` (Tom Select) branché sur les formulaires Symfony *(choix de Claude ; le
  builder vérifie la compatibilité Symfony 8.1 / AssetMapper ; à défaut, Tom Select via importmap + contrôleur
  Stimulus maison, même rendu)*. Habillé aux couleurs de la charte (bordure `--blue` à l'ouverture, ligne
  survolée `#e3edf9`).
- Amélioration progressive *(choix de Claude)* : le formulaire soumet les mêmes valeurs qu'un
  `EntityType`/`ChoiceType` classique (identifiants), validées côté serveur ; sans JavaScript le champ reste un
  `<select>` fonctionnel. Les tests fonctionnels (sans JS) s'appuient sur ce comportement ; les points d'accès
  de suggestions serveur ont leurs propres tests.

**Listes de données (tableaux)**
- **Toujours paginées**, 30 éléments par page, quelle que soit la liste (utilisateurs, saisons, fournisseurs,
  marques, catégories, produits, imports, et toutes les listes des lots suivants).
- Sous la liste : compteur « 31–60 sur 633 produits » + pages (précédent, 1, …, page courante ± 1, …, dernière,
  suivant). Page courante fond `--blue`. Paramètre `?page=` dans l'URL, filtres conservés en changeant de page.
- Ordinateur : tableau blanc, en-têtes `--band` texte blanc capitales, montants alignés à droite en chiffres
  tabulaires. Mobile : chaque ligne devient une carte blanche (bordure gauche `--blue` 4 px).
- Un composant de pagination unique (Twig) réutilisé partout. *(Choix de Claude)* : un petit objet
  `App\Pagination\Page` (éléments, page, nombre par page, total) construit depuis une requête Doctrine via le
  `Paginator` de Doctrine ; les repositories de liste renvoient un `Page` ; le gabarit Twig de pagination ne
  connaît que `Page`. `CatalogueSearch::search()` (Task 6) renvoie aussi un `Page`.
- État vide : message en `--muted` + action principale si l'utilisateur peut créer.

**Badges** : petite pastille arrondie 800. Fort : fond `--navy` texte blanc (ex. « Sérialisé »). Neutre : fond
`#e3e7ec` texte `--muted` (ex. « Quantité », « Plus au catalogue »).

**Messages flash** : bandeau en haut du contenu, fond pâle + bordure gauche de la couleur du type (succès,
erreur, info).

**Écran de connexion** : fond `--bg`, carte blanche centrée, logo ~120 px au-dessus, « Corsaires · Matériel »,
champs email / mot de passe, case « Rester connecté », bouton principal pleine largeur.

**Accessibilité** : contraste texte ≥ AA, focus visible `outline: 3px solid var(--light)` (comme vestiaire),
cibles tactiles ≥ 44 px, `<html lang="fr">`, menu dans un `<nav aria-label>`, entrée active
`aria-current="page"`.

## 7. Impact sur le plan du lot 1

- **Task 1** pose la charte : copie du logo et des polices, favicon, `assets/styles/app.css` (jetons,
  composants), layout `base.html.twig` (menu latéral / menu bas selon §5), écran de connexion, composant de
  pagination, installation et habillage de l'autocomplétion.
- **Tasks 2 à 11** : chaque liste utilise le composant de pagination ; chaque choix suit la règle radios /
  autocomplétion ; tests fonctionnels vérifiant la pagination (≥ 31 éléments → 2 pages) là où une liste est
  créée.
- La ligne « CSS mobile-first simple, pas de framework » de la Task 1 reste vraie et est précisée par ce
  document.

## 8. Laissé au builder

- Structure exacte du CSS (un fichier ou plusieurs), nommage des classes.
- Détail du panneau « Plus » sur mobile.
- Seuil de chargement serveur pour l'autocomplétion.
- Place de Marques et Catégories dans le menu.
- Icônes éventuelles dans le menu (aucune exigée).
