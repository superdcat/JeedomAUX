# Spec technique — UC03 du domaine post-mvp/07 « Icône du plugin »

> Spec fonctionnelle : `03-icone-du-plugin.md` (révisée le 2026-09-13) · **Statut** : implémentée ·
> **Recette** : AC4 et AC6 **pré-validés hors IHM** (sur rendu réduit produit localement, cf. § 5.2) ;
> **AC5 réellement en attente** — il exige un déploiement, personne ne peut le cocher depuis le dépôt.

## 0. Nature de cette UC — à lire avant le reste

Cette UC ne livre **aucun code PHP, aucune chaîne UI, aucune dépendance de plugin**. Son livrable est un
**asset binaire** (`plugin_info/smartclim_icon.png`) plus la recette reproductible qui le produit. Tout ce
qui suit est calibré là-dessus : il n'y a ni « Server vs Client », ni action AJAX, ni validation d'entrée,
parce qu'il n'y a aucun chemin d'exécution.

⚠️ **Le plugin ne génère jamais d'image à l'exécution** et ne dépend pas de Pillow. `generer-icone.py` est
un outil de la machine de développement, lancé à la main. `packages.json` n'est pas touché.

## 1. Provenance et licence du visuel — porte l'AC7

| | |
|---|---|
| **Source** | `.memory/assets/logo-aux-Blk--YM1.png` (2000 × 768 RGBA), déposé par l'utilisateur du dépôt |
| **Nature** | Logo de la marque commerciale **AUX** (AUX Air Conditioner). Marque tierce, **non libre de droits**, reproduite sans autorisation du titulaire |
| **Outil** | `.claude/scripts/generer-icone.py` (Pillow, machine de dev) — recadrage, mise à l'échelle, encrage ; **aucun élément graphique tiers ajouté** (pas de banque d'icônes, pas de police, pas de modèle génératif) |
| **Arbitrage** | Décision explicite de l'utilisateur du **2026-09-13**, consignée dans l'objectif de la spec fonctionnelle. Motif : installation Jeedom **locale et privée**, aucune diffusion publique |

⚠️ **Deux limites, à ne pas laisser se perdre** :

1. **L'arbitrage ne couvre pas une publication publique.** L'UC04 de ce domaine doit remplacer l'icône par
   un visuel **neutre** avant toute soumission au market. Le script reste utile pour cela : plaque, rayon,
   canevas et contrôles de `verifier()` sont indépendants du sujet — **seule `charger_monogramme()` est à
   refaire**. Ce n'est pas un changement de constante, c'est un nouveau dessin : ne pas le sous-estimer en
   planifiant UC04.
2. **L'AC8 ne dépublie rien.** `logo-aux-Blk--YM1.png` avait été commité à la racine (commit `1c29736`)
   **avant** cette UC, sur un dépôt GitHub **public**. Le `git mv` le soustrait au téléchargement anonyme
   depuis une installation Jeedom — c'est le motif énoncé par l'AC — mais un `.htaccess` protège une
   installation, **jamais un dépôt distant** (même leçon que les rapports de sonde,
   cf. `smartclimDiagnostic`). Seule une réécriture d'historique avec force-push le retirerait : écartée,
   destructive, et sans effet sur un arbitrage qui assume déjà l'usage privé du visuel de marque. **Fait
   consigné, non rouvert.**

Cette section tient lieu de porteur de l'AC7 **tant que la section « Crédits » de l'UC02 de ce domaine
n'existe pas**. Quand UC02 sera implémentée, elle devra y renvoyer ou la reprendre.

## 2. Contrat externe — le format d'icône Jeedom

Sources : `https://doc.jeedom.com/fr_FR/dev/Icone_de_plugin` et le code du core. Ce qui est **mesuré** est
signalé comme tel ; le reste est cité.

- **Format imposé** : PNG **309 × 348**, nommé `<plugin-id>_icon.png`, dans `plugin_info/`. « *Ce fichier
  est obligatoire.* »
- **Pas de nom sous l'image**, « *attention à garder quand même les mêmes tailles du modèle !* »
  (directive 2020). Caractéristiques attendues d'après le modèle : « *bords arrondi, taille, fond coloré,
  transparence autour etc..* »
- ⚠️ **Ce que « garder les mêmes tailles du modèle » veut dire, mesuré et non déduit** : le
  `template_icon.png` fourni par cette page a une **bbox alpha de `(0, 0, 309, 309)`**. Le dessin occupe
  le **carré supérieur 309 × 309** ; les **39 px du bas sont intégralement transparents** — c'est la place
  qu'occupait le nom avant la directive de 2020. Notre icône reproduit cette bbox **à l'identique**, et
  `verifier()` en fait une égalité stricte.
- **Où le fichier est consommé** : `plugin::getPathImgIcon()` (`core/class/plugin.class.php` l. 1068-1082)
  construit le chemin **en dur** depuis l'`id` du plugin — quatre `file_exists` en cascade
  (`plugin_info/<id>_icon.png`, `doc/images/<id>_icon.png`, puis les deux en minuscules), et à défaut
  `core/img/no-image-plugin.png`. ⚠️ **`info.json` ne porte aucune clé d'icône** et n'est donc pas à
  modifier (hors le bump automatique de `pluginVersion`).
- **Seconde déclinaison « market » : tranchée — aucune n'est établie.** La page `Icone_de_plugin` ne
  mentionne qu'un fichier ; la page `publication_plugin` n'en mentionne aucun ; `getPathImgIcon()` n'en
  cherche qu'un. Le point « À confirmer » de la spec fonctionnelle se ferme ici. **Résidu non vérifiable
  depuis la doc publique** : la façon dont le site du market recadre l'image dans son catalogue, et
  l'éventuelle demande d'une capture d'écran au moment de la soumission (formulaire non consultable sans
  compte développeur). Ce résidu appartient à **UC04**.
- **`plugin_info/.htaccess` : rien à toucher.** Son bloc `<Files ~ "\.(jpg|jpeg|png|gif|pdf|bmp)$">` avec
  `allow from all` couvre déjà `png` — c'est précisément ce qui rend l'icône servable malgré le
  `Deny from all` du dossier. Remplacer un `.png` par un autre ne change aucune règle. ⚠️ Ne **jamais** y
  ajouter d'extension (rappel `CLAUDE.md` : `txt` en a été retiré pour ne pas publier
  `configuration.txt`).

## 3. Architecture — fichiers

| Chemin | Action | Contenu |
|---|---|---|
| `plugin_info/smartclim_icon.png` | **remplacé** | Le PNG produit. 309 × 348 RGBA. Remplace la copie de l'icône du template |
| `.claude/scripts/generer-icone.py` | **créé** | La recette, en constantes nommées + contrôles du source + `verifier()`. En-tête portant la provenance (§ 1). 4 espaces, LF-pur, comme ses trois voisins |
| `.memory/assets/logo-aux-Blk--YM1.png` | **créé par `git mv`** | Le fichier source, inchangé, déplacé depuis la racine |
| `.claude/scripts/verif-plugin.py` | **modifié** | Une garde « fichier binaire » (§ 6) |
| `plugin_info/info.json` | **modifié par le hook** | `pluginVersion` incrémentée automatiquement — cf. § 7 |

**Emplacement du script : `.claude/scripts/`, et pas ailleurs.** Trois motifs, aucun esthétique.
(a) C'est là que vit déjà tout l'outillage du projet, sous `Deny from all`. (b) Le dossier est **hors du
périmètre de bump** (`bump-version.py` : `PERIMETRE = ('core/', 'desktop/', 'plugin_info/',
'resources/')`) — retoucher le script ne consomme pas de version. (c) ⚠️ **Le mettre sous `core/`,
`desktop/` ou `resources/` le livrerait sur l'installation Jeedom** et suggérerait une dépendance runtime
à Pillow qui n'existe pas.

**Emplacement du source : `.memory/assets/`.** `.memory/.htaccess` porte un `Deny from all` qui s'applique
**récursivement aux sous-dossiers** : l'AC8 est satisfait dans son motif énoncé. La suppression pure a été
écartée parce qu'elle **ne gagne rien** sur l'exposition publique (§ 1, limite 2) tout en coûtant la
reproductibilité de la recette. ⚠️ C'est un **nouveau dossier de premier niveau** sous `.memory/`, dont la
convention documentée jusqu'ici ne portait que de la connaissance textuelle (`specs/`, `analyse/`,
`external/doc/`) : `CLAUDE.md` est mis à jour en conséquence, sans quoi une session future y trouverait un
dossier sans savoir pourquoi il existe.

## 4. Recette de transformation — paramètres

```
SOURCE        .memory/assets/logo-aux-Blk--YM1.png   (2000 x 768, RGBA)
CIBLE         plugin_info/smartclim_icon.png
CANEVAS       309 x 348          # imposé par la doc
COTE          309, plaque posée en (0, 0)
BANDE BASSE   39 px, alpha 0     # « mêmes tailles du modèle »
RAYON         64 px, dessiné en supersampling x4
MARGE_H       14 px de chaque côté
FOND          #163F6E  (bleu exact du logo AUX)
ENCRE         #FFFFFF
```

**Étape 1 — recadrage, et exclusion du texte par construction.** Le source porte **deux bandes d'encre** :
le monogramme (lignes 34→496) et « AIR CONDITIONER » (lignes 588→736), séparées par un vide. Le script
coupe à la ligne **540**, au milieu de ce vide donc à distance des deux, puis prend le `getbbox()` du canal
alpha → exactement `(109, 34, 1911, 497)`, soit **1802 × 463**, rapport **3,892**.
⚠️ **Les mesures du source sont contrôlées, pas documentées** : taille et bbox. Si le fichier source est
un jour remplacé, on veut un échec bruyant — sans ce contrôle, un autre fichier produirait **en silence**
une icône portant la ligne de texte, et AC3/AC6 tomberaient sans que rien ne le signale.
⚠️ **Contrôle par `raise`, jamais par `assert`** : `python -O` supprime les `assert` sans un mot, et ce
garde-fou est le seul qui tienne AC3 et AC6 — il ne doit pas dépendre d'un drapeau d'interpréteur.

**Étape 2 — masque.** Le PNG source est **déjà RGBA à fond transparent** : son canal alpha **est** le
masque du dessin, avec son antialiasing d'origine. Il n'y a donc ni détourage à fabriquer, ni seuillage, ni
conversion de luminance — et aucun des artefacts de bord que ces deux techniques produisent.
*Repli documenté, non emprunté* : si le source devenait opaque sur blanc, la conversion serait
`alpha = 255 - luminance`, **jamais** un seuillage, qui détruit l'antialiasing et rend des contours
crénelés dès 75 px. Noté pour que ce choix ne soit pas refait à l'envers.

**Étape 3 — plaque.** `rounded_rectangle` sur un masque `L` de 1236 × 1236 (309 × 4), rayon 256 (64 × 4),
puis réduction LANCZOS. ⚠️ **Le supersampling n'est pas un raffinement** : `rounded_rectangle` en taille
native rend un coin crénelé. Le facteur 4 donne un profil de coin ajusté à 60,7 px, contre 61,6 px mesurés
sur le modèle officiel — écart ≤ 1 px point à point.

**Étape 4 — mise à l'échelle et placement.** Largeur utile `309 − 2×14 = 281`, hauteur `round(281 / 3,892)
= 72`, centrage vertical en `(309 − 72) // 2 = 118`.
⚠️ **C'est le MASQUE seul qui est redimensionné, jamais l'image RGBA du source** : ses pixels transparents
ont un RGB noir, et composer l'image entière poserait un liseré sombre sur tous les contours.
**Occupation du cadre** : le monogramme fait 91 % de la largeur et ~23 % de la hauteur de la plaque. Le
vide vertical restant est du **fond de marque**, pas un défaut de cadrage. Les trois alternatives sont
chacune pire — étirer viole la non-déformation, empiler A/U/X sur trois lignes **recompose** la marque au
lieu de reprendre le monogramme du fichier source (AC6), élargir au-delà de 309 px rogne la marque.
Avantage collatéral : le sujet tient entièrement dans la bande de **pleine largeur** de la plaque (lignes
118→190, alors que la courbure n'agit qu'en `y < 64` et `y > 244`), donc la marge de 14 px est la distance
réelle au bord sur toute la hauteur du monogramme.

**Étape 5 — écriture.** Canevas RGBA transparent, plaque collée en (0,0), `save(optimize=True)`.
⚠️ **Aucun `dpi` posé** — le modèle officiel n'en a pas, aucun consommateur ne le lit, et l'omettre rend la
sortie **déterministe** : deux exécutions rendent la même empreinte (vérifié, `6162e96e…`).

### 4.1 Arbitrage chromatique — `#163F6E`, le navy exact de la marque

Point « À confirmer » de la spec fonctionnelle, tranché **par l'utilisateur le 2026-09-13** sur planche de
comparaison rendue aux quatre tailles réelles (309 / 75 / 36 / 18 px) et sur les deux fonds de thème.

Fond coloré + monogramme blanc, et non l'inverse : la doc exige littéralement un « fond coloré », et une
plaque claire `#F5F6F8` contre le fond du thème clair `#F0F1F2` donne **1,05:1** — l'icône disparaîtrait
purement et simplement, ne laissant flotter que les lettres.

⚠️ **Le navy de marque est retenu MALGRÉ un contraste de plaque faible sur le thème sombre, et c'est un
choix, pas un oubli.** Mesures : **9,42:1** contre le thème clair `#F0F1F2`, **1,51:1** contre le thème
sombre `#212121` — or le thème **alterne automatiquement** à 08:00/20:00 dans la configuration livrée
(`theme_changeAccordingTime = 1`). Le motif de le retenir quand même : **c'est le monogramme blanc qui
identifie le plugin, à 10,66:1 sur la plaque quel que soit le fond de page**. Relever la valeur du bleu
pour gagner du contraste de plaque éloignerait visiblement de la marque — ce que l'UC cherche précisément
à restituer.
⚠️ **Ne pas invoquer WCAG 1.4.11 (3:1) pour rouvrir ce point** : ce critère comporte une **exemption
explicite pour les logotypes**, et le justifier par une conformité normative serait un habillage faux. Le
motif réel est la lisibilité pratique, et il a été arbitré à l'œil.
**Variantes écartées, conservées pour ne pas les re-dériver** : `#1E5494` (6,75 / 2,11) et `#266CBC`
(4,70 / 3,03), obtenues en conservant teinte (212°) et saturation (0,800) du navy et en relevant la seule
valeur.

**Contexte utile** : les icônes des plugins **officiels** échantillonnées (`mode`, `script`, `virtual`,
`openzwave`, `mobile`) sont toutes au vert `#95C12B` — et l'icône héritée du template **était exactement
cette couleur**. Le dépôt était donc en infraction avec la recommandation de la doc (« *ne pas utiliser le
même code couleur que les icônes des plugins officiels* ») ; l'UC la corrige au passage, et `verifier()` en
fait un contrôle. ⚠️ **L'exhaustivité n'est pas prétendue** : la doc ne publie pas la liste des plugins
officiels. Ce qui est affirmé est « teinte 212°, hors de la famille verte des officiels échantillonnés ».

## 5. Validation

### 5.1 Automatisé — `generer-icone.py --verifier`, tous verts

| Contrôle | Attendu | AC |
|---|---|---|
| `im.size`, mode | `(309, 348)`, `RGBA` | AC1 |
| `alpha.getbbox()` | `(0, 0, 309, 309)` — **égalité stricte avec le modèle officiel** | AC2, AC3 |
| alpha des 4 coins de la plaque | `0` | AC2 |
| alpha max des lignes 309→347 | `0` | AC2, AC3 |
| pixel central | `(22, 63, 110, 255)` | AC2 |
| pixels au vert `#95C12B` | `0` | AC5 (condition nécessaire) |
| bbox de recadrage du source | `(109, 34, 1911, 497)` — prouve l'exclusion de la bande 588→736 | AC3, AC6 |
| empreinte sur deux exécutions | identique | reproductibilité |

### 5.2 Jugement humain — en recette, non cochable autrement

- **AC4, lisibilité en vignette.** ⚠️ **Le libellé dit « le sujet ne se brouille pas en un amas de détails
  illisibles » — pas « les lettres restent lisibles ».** La distinction est décisive : à **18 px**, taille
  du rendu **par défaut** (`theme_displayAsTable = 1`, `max-width: 18px` dans `desktop.main.css`), aucune
  icône du dépôt ni du core n'est lisible *comme texte*. Vérifié sur rendu réduit réel : le sujet reste une
  **tuile colorée nette portant une marque blanche horizontale**, c'est-à-dire une forme cohérente et non
  un amas — AC4 est tenu au sens de son libellé. En mode carte (75 px), « AUX » se lit sans effort.
  ⚠️ **Ce constat porte sur un rendu produit localement, pas sur l'IHM Jeedom**, que le libellé de l'AC
  invoque (« telle qu'elle apparaît dans la liste des plugins »). Le redimensionnement du navigateur n'est
  pas celui de Pillow : tenir pour **pré-validé**, à reconfirmer d'un coup d'œil pendant la recette d'AC5,
  qui de toute façon amène sur la bonne page.
- **AC6, reconnaissabilité du monogramme.** Sa première moitié — « la mention *AIR CONDITIONER* n'y figure
  pas » — est automatisée (§ 5.1, bbox de recadrage). ⚠️ Sa **seconde** moitié, « le monogramme AUX est
  reconnaissable en vignette », est de même nature qu'AC4 et relève du **même** coup d'œil : le contrôle
  automatique ne prouve que l'exclusion du texte, jamais la reconnaissabilité. Ne pas la cocher sur la
  foi du § 5.1.
- **AC5, affichage dans l'IHM.** Procédure : commit → push → mise à jour du plugin depuis le Market GitHub
  (⚠️ **c'est le bump de `pluginVersion` qui rend la mise à jour proposable** — sans lui le fichier
  n'atteint jamais l'installation) → page « Plugins » → **basculer la liste en mode carte** via le bouton
  à droite du champ de recherche, sans quoi l'icône reste à 18 px et la comparaison ne vaut rien.
- **Contrôle chromatique sur place** : regarder l'icône **aux deux thèmes**, le basculement étant
  automatique.

## 6. Modification de `verif-plugin.py` — garde binaire

`fichiers_git()` ne filtre **pas** par extension (contrairement à `fichiers_tout()`), et la boucle par
fichier de `main()` appelle `check_fins_de_ligne` / `check_octets_controle`, qui n'ont pas de garde. Cette
UC est **le premier commit du dépôt qui modifie un binaire** : constaté avant correctif, le script sortait
en **PROBLEME** — « fins de ligne MIXTES (520 CR pour 494 LF) » et « 19948 octets de contrôle BRUTS ».

Correctif : constante `EXT_BINAIRE` et court-circuit en tête de boucle, **avec impression d'une ligne de
verdict `binaire`** — jamais un saut muet, un fichier absent du rapport se lirait comme un fichier oublié.

⚠️ **La détection est fondée sur l'EXTENSION, liste fermée, et jamais sur le contenu.** Sauter un fichier
parce qu'il contient un octet nul ferait taire exactement le cas que `check_octets_controle` existe pour
attraper : un texte accidentellement enregistré en **UTF-16**, où un `0x00` sépare chaque caractère ASCII.
Une garde par contenu aurait transformé un contrôle en angle mort.

## 7. Dépendances et effets de bord

- **Aucune dépendance de plugin.** `packages.json` n'est pas touché : Pillow est un outil de la machine de
  dev. ⚠️ Rappel `CLAUDE.md` — chaque paquet ajouté re-déclenche un cycle d'installation sur tout le parc.
- **Bump de `pluginVersion` : souhaité et nécessaire.** Le commit touche `plugin_info/`, donc le hook
  `pre-commit` incrémente. Sans bump, le Market GitHub ne propose pas la mise à jour et **AC5 serait
  invérifiable**. ⚠️ Ne pas éditer `info.json` à la main dans ce commit : le hook s'abstient si le fichier
  porte des modifications non indexées.
- **i18n : sans objet, et vérifié.** Aucune chaîne introduite — une icône est un binaire, hors `{{…}}` et
  `__()` ; le nom affiché sous l'icône vient de `$plugin->getName()`, donc d'`info.json`, non touché.
  `verif-plugin.py` rapporte **337 clés UI sur les trois langues cibles avant comme après**. Aucune
  intervention du sous-agent `translator` n'est requise.
- **Non touché, et à ne pas « sécuriser » au passage** : `plugin_info/.htaccess` (§ 2), `info.json` hors
  version, les widgets `core/template/` (icônes de commandes = domaine post-mvp/06), `docs/`.

## 8. Dette

- **D-07UC03-01 — Le logo de marque reste dans l'historique GitHub public.** § 1, limite 2. Non traité,
  arbitré. Rouvrir seulement si le dépôt change de statut de diffusion.
- **D-07UC03-02 — L'exhaustivité de la contrainte « pas la couleur des plugins officiels » n'est pas
  vérifiable.** § 4.1. Cinq icônes officielles échantillonnées, toutes vertes ; la doc ne publie pas la
  liste. Le risque résiduel est une collision avec un plugin officiel bleu non échantillonné — conséquence
  bornée à une confusion visuelle.
- **D-07UC03-03 — UC04 devra refaire `charger_monogramme()`.** § 1, limite 1. À reporter dans la spec
  d'UC04 au moment de l'écrire, pour qu'elle ne reparte pas de zéro et n'oublie pas la contrainte.
