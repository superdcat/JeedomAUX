# Pilotage en réseau local (Broadlink)

Ce climatiseur peut être piloté directement sur votre réseau local, sans passer par le
cloud du fabricant. Ce document explique comment son adresse est trouvée, ce qui se
passe si elle change, et comment sécuriser durablement ce fonctionnement.

## Comment l'adresse est trouvée

Un scan du réseau local (page de configuration du plugin) diffuse une requête de
découverte : les climatiseurs qui répondent sont identifiés par leur adresse MAC, et
leur adresse IP est mémorisée automatiquement. Aucune saisie n'est nécessaire dans le
cas courant.

## Si l'adresse IP change

Une box qui redémarre ou un bail DHCP qui se renouvelle peut changer l'adresse IP du
climatiseur du jour au lendemain. Le plugin détecte ce cas automatiquement : avant de
considérer l'appareil injoignable, il relance une découverte par diffusion et
retrouve le climatiseur par son adresse MAC, même si son IP a changé. La nouvelle
adresse est alors enregistrée toute seule, sans action de votre part — le pilotage
local reprend au cycle suivant (ou à la prochaine commande).

## Recommandation : réserver l'adresse IP du climatiseur

Pour limiter la fréquence de ce cas (et le délai qu'il ajoute), il est recommandé de
**réserver l'adresse IP du climatiseur dans votre box ou votre serveur DHCP** (réservation
par adresse MAC). L'appareil conserve alors la même adresse IP de façon durable, et la
redécouverte automatique n'a plus lieu d'être déclenchée.

## Secours en réseau segmenté (VLAN)

Si votre climatiseur est sur un VLAN séparé de celui de Jeedom, la diffusion de
découverte peut ne jamais l'atteindre. Dans ce cas, saisissez son adresse IP (et, si
besoin, sa MAC) directement dans la configuration de l'équipement : cette adresse
saisie est alors **toujours prioritaire** et le pilotage local continue de fonctionner.

⚠️ Une adresse saisie manuellement n'est **jamais corrigée automatiquement** : si le
climatiseur change d'adresse dans ce cas, pensez à mettre à jour vous-même la
configuration de l'équipement (un message d'avertissement dans les logs du plugin vous
signale la nouvelle adresse retrouvée).
