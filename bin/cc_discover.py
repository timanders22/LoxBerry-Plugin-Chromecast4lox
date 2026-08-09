#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Chromecast 4 Lox NG - Geraetesuche

Sucht Chromecasts im Netz und gibt sie als Klartexttabelle aus. Wird vom
Reiter Test aufgerufen. Der ausgegebene Name ist genau der, der in die
Einstellungen gehoert.
"""

import sys

TIMEOUT = 10


def main():
    try:
        import pychromecast
    except ImportError:
        print("pychromecast ist nicht installiert.")
        print()
        print("Nachholen mit:  sudo apt-get install -y python3-pychromecast")
        return 1

    try:
        gefunden, browser = pychromecast.get_chromecasts(timeout=TIMEOUT)
    except Exception as fehler:  # noqa: BLE001
        print("Suche fehlgeschlagen: {0}".format(fehler))
        return 1

    try:
        if not gefunden:
            print("Kein Chromecast gefunden.")
            print()
            print("Moegliche Gruende:")
            print("  - Der LoxBerry haengt in einem anderen Netz oder VLAN als der")
            print("    Chromecast. Die Suche laeuft ueber mDNS und geht nicht ueber")
            print("    Netzgrenzen.")
            print("  - Der Chromecast ist stromlos oder gerade neu gestartet.")
            print("  - Eine Firewall blockiert UDP 5353.")
            return 0

        print("{0} Geraet(e) gefunden. Der Name in der ersten Spalte gehoert".format(len(gefunden)))
        print("genau so in die Einstellungen.")
        print()
        print("{0:<28} {1:<22} {2:<16} {3}".format("Name", "Modell", "Adresse", "Art"))
        print("-" * 82)
        for cast in gefunden:
            info = getattr(cast, "cast_info", None)
            host = getattr(info, "host", "") if info else ""
            port = getattr(info, "port", "") if info else ""
            adresse = "{0}:{1}".format(host, port) if host else "?"
            print("{0:<28} {1:<22} {2:<16} {3}".format(
                cast.name or "?",
                getattr(cast, "model_name", "") or "?",
                adresse,
                getattr(cast, "cast_type", "") or "?",
            ))
    finally:
        try:
            browser.stop_discovery()
        except Exception:  # noqa: BLE001
            pass

    return 0


if __name__ == "__main__":
    sys.exit(main())
