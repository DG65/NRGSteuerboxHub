# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds (DG65-Energie-Suite), an mehreren wird teils gleichzeitig in
getrennten Sitzungen gearbeitet:

- **SteuerboxHub** (dieses Repo): §14a-EnWG-Dimmsignal des Netzbetreibers — https://github.com/DG65/SteuerboxHub
- **EMS**: koordinierende Instanz, einziger zulässiger Konsument der Steuer-Verträge — https://github.com/DG65/NRGEMS
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

**Zielweg ist ausschließlich EEBus** (Dietmar, 24.07.2026, aus Branchenkenntnis: Zählermonteure
bestätigen, dass verbaute FREs beim Steuerbox-Einbau zurückgebaut werden — Kontakte sind
Übergangslösung, kein Dauerzustand). Trotzdem **beide Wege bauen**, da andere NRG-Stack-Nutzer
ggf. übergangsweise oder dauerhaft nur Kontakte haben (auch per Modbus-Digitalisierer wie
ADAM-Module/Shellys, falls versehentlich eine reine Kontakt-Steuerbox verbaut wurde):

- **Potentialfreie Kontakte** über ein IPS-I/O-Modul/GPIO oder Modbus-Digitalisierer.
  Klassisches RCR-Schema (0/30/60/100 %) — bei der §14a-Variante ersetzt das §14a-Signal die
  100 %-Meldung (kein Signal bei 0/30/60 % → 100 % = keine Abregelung). Der eigentliche
  Zielwert (`loadPMin`) steht NICHT im Signal, sondern wird vom Installateur/Nutzer in der
  Instanz konfiguriert (inkl. von Hand berechnetem Gleichzeitigkeitsfaktor bei mehreren
  Verbrauchern am selben Kontakt — dafür braucht die Instanz eine Rechenhilfe/Formularfeld).
- **EEBus** — löst das Verkabelungsproblem (keine Steuerleitung bis zu Charger/WP/WR nötig),
  liefert kontinuierliche Werte statt Stufen, UND überträgt zusätzlich Reduktionsbefehle für
  die PV-Einspeisung (nicht nur für Verbraucher) — daher die zwei Achsen in `SBH_GetState`
  (`load*`/`feedIn*`). EEBus-Grundlagen: SPINE (Datenmodell, Use Case „LPC" = Limitation of
  Power Consumption ist der einschlägige §14a-Fall) + SHIP (gesichertes IP-Transportprotokoll).
  **Bewusst vage gehalten im Vertrag:** EEBus gilt als komplex und uneinheitlich implementiert
  (Gerätezertifizierung erst seit Herbst 2025, bei Wallboxen dominiert eher ISO 15118, EEBus
  eher bei Wärmepumpen) — welche konkreten Werte/Wertebereiche/Nachrichten die tatsächlich
  verbaute Steuerbox (Ziel: SELEXA) liefert, muss dieses Modul beim Bau selbst herausfinden;
  das ist NICHT Teil des `SBH_GetState`-Vertrags, sondern interne Übersetzungsarbeit.

## Abgrenzung: Modul 3 gehört NICHT hierher

Zeitvariable Netzentgelte nach Modul 3 (Hoch-/Standard-/Niedrigtarif) sind reine
Preisinformation und gehören in die Tarif-/Preiskurven-Verträge von TibberGridRewards. Nur die
tatsächliche Dimmung/Leistungsbegrenzung (§14a-Steuerbefehl) ist Sache dieses Moduls.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — Prüflogik in allen Hub-Repos gleich halten.


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
