# Objectif

Développer un **plugin Jeedom complet pour piloter le maximum de climatiseurs Wi-Fi basés sur l'écosystème AUX / Broadlink / AC Freedom**, quelle que soit leur marque commerciale.

Le plugin doit idéalement supporter :

* le **pilotage local LAN** lorsque le module Wi-Fi supporte le protocole Broadlink UDP ;
* le **pilotage cloud historique AC Freedom / AUX Cloud** ;
* le **nouveau cloud AUX Home** utilisé par les appareils récents ;
* un mode **AUTO / hybride** qui privilégie le local et bascule vers le cloud lorsque nécessaire.

L'objectif n'est surtout pas de développer une intégration uniquement pour un modèle AUX précis, mais de concevoir une couche générique basée sur :

* le protocole utilisé ;
* les capacités réellement annoncées par l'appareil ;
* les fonctionnalités disponibles dans l'API ;
* et non sur une whitelist rigide de références commerciales.

---

# 1. Étudier les projets existants avant de coder

Commencer impérativement par analyser en détail ces repositories GitHub :

## maeek/ha-aux-cloud

Intégration Home Assistant Python historique pour AUX Cloud / AC Freedom.

À étudier notamment :

* authentification ;
* découverte des familles ;
* découverte des appareils ;
* WebSocket ;
* lecture des états ;
* commandes ;
* mapping des fonctions AUX ;
* gestion des pompes à chaleur ;
* structure des messages cloud.

---

## azadaydinli/ac_freedom

Très important.

Cette intégration Home Assistant gère déjà :

* Broadlink UDP local ;
* AUX Cloud ;
* détection automatique des appareils locaux ;
* climatiseurs récents n'acceptant plus l'ancien protocole UDP ;
* presets et fonctionnalités avancées.

Analyser particulièrement l'abstraction entre :

`Local Broadlink UDP`

et

`Cloud AUX`

et voir ce qui peut être réutilisé conceptuellement dans Jeedom.

---

## fparrav/homebridge-aux-cloud

Probablement l'une des meilleures bases pour le futur plugin Jeedom.

Projet TypeScript qui implémente trois stratégies :

* LAN only ;
* Cloud only ;
* Cloud + LAN avec priorité au local et fallback cloud.

Analyser particulièrement :

* discovery UDP ;
* authentification Broadlink `0x65` ;
* AES-128-CBC ;
* UDP port 80 ;
* `getState` ;
* `getInfo` ;
* envoi de commandes ;
* gestion des sessions ;
* polling ;
* fallback local → cloud ;
* découverte automatique des appareils cloud ;
* retry / timeout ;
* protection contre les états périmés après une commande.

---

## azadaydinli/homebridge-ac-freedom

Analyser également ce projet.

Il est particulièrement intéressant pour le support multimarque.

Il annonce supporter les climatiseurs utilisant AC Freedom, notamment :

* AUX
* Ballu
* Centek
* Dunham Bush
* Kenwood
* Rinnai
* Rcool
* Tornado
* Akai
* Hyundai
* Hisense
* Royal Clima

Ne pas considérer cette liste comme exhaustive.

Le critère réel doit être :

**appareil compatible avec le protocole Broadlink AC / AC Freedom / AUX Cloud**, quelle que soit la marque imprimée dessus.

---

# 2. Prendre également en charge le nouveau AUX Home

Attention : les intégrations existantes semblent principalement basées sur l'infrastructure historique AC Freedom / AUX Cloud.

Les nouveaux appareils utilisent maintenant l'application :

**AUX Home**

et une nouvelle API européenne :

`eu-smthome-api.aux-global.com`

Nous avons déjà vérifié expérimentalement qu'elle est exploitable depuis un programme externe.

Le flux observé dans AUX Home 2.3.2 est notamment :

### Obtention de la clé publique

`GET /app/auth/getPubkey`

Puis :

### Authentification

`POST /app/auth/login/pwd`

Le body contient notamment :

* `account`
* `password`
* `publicKeyBase64`
* `ts`

`account` et `password` ne sont pas envoyés en clair.

Après authentification, l'application utilise :

`Authorization: bearer <token>`

Nous avons déjà testé avec succès une requête externe Python utilisant le token récupéré depuis AUX Home :

`GET /app/getConfig?id=deviceMutex`

Résultat :

* HTTP 200
* JSON valide
* authentification acceptée

Le JSON `deviceMutex` expose notamment les concepts :

* `on_off`
* `temperature`
* `air_con_func`
* `wind_speed`
* `up_down_swing`
* `left_right_swing`
* `screen`
* `sleep_mode`
* `eco`
* `clean`
* `healthy`
* `anti_fungus`
* `ultra_silence`
* etc.

Cette table est générique et ne signifie pas que chaque appareil supporte toutes ces fonctions.

Il faudra donc étudier/reverse-engineerer proprement cette **nouvelle API AUX Home**, idéalement en capitalisant sur les projets existants plutôt qu'en faisant une implémentation ad hoc.

---

# 3. Architecture souhaitée

Je souhaite une architecture avec une abstraction commune du climatiseur.


L'objectif est que la partie Jeedom ne dépende pas du protocole utilisé.

---

# 4. Modes de connexion

Prévoir au minimum quatre stratégies.

## AUTO

Mode recommandé.

Pour chaque climatiseur :

1. rechercher s'il est joignable en Broadlink UDP local ;
2. si oui, utiliser le LAN en priorité ;
3. sinon utiliser son cloud disponible ;
4. éventuellement utiliser le cloud comme fallback après plusieurs erreurs LAN.

---

## LOCAL

Aucun cloud.

Communication exclusivement LAN Broadlink.

Cela doit fonctionner même sans Internet.

---

## CLOUD

Communication uniquement via le cloud correspondant à l'appareil :

* AC Freedom/AUX Cloud historique ;
* ou AUX Home récent.

---

## HYBRID

Local prioritaire avec fallback cloud.

Exemple :

```text
commande
   ↓
Broadlink LAN
   ↓
succès → terminé

échec N fois
   ↓
AUX Cloud
```

Le plugin doit afficher clairement quel transport est actuellement utilisé.

---

# 5. Broadlink LAN

Réutiliser les connaissances disponibles dans les projets existants.

Le protocole historique utilise notamment :

* UDP ;
* port 80 ;
* Broadlink auth command `0x65` ;
* session key ;
* AES-128-CBC ;
* commandes HVAC spécifiques.

Prendre en charge :

* découverte broadcast ;
* IP statique ;
* MAC ;
* authentification ;
* lecture état ;
* lecture température ambiante ;
* écriture état ;
* timeout ;
* retry ;
* reconnexion ;
* appareils sur VLAN lorsque l'IP est saisie manuellement.

Attention :

**un appareil ayant une MAC Broadlink n'est pas forcément pilotable en UDP local.**

Certains firmwares récents ignorent complètement les requêtes Broadlink LAN.

Ne pas considérer cela comme une erreur fatale : dans AUTO/HYBRID, basculer vers le cloud.

---

# 6. Cloud historique AC Freedom

Implémenter le support cloud historique en étudiant les projets cités.

Supporter autant que possible :

* Europe ;
* USA ;
* Chine ;
* autres régions facilement ajoutables.

Faire :

```text
login
→ homes/families
→ devices
→ capabilities
→ state
→ commands
→ realtime/WebSocket si disponible
```

Préférer les mises à jour push/WebSocket au polling agressif lorsqu'elles existent.

---

# 7. Nouveau AUX Home Cloud

Implémenter séparément le nouveau backend AUX Home.

Ne pas supposer que le login historique AC Freedom fonctionne.

L'application récente utilise au minimum :

```text
eu-smthome-api.aux-global.com
```

Étudier :

* génération/récupération de clé publique ;
* chiffrement du login ;
* obtention du bearer token ;
* refresh token / renouvellement de session ;
* homes/families ;
* liste des appareils ;
* état ;
* capabilities ;
* commandes ;
* WebSocket éventuel ;
* événements push éventuels.

IMPORTANT :

Ne jamais nécessiter qu'un utilisateur copie manuellement un bearer token depuis PCAPdroid dans l'utilisation normale du plugin.

Cela peut être utilisé uniquement pendant le développement.

Le plugin final doit savoir faire le login lui-même avec les identifiants AUX Home.

---

# 8. Détection des capacités

C'est un point essentiel.

Ne pas créer des équipements figés du genre :

```text
si modèle XXX → commandes A/B/C
si modèle YYY → commandes D/E/F
```

Construire les commandes Jeedom dynamiquement à partir des capacités réellement disponibles.

Fonctions potentielles :

### Informations

* online
* power
* ambientTemperature
* targetTemperature
* mode
* fanSpeed
* verticalSwing
* horizontalSwing
* display
* sleep
* eco
* health/ionizer
* mildew
* clean
* silent
* turbo
* heating
* etc.

### Actions

* On
* Off
* température
* mode Auto
* Cool
* Heat
* Dry
* Fan
* vitesse ventilation
* Auto
* Low
* Medium
* High
* Turbo
* swing vertical
* swing horizontal
* Display
* Sleep
* Eco
* Clean
* Health
* Anti-fungus
* autres fonctions détectées

Le plugin doit créer seulement les commandes correspondant aux capacités du périphérique.

---

# 9. Mapping générique des modes

Créer une représentation interne indépendante des codes propriétaires.

Exemple :

```text
AUTO
COOL
DRY
HEAT
FAN
```

Puis les transports traduisent vers les valeurs AUX/Broadlink correspondantes.

Même logique pour :

```text
fan:
AUTO
LOW
MEDIUM
HIGH
TURBO
SILENT
```

et pour les swings/features.

Cela permettra de prendre en charge plusieurs générations de firmware sans modifier toute la partie Jeedom.

---

# 10. Multimarque

Le plugin ne doit pas être nommé ou codé comme s'il ne supportait que AUX si ce n'est pas techniquement nécessaire.

Chercher à supporter tous les appareils compatibles avec cet écosystème.

Au minimum vérifier la compatibilité potentielle avec :

* AUX
* Ballu
* Centek
* Dunham Bush
* Kenwood
* Rinnai
* Rcool
* Tornado
* Akai
* Hyundai
* Hisense
* Royal Clima

Mais ne pas coder une whitelist limitante.

Si demain une clim "FooBar" utilise exactement le même protocole AC Freedom, elle doit pouvoir être découverte et fonctionner.

---

# 11. Jeedom

Respecter les conventions actuelles de développement des plugins Jeedom.


### Scan

Bouton :

**Scanner les climatiseurs**

Il doit essayer :

1. discovery Broadlink LAN ;
2. discovery cloud historique ;
3. discovery AUX Home ;
4. fusionner les doublons.

Pour chaque appareil trouvé afficher idéalement :

* nom ;
* marque si connue ;
* modèle ;
* MAC ;
* IP ;
* endpoint/device ID ;
* cloud ;
* protocole local disponible ;
* statut online ;
* capabilities.

---

# 12. Fusion LAN / Cloud d'un même équipement

Éviter qu'une même clim apparaisse deux fois parce qu'elle est trouvée :

* en LAN ;
* et dans le cloud.

Essayer de faire le rapprochement via :

* MAC ;
* endpoint ID ;
* device ID ;
* données cloud ;
* autres identifiants.

Exemple :

```text
Clim Chambre

MAC : xx:xx:xx:xx:xx:xx
IP : 192.168.x.x
Cloud endpoint : xxxxx
Local : NON
AUX Home : OUI

Transport actif : AUX Home Cloud
```

ou :

```text
Clim Séjour

Local : OUI
Cloud : OUI

Transport actif : LOCAL
Fallback : CLOUD
```

---

# 13. Daemon

Choisir la meilleure architecture après étude des projets.

Jeedom étant principalement PHP mais les implémentations existantes étant Python et TypeScript, il est acceptable et probablement préférable d'utiliser un daemon.

Par exemple :

```text
Plugin PHP Jeedom
        ↕
daemon Python ou Node.js
        ↕
LAN / Cloud
```

Le choix Python vs Node doit être fait après analyse du code existant et du volume de code réutilisable.

Ne pas réimplémenter en PHP un protocole complexe uniquement pour avoir du PHP.

Le daemon doit :

* gérer les connexions persistantes ;
* gérer WebSocket ;
* maintenir les sessions ;
* recevoir les mises à jour temps réel ;
* envoyer les changements à Jeedom ;
* recevoir les commandes de Jeedom.

---

# 14. État temps réel

Si le cloud fournit un WebSocket ou événement push :

**l'utiliser.**

Ne pas poller toutes les 2 secondes si un push existe.

Le plugin doit aussi détecter les changements effectués :

* depuis la télécommande IR ;
* depuis AUX Home / AC Freedom ;
* depuis un autre système ;
* directement sur la clim.

Et synchroniser Jeedom.

---

# 15. Robustesse

Prévoir :

* reconnexion WebSocket ;
* expiration token ;
* renouvellement automatique du token ;
* changement IP DHCP ;
* appareil offline ;
* timeout LAN ;
* fallback cloud ;
* cloud indisponible ;
* Internet indisponible ;
* reprise après redémarrage de Jeedom ;
* reprise après redémarrage de la clim.

Ne jamais laisser une commande Jeedom bloquée longtemps.

---

# 16. Sécurité

Très important :

* ne jamais logger le mot de passe ;
* ne jamais logger complètement un bearer token ;
* masquer les secrets dans les logs debug ;
* stocker les credentials via les mécanismes Jeedom appropriés ;
* ne jamais désactiver la validation TLS ;
* ne pas hardcoder de token ;
* éviter toute dépendance à une capture MITM dans le produit final.

---

# 17. Licences

Avant de copier/réutiliser du code :


Documenter clairement les sources ayant servi à l'implémentation.

---

# 18. Tests

Les tests seront faits dans un environnement utilisateur

---

# 19. Premier appareil de validation

Le premier périphérique réel utilisé pour valider le plugin sera un climatiseur mobile AUX récent.

Il utilise :

* application : AUX Home
* cloud Europe récent ;
* MAC appartenant à Broadlink ;
* mais ne répond PAS au protocole Broadlink UDP historique.

Tests déjà effectués :

```text
Broadlink hello → timeout
Broadlink auth → timeout
Broadlink broadcast → appareil absent
```

Sur le même réseau, deux RM4 Pro Broadlink sont parfaitement découverts, donc ce n'est pas un problème de réseau ou de broadcast.

La clim utilise :

```text
eu-smthome-api.aux-global.com
```

Un bearer token AUX Home récupéré depuis l'application a déjà permis d'effectuer depuis un script Python :

```text
GET /app/getConfig?id=deviceMutex
```

avec :

```text
HTTP 200
code: 200
```

Donc la faisabilité d'un accès externe au **nouveau cloud AUX Home est déjà confirmée**.

Le travail restant est essentiellement :

* reproduire le login proprement ;
* découvrir les appareils ;
* récupérer leur état ;
* envoyer les commandes.

---

# 20. Philosophie générale

Je veux éviter un plugin bricolé uniquement pour ma clim.

Construire plutôt une véritable intégration :

**Jeedom AC Freedom / AUX / Broadlink multi-protocoles et multimarques.**

Elle doit pouvoir évoluer facilement lorsqu'une nouvelle génération apparaît.

Principe :

```text
Device
   ↓
Capabilities
   ↓
Generic AC API
   ↓
Transport
      ├── Broadlink Local
      ├── AUX Cloud legacy
      └── AUX Home Cloud
```

Le protocole doit être une implémentation interchangeable.

---