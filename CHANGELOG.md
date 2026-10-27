# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

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
