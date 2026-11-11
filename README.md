# SteuerboxHub

🇩🇪 Deutsch · 🇬🇧 [English](README.en.md)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.6.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSteuerboxHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSteuerboxHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Symcon-Modul zur Erfassung des Dimm-/Steuersignals des Netzbetreibers nach **§14a EnWG**
(Steuerung steuerbarer Verbrauchseinrichtungen) und Bereitstellung an ein EMS als Constraint
oberster Priorität.

Zwei Module in einer Bibliothek, je ein Transportweg:

- **NRG-Stack SteuerboxHub** (Kontakte, Präfix `SBH`): potentialfreie Kontakte
  (Rundsteuerempfänger-Schema, FNN-Dreistufenschema W3/S1/S2) über bestehende Symcon-Variablen eines
  I/O-Moduls oder Digitalisierers. Der Zielwert Pmin wird in der Instanz eingestellt (mit
  Gleichzeitigkeitsfaktor-Rechenhilfe). Vertrag `SBH_GetState`.
- **NRG-Stack SteuerboxHub EEBUS** (Präfix `SBHE`, ab 0.6.0 ein eigener Steuerhub): hängt direkt am
  **NRG-Stack EEBUS-Gateway** und meldet sich über den Datenfluss mit den Anwendungsfällen LPC und LPP
  (Begrenzung von Bezug und Einspeisung) an. Die Steuerbox schreibt Grenzen in Watt, auf Wunsch
  befristet. Fehlt ihr Lebenszeichen länger als 120 s, gelten die Ausfallgrenzen, bis sie einen neuen
  Grenzwert schickt oder die Mindestdauer (2 bis 24 h) abgelaufen ist. Vertrag `SBHE_GetState` (gleiche
  Felder). Bisher geprüft gegen den Steuerbox-Simulator `controlbox` aus eebus-go (über das Gateway),
  noch nicht mit einer echten Steuerbox.

Das Gemeinsame (Formular, Pmin-Rechenhilfe, Zahlen- und Zeitformat) steht in einer Oberklasse
(`libs/SteuerboxHubBase.php`), von der beide Module erben. Bis 0.5.0 gab es statt des EEBUS-Hubs eine
Brücke zwischen Gateway und SteuerboxHub; der Store-Review vom 11.11.2026 wollte den Datenfluss statt
öffentlicher Funktionen zwischen den Instanzen.

## ## Installation

Über den **Symcon Module Store** in der Verwaltungskonsole: nach **SteuerboxHub** suchen und
installieren. Die Bibliothek bringt zwei Module mit:
„NRG-Stack SteuerboxHub“ (Kontakte) und „NRG-Stack SteuerboxHub EEBUS“.

**Kontakte:** Instanz „NRG-Stack SteuerboxHub“ anlegen, Eingangsvariablen verknüpfen, Pmin einstellen.

**EEBUS:** zusätzlich **NRG-Stack EEBUS-Gateway** aus dem Store installieren (Suchbegriff **EEBUS**).
Danach:

1. Instanz „NRG-Stack EEBUS-Gateway“ anlegen, Adresse und Port der Steuerbox eintragen,
   „Gegenstelle prüfen“, SKI vergleichen und übernehmen; den SKI des Gateways bei der
   Gegenstelle (bzw. beim Messstellenbetreiber) freigeben.
2. Instanz „NRG-Stack SteuerboxHub EEBUS“ anlegen, oben „Gateway ändern“ wählen und das Gateway
   zuordnen, die installierte PV-Leistung (kWp) für die Einspeisegrenze eintragen.
3. Das EMS liest den Zustand mit `SBHE_GetState`.

**Update von 0.5.0 oder früher:** Eine SteuerboxHub-Instanz mit Transportweg EEBUS zeigt jetzt einen
Hinweis und liefert kein Signal. Die frühere Instanz „SteuerboxHub EEBUS-Anbindung“ ist der neue
EEBUS-Hub (gleiche Instanz, Gateway bleibt zugeordnet): dort die Nennwerte neu eintragen und im EMS
auf `SBHE_GetState` umstellen. Die alte SteuerboxHub-Instanz kann auf Kontakte gestellt oder gelöscht
werden.

Fragen, Erfahrungen und Rückmeldungen gern im Forum:
[Beta-Tester gesucht: NRG-Stack EEBUS-Gateway + SteuerboxHub](https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-eebus-gateway-steuerboxhub-14a-steuerbox-per-eebus-in-reinem-php/144795)

## Warum ein eigenes Modul (nicht ins EMS integriert)

1. **Rechtspflicht vor Optimierung:** §14a ist verpflichtend und muss auch greifen/nachweisbar
   sein, wenn das EMS deaktiviert oder defekt ist — Eigenständigkeitsregel des Verbunds.
2. **Eigener Hardware-Transport:** potentialfreie Kontakte (IO-Modul/GPIO) oder EEBus — das ist
   ein Gerätetreiber-Muster wie InverterHub/MeterHub/ChargerHub, kein Optimierungsproblem.
3. **Auditierbarkeit:** ein kleines, isoliertes Modul, das „Dimmbefehl empfangen/aufgehoben"
   mit Zeitstempel publiziert, ist gegenüber dem Netzbetreiber leichter nachzuweisen.

## Rollenteilung

- **SteuerboxHub** erfasst nur das Signal und publiziert es (kein zweiter Regler).
- **EMS** konsumiert es über `SBH_GetState` als höchste Priorität und verteilt die Sollwerte
  an die Hubs (`Gesetz/Netzbetreiber > Vermarkter > EMS-Optimierung > Komfort`).
- **Hubs** bieten Stellhebel und führen je Gerät aus (z. B. HeishaMon: Heizstab sperren + Quiet
  Mode als §14a-Hebel, ChargerHub: Ladepunkt-Budget senken).

## Vertrag `SBH_GetState($id): array` und `SBHE_GetState($id): array` (contractVersion 1.0)

`SBH_GetState` liefert der Kontakte-Hub (`source: contacts`), `SBHE_GetState` der EEBUS-Hub
(`source: eebus`). Beide liefern dieselben Felder:

| Feld | Typ | Bedeutung |
|---|---|---|
| `source` | string | `contacts` oder `eebus` |
| `loadDimmActive` | bool | Bezugsgrenze wirksam |
| `loadPMin` | float | Erlaubter Bezug der steuerbaren Verbraucher in kW (0 ohne Grenze) |
| `loadSince` | int | Unix-Zeitpunkt, seit dem die Bezugsgrenze gilt (0 = keine) |
| `feedInDimmActive` | bool | Einspeisegrenze wirksam |
| `feedInLimitPercent` | float | Erlaubte Einspeisung in % (100 = unbegrenzt); bei EEBUS bezogen auf die eingetragene installierte PV-Leistung (kWp, wie im EMS) |
| `feedInSince` | int | Unix-Zeitpunkt, seit dem die Einspeisegrenze gilt (0 = keine) |

Beim Aufrufer (EMS) stets hinter `function_exists('SBH_GetState')` bzw. `function_exists('SBHE_GetState')`.

**Hinweis zur Abgrenzung:** Zeitvariable Netzentgelte nach **Modul 3** sind KEIN
Steuerbox-Thema, sondern reine Preisinformation → sie gehören in die Preiskurven-/Tarif-
Verträge (TibberGridRewards), nicht hierher.

## Prüfstand

```
php .tools/test-eebus.php                                  # EEBUS-Weg gegen eine Gateway-Attrappe
php .tools/test-eebus.php --gateway /pfad/EEBUSGateway     # zusätzlich am echten Gateway-Code
php .tools/check-standalone.php                            # Eigenständigkeit
```

## Lizenz

PolyForm Noncommercial 1.0.0 (privat/nicht-kommerziell frei, gewerbliche Nutzung
lizenzpflichtig — Kontakt DG65), siehe [LICENSE](LICENSE).
