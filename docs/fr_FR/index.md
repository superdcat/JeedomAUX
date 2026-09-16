# SmartClim — piloter un climatiseur AUX / Broadlink / AC Freedom depuis Jeedom

> Documentation à jour de la version 0.45 du plugin.

SmartClim permet de piloter depuis [Jeedom](https://jeedom.com) un climatiseur Wi-Fi de l'écosystème AUX
/ Broadlink / AC Freedom : marche/arrêt, mode, température de consigne, vitesse de ventilation, ainsi que
la lecture de la température ambiante et de l'état de l'appareil. Cette page couvre l'installation, la
configuration d'un compte, la découverte de vos climatiseurs, les modes de transport et l'usage courant du
plugin.

## 1. À lire avant d'installer

### 1.1 Ce que fait ce plugin

SmartClim crée dans Jeedom un équipement par climatiseur découvert, avec les commandes correspondant aux
fonctions réellement détectées sur votre appareil (pas de liste figée de fonctions communes à tous les
modèles). Il peut joindre votre climatiseur par trois chemins différents, appelés des **« transports »**
dans cette documentation — un transport est simplement la voie technique empruntée pour parler à
l'appareil (un service en ligne du fabricant, ou directement votre réseau local) :

- **AUX Home** — le **cloud** (un service en ligne géré par le fabricant, par opposition à un dialogue
  direct sur votre réseau) le plus récent du fabricant AUX, celui utilisé par l'application mobile
  « AUX Home » ;
- **Broadlink LAN** — un dialogue direct avec le climatiseur sur votre réseau local (**LAN**, c'est-à-dire
  votre réseau domestique), sans passer par Internet ;
- **AUX Cloud (AC Freedom)** — un cloud plus ancien, utilisé notamment par l'application « AC Freedom » et
  par certains appareils plus anciens ou hors Europe.

### 1.2 Mon climatiseur est-il compatible ?

Il n'existe **aucune liste de marques ou de modèles compatibles**, et ce n'est pas un oubli : le seul
critère qui compte est que votre climatiseur soit **joignable par l'un des trois transports** ci-dessus
(un compte AUX Home ou AC Freedom auquel il est rattaché, ou une réponse à une découverte sur le réseau
local). Ce protocole est utilisé, sous des noms commerciaux très différents, par de nombreuses marques —
AUX, Ballu, Centek, Dunham Bush, Kenwood, Rinnai, Rcool, Tornado, Akai, Hyundai, Hisense, Royal Clima, et
probablement d'autres non répertoriées ici. Le nom imprimé sur la façade de votre climatiseur ne décide
donc de rien : c'est le résultat d'un **scan** — la recherche automatique de vos climatiseurs, en
interrogeant vos comptes cloud et votre réseau local, détaillée au § 4 — qui vous dira si votre appareil
répond.

### 1.3 Ce que ce plugin n'est pas

> ⚠️ SmartClim s'appuie sur du **reverse engineering communautaire** : l'observation et la reconstitution
> de protocoles que le fabricant n'a jamais documentés publiquement, par des développeurs indépendants qui
> ont analysé les échanges réseau de leurs propres appareils. Ce n'est ni un plugin officiel AUX, ni un
> plugin certifié par un fabricant.
>
> Conséquence directe : une mise à jour du firmware de votre climatiseur, ou une évolution du service
> cloud du fabricant, **peut casser un transport sans préavis**, à tout moment, sans que ce soit un défaut
> du plugin. C'est précisément pour limiter ce risque que trois transports indépendants existent : si l'un
> cesse de fonctionner, les autres peuvent continuer à couvrir votre appareil (cf. § 5).

## 2. Installation

### 2.1 Depuis le market

SmartClim s'installe comme n'importe quel plugin Jeedom, depuis le Market de votre installation Jeedom.
Une fois installé, activez-le pour faire apparaître sa page de configuration dans le menu Plugins.

### 2.2 Dépendances

Jeedom affiche un écran « Dépendances » pour tout plugin qui en déclare. SmartClim en déclare deux
(installées automatiquement par Jeedom, aucune action manuelle n'est nécessaire) : elles sont utilisées
par le petit programme auxiliaire décrit au paragraphe suivant.

### 2.3 Le démon

SmartClim embarque un petit programme auxiliaire, exécuté en tâche de fond, que Jeedom appelle un
**démon**. Il apparaît dans le panneau « Démon » de la page de configuration, avec son propre état
(démarré/arrêté) et ses propres logs.

> ⚠️ Ce démon **accélère** certains échanges (relais temps réel avec le cloud historique), il ne
> **conditionne** jamais le fonctionnement du plugin : SmartClim continue de piloter et de relire l'état
> de vos climatiseurs même si le démon est arrêté, en erreur, ou si ses dépendances ne sont pas installées.
> Si vous ne le voyez jamais démarrer, ce n'est pas bloquant pour l'usage courant décrit dans cette
> documentation.

## 3. Configurer un compte

La configuration se fait depuis la page du plugin (menu **Plugins → Confort → SmartClim**, ou équivalent
selon votre version de Jeedom), onglet **Configuration**.

### 3.1 Compte AUX Home (appareils récents)

Renseignez les trois champs du bloc « Compte AUX Home » :

- **Adresse e-mail** — celle du compte utilisé dans l'application mobile AUX Home, par exemple
  `mon.compte@exemple.fr` (exemple fictif) ;
- **Mot de passe** — le même mot de passe que dans l'application. Il est stocké **chiffré** par Jeedom et
  n'apparaît jamais en clair dans les logs ;
- **Pays** — cf. § 3.2 ci-dessous.

### 3.2 Choisir le pays

Le champ **Pays** est une liste déroulante. Elle propose par défaut la France (`FRA`), mais **le pays
attendu est celui du compte AUX Home tel que choisi à sa création dans l'application mobile — pas
nécessairement celui de votre installation Jeedom.** Un pays incorrect fait échouer la connexion.

Si votre pays n'apparaît pas dans la liste, sélectionnez l'option « Autre pays » : un champ de saisie
libre apparaît, dans lequel vous pouvez entrer le **code ISO à 3 lettres** de votre pays (par exemple
`BEL` pour la Belgique, `CHE` pour la Suisse).

### 3.3 Compte cloud historique (AC Freedom / AUX Cloud)

Bloc distinct « Compte cloud historique », pour les appareils plus anciens ou rattachés au cloud
« AC Freedom » / « AUX Cloud » :

- **Adresse e-mail** et **Mot de passe** — mêmes remarques que pour AUX Home (mot de passe chiffré) ;
- **Région** — une liste déroulante **fermée** (contrairement au champ Pays ci-dessus, aucune saisie
  libre n'est possible) : Europe, USA, Chine ou Russie, selon celle de votre compte.

### 3.4 Un seul compte, les deux, ou aucun

Les deux comptes sont **totalement indépendants**. Vous pouvez ne renseigner que le compte AUX Home, que
le compte historique, les deux à la fois (si une partie de votre parc de climatiseurs est sur l'un et
l'autre sur l'autre), ou même aucun si vous ne comptez piloter vos appareils qu'en réseau local (cf.
§ 5.1).

### 3.5 Tester la connexion

Chaque bloc de compte porte un bouton **« Tester la connexion »**, qui vérifie vos identifiants sans
attendre le prochain scan. Pensez à **enregistrer** vos modifications avant de tester : le test utilise
les identifiants déjà enregistrés.

Le bloc AUX Home porte en plus un bouton **« Effacer les identifiants »**, qui supprime l'e-mail et le
mot de passe enregistrés pour ce compte (avec confirmation). ⚠️ Ce bouton n'existe **que pour le compte
AUX Home** : il n'y a pas d'équivalent pour le compte cloud historique, dont les identifiants s'effacent
en vidant les champs à la main puis en enregistrant.

### 3.6 Intervalle de rafraîchissement

Le champ **« Intervalle de rafraîchissement (minutes) »** règle la fréquence à laquelle le plugin relit
l'état de vos climatiseurs sur le cloud AUX Home : entre 1 et 1440 minutes, 5 minutes par défaut.

> ⚠️ Descendre cet intervalle très bas n'accélère pas la donnée : la **température ambiante** remontée
> par AUX Home se rafraîchit elle-même lentement côté fabricant (cf. § 8.2). Les deux autres transports
> (réseau local, cloud historique) ont leur propre cadence de lecture, fixe, à **15 minutes**,
> **indépendante** de ce réglage — cf. § 5.

## 4. Découvrir ses climatiseurs (le scan)

### 4.1 Il n'y a pas de bouton « Ajouter »

Contrairement à beaucoup de plugins Jeedom, il n'existe **aucun moyen de créer un équipement climatiseur
à la main**. C'est volontaire : un climatiseur créé « à vide » n'aurait ni identifiant technique, ni
profil de fonctions détectées, donc aucune commande utilisable. Un équipement n'apparaît que par
**découverte** — un scan cloud, ou une réponse sur le réseau local.

### 4.2 Le bouton « Scanner les climatiseurs »

Sur la page de configuration, le bouton **« Scanner les climatiseurs »** interroge à la fois vos deux
comptes cloud (ceux que vous avez configurés) et le réseau local par diffusion. Chaque climatiseur trouvé
est créé comme équipement Jeedom, ou rattaché à un équipement existant s'il est déjà connu (un même
appareil retrouvé à la fois en local et dans le cloud n'est jamais dupliqué).

### 4.3 Lire le résultat

Le résultat du scan affiche d'abord un bloc **« Sources interrogées »**, une ligne par source (AUX Home,
cloud historique, réseau local) avec un état parmi :

> Repère de vocabulaire pour la suite : l'**adresse MAC** est l'identifiant physique unique de la carte
> réseau d'un appareil (six paires de caractères séparées par des deux-points, par exemple
> `aa:bb:cc:dd:ee:ff`, exemple fictif) ; les **capacités** d'un climatiseur sont les fonctions qu'il a
> réellement annoncées lors du scan (modes, vitesses, options de confort…) — c'est d'après elles, et non
> d'après un catalogue générique, que les commandes Jeedom sont créées (§ 6.1).

- **Disponible** — la source a répondu normalement ;
- **Dégradée** — la source a répondu, mais partiellement (certains appareils n'ont pas pu être lus) ;
- **En échec** — la source n'a pas répondu du tout ;
- **Non configurée** — ⚠️ ce **n'est pas une erreur** : c'est simplement une source que vous n'avez pas
  renseignée (§ 3.4).

Suit le tableau principal **« Climatiseurs (LAN + cloud) »**, une ligne par appareil, avec son nom, son
adresse MAC, son adresse IP, son modèle, son identifiant cloud, sa disponibilité sur chaque transport,
son état en ligne, ses capacités détectées et son transport actif.

Deux sections apparaissent seulement si elles contiennent quelque chose :

- **« Appareils écartés »** — des appareils détectés mais qu'il n'a pas été possible de rattacher
  automatiquement à un climatiseur (identifiant manquant, doublon dans une réponse…) — ce ne sont pas
  forcément des climatiseurs ;
- **« Autres appareils détectés »** — des appareils qui ont répondu sur le réseau local mais qui ne sont
  manifestement pas des climatiseurs (une télécommande universelle Broadlink, une prise connectée…). Ils
  ne sont **jamais** créés comme équipements.

Une dernière section, **« Climatiseurs introuvables sur le compte »**, liste les équipements déjà connus
que le dernier scan cloud n'a pas retrouvés (appareil débranché, supprimé du compte…).

Par exemple, une ligne du tableau principal peut ressembler à : nom « Salon » (exemple fictif), adresse
MAC `aa:bb:cc:dd:ee:ff` (exemple fictif), adresse IP `192.168.1.42` (exemple fictif), identifiant cloud
`XXXXXXXXXXXX` (exemple fictif).

### 4.4 Mon climatiseur n'apparaît pas : que faire

- Vérifiez d'abord le bloc « Sources interrogées » : une source « En échec » explique déjà beaucoup ;
- Si votre climatiseur est sur un réseau séparé de Jeedom (VLAN, réseau segmenté), la diffusion de
  découverte du réseau local peut ne jamais l'atteindre — voir la page dédiée
  [Pilotage en réseau local](reseau-local.md) ;
- Consultez les logs du plugin (§ 7.1) : une erreur d'identifiants ou de région y apparaît généralement en
  clair, sans jamais exposer votre mot de passe.

## 5. Les modes de transport

### 5.1 Les trois chemins

Un même climatiseur peut être joignable par plusieurs des trois transports présentés au § 1.1. Le plugin
choisit lequel utiliser à chaque instant, selon le réglage décrit ci-dessous.

### 5.2 Le réglage « Mode de transport »

Sur la fiche de configuration de chaque équipement, le champ **« Mode de transport »** propose trois
choix :

- **Automatique** (réglage par défaut) — le réseau local est privilégié quand l'appareil y répond ; en
  cas d'échecs répétés, le plugin bascule automatiquement sur le cloud, puis revient au réseau local dès
  qu'il redevient joignable ;
- **Local** — le cloud n'est jamais utilisé pour cet équipement ;
- **Cloud** — le réseau local n'est jamais utilisé pour cet équipement.

### 5.3 Ce qu'affiche « Transport actif »

La commande **« Transport actif »** affiche le chemin **réellement emprunté à la dernière lecture ou à
la dernière commande envoyée** — c'est une observation, pas un réglage. Elle prend l'une des trois
valeurs : « AUX Home », « Broadlink LAN » ou « AUX Cloud (AC Freedom) ».

> **« Mode de transport » = ce que vous demandez. « Transport actif » = ce qui a effectivement été
> utilisé.** Les deux peuvent diverger : un équipement réglé en « Automatique » qui affiche « AUX Home »
> alors que le réseau local a récemment échoué, c'est le **repli automatique** en action (§ 5.4), pas une
> anomalie.

### 5.4 Le repli automatique et le retour au local

En mode **Automatique**, après **3 échecs consécutifs** sur le réseau local, le plugin bascule
temporairement sur le cloud. Il retente ensuite le réseau local à intervalles croissants (30 secondes,
puis jusqu'à un maximum de 5 minutes entre deux tentatives), et y revient dès qu'une tentative aboutit —
sans action de votre part.

### 5.5 Pilotage en réseau local

Le détail du fonctionnement du transport « Broadlink LAN » (comment l'adresse de l'appareil est trouvée,
ce qui se passe si elle change, comment sécuriser durablement ce fonctionnement) fait l'objet d'une page
dédiée : [Pilotage en réseau local](reseau-local.md).

## 6. Utiliser le plugin au quotidien

### 6.1 Les commandes créées

Chaque équipement reçoit les commandes correspondant à ses fonctions **réellement détectées** lors du
scan — jamais un catalogue générique. On y trouve typiquement : Disponibilité, Marche, Arrêt, Mode,
Consigne (curseur de température), Température ambiante, Vitesse de ventilation, Transport actif,
Dernière mise à jour, et le bouton **« Rafraîchir »** (cf. § 6.4). Selon votre appareil, des commandes de
mode (Automatique, Refroidissement, Déshumidification, Chauffage, Ventilation) et de vitesse (Automatique,
Silencieux, Faible, Moyen-faible, Moyen, Moyen-fort, Fort, Turbo) s'y ajoutent — uniquement celles
détectées sur votre appareil.

### 6.2 La tuile sur le dashboard

Un widget résumé (« tuile ») peut être posé sur le dashboard Jeedom : il regroupe l'état marche/arrêt, le
mode courant, la consigne et la vitesse sur une seule vignette, avec une icône selon le mode. Les
commandes détaillées restent accessibles en réaffichant la commande correspondante depuis l'équipement,
si vous préférez leur affichage individuel.

### 6.3 La page « Mes climatiseurs »

Une page dédiée, accessible depuis le menu principal de Jeedom, liste tous les climatiseurs sur lesquels
l'utilisateur connecté a le droit de lecture, sous forme de tuiles.

> ⚠️ Cette page reste **masquée** dans le menu tant qu'un administrateur n'a pas coché l'option
> correspondante (« Afficher le panneau desktop ») dans les préférences de Jeedom. Par ailleurs, les
> droits par équipement (limiter tel utilisateur à tels climatiseurs) ne s'appliquent qu'aux profils
> utilisateur de type **« restreint »** : les profils administrateur et utilisateur standard voient tous
> les climatiseurs installés.

### 6.4 Le bouton « Rafraîchir »

Présent sur chaque équipement, ce bouton déclenche immédiatement un cycle complet de lecture d'état pour
tout votre parc de climatiseurs, sans attendre le prochain cycle automatique.

### 6.5 Bornes de température personnalisées

Par défaut, la consigne de température est comprise entre 16 et 32 °C, par pas de 0,5 °C — les valeurs
observées sur l'appareil. Sur la fiche de chaque équipement, vous pouvez personnaliser ces bornes (borne
minimale, borne maximale, pas de réglage) dans une enveloppe autorisée de 5 à 35 °C. Laisser un champ vide
revient à utiliser la valeur détectée automatiquement.

## 7. Régler un problème

### 7.1 Les logs

Comme tout plugin Jeedom, SmartClim journalise dans ses propres logs (menu **Outils du système → Log**,
fichier `smartclim`), consultables sans redémarrer quoi que ce soit. Les secrets (mots de passe, jetons de
session) n'y apparaissent **jamais** en clair.

### 7.2 « Sonde de diagnostic »

Sur la page de configuration, le bouton **« Sonde de diagnostic »** produit un rapport technique détaillé
sur un équipement, utile pour signaler un problème ou une incompatibilité. Ses identifiants sensibles sont
**masqués automatiquement** avant affichage.

> ⚠️ Même masqué, **relisez ce rapport avant de le partager publiquement** (forum, ticket) : un masquage
> automatique reste une aide, pas une garantie absolue face à un format de réponse inhabituel de votre
> appareil.

### 7.3 Supprimer un équipement en trop

Un doublon (même appareil créé deux fois avant fusion) ou une fausse détection sur le réseau local (un
autre appareil Broadlink de votre domicile, pas un climatiseur) se supprime comme n'importe quel
équipement Jeedom, depuis sa fiche de configuration.

### 7.4 Désinstaller ou désactiver le plugin

> ⚠️ Désinstaller ou simplement **désactiver** le plugin n'efface **pas** automatiquement vos identifiants
> enregistrés. Si vous souhaitez les retirer, utilisez le bouton « Effacer les identifiants » (§ 3.5)
> avant de désinstaller, ou videz les champs de mot de passe à la main.

### 7.5 Symptômes courants

| Symptôme | Cause probable |
|---|---|
| « Tester la connexion » échoue | Identifiants incorrects, ou pays/région erroné (§ 3.2, § 3.3) |
| Un climatiseur connu n'apparaît plus dans le scan | Débranché, retiré du compte, ou hors de portée du réseau local (§ 4.3, § 4.4) |
| « Transport actif » n'est pas celui attendu | Repli automatique en cours (§ 5.4) — pas une anomalie |
| La température ambiante semble figée | Retard normal du cloud AUX Home (§ 8.2) |
| Une fonction visible dans l'application du fabricant n'apparaît pas dans Jeedom | Fonction non disponible à ce jour dans le plugin (§ 8.4), ou non détectée sur votre appareil précis |

## 8. Limites connues

### 8.1 Des protocoles non documentés par le fabricant

Comme rappelé au § 1.3, les trois transports reposent sur du reverse engineering communautaire, sans
documentation officielle. Une mise à jour de firmware ou une évolution du cloud du fabricant peut casser
un transport **sans préavis** — un risque inhérent à ce type d'intégration, pas un défaut du plugin.

### 8.2 La température ambiante est en retard

D'après une observation tierce documentée par un développeur ayant étudié ce protocole (jamais mesurée
directement par ce plugin), la température ambiante remontée par le cloud AUX Home peut accuser **de
quelques minutes à une demi-heure de retard** sur la réalité — ce retard a été relevé y compris dans
l'application officielle du fabricant, il n'est donc pas propre à SmartClim.

> ⚠️ **Ne vous appuyez jamais sur cette température pour une régulation fine** (asservissement d'un
> chauffage d'appoint, scénario de thermostat précis). Pour ce type d'usage, préférez une **sonde de
> température Jeedom dédiée**, posée physiquement dans la pièce. Pour évaluer la fraîcheur de la donnée
> affichée, regardez la commande « Dernière mise à jour » (§ 6.1) ou la date affichée sur la tuile
> (§ 6.2).

### 8.3 Broadlink LAN et AUX Cloud (AC Freedom) : non validés sur matériel réel à ce jour

Les transports **Broadlink LAN** et **AUX Cloud (AC Freedom)** ont été construits et vérifiés contre des
implémentations open source de référence, mais **n'ont pas encore été validés sur un climatiseur réel** à
la date de cette documentation. Vos retours d'expérience — succès comme échecs — sont utiles pour les
faire progresser : voir § 10.

### 8.4 Fonctions non disponibles à ce jour

Certaines fonctions de confort que l'on peut voir dans l'application mobile du fabricant — afficheur,
mode veille, ioniseur/santé, nettoyage automatique, anti-moisissure — ainsi que les oscillations
(verticale et horizontale) et la sécurité enfant, **ne sont pas disponibles à ce jour** dans SmartClim :
aucune de ces commandes n'apparaît sur vos équipements. Leur activation est conditionnée à une mesure
préalable sur du matériel réel, qui n'a pas encore eu lieu pour ces fonctions.

### 8.5 Le rafraîchissement n'est pas du temps réel

Hors relais temps réel latéral décrit au § 2.3 (qui ne concerne que le cloud historique et n'est jamais
requis), la lecture de l'état de vos climatiseurs se fait par cycles périodiques (§ 3.6, § 5), pas par une
notification instantanée du fabricant. Un changement fait à la télécommande ou dans l'application du
fabricant met donc un peu de temps à se refléter dans Jeedom — sauf déclenchement manuel du bouton
« Rafraîchir » (§ 6.4).

## 9. Crédits et licences

SmartClim s'appuie sur l'analyse de plusieurs projets open source qui ont documenté ces protocoles avant
lui, et reprend du code de certains d'entre eux dans le respect de leur licence. Le plugin lui-même est
publié sous licence **GPL-3.0-or-later**.

La liste complète des projets cités, avec leur auteur, leur licence et ce qui a été repris de chacun, fait
l'objet d'une page dédiée : [Crédits et licences](credits.md).

## 10. Signaler un problème / contribuer

Pour signaler un problème, une incompatibilité, ou simplement confirmer qu'un transport fonctionne chez
vous (particulièrement utile pour les transports du § 8.3), utilisez le forum de la communauté Jeedom.
Un rapport produit par la « Sonde de diagnostic » (§ 7.2) — relu avant envoi — accélère grandement le
diagnostic.
