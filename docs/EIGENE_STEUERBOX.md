# Eigenes Steuerbox-Modul an das EMS anbinden

Für Module anderer Autoren, die das §14a-EnWG-Signal des Netzbetreibers liefern (z. B. eine andere
Steuerbox-Anbindung). Das EMS findet solche Module über den Vertrag, wenn sie ihn erfüllen. Ihr Modul
liefert nur das Signal; durchsetzen tut das EMS.

## Was zu tun ist

1. Eine öffentliche Modulfunktion `<PRÄFIX>_GetState(int $InstanceID): array` anbieten, ohne Standardwerte.
2. Folgende Felder liefern (Typen genau einhalten):

| Feld | Typ | Bedeutung |
|---|---|---|
| `contractType` | string | `steuerbox` |
| `contractVersion` | string | `1.1` |
| `source` | string | beliebig, z. B. `ext:<PRÄFIX>` wird vom EMS gesetzt |
| `loadDimmActive` | bool | Bezugsgrenze wirksam |
| `loadPMin` | float | erlaubter Bezug in kW (0 ohne Grenze) |
| `loadSince` | int | Unix-Zeit, seit wann die Bezugsgrenze gilt (0 = keine) |
| `feedInDimmActive` | bool | Einspeisegrenze wirksam |
| `feedInLimitPercent` | float | erlaubte Einspeisung in % der installierten PV-Leistung (100 = unbegrenzt) |
| `feedInSince` | int | Unix-Zeit, seit wann die Einspeisegrenze gilt (0 = keine) |
| `failsafeActive` | bool | Steuerbox unerreichbar, Ausfallgrenze gilt |
| `failsafeLoadPMin` | float | Bezugsgrenze im Ausfall in kW |
| `failsafeFeedInLimitPercent` | float oder null | Einspeisegrenze im Ausfall in %, `null` = unbekannt |
| `failsafeSec` | int | Mindestdauer des Ausfallzustands in s (0 = keiner) |
| `lastSeenAt` | int | Unix-Zeit des letzten Lebenszeichens |

3. Ohne Signal und ohne Ausfall: alles inaktiv, `loadPMin` 0, `feedInLimitPercent` 100.
4. Das Modul bleibt ohne EMS lauffähig; das EMS sucht Sie, nie umgekehrt.

Referenz: `SteuerboxHub/module.php` (Funktion `GetState`).
