<?php

// ===========================================================================
// SteuerboxHub — erfasst das Dimm-/Steuersignal des Netzbetreibers nach
// §14a EnWG (Steuerung steuerbarer Verbrauchseinrichtungen) und stellt es dem
// EMS als Constraint OBERSTER Priorität bereit.
//
// Rollenteilung im Verbund (bewusst so, siehe CLAUDE.md):
//   SteuerboxHub  — erfasst NUR das Signal und publiziert es sauber. Setzt
//                   selbst NICHTS durch (kein zweiter Regler).
//   EMS           — konsumiert das Signal (SBH_GetState) als höchste Priorität
//                   und verteilt die resultierenden Sollwerte an die Hubs.
//   Hubs          — bieten Stellhebel, führen je Gerät aus.
//
// Warum eigenes Modul (nicht ins EMS integriert): §14a ist eine Rechtspflicht,
// die auch greifen/nachweisbar sein muss, wenn das EMS deaktiviert/defekt ist
// (Eigenständigkeitsregel). Zudem eigener Hardware-Transport — potentialfreie
// Kontakte (IO-Modul/GPIO) ODER EEBus.
//
// STATUS: Gerüst. Die konkrete Signal-Erfassung (Kontakte vs. EEBus) ist noch
// nicht implementiert — die Steuerbox-Hardware existiert noch nicht. Dieses
// Modul reserviert Name/Prefix (SBH) und dokumentiert den geplanten Vertrag
// SBH_GetState, damit die Partnermodule ihre §14a-Stellhebel dagegen
// beschreiben können.
// ===========================================================================

class SteuerboxHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Transportweg: 0 = potentialfreie Kontakte (noch nicht implementiert),
        // 1 = EEBus (noch nicht implementiert).
        $this->RegisterPropertyInteger('Transport', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Noch keine Signalerfassung — Gerüst.
        $this->SetStatus(104);
    }

    // Final freigegebener Vertrag für das EMS (Dietmar, 24.07.2026), Referenz-Signatur,
    // Werte noch nicht befüllt (Signalerfassung fehlt, siehe Klassenkommentar oben).
    // Zwei unabhängige Achsen, weil eine §14a-Steuerbox BEIDES überträgt:
    //   - Lastseite: Begrenzung steuerbarer Verbrauchseinrichtungen (Wallbox, WP, Speicher).
    //   - Erzeugungsseite: Reduktion der PV-Einspeisung (0 % = keine Einspeisung erlaubt,
    //     Eigenverbrauch/Speicherladung bleiben unberührt — KEIN Abschalten der Anlage).
    // Verteilung auf Einzelgeräte (inkl. Gleichzeitigkeitsfaktor-Formel der BNetzA bei
    // mehreren steuerbaren Verbrauchern) ist Aufgabe des EMS, nicht dieses Moduls.
    //   [
    //     'contractVersion'    => '1.0',
    //     'source'             => string, // 'contacts' | 'eebus' — tatsächlicher Transport;
    //                                      // Protokolldetails/Wertebereich kapselt dieses
    //                                      // Modul intern, sind nicht Teil des Vertrags
    //     'loadDimmActive'     => bool,
    //     'loadPMin'           => float,  // kW, netzwirksamer Leistungsbezug lt. §14a;
    //                                      // bei 'contacts' vom Installateur in der Instanz
    //                                      // konfiguriert (Hardware liefert keinen Zahlenwert)
    //     'loadSince'          => int,    // Unix-Zeitpunkt, selbst geführt
    //     'feedInDimmActive'   => bool,
    //     'feedInLimitPercent' => float,  // 0–100 % der Einspeiseleistung
    //     'feedInSince'        => int,    // Unix-Zeitpunkt, selbst geführt
    //   ]
    // Immer hinter function_exists('SBH_GetState') beim Aufrufer (EMS).
    public function GetState(): array
    {
        return [
            'contractVersion'    => '1.0',
            'source'             => '',
            'loadDimmActive'     => false,
            'loadPMin'           => 0.0,
            'loadSince'          => 0,
            'feedInDimmActive'   => false,
            'feedInLimitPercent' => 100.0,
            'feedInSince'        => 0,
        ];
    }
}
