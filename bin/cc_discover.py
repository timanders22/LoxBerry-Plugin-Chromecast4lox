#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Chromecast 4 Lox NG - Geraetesuche

Sucht Chromecasts im Netz und gibt sie als Klartexttabelle aus. Wird vom
Reiter Test aufgerufen. Der ausgegebene Name ist genau der, der in die
Einstellungen gehoert.
"""

import json
import sys

TIMEOUT = 10

# Mit --ohne-gruppen bleiben Google-Lautsprechergruppen aus der Liste. Die
# Oberflaeche setzt den Schalter, wenn der Haken "Google-Lautsprechergruppen
# mitsuchen" aus ist - sonst zeigte die Suche etwas an, das der Dienst
# anschliessend abweist.
OHNE_GRUPPEN = "--ohne-gruppen" in sys.argv[1:]

# Mit --json antwortet die Suche maschinenlesbar. Die Oberflaeche baut
# daraus die Liste zum Anhaken - abgetippte Namen waren die haeufigste
# Fehlerursache dieses Plugins.
ALS_JSON = "--json" in sys.argv[1:]


def _adresse(cast):
    """Adresse eines Geraets - auf beiden pychromecast-Fassungen.

    cast_info gibt es erst ab pychromecast 10. Debian Bookworm und Trixie
    liefern 9.4.0; dort steht die Adresse in der Property uri
    ("host:port"), gebildet aus dem socket_client. Ohne diesen Rueckfall
    stand in der Spalte auf jedem LoxBerry ein "?".
    """
    info = getattr(cast, "cast_info", None)
    host = getattr(info, "host", "") if info else ""
    port = getattr(info, "port", "") if info else ""
    if host:
        return "{0}:{1}".format(host, port)
    return str(getattr(cast, "uri", "") or "?")


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

        if OHNE_GRUPPEN:
            gefunden = [c for c in gefunden
                        if str(getattr(c, "cast_type", "")).lower() != "group"]
            if ALS_JSON:
                pass
            elif not gefunden:
                print("Gefunden wurden nur Lautsprechergruppen, und die sind")
                print("in den Einstellungen abgeschaltet.")
                return 0
        if ALS_JSON:
            print(json.dumps([{
                "name": c.name or "",
                "modell": str(getattr(c, "model_name", "") or ""),
                "adresse": _adresse(c),
                "art": str(getattr(c, "cast_type", "") or ""),
            } for c in gefunden], ensure_ascii=False))
            return 0
        print("{0} Geraet(e) gefunden. Der Name in der ersten Spalte gehoert".format(len(gefunden)))
        print("genau so in die Einstellungen.")
        print()
        print("{0:<28} {1:<22} {2:<21} {3}".format("Name", "Modell", "Adresse", "Art"))
        print("-" * 82)
        for cast in gefunden:
            if OHNE_GRUPPEN and str(getattr(cast, "cast_type", "")).lower() == "group":
                continue
            adresse = _adresse(cast)
            print("{0:<28} {1:<22} {2:<21} {3}".format(
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
