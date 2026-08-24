# UC02 — Documentation utilisateur et crédits de licences

> **Domaine** : post-mvp/07-multimarque-documentation-et-diffusion · **Statut** : à implémenter ·
> **Dépend de** : UC08 du MVP (le socle complet est livré et stable)

## Objectif

Un plugin qui repose entièrement sur du reverse engineering de protocoles tiers et sur la réutilisation de
code MIT/Apache-2.0 (`.memory/brief.md` § 17, `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 6) doit à la
fois **s'expliquer** à un utilisateur non développeur (installation, configuration, limites) et **honorer**
les obligations des licences des projets dont il s'inspire ou reprend du code. Cette UC livre la
documentation française qui rend le plugin utilisable de façon autonome, et la section « Crédits » qui
respecte ces obligations.

## Comportement attendu

- Une documentation française sous `docs/fr_FR/` couvre, dans un langage accessible à un utilisateur non
  développeur : l'installation du plugin, la configuration du compte (identifiants, choix du pays), le
  lancement d'un scan, l'explication des modes de transport (`AUTO`/`LOCAL`/`CLOUD`) et de ce
  qu'affiche le « transport actif » sur un équipement, ainsi que les limites connues.
- ⚠️ Une limite est mise en avant explicitement : la **température ambiante** remontée par le cloud AUX
  Home peut accuser plusieurs dizaines de minutes de retard sur la réalité. La documentation déconseille
  formellement de s'appuyer dessus comme sonde de régulation fine dans un scénario (chauffage d'appoint,
  asservissement fin) et propose une alternative (sonde Jeedom dédiée) quand c'est pertinent.
- Une section **« Crédits »** liste chaque projet tiers dont du code ou de la logique a été repris pour
  construire SmartClim, avec son nom, son auteur/organisation, sa licence et un lien vers le dépôt
  d'origine ; la notice de copyright et de licence des projets MIT/Apache-2.0 réutilisés est conservée,
  conformément à leurs conditions.
- La documentation indique explicitement que les protocoles exploités (AUX Home, cloud legacy, Broadlink
  LAN) proviennent de **reverse engineering communautaire**, sans documentation officielle du fabricant, et
  qu'ils peuvent **cesser de fonctionner sans préavis** après une mise à jour de firmware du climatiseur ou
  une évolution du cloud — un risque inhérent à ce type d'intégration, pas un défaut du plugin.
- Toute capture d'écran ou exemple de configuration présenté dans la documentation est anonymisé : aucun
  identifiant réel (adresse e-mail, mot de passe, MAC, device ID, jeton) n'y apparaît.

## Critères d'acceptation

- [ ] **AC1** — Le dossier `docs/fr_FR/` contient une documentation qui couvre, à sa seule lecture,
      l'installation, la configuration du compte, le choix du pays, le lancement d'un scan et l'explication
      des modes de transport.
- [ ] **AC2** — Une section dédiée liste les limites connues du plugin, en particulier le retard de la
      température ambiante remontée par AUX Home (ordre de grandeur en dizaines de minutes indiqué), avec
      une recommandation explicite de ne pas l'utiliser pour une régulation fine en scénario.
- [ ] **AC3** — Une section « Crédits » cite chaque projet tiers dont du code a été repris (au minimum les
      projets sous licence MIT et Apache-2.0 identifiés dans l'analyse écosystème), avec nom, licence et
      lien vers le dépôt d'origine.
- [ ] **AC4** — La documentation mentionne explicitement l'origine reverse engineering des protocoles et le
      risque de rupture après mise à jour de firmware, en des termes compréhensibles par un utilisateur non
      développeur.
- [ ] **AC5** — Aucune capture d'écran ni aucun exemple de configuration inclus dans la documentation ne
      contient un identifiant réel (e-mail, mot de passe lisible, MAC, device ID, jeton).
- [ ] **AC6** — Recette de lecture : une personne n'ayant pas participé au développement du plugin, en
      suivant uniquement cette documentation, parvient à configurer son compte, lancer un scan et comprendre
      ce qu'indique le « transport actif » affiché sur un équipement.

## Impact i18n

- Sans objet pour le mécanisme d'i18n du plugin (`{{...}}` / `__()`) : la documentation utilisateur est un
  contenu Markdown sous `docs/fr_FR/`, hors périmètre du scan d'extraction i18n et des fichiers
  `core/i18n/*.json`.

## À confirmer

- La compatibilité des licences MIT/Apache-2.0 des projets réutilisés avec l'AGPL-3.0 du plugin est
  documentée comme favorable dans l'analyse écosystème (§ 6) sous réserve de conserver les notices — à
  confirmer néanmoins par une vérification juridique si le plugin est effectivement publié sur le market.
- `GrKoR/esphome_aux_ac_component` (licence `NOASSERTION`, cf. analyse écosystème § 6) : si son contenu a
  servi à comprendre le protocole série AUX, sa citation en section Crédits est prudente mais son statut de
  licence réel reste **à vérifier** avant tout emprunt de code — ne pas trancher ici.
- Les dépôts `azadaydinli/ac_freedom` et `azadaydinli/homebridge-ac-freedom` sont sans fichier `LICENSE`
  (droits réservés par défaut) : aucun code n'en a été copié, seule leur valeur de référence factuelle a pu
  être utilisée. Une citation en section Crédits (à titre de source d'inspiration, sans notice de licence à
  reproduire puisqu'aucune licence ouverte ne s'applique) est une option éditoriale, pas une obligation
  légale — à trancher au moment de la rédaction.
- La valeur précise du retard de température ambiante (« plusieurs dizaines de minutes ») provient de
  l'analyse transport AUX Home ; une valeur plus précise, si observée en recette sur un compte réel, devrait
  remplacer cette formulation approximative.

## Hors périmètre

- La traduction de cette documentation utilisateur vers `en_US`/`de_DE`/`es_ES` n'est pas couverte ici (le
  périmètre imposé par `CLAUDE.md` pour `docs/<langue>/` est distinct du mécanisme d'i18n de l'interface) ;
  seule la conformité des liens de documentation déclarés dans le manifeste est vérifiée par l'UC03 de ce
  domaine.
- La vérification de complétude et de conformité des traductions de l'**interface** (`core/i18n/*.json`) et
  de la `description` du manifeste → UC03 de ce domaine.
- La documentation technique interne (`.memory/`) n'est pas concernée : elle reste un artefact de
  développement, pas une documentation utilisateur.
