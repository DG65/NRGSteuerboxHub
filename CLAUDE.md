# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds (DG65-Energie-Suite), an mehreren wird teils gleichzeitig in
getrennten Sitzungen gearbeitet:

- **SteuerboxHub** (dieses Repo): §14a-EnWG-Dimmsignal des Netzbetreibers — https://github.com/DG65/SteuerboxHub
- **EMS**: koordinierende Instanz, einziger zulässiger Konsument der Steuer-Verträge — https://github.com/DG65/EMS
- **InverterHub / MeterHub / ChargerHub**: Geräte-Hubs (Modbus TCP)
- **HeishaMon**: Wärmepumpe (liefert §14a-Stellhebel: Heizstab sperren, Quiet Mode)
- **TibberGridRewards**: Preiskurve/Tarif (Modul-3-Netzentgelte gehören DORTHIN, nicht hierher)

## Rolle und Grundregeln

1. **Nur Signalquelle, kein Regler.** SteuerboxHub erfasst den Dimm-/Steuerbefehl und
   publiziert ihn über `SBH_GetState`. Es setzt NICHTS selbst durch — das EMS verteilt die
   Sollwerte. So bleibt „ein Regler pro Stellgröße" gewahrt.
2. **Höchste Priorität beim Konsumenten.** Das EMS behandelt das Signal als Constraint
   oberster Priorität: `Gesetz/Netzbetreiber > Vermarkter > EMS-Optimierung > Komfort`.
3. **Eigenständigkeit.** Kein Modul darf ein anderes voraussetzen; jeder Fremdaufruf hinter
   `function_exists()`. Prüfwerkzeug: `.tools/check-standalone.php`.
4. **Suchrichtung nur vom EMS aus.** SteuerboxHub sucht nie nach dem EMS.
5. **Ein veröffentlichter Vertrag wird nicht umbenannt** — Feldnamen von `SBH_GetState` und
   Idents sind API.
6. **Sprachregel:** alles Nutzersichtbare auf Deutsch, keine vermeidbaren Anglizismen; Idents,
   Property-/Methodennamen und feststehende Fachbegriffe (EEBus, §14a EnWG) ausgenommen.
7. **Store-/Stable-Regeln von Anfang an** (aus Verbund-Reviews bekannt): Schaltflächen nur per
   `UpdateFormField` (nie `IPS_SetProperty`+`ApplyChanges` selbst), `vendor=""`, `library.json`
   `url` Pflicht, keine Emojis in Anzeigetexten, Klassenname = Modulname.

## Transportwege (geplant, noch nicht implementiert)

- **Potentialfreie Kontakte** über ein IPS-I/O-Modul/GPIO — der simple, wahrscheinlich erste
  Weg (Rundsteuer-/Schaltkontakt der Steuerbox).
- **EEBus** — aufwendiger eigener Stack; erst wenn eine EEBus-fähige Steuerbox vorliegt. Dann
  ist ggf. eine eigene Entwicklungssitzung sinnvoll.

## Abgrenzung: Modul 3 gehört NICHT hierher

Zeitvariable Netzentgelte nach Modul 3 (Hoch-/Standard-/Niedrigtarif) sind reine
Preisinformation und gehören in die Tarif-/Preiskurven-Verträge von TibberGridRewards. Nur die
tatsächliche Dimmung/Leistungsbegrenzung (§14a-Steuerbefehl) ist Sache dieses Moduls.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — Prüflogik in allen Hub-Repos gleich halten.
