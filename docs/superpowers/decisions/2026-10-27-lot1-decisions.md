# Lot 1 — Décisions d'exécution

Plan : `docs/superpowers/plans/2026-10-27-lot1-socle-catalogue.md`
Specs : `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md`,
`docs/superpowers/specs/2026-10-27-charte-graphique-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Avant exécution (vérification du plan)

| # | Décision | Pourquoi | Coût si faux |
|---|---|---|---|
| R1 | L'entité `User` n'a pas d'horodatage ni d'auteur (trait réservé aux entités du domaine). | La spec « horodatage + auteur sur toutes les entités » vise le catalogue et le stock ; `User` est créé avant le trait. | Une petite migration. |
| R2 | La migration de données Bauer (Task 8) remplit `created_at`/`updated_at` = `NOW()`, `created_by` NULL. | Une migration SQL contourne les extensions Doctrine. | Modifier la migration. |
| R3 | Les changements portant sur le produit (désignation, marque, catégorie) apparaissent dans l'aperçu d'import sur chaque variante du produit (`changedVariants`), l'application met à jour le produit. | Conserve la forme d'`ImportDiff` prévue par le plan. | Changer la forme du diff (Tasks 9 et 11). |
| R4 | Le statut `Pending` du plan correspond au `DRAFT` de la spec §5.1 ; noms du plan conservés. | Le plan détaille des statuts plus fins que la spec. | Renommer l'enum. |
| R5 | Travail directement sur `develop`, sans branche dédiée. | Flux de branches de l'utilisateur ; pas de push sans accord. | Aucun, historique linéaire. |

## Pendant l'exécution

| # | Tâche | Décision | Pourquoi | Coût si faux |
|---|---|---|---|---|
| R6 | 1 | Le test « `GET /favicon.ico` → 200 » est remplacé par : fichier présent et non vide + balises `<link>` présentes. | FrankenPHP sert le fichier statique sans passer par le noyau Symfony ; une route dédiée au test serait artificielle. | Aucun fonctionnel ; vérifié au navigateur en Task 12. |
| R7 | 4 | Le nom d'un fournisseur ou d'une marque est unique parmi les non supprimés (index unique partiel `WHERE deleted_at IS NULL`) ; recréer un nom supprimé est permis. | La validation d'unicité ne voit pas les lignes supprimées : une unicité globale finirait en erreur 500. | Une migration. |
| R8 | 4 | Unicité `(parent, nom)` des catégories aussi pour les racines (index partiel `WHERE parent_id IS NULL`) ; pas de suppression de catégorie en lot 1. | PostgreSQL considère les NULL distincts ; la contrainte globale ne prévoit le soft delete que sur fournisseur, marque, produit, variante. | Une migration et une action de suppression plus tard. |
| R9 | 4 | L'autocomplétion « Catégorie parente » ne propose que les niveaux 1-2 ; le cas `pat` du plan est testé avec une catégorie de niveau 2 ; le champ produit (Task 5) aura son propre champ. | Un parent de niveau 3 violerait la profondeur max 3 ; le plan supposait PATIN proposable. | Une classe de champ ou une option en plus. |
| R10 | 5 | Unicités de produit/variante (EAN, référence par fournisseur, clé de correspondance par produit) limitées aux lignes non supprimées ; tarif saison : unicité simple. | Même raison que R7 ; « EAN unique globalement » compris parmi les variantes vivantes. | Une migration ; l'import (Tasks 8-9) doit décider s'il ressuscite une variante supprimée de même EAN. |
| R11 | 6 | La liste du catalogue masque un produit seulement s'il a au moins une variante et aucune active ; un produit sans variante reste visible. | Sinon un produit saisi à la main (Task 7) disparaîtrait avant l'ajout de ses variantes. | Une clause de requête. |
| R12 | 6 | Le filtre Catégorie du catalogue utilise un champ d'autocomplétion tous niveaux, distinct du champ « Catégorie parente » ; fournisseur et marque ont chacun leur champ. | R9 réserve le champ parent aux niveaux 1-2. | Fusion de classes plus tard. |
| R13 | 7 | Message d'EAN en doublon : « Ce code EAN est déjà utilisé. » (texte du plan). | La Task 5 avait repris une formulation différente. | Une chaîne. |
| R14 | 7 | Mode de suivi pré-rempli côté serveur : radios vides à la création, valeur par défaut selon la catégorie si rien n'est choisi ; modifiable ensuite. | Pas de JS requis ; la catégorie n'est connue qu'à la soumission. | Un petit contrôleur Stimulus pour présélectionner. |
| R15 | 7 | Langue par défaut de l'application passée en `fr`. | Saisie « 12,50 » et messages de validation Symfony en français. | Une ligne de configuration. |
| R16 | 8 | La migration Bauer réutilise un fournisseur `BAUER` existant (non supprimé) ou le crée ; le mapping n'est inséré que s'il n'en a pas ; `down()` ne retire que le mapping. | Protège les données de dev, migration rejouable. | Modifier la migration. |
| R17 | 8 | Prix lus depuis la chaîne sans float : espaces retirés, un séparateur `,` ou `.`, 2 décimales max → centimes ; sinon `PriceInvalid`. | Règle « jamais de float ». | Ajuster l'analyseur. |
| R18 | 9 | Le rapprochement ignore produits et variantes supprimés : un EAN qui n'appartient qu'à une variante supprimée donne une nouvelle variante. | La suppression reste un geste volontaire que l'import ne défait pas. | Logique de réactivation en Task 10. |
| R19 | 9 | EAN connu chez ce fournisseur mais référence différente → ligne rejetée (`EanChanged`) ; une ligne rejetée qui désigne une variante existante compte comme présente (pas de désactivation). | Rien de silencieux, pas de déplacement de variante entre produits en lot 1. | Gérer les déplacements plus tard. |
| R20 | 9 | Un prix absent du fichier n'efface jamais un prix enregistré ; une variation de tarif n'est listée que si un montant change. | Spec : « tarif absent signalé », pas effacé. | Ajuster la sémantique du diff. |
| R21 | 9 | L'analyse ne crée rien : marque et catégorie comparées en texte (nom, chemin « A › B › C ») ; création à l'application (Task 10). | Spec : analyse en lecture seule. | Aucun. |
| R22 | 9 | Codes d'anomalie ajoutés `VariantDuplicateInFile`, `ProductFieldsConflict` (la 1ʳᵉ ligne l'emporte) et clé `ean` dans les changements de variante. | Aucun code du plan ne convenait ; rien de silencieux. | Renommer les codes. |
| R23 | 9 | L'analyse rejette (avec anomalie) les lignes qui heurteraient un index unique à l'application : EAN tenu par une variante vivante d'un produit supprimé (`EanHeldByDeletedProduct`), changement d'attributs vers la clé d'une variante sœur (`VariantKeyCollision`) ; une ligne rejetée par la normalisation qui désigne une variante la garde présente. | Index uniques partiels sur le `deleted_at` de la variante elle-même ; pas de diff inapplicable. | Une requête de préchargement en plus. |
| R24 | 10 | Collision entre la nouvelle clé d'une variante modifiée et celle d'une nouvelle variante du même fichier → ligne rejetée (`VariantKeyCollision`), détectée dans l'analyse (l'application ré-analyse). | Une seule garde pour l'aperçu et l'application. | Un contrôle en plus. |
| R25 | 10 | Changer la catégorie d'un produit existant par import conserve son mode de suivi (déduit seulement à la création). | Décision de stock, à ne pas recalculer en silence. | Recalcul au changement. |
| R26 | 11 | Upload : `.xlsx` uniquement, 20 Mo max ; lecteur limité aux formats XLSX/XLS. | Plan : « fichier non xlsx → erreur » ; défense en profondeur. | Autoriser `.xls` dans le formulaire. |
| R27 | 11 | Échec d'application → message + redirection sans réutiliser l'EntityManager ; rafraîchissement auto d'un run en cours par `<meta refresh>`. | `wrapInTransaction` ferme l'EM sur erreur ; fonctionne sans JS. | Passer à un polling Turbo. |
| R28 | 12 | Dev uniquement : `memory_limit` 512M (`20-app.dev.ini`). L'application synchrone du vrai fichier dépassait 128M en mode debug (profiler) ; rollback vérifié. | Prod hors debug : +10 Mo mesurés. | Passer l'application sur le worker. |
| R29 | 12 | À la demande du propriétaire : listes, tableaux et cartes en pleine largeur sur grand écran ; formulaires et textes gardent une largeur lisible. | Remplace « largeur de lecture confortable » de la charte. | Remettre le `max-width`. |
| R30 | revue finale | Sous-catégorie 1 vide mais sous-catégorie 2 remplie : chemin ramené à 2 niveaux avec anomalie non bloquante `CategoryLevelMissing`. | « Jamais silencieux », produit importé quand même. | Rejeter la ligne. |
| R31 | revue finale | Valeur plus longue que sa colonne → ligne rejetée (`ValueTooLong`, colonne et maximum nommés). | Sinon l'application échoue sans ligne désignée. | Raccourcir au lieu de rejeter. |

## Points mineurs reportés (à trier en revue finale du lot)

- Task 1 — `app:user:create` : pas de validation du format email ni de passage en minuscules (unicité sensible à la
  casse), pas de longueur minimale du mot de passe ; saisie masquée et mot de passe vide non testés.
- Task 1 — déconnexion par lien GET sans jeton CSRF.
- Task 1 — `access_control` `^/login` couvre aussi `/login-xxx` (préférer `^/login$`).
- Task 1 — commentaires générés laissés dans la migration ; `UserFactory` code en dur le coût bcrypt 4.
- Task 2 — formulaire utilisateur : pas de contrainte `Email` ni `Length(max: 180)` sur l'email (> 180 caractères
  → erreur base), pas de longueur max sur prénom/nom/mot de passe ; email non passé en minuscules (à faire avec une
  recherche insensible à la casse au login).
- Task 2 — `?page[]=x` → 400 ; pas de test `?page=0`/`-1` ni du changement de mot de passe en édition.
- Task 2 — détection « soi-même » par identité d'objet plutôt que par id ; `UserVoter` répète le contrôle `ROLE_ADMIN`.
- Task 3 — « rendre courante » : la remise à zéro en DQL contourne Gedmo (l'ancienne saison courante garde ses
  `updatedAt`/`updatedBy`) et laisse les entités en mémoire périmées ; deux appels concurrents → violation d'index (500).
- Task 3 — regex du libellé avec `$` (accepte un saut de ligne final, préférer `\z`) ; formulaire « nouvelle saison »
  pré-rempli avec deux dates identiques (invalide à l'ouverture) ; style en ligne sur le bouton « Rendre courante ».
- Task 3 — tests manquants : `CurrentSeasonProvider::get()`, doublon de libellé via le contrôleur, pagination,
  Responsable matériel refusé en édition.
- Task 4 — `findOrCreatePath` : pas de contrôle de longueur (150) après normalisation, un flush par niveau hors
  transaction (chemin partiel si échec, conflit d'unicité si imports concurrents) — à surveiller en Tasks 8/10.
- Task 4 — contrôleurs/formulaires/gabarits fournisseur, marque, catégorie quasi identiques ; entrées Tom Select
  inutiles (bootstrap4/5, .min.css) dans `importmap.php` ; liens Modifier/Supprimer à vérifier (cible tactile 44 px) ;
  habillage Tom Select non vérifié au navigateur (Task 12).
- Incident Task 4 — la base de dev a été remise à zéro par l'implementer (utilisateurs locaux perdus, à recréer via
  `app:user:create`). Les consignes suivantes interdisent de toucher aux données de dev.
- Task 5 — la marque d'un produit est facultative (spec muette) ; `Variant::setProduct()` ne met pas à jour
  `Product::$variants` en mémoire ; `VatCalculator` sans garde (taux −10000 → division par zéro), `vatRate` sans
  borne haute ; pas de test « une variante supprimée libère son EAN » (R10).
- Task 6 — montants affichés via `$cents / 100` (float, affichage seulement) ; autocomplétions fournisseur/marque/
  catégorie non insensibles aux accents ; filtres invalides ignorés sans message ; `?page[]=1` → 400.
- Task 6 — à vérifier : droit `CREATE EXTENSION unaccent` en production ; Turbo Frame et Tom Select au navigateur
  (Task 12).
- Task 7 — un tarif peut être enregistré sans aucun montant (la fiche affiche des tirets au lieu de « Tarif absent ») ;
  en édition, un POST sans mode de suivi retombe sur la valeur par défaut ; changement de fournisseur non testé ;
  taux de TVA non modifiable dans l'interface (20 %).
- Task 8 — catégorie avec niveau intermédiaire vide (sous-cat. 1 vide, sous-cat. 2 remplie) ramenée à 2 niveaux sans
  anomalie (à corriger : « jamais silencieux ») ; lecteur acceptant CSV/HTML/XML (restreindre à XLSX/XLS en Task 11) ;
  prix à très nombreux chiffres → débordement (500) ; en-têtes en double ignorés ; casse des couleurs non aliasées
  conservée ; migration Bauer ignorée en environnement de test.
- Task 9 (R22) — ajout des codes `VariantDuplicateInFile` et `ProductFieldsConflict` et d'une clé `ean` dans les
  changements de variante. À traiter en Task 10 : collisions à l'application (clé de correspondance d'une variante
  sœur, EAN d'une variante d'un produit supprimé) ; mémoire du worker sur un fichier complet.
- Revue finale — corrigés : catégorie à niveau intermédiaire vide (R30), session d'un utilisateur désactivé, valeurs
  trop longues (R31), annulation pendant l'analyse, compteur de variantes du tableau de bord, débordement de prix.
  Reportés (non bloquants) : tableau de bord affichant un import annulé comme « Dernier import » ; EAN-13 à zéro de tête
  saisi comme nombre dans Excel (12 chiffres) → ticket lot 2 ; 4 prix texte `48.964999999999996` (lignes 1844-1847)
  à corriger dans le fichier Bauer ; mémoire persistante du worker FrankenPHP à surveiller ; les autres mineurs ci-dessus.
