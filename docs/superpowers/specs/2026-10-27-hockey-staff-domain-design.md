# hockey-staff — Design du domaine et découpage

Date : 2026-10-27 · Statut : à valider par l'utilisateur

## 1. Intention

Application web + mobile pour **gérer le stock de matériel de hockey sur glace d'un club** : catalogue fournisseur
importé depuis Excel, mouvements de stock (achat, dotation, sortie, prêt, vente d'occasion…), attribution du
matériel aux joueurs et équipes d'une saison, inventaires par scan de codes-barres depuis le mobile, et suivi de la
**valeur HT du stock** dans le temps.

Le besoin de fond : savoir à tout moment **ce que le club possède, où c'est, qui l'a, et combien ça vaut**, sans
tableur, avec une saisie rapide au scan sur le terrain.

## 2. Objectifs / non-objectifs / anti-objectifs

**Objectifs**
- Import du catalogue fournisseur (Bauer en premier) chaque saison, avec rapport d'anomalies.
- Suivi mixte : pièces sérialisées (crosse, patin, casque…) et consommables en quantité.
- Mouvements tracés (qui, quand, pourquoi), multi-lieux.
- Scan code-barres (EAN fournisseur ou étiquette interne générée) sur mobile pour entrées/sorties/inventaire/CRUD.
- Attribution joueur / équipe par saison, retour avec état.
- Revente d'occasion avec % de dépréciation saisi au cas par cas.
- Valeur HT du stock au prix d'achat réel et son évolution.

**Non-objectifs**
- Multi-clubs / SaaS (un seul club).
- Comptabilité : pas de factures numérotées, TVA, avoirs, export comptable.
- Compte joueur / portail acheteur.
- Mode hors ligne (réseau disponible partout).
- Synchronisation FFHG.

**Anti-objectifs** (échec même si « ça marche »)
- Scanner lent ou peu fiable au point que le staff revienne au papier.
- Valeur du stock qui change quand un tarif change (elle doit reposer sur le prix d'achat réel).
- Perte d'historique : un mouvement ou une pièce supprimé(e) sans trace.
- Un import qui écrase silencieusement des données ou rejette des lignes sans le dire.

## 3. Contraintes

- Stack existante (cf. README) : Symfony 8.1, PHP 8.5, FrankenPHP worker, PostgreSQL 18, Doctrine ORM 3.7,
  API Platform 5, Mercure, Messenger, Scheduler, AssetMapper, Turbo + Stimulus (déjà installés),
  `security-bundle` installé (non configuré). QA : php-cs-fixer, PHPStan niveau 9, PHPUnit 13.
- Mobile : **Hotwire Native dès le départ** (coques iOS + Android affichant les pages Symfony), scanner natif via
  bridge component. Le web reste pleinement utilisable dans un navigateur.
- Montants en **centimes (int)**, jamais en float.
- Interface en français.

## 4. Décisions prises avec l'utilisateur

| Sujet | Décision |
|---|---|
| Utilisateurs | Staff d'un seul club, pas de compte joueur |
| Rôles | Admin, Manager, Responsable matériel, Lecture seule (détail §6) |
| Granularité | Mixte : sérialisé ou quantité selon le produit |
| Valorisation | Prix d'achat réel HT de chaque entrée (CUMP pour les quantités) |
| Prix d'achat | « PU Club TTC » du catalogue = prix d'achat. HT = TTC / (1 + TVA), TVA 20 % par défaut |
| Prix à l'entrée | Pré-rempli avec le prix club HT de la saison, **modifiable** (remise, facture réelle) |
| Tarifs | Évoluent chaque saison : un tarif par variante et par saison (club TTC, public TTC) |
| Dotation | Entrée à coût 0 € (valeur stock réelle) + valeur catalogue conservée à part, suivie par fournisseur/saison |
| Attributions | Prêt avec retour, vente, don définitif, attribution à une équipe |
| Revente | Prix = prix d'achat de la pièce × (1 − %), % saisi au moment de la vente selon l'état ; prix final modifiable |
| Acheteur | Nom + prénom libres (pas forcément un joueur), joueur lié optionnel ; statut payé / non payé |
| Sans code-barres | Étiquette interne générée (Code128/QR), PDF imprimable ; EAN fournisseur conservé s'il existe |
| Inventaire | Session (totale ou par lieu/catégorie), scans multi-utilisateurs, écarts, validation d'ajustements |
| Lieux | Plusieurs lieux, transferts entre lieux |
| Réseau | En ligne uniquement |
| Fournisseurs | Plusieurs à terme : import avec mapping de colonnes par fournisseur ; création manuelle possible |
| Joueurs/équipes | Minimal, saisie manuelle ou import CSV |
| Premier lot | Socle + catalogue |
| Ré-import | **Aperçu puis validation** : ImportRun en brouillon → diff affiché → l'utilisateur valide → application (voir §5.1) |
| Multi-fournisseurs | **Un seul fournisseur par produit** ; EAN unique globalement dans le catalogue du club |

## 5. Analyse du catalogue Bauer (`bauer-catalogue-travail.xlsx`)

1 feuille « Catalogue », 2 468 lignes, colonnes :
`Référence interne | Gencode | Désignation | Taille | Modèle/Fit | Côté | Largeur | Couleur | PU Public TTC |
PU Club TTC | Catégorie | sous-catégorie 1 | sous-catégorie 2 | Marque`

- 633 références = **produits** ; chaque ligne = **variante** identifiée par son gencode (EAN 12 ou 13 chiffres).
- Attributs de variante : Taille (146 valeurs : SR L, 10.0, P92, 84…), Fit (FIT1-3), Côté (LFT/RHT),
  Largeur (D, 2E, R, B, C), Couleur (27 valeurs).
- Catégorie 3 niveaux (8 catégories racine ; ex. EQUIPEMENT › JOUEUR › PATIN). Sous-niveaux parfois vides.
- 10 marques (BAUER, BLUE SPORT, GRIT…) : la **marque** est distincte du **fournisseur** (Bauer).
- Pas de saison dans le fichier : un fichier = un tarif de saison, choisie à l'import.
- Prix stockés en texte (`'0.5'`, `'3'`).

**Anomalies à gérer à l'import (rapport, jamais silencieux)**
- 11 lignes sans gencode → variante créée sans EAN, étiquette interne à générer.
- 2 gencodes en doublon (`688698508062`, `688698508079`) → signalées, seconde ligne rejetée.
- 85 lignes avec une couleur dans la colonne Taille (NOIR, BLANC) → normalisées vers Couleur.
- Couleurs FR/EN mélangées (NOIR/BLK, BLANC/WHT, NAVY/NAV…) → table de correspondance éditable.
- Crosses : courbure (P92, P28) et flex (84, 96…) mélangés dans Taille → conservés tels quels en lot 1
  (la colonne Taille reste un libellé libre) ; découpage courbure/flex = amélioration ultérieure.
- Prix manquants (public 26, club 15 lignes) → variante créée, tarif absent signalé.

### 5.1 Flux d'import et de ré-import

1. Upload du fichier + choix du fournisseur et de la saison → `ImportRun` en statut `DRAFT`, analyse async
   (Messenger).
2. Rapprochement de chaque ligne avec une variante existante :
   - par **gencode** s'il est présent ;
   - sinon par **fournisseur + référence interne + attributs** (Taille, Fit, Côté, Largeur, Couleur normalisés).
3. Aperçu (diff) présenté à l'utilisateur : nouvelles variantes / nouveaux produits, tarifs créés ou modifiés pour
   la saison, champs descriptifs changés (désignation, catégorie, marque, attributs), variantes absentes du
   fichier, anomalies (doublons, lignes rejetées, prix manquants, normalisations appliquées).
4. Validation → application en une transaction, `ImportRun` passe `APPLIED` (ou `CANCELLED`).
   - Champs descriptifs : mis à jour depuis le fichier (affichés dans le diff).
   - Variante absente du fichier : marquée **« plus au catalogue »** (`active = false`), jamais supprimée ; elle
     reste scannable et ses pièces en stock ne bougent pas.
   - Un gencode déjà rattaché à un **autre** fournisseur → ligne rejetée et signalée (EAN unique global).
   - Gencode qui change pour une même référence + attributs → signalé comme conflit, pas appliqué
     automatiquement.

**Mapping de colonnes** : enregistré par fournisseur (`SupplierImportMapping`, colonne source → champ cible +
table de correspondance couleurs). En lot 1 le mapping Bauer est fourni par une fixture/migration de données ;
l'écran d'édition du mapping vient plus tard.

## 6. Rôles et sécurité

Hiérarchie : `ROLE_ADMIN > ROLE_MANAGER > ROLE_STOCK > ROLE_VIEWER`.

| Rôle | Droits |
|---|---|
| Admin (l'utilisateur) | Tout + utilisateurs, paramètres, suppressions définitives |
| Manager | Catalogue, prix/tarifs, saisons, fournisseurs, joueurs, équipes, lieux, imports, tous les mouvements, ventes |
| Responsable matériel (`ROLE_STOCK`) | Entrées, sorties, transferts, prêts/retours, inventaires, étiquettes |
| Lecture seule | Consultation catalogue, stock, valeur, historique |

- Auth formulaire (email + mot de passe) + remember-me longue durée (session persistante dans Hotwire Native).
- Droits fins via **Voters**. Pas d'inscription publique : comptes créés par l'admin.
- Commande console pour créer le premier admin.

## 7. Modèle de domaine

```
Supplier ─< Product ─< Variant ─< SeasonPrice >─ Season
              │  └─ Brand, Category(arbre 3 niveaux), tracking: SERIALIZED|QUANTITY
              │
Variant ─< Item (pièce sérialisée : code interne, EAN, état, prix d'achat, lieu ou détenteur, statut)
Variant ─< StockLevel (variante × lieu : quantité, coût moyen pondéré)   [produits QUANTITY]

Location ─< Item / StockLevel
StockMovement (type, date, auteur, lieu source/dest, variante, item?, quantité, coût unitaire HT,
               valeur catalogue (dotation), saison, joueur?/équipe?, vente?, commentaire)

Season ─< TeamSeason (équipe × saison) ─< Roster (joueur × équipe-saison)
Player, Team
Assignment (item|quantité → joueur ou équipe-saison ; type LOAN|GIFT ; date début/retour ; état au retour)
Sale (acheteur nom/prénom, joueur?, lignes : item|variante×qté, prix d'achat, % dépréciation, prix de vente,
      statut PAID|UNPAID, date)
InventorySession (périmètre lieu/catégorie, statut OPEN|REVIEW|CLOSED) ─< InventoryCount (scan/compte)
StockValueSnapshot (date, valeur HT totale, ventilations par catégorie/lieu)
ImportRun (fournisseur, saison, fichier, compte rendu des anomalies)
```

Règles clés :
- **Toute variation de stock passe par un `StockMovement`** ; `Item` et `StockLevel` sont des états dérivés mis à
  jour dans la même transaction (service applicatif unique, pas d'écriture directe).
- Types de mouvement : `PURCHASE_IN`, `DONATION_IN`, `OUT` (perte, casse, rebut), `TRANSFER`, `LOAN_OUT`,
  `LOAN_RETURN`, `GIFT_OUT`, `SALE_OUT`, `INVENTORY_ADJUST`.
- Valeur stock HT = Σ prix d'achat des `Item` appartenant au club (en stock **et prêtés**)
  + Σ (quantité × CUMP) des `StockLevel`. Dotations à 0 €.
- Le `Item` porte un **code interne** unique (ex. `HS-000123`) toujours imprimable ; l'EAN est un attribut de
  la variante. Scan d'un EAN sur un produit sérialisé → choisir/créer la pièce ; scan d'un code interne → la pièce.
- Suppression : **soft delete** pour le catalogue, joueurs, équipes, lieux ; les mouvements ne se suppriment
  jamais (correction = mouvement inverse). Horodatage + auteur sur toutes les entités.
- Prix HT dérivé : stocké en centimes TTC + taux TVA sur `SeasonPrice` ; HT calculé.
- **Product appartient à un seul Supplier** ; **EAN unique globalement** (contrainte unique nullable sur
  `Variant.ean`) ; code interne `Item` unique.
- **Attributs de variante** : colonnes fixes `size`, `fit`, `side`, `width`, `color` (libellés normalisés)
  + `extraAttributes` JSON pour les attributs propres à un autre fournisseur.
- **Catégories** : arbre unique du club (initialisé depuis Bauer) ; les autres fournisseurs mappent leurs
  catégories dessus via leur mapping d'import.
- **Saison** : libellé (`2026-2027`), date de début, date de fin ; **une seule saison courante** (flag),
  choisie par le Manager ; création manuelle. Sert au pré-remplissage des prix d'entrée, à l'import et aux rosters.
- **Valeur catalogue d'une dotation** = prix club **HT** de la saison courante, figé dans le mouvement.
- **Suppression définitive (Admin)** : autorisée uniquement sur une entité sans aucun mouvement/pièce lié(e)
  (sinon soft delete seul). Les `StockMovement` ne sont jamais supprimables, par personne.

## 8. Ajouts à la stack

| Besoin | Choix |
|---|---|
| Import Excel | `phpoffice/phpspreadsheet` (traitement async via Messenger pour gros fichiers) |
| Codes-barres / QR | `picqer/php-barcode-generator` (Code128), `endroid/qr-code` |
| PDF (étiquettes, reçus) | `dompdf/dompdf` |
| Graphiques valeur | `symfony/ux-chartjs` |
| Scan web (navigateur) | contrôleur Stimulus + `@zxing/browser` via importmap (secours hors app native) |
| Hotwire Native | 2 dépôts séparés (`hockey-staff-ios` Swift, `hockey-staff-android` Kotlin) ; Symfony sert la path configuration JSON ; bridge component `barcode-scanner` |
| Auth | `security-bundle` (déjà là) : form_login, remember_me, hiérarchie, Voters |
| Soft delete / audit | `stof/doctrine-extensions-bundle` (Timestampable, Blameable, SoftDeleteable) |
| Temps réel inventaire | Mercure (déjà là) : compteurs de session live |
| Snapshot valeur | Scheduler (déjà là) : snapshot mensuel + à la clôture d'inventaire |

API Platform reste pour d'éventuels besoins JSON ; l'UI est en Twig + Turbo (pas de SPA).

## 9. Découpage en lots

1. **Socle + catalogue** — auth/rôles/utilisateurs, layout web responsive mobile-first (Turbo ; pas encore de
   path configuration ni de détection native — lot 3), Saison, Fournisseur, Marque,
   Catégorie, Produit/Variante/Tarif saison, import Bauer (mapping colonnes, rapport d'anomalies, ré-import
   saison suivante = mise à jour des tarifs), recherche/consultation catalogue (par EAN, désignation, catégorie),
   CRUD manuel produit/variante.
2. **Stock & mouvements** — **LIVRÉ** (recette manuelle à faire, voir les décisions du lot) — lieux, entrées achat/dotation, sorties, transferts, pièces sérialisées, CUMP,
   historique, étiquettes internes PDF. *Design détaillé : `2026-10-27-lot2-stock-mouvements-design.md` ; les
   étiquettes passent au lot 8, ordre retenu lot 2 → lot 8 → lot 3.*
3. **Scan & Hotwire Native** — coques iOS/Android, bridge scanner, flux « scanner → action », scan web de secours.
4. **Joueurs, équipes, attributions, ventes** — **LIVRÉ** (recette manuelle à faire, voir les décisions du lot) — saisons d'équipe, roster, prêt/retour avec état, don, ventes
   d'occasion avec dépréciation. *Décidé le 2026-10-28 : le lot 4 passe **avant** le lot 3. Design détaillé :
   `2026-10-28-lot4-joueurs-attributions-ventes-design.md`, qui remplace sur ces points le § 4 : pas de reçu PDF,
   ni moyen de paiement, ni statut payé / non payé ; pas d'import CSV des joueurs ; pas de prêt à une équipe (don à
   un joueur ou à une équipe) ; prix de revente = prix **public** TTC × (1 − % de réduction).*
   *Lot 4b — **LIVRÉ** : stock en quantité par variante × lieu × état, fin du suivi pièce par pièce ; voir `2026-10-28-stock-quantite-etat-design.md` et `../decisions/2026-10-28-lot4b-decisions.md`.*
5. **Inventaire** — sessions, scans multi-utilisateurs live (Mercure), écarts, ajustements.
6. **Valorisation** — valeur HT courante, snapshots, graphiques d'évolution, valeur des dotations par fournisseur.
7. **Préparation assistée du catalogue (IA)** — **LIVRÉ** (recette manuelle à faire, voir les décisions du lot) — *ajouté le 2026-10-27, position dans l'ordre des lots à décider.
   Design détaillé : `2026-10-27-lot7-preparation-catalogue-design.md` (il remplace les questions ouvertes
   ci-dessous).*
   - **Fichier template d'import** officiel (xlsx téléchargeable depuis l'appli) : format pivot unique accepté par
     l'import du lot 1, colonnes de `bauer-catalogue-travail.xlsx` + notre arborescence de catégories (référentiel
     maison, propre au club, différent de celui du fournisseur).
   - **Consolidation par IA** : à partir du fichier fournisseur brut Bauer (multi-onglets, structure propre à
     Bauer), une IA extrait et centralise les lignes dans le template : produits/variantes, gencodes, attributs
     (taille, fit, côté, largeur, couleur normalisée), prix, et **affectation à nos catégories**.
   - Le template produit repasse par le flux d'import existant (analyse, diff, anomalies, validation) : l'IA ne
     touche jamais la base directement, l'humain valide toujours.
   - Questions ouvertes (à trancher en brainstorming, sur le vrai fichier Bauer brut) : IA intégrée à l'appli
     (API Claude, job Messenger) ou outil hors appli ; signalement des lignes à faible confiance (catégorie
     incertaine) ; mémorisation des affectations validées d'une saison sur l'autre (référence → catégorie) ;
     coût par import ; généricité pour d'autres fournisseurs.
   - Pré-requis : fichier Bauer brut multi-onglets fourni par l'utilisateur ; référentiel de catégories maison
     stabilisé.
8. **Codes-barres et étiquettes** — **LIVRÉ** (recette manuelle à faire, voir les décisions du lot) — *ajouté le 2026-10-27 à la demande de l'utilisateur, position dans l'ordre des
   lots : après le lot 2, avant le lot 3 (décidé avec le design du lot 2). Design détaillé :
   `2026-10-27-lot8-codes-barres-etiquettes-design.md` (il tranche les questions ouvertes ci-dessous).*
   - **Génération de codes-barres** pour chaque variante : à partir de l'**EAN** fournisseur quand il existe
     (symbologie EAN-13 / UPC-A), sinon de la **référence fournisseur** (+ attributs) ou du code interne `HS-`
     (Code128 / QR). But : rendre les mouvements de stock au téléphone plus rapides et fiables (scan au lieu de
     recherche).
   - **Impression d'étiquettes** avec code-barres : depuis la fiche produit/variante, une sélection du catalogue ou
     une entrée de stock (une étiquette par pièce reçue) ; PDF imprimable (planches A4 autocollantes et/ou
     imprimante d'étiquettes), avec désignation, taille/attributs et code lisible.
   - Englobe et remplace les « étiquettes internes PDF » prévues au lot 2 (le lot 2 peut s'en tenir au code
     interne si le lot 8 vient après).
   - Le scan doit retrouver la variante quel que soit le code imprimé (EAN, référence fournisseur, code interne),
     y compris l'EAN avec zéro de tête.
   - Questions ouvertes (à trancher en brainstorming) : formats d'étiquettes et matériel d'impression du club ;
     unicité d'un code basé sur la référence fournisseur (une référence = plusieurs variantes) ; étiquette par
     pièce sérialisée vs par variante ; réimpression en masse après un import ; génération côté serveur (lib PHP)
     ou navigateur.

Chaque lot passe par son propre plan d'implémentation.

## 10. Choix faits par Claude (à vérifier en lecture) et marge du builder

**Choix faits par Claude**
- TVA 20 % par défaut, stockée par tarif.
- Matériel prêté compté dans la valeur du stock (il appartient toujours au club).
- Snapshot de valeur mensuel + à chaque clôture d'inventaire.
- Code interne au format `HS-` + séquence.
- Hotwire Native dans 2 dépôts séparés.
- Colonne Taille gardée comme libellé libre en lot 1.
- Suivi `SERIALIZED` par défaut pour la catégorie EQUIPEMENT, `QUANTITY` pour le reste ; modifiable par produit.
- Lot 1 = web responsive seul ; tout le spécifique Hotwire Native en lot 3.
- Suppression définitive limitée aux entités sans mouvement lié.
- Saison = libellé + dates + une seule courante, création manuelle.
- Valeur catalogue dotation = prix club HT.
- Attributs de variante = 5 colonnes fixes + JSON ; mapping d'import par fournisseur, Bauer livré en données.
- Champs descriptifs mis à jour au ré-import (visibles dans l'aperçu avant validation).

**Laissés au builder**
- Écrans exacts, navigation, design visuel (mobile-first).
- Structure interne des services, nommage des classes.
- Format exact du rapport d'import.
