# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
#
# ---------------------------------------------------------------------------
# Portage SmartClim - origine : jeedom/plugin-template, commit ceed01b de ce
# depot (identique a l'amont jeedom/plugin-template@master a cette date).
# Ecarts par rapport a l'amont (cf. spec technique UC02 du domaine
# post-mvp/05-temps-reel-et-demon, S 2.3) :
#   (b) _socket_host = '127.0.0.1' au lieu de 'localhost' (pas de resolution
#       DNS, aucun risque de ::1 ni d'interface externe) ; defauts
#       _socket_port = 55112 et _pidfile = '/tmp/smartclim_demon.pid' ;
#   (c) suppression de logging.info('Apikey: %s', _apikey) et de l'argument
#       --device (aucun materiel serie) ;
#   (d) read_socket() reecrit : try/except Exception englobant,
#       bytes.decode('utf-8', 'ignore') au lieu de jeedom_utils.stripped(),
#       comparaison de cle par hmac.compare_digest, aiguillage sur
#       message.get('cmd') avec "ping" comme seul ordre reconnu, et
#       journalisation d'un ordre inconnu sans recopier la charge recue ; le
#       jeton (donnee VENUE DU RESEAU, avant authentification du seul cote
#       PHP du trajet retour) est valide par la MEME regexp que
#       smartclimDemon::enregistrerPong() avant d'etre journalise ou renvoye
#       dans le pong (trouve en revue croisee UC02 : injection de journal) ;
#   (e) shutdown() : os.path.exists() avant os.remove(), et test de presence
#       de my_jeedom_socket dans globals() ;
#   (f) messages de journal en francais.
# Seul ordre metier gere a ce stade : "ping" -> "pong" (socle du pont,
# aucun protocole climatiseur reel).
# ---------------------------------------------------------------------------

import logging
import sys
import os
import time
import traceback
import signal
import json
import hmac
import re
import argparse

from jeedom.jeedom import jeedom_socket, jeedom_utils, jeedom_com, JEEDOM_SOCKET_MESSAGE

# Meme forme que smartclimDemon::enregistrerPong() (core/class/smartclimDemon.class.php)
# cote PHP : les deux barrieres doivent rester identiques. fullmatch() (et non match()
# avec un motif ancre par ^/$) : $ tolere aussi une position juste avant un '\n' final,
# ce qui laisserait passer un jeton du type "abcd1234\n".
REGEX_JETON = re.compile(r'[0-9a-f]{8,32}')


def read_socket():
    if not JEEDOM_SOCKET_MESSAGE.empty():
        logging.debug("Message recu dans le socket JEEDOM_SOCKET_MESSAGE")
        try:
            brut = JEEDOM_SOCKET_MESSAGE.get()
            texte = brut.decode('utf-8', 'ignore') if isinstance(brut, bytes) else brut
            message = json.loads(texte)
            if not hmac.compare_digest(str(message.get('apikey', '')), _apikey):
                logging.error("Apikey invalide recue sur le socket")
                return
            commande = message.get('cmd')
            if commande == 'ping':
                jeton = message.get('jeton')
                if not isinstance(jeton, str) or not REGEX_JETON.fullmatch(jeton):
                    logging.warning("Ping recu avec un jeton de forme invalide, ignore")
                    return
                logging.info("Ping recu (jeton %s), envoi du pong", jeton)
                my_jeedom_com.add_changes('pont::pong', jeton)
            else:
                logging.warning("Commande inconnue recue sur le socket")
        except Exception as e:
            logging.error('Erreur de traitement du message recu sur le socket: %s', e)


def listen():
    my_jeedom_socket.open()
    try:
        while 1:
            time.sleep(0.5)
            read_socket()
    except KeyboardInterrupt:
        shutdown()


def handler(signum=None, frame=None):
    logging.debug("Signal %i recu, arret en cours...", int(signum))
    shutdown()


def shutdown():
    logging.debug("Arret du demon")
    logging.debug("Suppression du fichier PID %s", _pidfile)
    if os.path.exists(_pidfile):
        try:
            os.remove(_pidfile)
        except Exception as e:
            logging.warning('Erreur lors de la suppression du fichier PID: %s', e)
    if 'my_jeedom_socket' in globals():
        try:
            my_jeedom_socket.close()
        except Exception as e:
            logging.warning('Erreur lors de la fermeture du socket: %s', e)
    logging.debug("Fin (code 0)")
    sys.stdout.flush()
    os._exit(0)


_log_level = "error"
_socket_port = 55112
_socket_host = '127.0.0.1'
_pidfile = '/tmp/smartclim_demon.pid'
_apikey = ''
_callback = ''
_cycle = 0.3

parser = argparse.ArgumentParser(description='Demon SmartClim pour Jeedom')
parser.add_argument("--loglevel", help="Niveau de journalisation du demon", type=str)
parser.add_argument("--callback", help="URL de rappel", type=str)
parser.add_argument("--apikey", help="Cle d'API", type=str)
parser.add_argument("--cycle", help="Cycle d'envoi des evenements", type=float)
parser.add_argument("--pid", help="Fichier PID", type=str)
parser.add_argument("--socketport", help="Port d'ecoute du socket", type=int)
args = parser.parse_args()

if args.loglevel:
    _log_level = args.loglevel
if args.callback:
    _callback = args.callback
if args.apikey:
    _apikey = args.apikey
if args.pid:
    _pidfile = args.pid
if args.cycle:
    _cycle = float(args.cycle)
if args.socketport:
    _socket_port = args.socketport

_socket_port = int(_socket_port)

jeedom_utils.set_log_level(_log_level)

logging.info('Demarrage du demon SmartClim')
logging.info('Niveau de journalisation: %s', _log_level)
logging.info('Port du socket: %s', _socket_port)
logging.info('Hote du socket: %s', _socket_host)
logging.info('Fichier PID: %s', _pidfile)

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    my_jeedom_com = jeedom_com(apikey=_apikey, url=_callback, cycle=_cycle)
    if not my_jeedom_com.test():
        logging.error('Probleme de communication reseau. Verifiez la configuration reseau de Jeedom.')
        shutdown()
    my_jeedom_socket = jeedom_socket(port=_socket_port, address=_socket_host)
    listen()
except Exception as e:
    logging.error('Erreur fatale: %s', e)
    logging.info(traceback.format_exc())
    shutdown()
