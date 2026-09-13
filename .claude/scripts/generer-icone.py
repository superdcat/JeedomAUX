#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Génère `plugin_info/smartclim_icon.png` à partir du logo source AUX.

    python .claude/scripts/generer-icone.py            # génère et vérifie
    python .claude/scripts/generer-icone.py --verifier  # vérifie seulement l'existant

⚠️ OUTIL HORS RUNTIME. Le plugin ne génère aucune image à l'exécution et ne dépend pas de
Pillow : ce script est un outil de la machine de développement, exécuté à la main, dont le
seul produit est le PNG versionné. C'est pourquoi il vit dans `.claude/scripts/` et non
sous `core/`, `desktop/` ou `resources/` — il y serait livré sur l'installation Jeedom et
suggérerait une dépendance qui n'existe pas. Ce dossier est en outre hors du périmètre du
hook `pre-commit` de bump de version, donc le retoucher ne consomme pas de `pluginVersion`.

--- Provenance et licence du visuel (porte l'AC7 de l'UC03 post-mvp/07) ----------------

Source    : `.memory/assets/logo-aux-Blk--YM1.png`, déposé par l'utilisateur du dépôt.
Nature    : logo de la marque commerciale **AUX** (AUX Air Conditioner). Marque tierce,
            NON libre de droits, reproduite ici sans autorisation du titulaire.
Arbitrage : décision explicite de l'utilisateur du 2026-09-13, consignée dans l'objectif de
            `.memory/specs/post-mvp/07-multimarque-documentation-et-diffusion/03-icone-du-plugin.md`.
            Motif : installation Jeedom **locale et privée**, aucune diffusion publique.
            Le risque de contrefaçon de marque n'est pas nul — il est assumé et borné à cet
            usage.
⚠️ LIMITE  : cet arbitrage ne couvre PAS une publication sur le market Jeedom. L'UC04 du
            même domaine doit remplacer l'icône par un visuel **neutre** avant toute
            soumission publique. Seule `charger_monogramme()` est alors à refaire : la
            plaque, le rayon, le canevas et les contrôles de `verifier()` restent valables.
⚠️ LIMITE  : le déplacement du fichier source hors de la racine (AC8) le soustrait au
            téléchargement anonyme sur une installation Jeedom (`.memory/.htaccess` porte
            un `Deny from all` récursif). Il ne le retire PAS de l'historique du dépôt
            GitHub public, où il a été commité avant cette UC. Fait consigné, non traité :
            une réécriture d'historique serait destructive et ne changerait pas l'arbitrage.

--- Géométrie : reproduite du modèle officiel Jeedom, pas inventée --------------------

`https://doc.jeedom.com/fr_FR/dev/Icone_de_plugin` impose un PNG de 309 x 348 nommé
`<plugin-id>_icon.png` dans `plugin_info/`, sans nom sous l'image mais « en gardant les
mêmes tailles du modèle ». Le modèle fourni par cette page (`template_icon.png`) a une
**bbox alpha de (0, 0, 309, 309)** : le dessin occupe le carré supérieur, les 39 px du bas
sont intégralement transparents. C'est cette disposition qui est reproduite ici.

Le core consomme le fichier par `plugin::getPathImgIcon()` (`core/class/plugin.class.php`),
qui construit le chemin **en dur** depuis l'id du plugin — `info.json` ne porte aucune clé
d'icône et n'est donc pas concerné.
"""

import hashlib
import io
import os
import sys

from PIL import Image, ImageDraw

# La console Windows est en cp1252 : sans cela, le moindre accent du rapport fait planter le
# script AU MOMENT de l'afficher. Même parade que `verif-plugin.py`.
for flux in (sys.stdout, sys.stderr):
    try:
        flux.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SOURCE = '.memory/assets/logo-aux-Blk--YM1.png'
CIBLE = 'plugin_info/smartclim_icon.png'

# Mesures figées du fichier source. Elles ne sont pas de la documentation : elles sont
# contrôlées. Si le source est un jour remplacé, on veut un échec bruyant — sans ce contrôle,
# un autre fichier produirait en silence une icône portant la ligne « AIR CONDITIONER ».
# ⚠️ Contrôlées par `raise`, JAMAIS par `assert` : `python -O` supprime les `assert`, et ce
# garde-fou est le seul qui tienne AC3 et AC6 — il ne doit pas dépendre d'un drapeau
# d'interpréteur.
SOURCE_TAILLE = (2000, 768)
SOURCE_BBOX = (109, 34, 1911, 497)      # monogramme « AUX » seul, sans la seconde bande
SOURCE_COUPE = 540                       # ligne de coupe, au milieu du vide 497-587

CANEVAS = (309, 348)
COTE = 309                               # côté de la plaque, posée en (0, 0)
RAYON = 64
SUPERSAMPLING = 4
MARGE_H = 14                             # marge latérale du monogramme dans la plaque

# Bleu exact du logo AUX. ⚠️ Retenu PAR CALCUL puis confirmé visuellement sur planche de
# comparaison, mais à REVOIR EN RECETTE sur l'installation réelle : la spec fonctionnelle
# pose que le choix chromatique ne se tranche qu'au rendu, la doc Jeedom se bornant à
# demander de ne pas reprendre le code couleur des plugins officiels — qui est le vert
# `#95C12B`, mesuré sur cinq d'entre eux, et qui était précisément la couleur de l'icône
# héritée du template que celle-ci remplace.
# Contraste mesuré : 9,42:1 sur le thème clair du core (#F0F1F2), 1,51:1 sur le thème
# sombre (#212121). Le second est faible, et c'est un choix assumé : à cette taille c'est
# le monogramme blanc qui identifie le plugin, à 10,66:1 sur la plaque quel que soit le
# fond de page. Relever la valeur du bleu pour gagner du contraste de plaque éloignerait
# visiblement de la marque, ce que l'UC cherche précisément à restituer.
FOND = (22, 63, 110)
ENCRE = (255, 255, 255)

# Vert des icônes de plugins officiels Jeedom, mesuré sur cinq d'entre eux — et couleur
# exacte de l'icône héritée du template. Sa disparition totale est une condition nécessaire
# d'AC5, contrôlée plus bas.
VERT_TEMPLATE = (149, 193, 43)


def chemin(rel):
    return os.path.join(RACINE, rel)


def charger_monogramme():
    """Rend le canal alpha du monogramme « AUX » seul, en mode `L`.

    Le PNG source est déjà RGBA à fond transparent : son canal alpha EST le masque du
    dessin, avec son antialiasing d'origine. Il n'y a donc ni détourage à fabriquer, ni
    seuillage, ni conversion de luminance — et aucun des artefacts de bord que ces deux
    techniques produisent. Si le source devenait un jour opaque sur blanc, la conversion à
    faire serait `alpha = 255 - luminance`, JAMAIS un seuillage, qui détruit l'antialiasing
    et rend des contours crénelés dès 75 px.
    """
    src = Image.open(chemin(SOURCE)).convert('RGBA')
    if src.size != SOURCE_TAILLE:
        raise ValueError('source inattendu : %r au lieu de %r' % (src.size, SOURCE_TAILLE))

    # Le source porte DEUX bandes d'encre : le monogramme (lignes 34-496) et la mention
    # « AIR CONDITIONER » (lignes 588-736). La coupe tombe dans le vide qui les sépare,
    # donc à distance des deux — c'est ce qui exclut le texte par construction (AC3, AC6),
    # sans avoir à le détecter.
    coupe = src.crop((0, 0, SOURCE_TAILLE[0], SOURCE_COUPE))
    bbox = coupe.getchannel('A').getbbox()
    if bbox != SOURCE_BBOX:
        raise ValueError('monogramme inattendu : %r au lieu de %r' % (bbox, SOURCE_BBOX))
    return coupe.crop(bbox).getchannel('A')


def fabriquer_plaque():
    """Plaque arrondie opaque, dessinée en supersampling puis réduite.

    `ImageDraw.rounded_rectangle` en taille native rend un coin crénelé ; le facteur 4 est
    ce qui donne un profil comparable au modèle officiel (ajustement du coin : 60,7 px de
    rayon apparent contre 61,6 px pour le modèle).
    """
    grand = COTE * SUPERSAMPLING
    masque = Image.new('L', (grand, grand), 0)
    ImageDraw.Draw(masque).rounded_rectangle(
        (0, 0, grand - 1, grand - 1), radius=RAYON * SUPERSAMPLING, fill=255)
    masque = masque.resize((COTE, COTE), Image.LANCZOS)

    plaque = Image.new('RGBA', (COTE, COTE), FOND + (255,))
    plaque.putalpha(masque)
    return plaque


def poser_monogramme(plaque, monogramme):
    """Centre le monogramme sur la plaque et l'encre en blanc.

    ⚠️ C'est le MASQUE seul qui est redimensionné, jamais l'image RGBA du source : ses
    pixels transparents ont un RGB noir, et composer l'image entière poserait un liseré
    sombre sur tous les contours.

    Le monogramme fait 3,89 de rapport largeur/hauteur, dans un cadre qui est presque
    carré : il occupe donc toute la largeur utile et environ un quart de la hauteur. Le
    vide vertical restant est du fond de marque, pas un défaut de cadrage — l'étirer
    violerait la non-déformation, et empiler A/U/X sur trois lignes recomposerait la marque
    au lieu de reprendre le monogramme du fichier source (AC6).
    """
    largeur = COTE - 2 * MARGE_H
    hauteur = round(largeur / (monogramme.width / monogramme.height))

    tampon = Image.new('L', (COTE, COTE), 0)
    tampon.paste(monogramme.resize((largeur, hauteur), Image.LANCZOS),
                 (MARGE_H, (COTE - hauteur) // 2))
    plaque.paste(Image.new('RGBA', (COTE, COTE), ENCRE + (255,)), (0, 0), tampon)


def generer():
    plaque = fabriquer_plaque()
    poser_monogramme(plaque, charger_monogramme())

    icone = Image.new('RGBA', CANEVAS, (0, 0, 0, 0))
    icone.paste(plaque, (0, 0))
    # Pas de `dpi` posé : le modèle officiel n'en a pas et aucun consommateur ne le lit.
    # L'omettre rend la sortie déterministe (même empreinte d'une exécution à l'autre).
    icone.save(chemin(CIBLE), format='PNG', optimize=True)


def verifier():
    """Rejoue sur le fichier écrit les contrôles automatisables des critères d'acceptation.

    Rend `True` si tout passe. Ce qui n'est PAS vérifiable ici et relève du jugement humain
    en recette : la lisibilité en vignette (AC4) et l'affichage effectif dans la liste des
    plugins Jeedom (AC5).
    """
    im = Image.open(chemin(CIBLE))
    alpha = im.convert('RGBA').getchannel('A')
    echecs = []

    def controle(intitule, obtenu, attendu):
        etat = 'ok ' if obtenu == attendu else 'ECHEC'
        if obtenu != attendu:
            echecs.append(intitule)
        print('  [%s] %-46s %r' % (etat, intitule, obtenu))

    controle('AC1 dimensions', im.size, CANEVAS)
    controle('AC1 mode', im.mode, 'RGBA')
    # Égalité stricte avec la bbox du modèle officiel : plaque carrée en haut, bande basse
    # vide. Ce seul contrôle porte à la fois « bords arrondis + transparence autour » (AC2)
    # et « aucun texte sous le dessin » (AC3).
    controle('AC2/AC3 bbox alpha', alpha.getbbox(), (0, 0, COTE, COTE))
    controle('AC2 coins transparents',
             [alpha.getpixel(p) for p in ((0, 0), (COTE - 1, 0),
                                          (0, COTE - 1), (COTE - 1, COTE - 1))],
             [0, 0, 0, 0])
    controle('AC3 bande basse vide',
             max(alpha.getpixel((x, y)) for y in range(COTE, CANEVAS[1])
                 for x in range(CANEVAS[0])), 0)
    controle('AC2 centre opaque et au fond',
             im.convert('RGBA').getpixel((COTE // 2, COTE // 2)), FOND + (255,))
    # Condition nécessaire d'AC5 : plus aucun pixel du vert des plugins officiels, qui est
    # exactement la couleur de l'icône du template que celle-ci remplace.
    # `getcolors` rend None au-delà de son plafond ; l'icône compte quelques centaines de
    # couleurs, mais on refuse quand même de conclure sur un comptage tronqué.
    couleurs = im.convert('RGB').getcolors(65536)
    controle('AC5 plus de vert template',
             sum(n for n, c in couleurs if c == VERT_TEMPLATE)
             if couleurs is not None else 'comptage tronqué', 0)

    with io.open(chemin(CIBLE), 'rb') as f:
        print('  [   ] %-46s %s' % ('empreinte', hashlib.sha1(f.read()).hexdigest()[:16]))

    if echecs:
        print('\n%d contrôle(s) en échec : %s' % (len(echecs), ', '.join(echecs)))
        return False
    print('\nTous les contrôles automatisables passent.')
    print('Reste à valider humainement : AC4 (lisibilité en vignette) et AC5 (affichage '
          'réel dans la liste des plugins, en mode carte).')
    return True


def main():
    if '--verifier' not in sys.argv:
        generer()
        print('Écrit : %s' % CIBLE)
    print('=== Contrôles ===')
    return 0 if verifier() else 1


if __name__ == '__main__':
    sys.exit(main())
