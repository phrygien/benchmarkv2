# Positionnement prix : guide de lecture des statistiques

Ce document explique comment sont calculés et comment lire les indicateurs de la page **Positionnement prix** et de l'API `GET /api/positionement`.

Les chiffres cités en exemple viennent de la page du 12/10/2026 (onglet France, référence « prix médian », tolérance 0,5 %).

---

## 1. Périmètre et principe de calcul

**Périmètre.** L'analyse porte uniquement sur les produits **matchés par EAN** avec au moins un concurrent (`only_matched=1` côté API comparateur).

**Ce qui est comparé.** Notre prix au `prix_ht` du concurrent. Il n'y a **aucune vérification de devise** (tout est en euros) ni de **variation / contenance**.

**Formule.**

```
écart % = notre prix ÷ prix du marché − 1
```

| Écart | Couleur | Signification |
|---|---|---|
| > 0 | rouge | nous vendons plus cher que le marché |
| < 0 | vert | nous vendons moins cher que le marché |

**Prix du marché** (au choix) :

| Référence | Calcul |
|---|---|
| `median` (défaut) | médiane des prix concurrents du produit |
| `avg` | moyenne des prix concurrents |
| `best` / `min` | prix concurrent le plus bas |

**Notre prix** (au choix) :

| Option | Valeur utilisée |
|---|---|
| `effective` (défaut) | prix pratiqué : promo si elle existe (et si elle est inférieure au prix catalogue), sinon prix catalogue |
| `regular` | prix catalogue, hors promo |

**Position d'un produit.**

| Position | Condition |
|---|---|
| Même prix | \|écart\| ≤ tolérance |
| Plus cher | écart > tolérance |
| Moins cher | écart < −tolérance |

---

## 2. Fiabilisation des données

| Mécanisme | Effet |
|---|---|
| **1 relevé par site et par produit** | si un site a plusieurs relevés pour un même produit, on garde le plus récent, puis le moins cher |
| **Fraîcheur des relevés** | option : ignore les relevés plus vieux que N jours |
| **Concurrents minimum** | option : ne retient que les produits avec au moins N concurrents |
| **Relevés aberrants** | option : pour un produit avec **≥ 3 concurrents**, un relevé hors de `[médiane × 0,4 ; médiane × 2,5]` est ignoré |
| **Produits « à vérifier »** | si \|écart\| > seuil (50 % par défaut), le produit est marqué suspect et, par défaut, **exclu des statistiques** (il reste visible via le filtre « À vérifier ») |

---

## 3. La ligne de qualité des données

> *8 276 produits matchés exploitables sur 8 356 · 0 doublon(s) · 5 relevé(s) aberrant(s) ignoré(s) · 117 produit(s) « à vérifier »*

| Élément | Sens |
|---|---|
| **8 356 matchés reçus** | produits renvoyés par l'API comparateur |
| **8 276 exploitables** | 80 produits écartés à la collecte (pas de prix chez nous, ou aucun relevé concurrent valide) |
| **Doublons** | relevés en double pour un même site et un même produit |
| **Relevés aberrants** | prix concurrents ignorés par la règle médiane × 0,4 / × 2,5 |
| **Produits à vérifier** | produits dont l'écart dépasse le seuil, exclus des stats |
| **7 106 comparés** (en-tête) | produits exploitables ayant au moins un concurrent **dans le pays choisi**, hors suspects |

---

## 4. Le bloc principal

> *Nous vendons 5,5 % moins cher · écart moyen −5,9 % · sur 7 106 produits*

- **Écart médian (titre)** : la valeur du milieu des écarts. Elle est peu sensible aux erreurs de données.
- **Écart moyen** : tiré par les valeurs extrêmes. Un petit écart entre les deux indique une distribution assez équilibrée.

---

## 5. Les quatre cartes

| Carte | Exemple | Définition |
|---|---|---|
| Nous vendons moins cher | 69,6 % (4 946) | écart < −tolérance |
| Même prix | 2,4 % (171) | \|écart\| ≤ tolérance |
| Nous vendons plus cher | 28,0 % (1 989) | écart > +tolérance |
| Nous sommes le moins cher | 56,4 % (4 009) | notre prix ≤ prix minimum du marché (à 0,005 € près) |

Les trois premières cartes totalisent 100 % des produits comparés. La quatrième est **plus stricte** que « moins cher que la médiane » : elle exige d'être sous **tous** les concurrents.

---

## 6. Répartition des écarts (histogramme)

Nombre de produits par tranche d'écart :

| Tranche | Produits |
|---|---|
| < −20 % | 917 |
| −20 à −10 % | 1 443 |
| −10 à −5 % | 1 491 |
| −5 à −1 % | 927 |
| −1 à +1 % | 438 |
| +1 à +5 % | 444 |
| +5 à +10 % | 463 |
| +10 à +20 % | 791 |
| > +20 % | 192 |

> La tranche « −1 à +1 % » (438) est plus large que « Même prix » (± 0,5 %, soit 171 produits), d'où la différence entre les deux chiffres.

---

## 7. Tableau produit par produit

| Colonne | Contenu |
|---|---|
| **Produit** | titre, marque, SKU, EAN |
| **Notre prix** | prix utilisé pour la comparaison (badge *Promo* si une promo s'applique) |
| **Prix du marché** | prix de référence et nombre de sites concurrents |
| **Écart** | en % et en euros, avec le badge *À vérifier* si le seuil est dépassé |
| **Meilleur concurrent** | site le moins cher, son prix, et notre écart par rapport à lui |
| **Fourchette** | prix concurrent minimum → maximum |

Tris disponibles : les plus chers d'abord, les moins chers d'abord, plus gros écart (±), les plus loin du meilleur prix. Export CSV de la liste filtrée.

---

## 8. Tableaux « Par marque » et « Par type »

| Colonne | Contenu |
|---|---|
| Produits | nombre de produits comparés |
| Répartition | barre verte (moins chers) / grise (même prix) / rouge (plus chers) |
| Moins chers / Plus chers | part des produits dans chaque position |
| Écart médian | positionnement typique du groupe (la moyenne apparaît en infobulle) |

Exemples de lecture :

- **Points forts** : Dior (922 produits, 97,7 % moins chers, −7,0 %), Guerlain (95,2 %, −6,3 %).
- **Points faibles** : Pupa (507 produits, 91,9 % plus chers, +10,0 %), Jean Paul Gaultier (+13,2 %), Kenzo (+9,5 %).

---

## 9. Tableau « Concurrents »

| Colonne | Contenu |
|---|---|
| Produits comparés | produits pour lesquels ce site a un relevé |
| Le moins cher | produits où ce site a le meilleur prix (ex æquo inclus) |
| Moins cher que nous | produits où ce site est **strictement** sous notre prix |
| Écart médian vs nous | de combien nous sommes plus chers (+) ou moins chers (−) que ce site |

Exemples de lecture :

- **Parfumdreams** (1 709 produits) est le plus offensif : moins cher que nous sur 68,5 % de ses produits, nous sommes à +5,0 % par rapport à lui.
- **Kalista, Parfumdo, My origines** couvrent le plus de produits et sont plus chers que nous (écart médian entre −5 % et −6 %).
- **Notino** (31 produits) et **Primor** (3) couvrent trop peu de produits pour en tirer des conclusions.

---

## 10. Points de vigilance

1. **Un seul concurrent.** Avec un seul site, la « médiane » est simplement son prix. Pour des chiffres plus solides, utiliser « Concurrents minimum » = 2 et comparer.
2. **Bloc de produits en promo autour de +48 %.** Plusieurs produits en tête de liste ont un ratio presque identique (par exemple 52,95 / 35,55 ≈ 1,49 et 150,24 / 102,00 ≈ 1,47). Un ratio aussi constant ressemble à une cause systématique (base de prix ou promo différente) plutôt qu'à un vrai positionnement. À vérifier à la main avant de baisser des prix.
3. **Marques en double à cause de la casse.** Pupa / PUPA / « Pupa -Pupa », Mugler / MUGLER, Dior / DIOR, Biotherm / BIOTHERM, Dolce & Gabbana / « Dolce &amp; Gabbana » apparaissent séparément. Il faut normaliser le nom de marque (décodage HTML, regroupement insensible à la casse) pour des totaux corrects.
4. **Prix HT contre notre prix.** Le prix concurrent est en HT : vérifier que notre `price` est comparable (HT également), sinon tous les écarts sont décalés.
5. **Les données viennent du scraping.** Elles peuvent contenir des erreurs (mauvais EAN, relevé ancien). Vérifier les écarts importants, surtout au-delà de ± 50 %, avant de modifier un prix.

---

## 11. Équivalences page ↔ API

| Page | API `GET /api/positionement` |
|---|---|
| Référence marché | `reference` (`median`, `avg`, `min`) |
| Notre prix | `our_price` (`effective`, `regular`) |
| Fraîcheur des relevés | `max_age` |
| Concurrents minimum | `min_competitors` |
| Même prix si écart ≤ | `tolerance` (défaut API : 2, défaut page : 0,5) |
| « À vérifier » si écart > | `suspect_pct` |
| Écarter les relevés aberrants | `clean_outliers` |
| Exclure les produits « à vérifier » | `exclude_suspects` |
| Onglet pays | `country` |
| Marque / recherche | `marque`, `search` |

Pour obtenir les mêmes chiffres que la page, passer `tolerance=0.5` à l'API.
