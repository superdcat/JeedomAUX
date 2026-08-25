# UC01 — Configuration du plugin et stockage sécurisé des identifiants

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : —

## Objectif

L'utilisateur doit pouvoir déclarer, depuis la page de configuration du plugin SmartClim, les identifiants
de son compte AUX Home (e-mail, mot de passe, pays) ainsi que la fréquence de rafraîchissement souhaitée,
sans exposer son mot de passe et sans avoir à comprendre les contraintes techniques de l'API cloud. C'est le
socle indispensable à toute connexion ultérieure (UC02) : sans configuration valide, aucune découverte ni
aucun pilotage n'est possible.

## Comportement attendu

Sur la page de configuration du plugin, l'utilisateur renseigne un e-mail, un mot de passe et un pays
associés à son compte AUX Home, ainsi qu'un intervalle de rafraîchissement des climatiseurs (en minutes).
Le pays se choisit dans une **liste déroulante** des pays pris en charge, positionnée par défaut sur
**France (FRA)**. Aucune déduction automatique n'est tentée (cf. « Décisions actées ») : le défaut est
constant, et l'utilisateur le change en un clic. Un compte dont le pays ne figure pas dans la liste reste
saisissable via l'entrée « Autre pays », qui ouvre un champ de code ISO à 3 lettres. Une fois le formulaire
enregistré, le mot de passe n'est plus jamais restitué en
clair : ni à l'écran après rechargement de la page, ni dans les journaux du plugin, quel que soit le niveau
de log.

L'intervalle de rafraîchissement a un plancher (1 minute) et une valeur par défaut (5 minutes) ; le
formulaire explique pourquoi une valeur plus agressive n'apporte pas de bénéfice : la donnée d'ambiance
remontée par AUX Home est intrinsèquement lente à se rafraîchir côté cloud, y compris dans l'application
officielle.

Cas dégradé : si le compte n'est pas encore configuré, aucune tentative de connexion n'est déclenchée
ailleurs dans le plugin (le test de connexion et le scan restent inertes, cf. UC02/UC03) — le plugin ne
doit jamais tenter d'appeler le cloud avec des identifiants vides.

## Critères d'acceptation

- [ ] **AC1** — Après avoir saisi e-mail, mot de passe, pays et intervalle puis enregistré, un rechargement
      de la page de configuration réaffiche l'e-mail et le pays saisis, mais jamais le mot de passe en
      clair (champ vide ou masqué).
- [ ] **AC2** — Le mot de passe enregistré n'apparaît en clair dans aucun journal du plugin (tous niveaux
      confondus), y compris juste après l'enregistrement du formulaire.
- [ ] **AC3** — À la toute première ouverture de la page de configuration (aucune configuration
      préexistante), le champ pays est une liste déroulante déjà positionnée sur **France (FRA)**, et
      l'utilisateur peut y choisir un autre pays, qui est bien celui réenregistré et réaffiché.
      *(Amendé en recette : cet AC exigeait auparavant un code déduit du fuseau horaire de Jeedom.)*
- [ ] **AC4** — Renseigner un intervalle de rafraîchissement inférieur à 1 minute puis enregistrer aboutit
      soit à un refus explicite, soit à une valeur ramenée à 1 minute — jamais à une valeur inférieure
      effectivement appliquée.
- [ ] **AC5** — Sans qu'aucun intervalle ne soit renseigné, la valeur appliquée après enregistrement est de
      5 minutes.
- [ ] **AC6** — Le formulaire affiche, à proximité du champ d'intervalle, un texte explicatif indiquant que
      la donnée d'ambiance AUX Home se rafraîchit lentement côté cloud et qu'un intervalle plus court n'en
      améliore pas la fraîcheur.
- [ ] **AC7** — Toutes les chaînes visibles du formulaire (libellés, aide, boutons) sont affichées en
      français.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Compte AUX Home », « Adresse e-mail », « Mot de passe »,
  « Pays », « Intervalle de rafraîchissement (minutes) », « La température ambiante remontée par AUX Home
  se rafraîchit lentement (jusqu'à environ 30 minutes) ; réduire cet intervalle n'accélère pas la donnée »,
  « Enregistrer ».
- S'y ajoutent, depuis l'amendement ci-dessous : « Sélectionnez un pays », « Autre pays (code ISO à
  3 lettres) », et **les libellés des pays proposés** (un par entrée de la liste, portés par
  `smartclimAuxHomeApi::paysDisponibles()`, donc traduits dans la section
  `core/class/smartclimAuxHomeApi.class.php` des fichiers `core/i18n/*.json`).

## Décisions actées

- **Pas de déduction du pays depuis le fuseau horaire** (arbitré en recette, 2026-08-25, contre la
  conception d'origine). Le fuseau horaire de Jeedom ne dit rien du pays du **compte cloud** : une
  installation française réglée sur `Europe/Brussels` se voyait proposer `BEL`, et un pays faux échoue au
  login sur un message trompeur (« identifiant ou mot de passe incorrect »). La table
  « fuseau → ISO-3 » (`smartclim-transport-aux-home.md` § 5) et son amorçage en base ont été retirés au
  profit d'un **défaut constant** `FRA` et d'une **liste déroulante**, qui rend la correction triviale.
- **Liste des pays limitée à l'Europe** : le transport AUX Home n'a qu'un point d'entrée régional
  (`eu-smthome-api.aux-global.com`), et proposer un code ISO plausible mais non confirmé produirait le
  même échec trompeur. Les comptes hors de cette couverture passent par l'entrée « Autre pays » et son
  champ de saisie libre.

## Hors périmètre

- Test de connexion effectif au compte configuré → UC02.
- Découverte et création des équipements → UC03.
- Configuration du cloud legacy AC Freedom/AUX Cloud → post-mvp/03.
- Choix de stratégie de transport (AUTO/LOCAL/CLOUD) → post-mvp/02.
