# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.3.1] - 2026-10-27

### Fixed
- EEBUS ohne Anbindung oder Gateway: keine Begrenzung mehr aus einem früher gespeicherten
  Zustand (live: Ausfallgrenze 13,02 kW bei Status 104 „nicht konfiguriert“).
- Anmeldung an einem anderen Gateway verwirft Grenzen, Ausfallwerte und Ausfallzustand
  der früheren Steuerbox.
- Formular: PV-Leistung kurz beschriftet, Einheit kWp statt kW.

## [0.3.0] - 2026-10-27

### Added
- Formular nach NRG-Stack-Konvention: „Wozu dieses Modul?“, „Neu bis Version“ (`NEWS_VERSIONS`),
  Rückmeldungen, „Über dieses Modul“.
- Neues Modul **SteuerboxHubEEBUS** („NRG-Stack SteuerboxHub EEBUS-Anbindung“, Präfix `SBHE`):
  hängt am EEBUS-Gateway und reicht Anfragen (`SBHE_Forward`) und Ereignisse
  (`SBH_ProcessEEBus`) durch. SteuerboxHub wählt die Anbindung im Abschnitt EEBUS
  (automatisch, wenn es genau eine gibt) oder legt sie per Knopf an.

### Removed
- `parentRequirements`/`implemented` an SteuerboxHub: jede Instanz auf dem Kontakte-Weg zeigte
  sonst den Balken „benötigt eine übergeordnete Instanz“ (live 27.10.2026).

### Changed
- EEBUS: Bezugsgröße der Einspeisegrenze ist die installierte PV-Leistung (kWp), dieselbe Größe,
  mit der das EMS den Prozentwert in Watt zurückrechnet (live geprüft: 3000 W → 32,7 % → 3002 W).
- GZF-Rechenhilfe meldet das Ergebnis und erinnert an „Übernehmen“; `SBH_CalculateGZF` ohne
  Standardwerte (Stolperstein 8).
- Nur noch ein Anzeigename im Anlege-Dialog („NRG-Stack SteuerboxHub“).

## [0.2.0] - 2026-10-27

### Added
- Transportweg **EEBUS** (`source: 'eebus'`): SteuerboxHub hängt als Kind-Instanz am NRG-Stack
  EEBUS-Gateway (Kind-Schnittstelle 1.0) und meldet sich als Entität mit LPC und LPP
  (Rolle ControllableSystem, Szenarien 1–4) an. Liefert Grenzbeschreibung, aktuelle Grenzen,
  Ausfallwerte und Nennleistungen; Schreibbefehle (Bezug, Einspeisung, Befristung) und
  Heartbeat-Ausfall erscheinen in `SBH_GetState` (Vertrag 1.0 unverändert).
- Ausfallzustand nach LPC/LPP: über 120 s kein Lebenszeichen der Steuerbox → Ausfallgrenzen,
  Ende durch neuen Grenzwert oder nach der Mindestdauer (2–24 h). Vorgabe der Ausfallgrenze
  Bezug ist Pmin nach §14a.
- Formular: Transportweg wählbar, EEBUS-Panel mit Live-Statuszeilen, Pmin-Panel für beide Wege;
  Statuscodes 203 (Anmeldung abgelehnt), 204 (Steuerbox nicht erreichbar), 210 (wartet auf
  erste Verbindung).
- Prüfstand `.tools/test-eebus.php` (Attrappe, optional `--gateway <Pfad>` am echten Gateway-Code).

## [0.1.0] - 2026-07-23

### Added
- Repo-Gerüst angelegt: Modul-Skelett (`SteuerboxHub/module.php`, `module.json`),
  `library.json`, README mit dem geplanten `SBH_GetState`-Vertrag, LICENSE, CLAUDE.md,
  `.tools/check-standalone.php`
- Noch keine Signalerfassung implementiert (Steuerbox-Hardware existiert noch nicht)
