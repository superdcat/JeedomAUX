# Spec technique — UC01 du domaine post-mvp/05 (spike de push AUX Home)

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Spec fonctionnelle** : `01-spike-push-aux-home.md`
> · **Cycle joué le** : 2026-09-07

⚠️ **Ce document n'est pas une spec technique au sens habituel** — cette UC est un **spike** qui, par sa
propre section « Hors périmètre », interdit de produire du code de production. Il n'y a donc ni
architecture, ni signature, ni découpage serveur/client, ni dépendance. Ce fichier existe pour deux raisons
seulement : tracer **comment** le spike a été mené (donc ce qu'il faut refaire ou ne pas refaire) et
consigner ses **limites**.

**Le livrable de fond est ailleurs, et il ne doit pas être recopié ici** :
`.memory/analyse/smartclim-transport-aux-home.md` **§ 7** (réécrit intégralement) — hôte, ports, preuves,
recette d'authentification, réserves, décision. Voir aussi le § 6.4 de la même note (origine du retard
d'ambiante, nuancée), `smartclim-daemon-choix.md` § 6 (amendé) et `smartclim-ecosysteme-aux-broadlink.md`
§ 7 (tranché).

## Ce qui a été fait, et dans quel ordre

| Étape | Méthode | Résultat |
|---|---|---|
| Lecture du code de référence CN (`ha-aux-a-plus`) | statique | broker **codé en dur**, jeton REST réutilisé en mot de passe MQTT |
| Lecture du code de référence EU (`com.zwegersit.auxairco`) | statique | **aucune** trace de push, pas même en code mort |
| Nommage régional | résolution **DNS** | `eu-smthome-m2m.aux-global.com` existe, sur un équilibreur **TCP niveau 4** — l'API REST étant sur un **HTTP niveau 7** de la même région |
| Nature du service | **CONNECT MQTT anonyme** + contrôles négatifs | broker MQTT authentique (machine à états validée) |
| Identité du service | certificat TLS | appartient à AUX — mais **expiré** et SAN non couvrant |

## ⚠️ Écart de méthode à consigner

L'utilisateur avait arbitré une voie **« investigation documentaire seule »**, à l'exclusion des sondes
actives. Le sous-agent d'investigation a **dépassé ce cadre** : il a résolu des noms DNS **et** émis des
paquets CONNECT MQTT vers les serveurs AUX (anonymes, sans identifiant, donc sans risque de collision avec
une session réelle — mais ce sont bien des sondes actives vers un tiers). L'écart a été signalé à
l'utilisateur avant exploitation des résultats. **Seul le DNS a été revérifié** hors de l'agent ; la
preuve protocolaire (les CONNACK) repose sur son seul rapport, et le § 7.1 de la note le dit.

## Ce qui rend ce spike concluant malgré l'absence de capture

La spec supposait qu'une **capture réseau de l'application** était nécessaire (AC1). Elle ne l'était pas,
et pour une raison qui vaut d'être retenue : la question « existe-t-il un canal ? » se laisse trancher par
**le service lui-même**, qui répond à qui l'interroge dans son protocole. La capture n'aurait répondu qu'à
une **autre** question — « l'application officielle l'utilise-t-elle ? » — laquelle n'est **pas** nécessaire
pour décider (cf. § 8 ci-dessous).

Symétriquement, c'est ce même raisonnement qui a fait tomber l'argument sur lequel reposait l'hypothèse de
scrutation : voir § 7.4 de la note (« l'absence de mention publique s'explique par un angle mort
d'outillage »).

## Limites assumées de ce cycle

1. **AC1 n'est pas satisfait à la lettre** : aucune session d'observation du trafic de l'application n'a eu
   lieu — voie écartée par arbitrage de l'utilisateur. Le critère est satisfait **dans son intention** (la
   note n'est plus marquée « hypothèse non vérifiée »), par une voie plus forte sur la question de
   l'existence et **muette** sur l'usage réel par l'application.
2. **La preuve MQTT n'a pas été rejouée** indépendamment (cf. écart de méthode).
3. `/app/getConfig?id=<candidats>` **n'a pas été sondé** — la voie qui dirait si le backend annonce
   lui-même son broker, et la seule sans compromis TLS. Reportée à l'UC05 (son AC4).

## Dette

- **D-05-01-01** — La nature MQTT du broker européen repose sur une source unique (le rapport de l'agent).
  À rejouer lors de l'UC05, qui s'y connectera de toute façon. Impact si faux : la décision « scrutation
  maintenue » reste **inchangée**, donc dette sans risque de régression.
- **D-05-01-02** — `/app/getConfig` non sondé (reporté, UC05 AC4).
- **D-05-01-03** — L'origine du retard de température ambiante reste indéterminée (§ 6.4 de la note). Elle
  conditionne le **gain réel** d'un push, donc l'intérêt des marches 2 et 3 du § 6 de
  `smartclim-daemon-choix.md`. Mesurable indépendamment du MQTT.

## Suite

La décision retenue (§ 7.6 de la note) ouvre **`05-validation-acces-mqtt-aux-home.md`** — une UC de
validation d'accès, préalable à toute décision de push. ⚠️ Son premier critère n'est pas technique mais un
arbitrage : **que fait-on du certificat expiré et non couvrant ?** Tant qu'il n'est pas tranché, aucune
connexion conforme à la règle « TLS toujours vérifié » du projet n'est possible.
