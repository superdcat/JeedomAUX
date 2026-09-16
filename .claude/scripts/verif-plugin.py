#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Vérifications mécaniques du plugin smartclim — un seul passage, un seul rapport.

Regroupe les contrôles répétitifs qui étaient jusqu'ici réinventés en `grep`/`sed` ad hoc
à chaque tour de correction (et dont un `grep -c $'\\r'` mal échappé a déjà produit un
faux « tout est en CRLF »).

    python .claude/scripts/verif-plugin.py              # les fichiers modifiés selon git
    python .claude/scripts/verif-plugin.py f1 f2 ...    # des fichiers précis
    python .claude/scripts/verif-plugin.py --tout       # tout le code du plugin

Sortie : une ligne par fichier, puis les contrôles transverses (miroir, i18n, interdits).
Code de retour 1 s'il reste au moins un PROBLEME, 0 sinon (les AVIS n'échouent pas).

⚠️ Ne lit JAMAIS `plugin_info/configuration.php` (interdit par les permissions de session) :
la synchronisation du miroir est vérifiée via `git diff --numstat`, pas par comparaison.
"""

import io
import json
import os
import re
import subprocess
import sys

# La console Windows est en cp1252 : sans cela, le moindre accent dans un message fait
# planter le script AU MOMENT d'afficher le rapport (constaté au premier essai).
for flux in (sys.stdout, sys.stderr):
    try:
        flux.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
CONFIG_PHP = 'plugin_info/configuration.php'
CONFIG_TXT = 'plugin_info/configuration.txt'
LANGUES = ('en_US', 'de_DE', 'es_ES')

# Fichiers de code du plugin. Le markdown interne (.memory/, .claude/) est laissé libre —
# git le normalise et ce n'est pas du code exécuté.
EXT_CODE = ('.php', '.js', '.json', '.ini', '.txt', '.html')
EXT_STRUCT = ('.php', '.js', '.txt', '.html')
BLANCS = ' \t\r\n'

# CRLF exigé uniquement là où une divergence coûte quelque chose : le bot prettier et les
# éditeurs y produiraient un diff intégral. ⚠️ `.json` en est EXCLU : tout le JSON du dépôt
# est en LF, y compris `info.json` et `packages.json` livrés par le squelette — l'exiger
# produirait 5 faux positifs à chaque exécution, et un script qui crie au loup se fait ignorer.
EXT_CRLF = ('.php', '.js', '.ini', '.txt', '.html')

# Assets binaires que `fichiers_git()` peut remonter (il ne filtre pas par extension,
# contrairement à `fichiers_tout()`). Sans cette liste, un `.png` modifié traverse
# `check_fins_de_ligne` et `check_octets_controle`, qui n'ont pas de garde, et le script
# sort en PROBLEME sur « fins de ligne MIXTES » et « octets de contrôle BRUTS » — constaté
# sur le commit de l'icône du plugin (UC03 post-mvp/07).
# ⚠️ Liste FERMÉE et fondée sur l'EXTENSION, jamais sur le contenu. Sauter un fichier parce
# qu'il contient un octet nul ferait taire le cas que `check_octets_controle` existe
# justement pour attraper : un texte accidentellement enregistré en UTF-16, où un `0x00`
# sépare chaque caractère ASCII.
EXT_BINAIRE = ('.png', '.jpg', '.jpeg', '.gif', '.bmp', '.pdf', '.ico')

# Gabarit UNIQUE de la ligne de rapport, partagé par la branche texte et la branche binaire.
# Deux formats distincts divergent dès qu'on touche à l'un : les colonnes se désalignent et
# le rapport devient illisible en diagonale, ce qui est tout ce qu'on lui demande.
LIGNE = '  %-52s %-8s ctrl=%-3s struct=%-8s meta=%s'

problemes = []
avis = []


def sh(args):
    try:
        return subprocess.run(args, cwd=RACINE, capture_output=True, text=True).stdout
    except Exception:
        return ''


def lire_octets(rel):
    with io.open(os.path.join(RACINE, rel), 'rb') as f:
        return f.read()


# --------------------------------------------------------------------------- fichiers


def fichiers_git():
    out = sh(['git', 'status', '--short'])
    res = []
    for ligne in out.splitlines():
        chemin = ligne[3:].strip().strip('"')
        if ' -> ' in chemin:                     # renommage
            chemin = chemin.split(' -> ')[-1]
        if chemin.endswith('/'):
            continue
        if chemin.startswith('.claude/agent-memory/'):
            continue
        if os.path.isfile(os.path.join(RACINE, chemin)):
            res.append(chemin)
    return sorted(set(res))


def fichiers_tout():
    res = []
    for base in ('core', 'desktop', 'plugin_info'):
        for rep, _, noms in os.walk(os.path.join(RACINE, base)):
            for nom in noms:
                rel = os.path.relpath(os.path.join(rep, nom), RACINE).replace('\\', '/')
                if rel != CONFIG_PHP and nom.endswith(EXT_CODE):
                    res.append(rel)
    return sorted(res)


# ------------------------------------------------------------------- fins de ligne


def check_fins_de_ligne(rel, data):
    cr = data.count(b'\r')
    lf = data.count(b'\n')
    if lf == 0:
        return 'aucune ligne'
    if cr == 0:
        verdict = 'LF-pur'
    elif cr == lf:
        verdict = 'CRLF'
    else:
        verdict = 'MIXTE'
    if rel.endswith(EXT_CRLF) and verdict != 'CRLF':
        problemes.append('%s : fins de ligne %s — attendu CRLF pour ce type de fichier' % (rel, verdict))
    elif verdict == 'MIXTE':
        problemes.append('%s : fins de ligne MIXTES (%d CR pour %d LF)' % (rel, cr, lf))
    return verdict


# --------------------------------------------------------------- octets de contrôle


def check_octets_controle(rel, data):
    # \t est légitime (desktop/php/*.php est indenté en tabulations).
    suspects = [o for o in data if o < 0x20 and o not in (0x09, 0x0A, 0x0D)] + \
               [o for o in data if o == 0x7F]
    if suspects:
        vus = sorted(set('0x%02X' % o for o in suspects))
        problemes.append('%s : %d octet(s) de contrôle BRUT(S) %s — texte à échappements corrompu ?'
                         % (rel, len(suspects), ', '.join(vus)))
    return len(suspects)


# ------------------------------------------------------- équilibrage structurel


def _sans_chaines_ni_commentaires(src):
    """Retire commentaires et littéraux de chaîne, pour ne compter que la structure.

    Indispensable : un compte naïf sur du HTML français produit un faux déséquilibre
    (apostrophes typographiques, accolades dans les chaînes).
    """
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        deux = src[i:i + 2]
        if deux == '/*':
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        if deux == '//':
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if c == '#' and deux != '#[':
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if c in '"\'':
            quote = c
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def _segments_a_analyser(rel, texte):
    """Ne renvoie que ce qui est réellement du code : PHP entre balises, et blocs <script>."""
    segments = []
    if '<?php' in texte:
        for m in re.finditer(r'<\?php(.*?)(\?>|\Z)', texte, re.S):
            segments.append(('php', m.group(1)))
    elif rel.endswith('.js'):
        segments.append(('js', texte))
    for m in re.finditer(r'<script[^>]*>(.*?)</script>', texte, re.S | re.I):
        segments.append(('js', m.group(1)))
    if not segments and rel.endswith('.js'):
        segments.append(('js', texte))
    return segments


def check_structure(rel, texte):
    if not rel.endswith(EXT_STRUCT):
        return 'n/a'
    # Les segments d'un MÊME genre sont recollés avant comptage : en PHP, une accolade
    # ouverte avant une sortie HTML se referme dans le bloc `<?php` suivant
    # (`foreach (…) { ?> … <?php }`), motif tout à fait normal d'une vue Jeedom. Compter
    # segment par segment produisait deux « {} déséquilibré » permanents sur
    # configuration.txt — et un contrôle qui crie au loup finit par être ignoré.
    par_genre = {}
    for genre, src in _segments_a_analyser(rel, texte):
        par_genre.setdefault(genre, []).append(_sans_chaines_ni_commentaires(src))
    etats = []
    for genre, morceaux in par_genre.items():
        propre = ''.join(morceaux)
        for ouvre, ferme, nom in (('{', '}', '{}'), ('(', ')', '()'), ('[', ']', '[]')):
            a, b = propre.count(ouvre), propre.count(ferme)
            if a != b:
                problemes.append('%s : %s déséquilibré dans le code %s (%d vs %d)'
                                 % (rel, nom, genre, a, b))
                etats.append('%s!' % nom)
    return 'OK' if not etats else ' '.join(etats)


# ------------------------------------------------- méta-séquences prises pour du code


# Fichiers dont le HTML est RENDU par le core, donc balayé par son moteur i18n : un `{{`
# y est dangereux jusque dans un commentaire. Ailleurs (core/class, core/php) il n'est
# jamais interprété, et le signaler ne ferait que du bruit.
PREFIXES_RENDUS = ('desktop/', 'plugin_info/configuration.', 'core/template/')
RE_SCRIPT = re.compile(r'<script[^>]*>', re.I)


def check_meta_commentaires(rel, texte):
    """Traque les séquences qu'un délimiteur avale — ce que check_structure ne PEUT pas voir.

    Cas réel qui a motivé ce contrôle : `mb_*/intl` écrit dans un docblock. Le `*/` du
    milieu ferme le commentaire, `intl, …` est relu comme du code (« syntax error,
    unexpected 'intl' ») et la classe entière devient introuvable au runtime. Invisible à
    la relecture — une phrase française bien formée, dans un commentaire bien formé — et
    invisible au comptage de délimiteurs : le texte éjecté du commentaire ne contient ni
    accolade, ni parenthèse, ni crochet, donc l'équilibre reste parfait.

    Trois variantes, un seul mécanisme (un littéral pris pour de la méta-syntaxe) :
      · `*/` collé à du texte, dans un commentaire de bloc ;
      · `?>` dans un commentaire `//` ou `#` : PHP QUITTE le mode PHP à cet endroit, et la
        fin de la ligne part telle quelle au navigateur ;
      · `{{` dans un commentaire d'un fichier rendu : le moteur i18n du core y voit un
        début de clé de traduction et avale tout jusqu'à la fermeture suivante.
    """
    if not rel.endswith(EXT_STRUCT):
        return 'n/a'
    rendu = rel.startswith(PREFIXES_RENDUS)
    n = len(texte)
    i = 0
    en_code = rel.endswith('.js')          # un .js est du code de bout en bout
    fin_code = n                           # sortie forcée du mode code (fin de <script>)
    nb = 0

    def signale(pos, quoi):
        eol = texte.find('\n', pos)
        extrait = texte[texte.rfind('\n', 0, pos) + 1:n if eol < 0 else eol].strip()
        problemes.append('%s:%d : %s — l. %s'
                         % (rel, texte.count('\n', 0, pos) + 1, quoi,
                            repr(extrait[:70] + ('…' if len(extrait) > 70 else ''))))

    while i < n:
        if en_code and i >= fin_code:
            en_code = False
            continue
        if not en_code:                    # du HTML : seuls ses commentaires nous intéressent
            if texte[i:i + 5] == '<?php' or texte[i:i + 3] == '<?=':
                en_code, fin_code, i = True, n, i + 3
                continue
            m = RE_SCRIPT.match(texte, i)
            if m:
                ferme = texte.lower().find('</script>', m.end())
                en_code, fin_code, i = True, (n if ferme < 0 else ferme), m.end()
                continue
            if texte[i:i + 4] == '<!--':
                fin = texte.find('-->', i + 4)
                fin = n if fin < 0 else fin
                if rendu and '{{' in texte[i:fin]:
                    signale(texte.index('{{', i, fin), "'{{' dans un commentaire HTML : le moteur "
                            'i18n du core le lit dans le HTML rendu et avale la suite')
                    nb += 1
                i = fin + 3
                continue
            i += 1
            continue
        deux = texte[i:i + 2]
        if deux == '?>':
            en_code, i = False, i + 2
            continue
        if deux == '/*':
            fin = texte.find('*/', i + 2)
            if fin < 0 or fin >= fin_code:
                signale(i, 'commentaire de bloc jamais refermé')
                nb += 1
                break
            # Une fermeture saine est précédée d'un blanc ou d'une étoile (` */`, `**/`).
            # Collée à autre chose, elle vient du TEXTE : c'est une fermeture accidentelle.
            if texte[fin - 1] not in BLANCS and texte[fin - 1] != '*':
                signale(fin, "'*/' collé à du texte : le commentaire se FERME ici, la suite est "
                        'relue comme du code')
                nb += 1
            if rendu and '{{' in texte[i:fin]:
                signale(texte.index('{{', i, fin), "'{{' dans un commentaire de bloc : lu par le "
                        'moteur i18n du core')
                nb += 1
            i = fin + 2
            continue
        if deux == '//' or (texte[i] == '#' and deux != '#['):
            fin = texte.find('\n', i)
            fin = n if fin < 0 else fin
            corps = texte[i:fin]
            if '?>' in corps:
                signale(i, "'?>' dans un commentaire de ligne : PHP QUITTE le mode PHP ici, la "
                        'fin de la ligne part au navigateur')
                nb += 1
            if rendu and '{{' in corps:
                signale(i, "'{{' dans un commentaire de ligne : lu par le moteur i18n du core")
                nb += 1
            i = fin
            continue
        if texte[i] in '"\'':               # une chaîne n'est pas du commentaire : on saute
            quote = texte[i]
            i += 1
            while i < n:
                if texte[i] == '\\':
                    i += 2
                    continue
                if texte[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        i += 1
    return 'OK' if not nb else '%d!' % nb


# ------------------------------------------------------------------ espaces de fin


def check_espaces_fin(rel, texte):
    fautives = [i + 1 for i, l in enumerate(texte.split('\n')) if l.rstrip('\r') != l.rstrip()]
    if fautives:
        apercu = ', '.join(str(x) for x in fautives[:5])
        avis.append('%s : espace(s) en fin de ligne (l. %s%s)'
                    % (rel, apercu, '…' if len(fautives) > 5 else ''))
    return len(fautives)


# ------------------------------------------------------------------ miroir txt/php


def check_miroir(fichiers):
    if CONFIG_TXT not in fichiers and CONFIG_PHP not in fichiers:
        return
    out = sh(['git', 'diff', '--numstat', '--', CONFIG_TXT, CONFIG_PHP])
    stats = {}
    for ligne in out.splitlines():
        cols = ligne.split('\t')
        if len(cols) == 3:
            stats[cols[2].replace('\\', '/')] = (cols[0], cols[1])
    a, b = stats.get(CONFIG_TXT), stats.get(CONFIG_PHP)
    if a is None and b is None:
        avis.append('miroir : aucun des deux fichiers n\'est modifié par rapport à HEAD')
    elif a != b:
        problemes.append('miroir DÉSYNCHRONISÉ : configuration.txt %s vs configuration.php %s '
                         '— relancer « cp plugin_info/configuration.txt plugin_info/configuration.php »'
                         % (a, b))
    else:
        print('  miroir configuration.txt/.php : synchrone (%s ajouts, %s suppressions de chaque côté)'
              % a)


# ------------------------------------------------------------------------- i18n


def _cles_source():
    """Clés UI par fichier source, telles que les fichiers i18n doivent les indexer."""
    cles = {}
    for rel in fichiers_tout():
        if rel == CONFIG_TXT:                      # indexé sous le .php, jamais sous le .txt
            cible = 'plugins/smartclim/' + CONFIG_PHP
        else:
            cible = 'plugins/smartclim/' + rel
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        trouvees = set(re.findall(r'\{\{(.+?)\}\}', texte, re.S))
        trouvees |= set(m.group(1) for m in
                        re.finditer(r"__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*__FILE__\s*\)", texte))
        trouvees |= set(m.group(1) for m in
                        re.finditer(r'__\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*__FILE__\s*\)', texte))
        if trouvees:
            cles.setdefault(cible, set()).update(
                t.replace("\\'", "'").replace('\\"', '"') for t in trouvees)
    return cles


def check_i18n():
    presents = [lg for lg in LANGUES if os.path.isfile(os.path.join(RACINE, 'core/i18n/%s.json' % lg))]
    if not presents:
        avis.append('i18n : aucun fichier core/i18n/*.json (normal avant l\'étape de traduction)')
        return
    source = _cles_source()
    total_source = sum(len(v) for v in source.values())
    print('  i18n : %d clé(s) UI dans le code, réparties sur %d fichier(s)'
          % (total_source, len(source)))
    for lg in presents:
        chemin = 'core/i18n/%s.json' % lg
        try:
            data = json.loads(lire_octets(chemin).decode('utf-8'))
        except Exception as e:
            problemes.append('%s : JSON invalide (%s)' % (chemin, e))
            continue
        manquantes, orphelines = [], []
        for cible, attendues in source.items():
            traduites = set(data.get(cible, {}))
            absentes = sorted(attendues - traduites)
            if not absentes:
                continue
            # ⚠️ Durcissement A1 (post-mvp/07 UC04) : un fichier dont AUCUNE clé n'est traduite
            # N'EST PLUS classé « hors périmètre ». Exempter une catégorie sur le signal même
            # que le contrôle cherche (0 clé traduite) rend le contrôle inatteignable en
            # silence — c'était précisément le trou que cette UC referme. Un fichier source
            # sans aucune traduction est donc un PROBLÈME au même titre qu'un fichier
            # partiellement traduit.
            manquantes += ['%s :: %s' % (cible, k) for k in absentes]
        for cible, trad in data.items():
            orphelines += ['%s :: %s' % (cible, k)
                           for k in sorted(set(trad) - source.get(cible, set()))]
        nb = sum(len(v) for v in data.values())
        etat = '%-6s %3d clé(s), %d section(s)' % (lg, nb, len(data))
        if manquantes:
            etat += ' | %d MANQUANTE(S)' % len(manquantes)
            problemes.append('%s : %d clé(s) manquante(s), dont %s'
                             % (chemin, len(manquantes), manquantes[0]))
        if orphelines:
            etat += ' | %d orpheline(s)' % len(orphelines)
            avis.append('%s : %d clé(s) traduite(s) sans source dans le code, dont %s'
                        % (chemin, len(orphelines), orphelines[0]))
        print('    ' + etat)
    # Une chaîne injectée dans du JS ne doit pas être délimitée par des apostrophes simples.
    try:
        txt = lire_octets(CONFIG_TXT).decode('utf-8', 'replace')
    except Exception:
        return
    for m in re.finditer(r'<script[^>]*>(.*?)</script>', txt, re.S | re.I):
        bloc = m.group(1)
        for mm in re.finditer(r"'(\{\{.+?\}\})'", bloc, re.S):
            problemes.append('%s : chaîne JS %s délimitée par des APOSTROPHES SIMPLES — '
                             'une traduction contenant une apostrophe casserait le script'
                             % (CONFIG_TXT, mm.group(1)[:40]))


# -------------------------------------------------------------------- interdits


INTERDITS = (
    (r'CURLOPT_VERBOSE|CURLOPT_STDERR|CURLOPT_DEBUGFUNCTION',
     'mode verbose cURL — écrirait l\'en-tête Authorization complet dans le log du serveur web'),
    (r'getTraceAsString\s*\(', 'trace d\'exception — porte les arguments de chaque frame'),
    (r'CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)\b', 'vérification TLS désactivée'),
    (r'\bvar_dump\s*\(|\bprint_r\s*\(', 'débogage oublié'),
    (r'displayException\s*\(\s*\$e\s*\)', 'displayException — jamais sur une smartclimException'),
)


def check_interdits(fichiers):
    for rel in fichiers:
        if not rel.endswith(('.php', '.js')):
            continue
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        for num, ligne in enumerate(texte.split('\n'), 1):
            nu = ligne.strip()
            if nu.startswith(('//', '*', '/*', '#')):     # un commentaire qui INTERDIT le motif
                continue
            for motif, pourquoi in INTERDITS:
                if re.search(motif, ligne):
                    avis.append('%s:%d — motif sensible (%s) : %s'
                                % (rel, num, pourquoi, nu[:80]))


# --------------------------------------------------------------- A2 : fr_FR.json absent


def check_fr_fr_absent():
    """PROBLEME si `core/i18n/fr_FR.json` existe (AC2).

    La clé française EST le texte source (cf. CLAUDE.md § i18n) : un fichier fr_FR.json
    n'a donc structurellement rien à contenir de plus, et sa seule apparition — par exemple
    un `translator` futur le recréant « par symétrie » avec les trois autres langues —
    est une régression à elle seule.
    """
    chemin = 'core/i18n/fr_FR.json'
    if os.path.isfile(os.path.join(RACINE, chemin)):
        problemes.append("%s : ne doit PAS exister — la clé française EST le texte source, "
                         "aucune traduction fr_FR n'a de sens" % chemin)


# ------------------------------------------------ A3 : '{{' dans un template de widget


def check_template_sans_i18n():
    """PROBLEME si '{{' apparaît dans un fichier de `core/template/**`, commentaire compris.

    Ce n'est pas prudentiel : `cmd::toHtml()` rend un template de plugin via
    `translate::exec($template, 'core/template/...')`, et `getWidgetTemplateCode()` renvoie
    `isCoreWidget => true` MÊME pour un template trouvé dans un plugin — `translate::
    getPluginFromName('core/template/...')` ne trouve pas `plugins/` et renvoie `core`. Une
    entrée `plugins/smartclim/core/template/...` d'un `core/i18n/*.json` ne peut donc JAMAIS
    être lue : toute double-accolade dans un widget est structurellement inatteignable par
    traduction, quel que soit l'effort mis à la traduire.
    """
    base_rel = 'core/template'
    base_abs = os.path.join(RACINE, base_rel)
    if not os.path.isdir(base_abs):
        return
    trouve = False
    for rep, _, noms in os.walk(base_abs):
        for nom in noms:
            chemin_abs = os.path.join(rep, nom)
            rel = os.path.relpath(chemin_abs, RACINE).replace('\\', '/')
            try:
                texte = lire_octets(rel).decode('utf-8', 'replace')
            except Exception:
                continue
            occurrences = [m.start() for m in re.finditer(r'\{\{', texte)]
            if occurrences:
                trouve = True
                lignes = ', '.join(str(texte.count('\n', 0, p) + 1) for p in occurrences[:5])
                problemes.append("%s : '{{' interdit dans un template de widget (jamais lu par "
                                 "le moteur i18n du core pour un template de plugin) — l. %s%s"
                                 % (rel, lignes, '…' if len(occurrences) > 5 else ''))
    if not trouve:
        print("  templates de widget (core/template/**) : aucune '{{' — OK")


# --------------------------------------------------- A4 : attributs/texte non enveloppés


# ⚠️ Liste FERMÉE, fondée sur l'ATTRIBUT — même patron que `EXT_BINAIRE` ci-dessus : à
# COMPLÉTER dès qu'un nouvel attribut porteur de texte utilisateur apparaît (`data-content`
# d'un popover, `data-title`, `summary`…). Un attribut absent de cette liste n'est pas
# « toléré » : il est INVISIBLE au contrôle, donc AC4 devient faux sans aucun signal.
ATTRS_TEXTE_A4 = ('title', 'placeholder', 'alt', 'data-original-title', 'aria-label',
                  'data-confirm')
RE_ATTR_A4 = re.compile(r'\b(' + '|'.join(ATTRS_TEXTE_A4) + r')\s*=\s*"([^"]*)"', re.I)
RE_TEXTE_NOEUD_A4 = re.compile(r'>([^<>{}#\n]{3,}?)<')


def _neutraliser_a4(rel, texte):
    t = re.sub(r'<\?php.*?\?>', ' ', texte, flags=re.S)
    t = re.sub(r'<\?=.*?\?>', ' ', t, flags=re.S)
    if not rel.endswith('.js'):        # un .js n'a pas de balise <script> à retirer
        t = re.sub(r'<script[^>]*>.*?</script>', ' ', t, flags=re.S | re.I)
    t = re.sub(r'<!--.*?-->', ' ', t, flags=re.S)
    t = re.sub(r'\{\{.*?\}\}', '', t, flags=re.S)      # déjà enveloppé : hors du périmètre
    return t


def _est_bruit_a4(valeur):
    if '<?php' in valeur or '<?=' in valeur:
        return True
    if "' ." in valeur or ". '" in valeur or '" +' in valeur:
        return True
    if "' +" in valeur or "+ '" in valeur or '" +' in valeur:
        return True                    # concaténation JS (`+`), symétrique de la PHP (`.`)
    if '#' in valeur:                  # jeton de widget #clé#
        return True
    if re.match(r'^[a-z0-9_\-\. ]+$', valeur):
        return True                    # classe/id technique (tout minuscule, sans accent)
    return False


def check_chaines_non_enveloppees():
    """AC4 — attribut/texte visible non enveloppé par {{...}} (portée honnête, cf. § 6.5)."""
    for rel in fichiers_tout():
        if not rel.startswith(PREFIXES_RENDUS):
            continue
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        propre = _neutraliser_a4(rel, texte)
        for m in RE_ATTR_A4.finditer(propre):
            valeur = m.group(2).strip()
            if not valeur or _est_bruit_a4(valeur):
                continue
            num = propre.count('\n', 0, m.start()) + 1
            problemes.append("%s:%d : attribut %s=\"%s\" non enveloppé par {{...}}"
                             % (rel, num, m.group(1), valeur[:60]))
        for m in RE_TEXTE_NOEUD_A4.finditer(propre):
            valeur = m.group(1).strip()
            if len(valeur) < 3 or _est_bruit_a4(valeur):
                continue
            if not re.search(r'[A-Za-zÀ-ÖØ-öø-ÿ]{3,}', valeur):
                continue
            num = propre.count('\n', 0, m.start()) + 1
            avis.append("%s:%d : texte visible '%s' hors {{...}} (à vérifier)"
                        % (rel, num, valeur[:60]))


# ---------------------------------------------------------- A5 : appels __() sur variable


def check_appels_traduction():
    """AC5 — chaque appel `__(...)` a un 1er argument LITTÉRAL et un 2e argument `__FILE__`.

    Lexeur minimal (commentaires puis chaînes sautés) : un `__($var)` ou `__('x' . $v, ...)`
    échapperait sinon totalement à `_cles_source()` (regex sur littéral) — la clé construite
    à l'exécution ne correspond à aucune entrée de `core/i18n/*.json`, et la chaîne source
    sort en français sans laisser aucune trace dans le rapport de complétude (AC1).
    """
    total = conforme = 0
    for rel in fichiers_tout():
        if not rel.endswith('.php'):
            continue
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        n = len(texte)
        i = 0
        while i < n:
            deux = texte[i:i + 2]
            if deux == '/*':
                fin = texte.find('*/', i + 2)
                i = n if fin < 0 else fin + 2
                continue
            if deux == '//':
                fin = texte.find('\n', i)
                i = n if fin < 0 else fin
                continue
            if texte[i] == '#' and deux != '#[':
                fin = texte.find('\n', i)
                i = n if fin < 0 else fin
                continue
            if texte[i] in '"\'':
                quote = texte[i]
                i += 1
                while i < n:
                    if texte[i] == '\\':
                        i += 2
                        continue
                    if texte[i] == quote:
                        i += 1
                        break
                    i += 1
                continue
            if texte[i:i + 3] == '__(' and (
                    i == 0 or not (texte[i - 1].isalnum() or texte[i - 1] in '_$')):
                debut_ligne = texte.rfind('\n', 0, i) + 1
                fin_ligne = texte.find('\n', i)
                fin_ligne = n if fin_ligne < 0 else fin_ligne
                ligne_nu = texte[debut_ligne:fin_ligne].strip()
                num = texte.count('\n', 0, i) + 1
                # ⚠️ Redondant avec le saut de commentaires du lexeur ci-dessus (/* */, //,
                # #) : ce point n'est normalement jamais atteint depuis une ligne de
                # commentaire, puisque le lexeur en a déjà sauté le contenu. Conservé
                # volontairement comme filet de défense en profondeur si ce lexeur est un
                # jour retouché — ne pas le prendre pour la protection principale.
                if ligne_nu.startswith(('*', '//', '#')):
                    i += 3
                    continue
                total += 1
                j = i + 3
                while j < n and texte[j] in ' \t\r\n':
                    j += 1
                lit_ok, fin_lit = False, j
                if j < n and texte[j] in '"\'':
                    quote = texte[j]
                    k = j + 1
                    ferme = False
                    while k < n and texte[k] != '\n':
                        if texte[k] == '\\':
                            k += 2
                            continue
                        if texte[k] == quote:
                            ferme = True
                            break
                        k += 1
                    if ferme:
                        fin_lit, lit_ok = k + 1, True
                if not lit_ok:
                    problemes.append("%s:%d : __() — 1er argument non littéral (concaténation) "
                                     "— la clé construite à l'exécution n'existera dans aucun "
                                     "core/i18n/*.json" % (rel, num))
                    i += 3
                    continue
                m2 = fin_lit
                while m2 < n and texte[m2] in ' \t\r\n':
                    m2 += 1
                if m2 >= n or texte[m2] != ',':
                    problemes.append("%s:%d : __() — 1er argument non littéral (concaténation) "
                                     "— la clé construite à l'exécution n'existera dans aucun "
                                     "core/i18n/*.json" % (rel, num))
                    i = fin_lit
                    continue
                m3 = m2 + 1
                while m3 < n and texte[m3] in ' \t\r\n':
                    m3 += 1
                if texte[m3:m3 + 8] != '__FILE__':
                    problemes.append("%s:%d : __() — __FILE__ manquant (2e argument)"
                                     % (rel, num))
                    i = fin_lit
                    continue
                conforme += 1
                i = fin_lit
                continue
            i += 1
    print('  __() : %d appel(s), %d conforme(s)' % (total, conforme))


# --------------------------------------------------------- A6 : manifeste info.json


# Nomenclature « category » du market Jeedom — recopiée du tableau « NOMENCLATURE
# CATEGORIES » de doc.jeedom.com/fr_FR/dev/structure_info_json, vérifiée le 2026-09-16.
# Liste FERMÉE : une valeur absente de la doc n'y est pas ajoutée « par prudence ». La doc
# étant la seule source et pouvant évoluer, toute alerte de ce contrôle se recoupe contre
# elle avant de conclure à une catégorie invalide.
CATEGORIES_MARKET = (
    'communication', 'wellness', 'energy', 'weather', 'monitoring', 'multimedia',
    'nature', 'devicecommunication', 'organization', 'home automation protocol',
    'programming', 'automation protocol', 'health', 'security', 'automatisation',
)


def _valeurs_chaines(obj):
    if isinstance(obj, str):
        yield obj
    elif isinstance(obj, dict):
        for v in obj.values():
            for x in _valeurs_chaines(v):
                yield x
    elif isinstance(obj, list):
        for v in obj:
            for x in _valeurs_chaines(v):
                yield x


def check_manifeste():
    """AC3 + AC7 — description multilingue, category documentée, aucune trace de `template`,
    et les 4 URL de doc/changelog présentes, en https:// et hors du namespace doc.jeedom.com
    (réservé aux plugins hébergés par Jeedom : 404 garanti pour un plugin tiers).

    ⚠️ N'inclut AUCUN contrôle sur `licence` — refus motivé (§ 2.5 de la spec technique) :
    aucune nomenclature n'est documentée pour ce champ, une liste blanche à 6 échantillons
    inventerait une contrainte que ni le core ni la doc n'imposent.
    """
    chemin = 'plugin_info/info.json'
    try:
        data = json.loads(lire_octets(chemin).decode('utf-8'))
    except Exception as e:
        problemes.append('%s : JSON invalide (%s)' % (chemin, e))
        return

    langues = data.get('language') or []
    description = data.get('description')
    if not isinstance(description, dict):
        problemes.append('%s : "description" doit être un objet à clés de langue' % chemin)
    else:
        manquantes = sorted(set(langues) - set(description))
        orphelines = sorted(set(description) - set(langues))
        if manquantes:
            problemes.append('%s : description sans entrée pour %s'
                             % (chemin, ', '.join(manquantes)))
        if orphelines:
            avis.append('%s : description porte une langue hors de "language" : %s'
                        % (chemin, ', '.join(orphelines)))
        for lg, texte in description.items():
            taille = len(texte) if isinstance(texte, str) else 0
            if taille < 80:
                problemes.append('%s : description[%s] fait %d caractère(s), attendu >= 80'
                                 % (chemin, lg, taille))

    categorie = data.get('category')
    if categorie not in CATEGORIES_MARKET:
        problemes.append('%s : category "%s" hors de la nomenclature market documentée'
                         % (chemin, categorie))

    for valeur in _valeurs_chaines(data):
        if 'template' in valeur.lower():
            problemes.append('%s : valeur contenant "template" (résidu du squelette) : %s'
                             % (chemin, valeur[:80]))

    for cle in ('documentation', 'documentation_beta', 'changelog', 'changelog_beta'):
        url = data.get(cle)
        if not isinstance(url, str) or not url:
            problemes.append('%s : "%s" absent ou vide' % (chemin, cle))
            continue
        if not url.startswith('https://'):
            problemes.append('%s : "%s" n\'est pas en https:// (%s)' % (chemin, cle, url))
        if 'doc.jeedom.com' in url:
            problemes.append('%s : "%s" pointe encore vers doc.jeedom.com (namespace réservé '
                             'aux plugins hébergés par Jeedom, 404 garanti pour un plugin '
                             'tiers) : %s' % (chemin, cle, url))


# ------------------------------------------------------------------------ main


def main():
    # ⚠️ Toute forme de drapeau NON reconnue doit échouer bruyamment (stderr + code 2) : la
    # version précédente jetait silencieusement tout `--*`, ce qui a rendu `--tous` — la forme
    # documentée par erreur dans CLAUDE.md — vert à 0 fichier analysé (aucun contrôle par
    # fichier joué, sortie « Aucun problème détecté »). Une seule forme est acceptée : `--tout`.
    drapeaux = [a for a in sys.argv[1:] if a.startswith('--')]
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    inconnus = [d for d in drapeaux if d != '--tout']
    if inconnus:
        sys.stderr.write('drapeau(x) inconnu(s) : %s — seule forme acceptée : --tout\n'
                         % ', '.join(inconnus))
        return 2
    if '--tout' in drapeaux:
        fichiers = fichiers_tout()
    elif args:
        fichiers = [a.replace('\\', '/') for a in args]
    else:
        fichiers = fichiers_git()

    fichiers = [f for f in fichiers if f != CONFIG_PHP]   # illisible : jamais ouvert

    print('=== Fichiers (%d) ===' % len(fichiers))
    for rel in fichiers:
        if not os.path.isfile(os.path.join(RACINE, rel)):
            avis.append('%s : introuvable' % rel)
            continue
        if rel.endswith(EXT_BINAIRE):
            # Verdict imprimé, jamais un saut muet : un fichier absent du rapport se lirait
            # comme un fichier oublié de la liste.
            print(LIGNE % (rel, 'binaire', 'n/a', 'n/a', 'n/a'))
            continue
        data = lire_octets(rel)
        texte = data.decode('utf-8', 'replace')
        fdl = check_fins_de_ligne(rel, data)
        ctrl = check_octets_controle(rel, data)
        struct = check_structure(rel, texte)
        meta = check_meta_commentaires(rel, texte)
        check_espaces_fin(rel, texte)
        print(LIGNE % (rel, fdl, ctrl, struct, meta))

    print('\n=== Transverse ===')
    check_miroir(fichiers)
    check_i18n()
    check_interdits(fichiers)
    check_fr_fr_absent()
    check_template_sans_i18n()
    check_chaines_non_enveloppees()
    check_appels_traduction()
    check_manifeste()

    if avis:
        print('\n=== AVIS (%d) — à regarder, non bloquant ===' % len(avis))
        for a in avis:
            print('  · ' + a)
    if problemes:
        print('\n=== PROBLEMES (%d) ===' % len(problemes))
        for p in problemes:
            print('  [X] ' + p)
        return 1
    print('\n✓ Aucun problème mécanique détecté.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
