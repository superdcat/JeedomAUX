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
#   (a) suppression de "import serial", "import pyudev", de la classe
#       jeedom_serial et de jeedom_utils.find_tty_usb() : aucun materiel
#       serie n'est en jeu ici, et cela evite de declarer pyserial/pyudev
#       dans packages.json ;
#   (b) dans jeedom_com.__post_change() et .test(), le message d'exception
#       journalise est neutralise (l'apikey ne doit jamais atteindre un
#       fichier de journal) ;
#   (c) jeedom_utils.stripped() est conservee pour rester au plus pres de
#       l'amont, mais annotee : elle est cassee sur les entrees "bytes" que
#       lui passe read_socket() (TypeError) et ne doit plus etre reemployee
#       sur ce chemin (cf. smartclimd.py).
#   (d) jeedom_socket_handler.handle() ne journalise plus la CHARGE BRUTE du
#       message recu (6e point de fuite de l'apikey, trouve en revue croisee
#       UC02 : smartclimDemon::envoyer() ajoute inconditionnellement l'apikey
#       au message avant de l'ecrire sur le socket) - seule la LONGUEUR en
#       octets est journalisee ;
#   (e) jeedom_socket_handler porte desormais un timeout de connexion et une
#       taille maximale de ligne lue (readline(TAILLE_MAX_MESSAGE)) : sans
#       cela, un client local qui ouvre la connexion sans jamais envoyer de
#       "\n" bloque indefiniment l'unique thread du TCPServer non threade
#       (DoS local silencieux, trouve en revue croisee UC02). Symptome sans le
#       correctif : le pont cesse de repondre aux "ping" sans que le demon ne
#       meure ni ne journalise quoi que ce soit, et etat() continue d'afficher
#       un demon en pleine forme - donc un incident invisible depuis le
#       panneau "Demon". Voir spec technique, section 9 R13.
# ---------------------------------------------------------------------------

import time
import logging
from threading import Thread
import requests
from collections.abc import Mapping
import os
from queue import Queue
import socketserver
from socketserver import (TCPServer, StreamRequestHandler)
import unicodedata


class jeedom_com():
    def __init__(self, apikey='', url='', cycle=0.5, retry=3):
        self._apikey = apikey
        self._url = url
        self._cycle = cycle
        self._retry = retry
        self._changes = {}
        if self._cycle > 0:
            Thread(target=self.__thread_changes_async, daemon=True).start()
        logging.info('Init request module v%s', requests.__version__)

    def __thread_changes_async(self):
        if self._cycle <= 0:
            return
        logging.info('Start changes async thread')
        while True:
            try:
                time.sleep(self._cycle)
                if len(self._changes) == 0:
                    continue
                changes = self._changes
                self._changes = {}
                self.__post_change(changes)
            except Exception as error:
                logging.error('Critical error on send_changes_async %s', error)

    def add_changes(self, key: str, value):
        if key.find('::') != -1:
            tmp_changes = {}
            changes = value
            for k in reversed(key.split('::')):
                if k not in tmp_changes:
                    tmp_changes[k] = {}
                tmp_changes[k] = changes
                changes = tmp_changes
                tmp_changes = {}
            if self._cycle <= 0:
                self.send_change_immediate(changes)
            else:
                self.merge_dict(self._changes, changes)
        else:
            if self._cycle <= 0:
                self.send_change_immediate({key: value})
            else:
                self._changes[key] = value

    def send_change_immediate(self, change):
        Thread(target=self.__post_change, args=(change,)).start()

    def __post_change(self, change):
        logging.debug('Send to jeedom: %s', change)
        for i in range(self._retry):
            try:
                r = requests.post(self._url + '?apikey=' + self._apikey, json=change, timeout=(0.5, 120), verify=False)
                if r.status_code == requests.codes.ok:
                    return True
                else:
                    logging.warning('Error on send request to jeedom, return code %s', r.status_code)
            except Exception as error:
                logging.error('Error on send request to jeedom "%s" retry: %i/%i', str(error).replace(self._apikey, '***'), i, self._retry)
            time.sleep(0.5)
        return False

    def set_change(self, changes):
        self._changes = changes

    def get_change(self):
        return self._changes

    def merge_dict(self, d1, d2):
        for k, v2 in d2.items():
            v1 = d1.get(k)  # returns None if v1 has no value for this key
            if isinstance(v1, Mapping) and isinstance(v2, Mapping):
                self.merge_dict(v1, v2)
            else:
                d1[k] = v2

    def test(self):
        try:
            response = requests.get(self._url + '?apikey=' + self._apikey, verify=False)
            if response.status_code != requests.codes.ok:
                logging.error('Callback error: %s %s. Please check your network configuration page', response.status_code, response.reason)
                return False
        except Exception as e:
            logging.error('Callback result as a unknown error: %s. Please check your network configuration page', str(e).replace(self._apikey, '***'))
            return False
        return True


class jeedom_utils():

    @staticmethod
    def convert_log_level(level='error'):
        LEVELS = {
            'debug': logging.DEBUG,
            'info': logging.INFO,
            'notice': logging.WARNING,
            'warning': logging.WARNING,
            'error': logging.ERROR,
            'critical': logging.CRITICAL,
            'none': logging.CRITICAL
            }
        return LEVELS.get(level, logging.CRITICAL)

    @staticmethod
    def set_log_level(level='error'):
        FORMAT = '[%(asctime)-15s][%(levelname)s] : %(message)s'
        logging.basicConfig(level=jeedom_utils.convert_log_level(level), format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")

    @staticmethod
    def stripped(str):
        # ATTENTION : cassee sur une entree "bytes" (comme celle empilee par
        # jeedom_socket_handler.handle()) - leve TypeError. Conservee pour
        # rester proche de l'amont mais volontairement non reemployee sur le
        # chemin de lecture du socket (cf. smartclimd.read_socket()).
        return "".join([i for i in str if i in range(32, 127)])

    @staticmethod
    def ByteToHex(byteStr: bytes):
        return byteStr.hex()

    @staticmethod
    def dec2bin(x, width=8):
        return ''.join(str((x >> i) & 1) for i in range(width-1, -1, -1))

    @staticmethod
    def dec2hex(dec):
        if dec is None:
            return '0x00'
        return "0x{:02X}".format(dec)

    @staticmethod
    def testBit(int_type, offset):
        mask = 1 << offset
        return (int_type & mask)

    @staticmethod
    def clearBit(int_type, offset):
        mask = ~(1 << offset)
        return (int_type & mask)

    @staticmethod
    def split_len(seq, length):
        return [seq[i:i+length] for i in range(0, len(seq), length)]

    @staticmethod
    def write_pid(path):
        pid = str(os.getpid())
        logging.info("Writing PID %s to %s", pid, path)
        open(path, 'w').write("%s\n" % pid)

    @staticmethod
    def remove_accents(input_str: str):
        nkfd_form = unicodedata.normalize('NFKD', input_str)
        return u"".join([c for c in nkfd_form if not unicodedata.combining(c)])

    @staticmethod
    def printHex(hex):
        return ' '.join([hex[i:i + 2] for i in range(0, len(hex), 2)])


JEEDOM_SOCKET_MESSAGE = Queue()

# Taille maximale (octets) d'une ligne lue sur le socket local (divergence (e), voir
# en-tete de fichier) : largement au-dessus d'un message JSON de commande, mais borne
# la memoire face a un client local qui n'enverrait jamais de "\n".
TAILLE_MAX_MESSAGE = 65536


class jeedom_socket_handler(StreamRequestHandler):
    # Applique automatiquement par StreamRequestHandler.setup() (self.connection.settimeout).
    # Sans cela, un client local qui ouvre la connexion sans jamais ecrire bloquerait
    # indefiniment l'unique thread du TCPServer non threade (divergence (e)).
    timeout = 5

    def handle(self):
        global JEEDOM_SOCKET_MESSAGE
        logging.info("Client connected to [%s:%d]", self.client_address[0], self.client_address[1])
        try:
            lg = self.rfile.readline(TAILLE_MAX_MESSAGE)
        except Exception as e:
            logging.warning("Erreur ou timeout de lecture sur le socket: %s", e)
            return
        if not lg.endswith(b'\n'):
            logging.warning("Message recu sur le socket rejete (ligne trop longue ou non terminee)")
            return
        JEEDOM_SOCKET_MESSAGE.put(lg)
        # (d) : JAMAIS la charge brute (contient l'apikey ajoutee par
        # smartclimDemon::envoyer()) - seule la longueur est journalisee.
        logging.info("Message recu du pont (%d octets)", len(lg))
        self.netAdapterClientConnected = False
        logging.info("Client disconnected from [%s:%d]", self.client_address[0], self.client_address[1])


class jeedom_socket():

    def __init__(self, address='localhost', port=55000):
        self.address = address
        self.port = port
        socketserver.TCPServer.allow_reuse_address = True

    def open(self):
        self.netAdapter = TCPServer((self.address, self.port), jeedom_socket_handler)
        if self.netAdapter:
            logging.info("Socket interface started")
            Thread(target=self.loopNetServer).start()
        else:
            logging.info("Cannot start socket interface")

    def loopNetServer(self):
        logging.info("LoopNetServer Thread started")
        logging.info("Listening on: [%s:%d]", self.address, self.port)
        self.netAdapter.serve_forever()
        logging.info("LoopNetServer Thread stopped")

    def close(self):
        self.netAdapter.shutdown()

    def getMessage(self):
        return self.message
