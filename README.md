# SteuerboxHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.1.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Modul zur Erfassung des Dimm-/Steuersignals des Netzbetreibers nach **§14a EnWG**
(Steuerung steuerbarer Verbrauchseinrichtungen) und Bereitstellung an ein EMS als Constraint
oberster Priorität.

**Status: Gerüst.** Die konkrete Signal-Erfassung ist noch nicht implementiert — die
Steuerbox-Hardware existiert noch nicht. Das Repo reserviert Name/Prefix (`SBH`) und
dokumentiert den geplanten Vertrag.

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

## Geplanter Vertrag `SBH_GetState($id): array`

| Feld | Typ | Bedeutung |
|---|---|---|
| `dimmActive` | bool | Liegt gerade ein Dimm-/Steuerbefehl an? |
| `pMax` | float | Erlaubte Maximalleistung in W (z. B. 4200) |
| `since` | int | Unix-Zeitpunkt, seit wann der Zustand gilt |
| `source` | string | Quelle/Transport (`contact` \| `eebus` \| …) |

Beim Aufrufer (EMS) stets hinter `function_exists('SBH_GetState')`.

**Hinweis zur Abgrenzung:** Zeitvariable Netzentgelte nach **Modul 3** sind KEIN
Steuerbox-Thema, sondern reine Preisinformation → sie gehören in die Preiskurven-/Tarif-
Verträge (TibberGridRewards), nicht hierher.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
