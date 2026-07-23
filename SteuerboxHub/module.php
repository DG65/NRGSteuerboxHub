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

    // Geplanter Vertrag für das EMS (Referenz-Signatur, noch nicht befüllt):
    //   [
    //     'dimmActive' => bool,   // liegt gerade ein Dimm-/Steuerbefehl an?
    //     'pMax'       => float,  // erlaubte Maximalleistung in W (z. B. 4200)
    //     'since'      => int,    // Unix-Zeitpunkt, seit wann der Zustand gilt
    //     'source'     => string, // Quelle/Transport ('contact' | 'eebus' | ...)
    //   ]
    // Immer hinter function_exists('SBH_GetState') beim Aufrufer (EMS).
    public function GetState(): array
    {
        return [
            'dimmActive' => false,
            'pMax'       => 0.0,
            'since'      => 0,
            'source'     => '',
        ];
    }
}
