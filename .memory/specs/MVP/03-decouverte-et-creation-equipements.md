# UC03 — Découverte des climatiseurs AUX Home et création des équipements

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC02

## Objectif

Permettre à l'utilisateur de peupler Jeedom avec un équipement par climatiseur détecté sur son compte AUX
Home, en un clic, sans création manuelle fastidieuse. Relancer cette découverte doit rester une opération
sûre et répétable, qui ne duplique jamais les équipements existants ni n'écrase les personnalisations déjà
faites par l'utilisateur (nom, emplacement, réglages).

## Comportement attendu

Un bouton « Scanner les climatiseurs » interroge le compte AUX Home configuré et liste les appareils
trouvés. Pour chaque appareil listé, un équipement Jeedom est créé s'il n'existe pas encore, ou mis à jour
s'il existe déjà (même appareil reconnu par sa MAC). La création se fait sans intervention supplémentaire de
l'utilisateur : nom, modèle et identifiant sont repris depuis le cloud à la création uniquement.

Relancer un scan alors que rien n'a changé côté compte ne doit produire aucun effet visible : ni doublon, ni
recréation, ni perte d'un nom ou d'un emplacement personnalisé par l'utilisateur entre-temps. Si un appareil
précédemment découvert disparaît du compte (désinscrit, supprimé côté AUX Home), le scan suivant le signale
clairement à l'utilisateur plutôt que de supprimer silencieusement l'équipement Jeedom correspondant — la
suppression reste une décision de l'utilisateur.

Cas dégradé : un compte sans aucun climatiseur associé affiche un message clair de résultat vide, sans
erreur brute. Un scan lancé alors que le compte n'est pas configuré ou que le test de connexion échoue
(UC02) n'aboutit pas à un scan silencieux : l'échec est signalé de la même façon qu'un échec de connexion.

## Critères d'acceptation

- [ ] **AC1** — Sur un compte comportant au moins un climatiseur, cliquer sur « Scanner les climatiseurs »
      fait apparaître un équipement Jeedom par climatiseur du compte dans la liste des équipements du
      plugin.
- [ ] **AC2** — L'écran de résultat du scan affiche, pour chaque appareil trouvé : son nom, son modèle, sa
      MAC, son identifiant cloud et son état en ligne/hors ligne — sans jamais afficher de jeton ni
      d'identifiant de compte (e-mail, mot de passe).
- [ ] **AC3** — Renommer un équipement créé par un scan, puis relancer un second scan sans changement côté
      compte : le nom personnalisé n'est pas réécrasé par le nom d'origine renvoyé par le cloud.
- [ ] **AC4** — Déplacer un équipement créé par un scan vers un autre objet (pièce), puis relancer un
      scan : l'objet parent choisi par l'utilisateur reste inchangé.
- [ ] **AC5** — Relancer un second scan identique au premier (aucun changement côté compte) ne crée aucun
      équipement supplémentaire — le nombre total d'équipements smartclim reste identique avant et après.
- [ ] **AC6** — Si un appareil précédemment découvert n'est plus renvoyé par le compte au scan suivant, un
      message signale clairement cet appareil comme introuvable/disparu, sans que son équipement Jeedom ni
      ses commandes ne soient supprimés automatiquement.
- [ ] **AC7** — Un scan sur un compte sans aucun climatiseur affiche un message clair (« aucun climatiseur
      trouvé »), jamais une erreur technique brute.
- [ ] **AC8** — Tout le texte du résultat de scan (statuts, messages, libellés) est affiché en français.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Scanner les climatiseurs », « Climatiseurs trouvés »,
  « En ligne », « Hors ligne », « Introuvable au dernier scan », « Aucun climatiseur trouvé sur ce
  compte ».

## À confirmer

- Comportement attendu si l'identifiant cloud (`deviceId`) d'un appareil déjà connu change alors que sa MAC
  reste identique (ex. ré-appairage côté AUX Home) : à valider en recette — le rapprochement par MAC doit
  rester prioritaire (`smartclim-architecture-jeedom.md` § 4).
- Le rapprochement d'équipement doit rester robuste à un éventuel ordre d'octets inversé de la MAC (piège
  documenté sur les implémentations Broadlink, cf. `smartclim-architecture-jeedom.md` § 4) : la vérification
  complète de ce cas nécessite un second transport (LAN/legacy, post-MVP) pour être observée en recette ;
  au MVP, seule la non-duplication sur AUX Home seul (AC5) est vérifiable.

## Hors périmètre

- Fusion des doublons avec la découverte LAN Broadlink ou cloud legacy → post-mvp/01 et post-mvp/03.
- Détection détaillée des capacités de chaque appareil (modes, vitesses, plage de température) → UC04.
- Suppression d'un équipement disparu → action manuelle de l'utilisateur via les fonctions natives de
  Jeedom, non automatisée par le plugin.
