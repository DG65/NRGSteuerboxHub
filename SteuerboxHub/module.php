<?php

// ===========================================================================
// SteuerboxHub — erfasst das Dimm-/Steuersignal des Netzbetreibers nach
// §14a EnWG (Steuerung steuerbarer Verbrauchseinrichtungen) über potentialfreie
// Kontakte und stellt es dem EMS als Constraint OBERSTER Priorität bereit.
//
// Rollenteilung im Verbund (bewusst so, siehe CLAUDE.md):
//   SteuerboxHub  — erfasst NUR das Signal und publiziert es sauber. Setzt
//                   selbst NICHTS durch (kein zweiter Regler).
//   EMS           — konsumiert das Signal (SBH_GetState) als höchste Priorität
//                   und verteilt die resultierenden Sollwerte an die Hubs.
//   Hubs          — bieten Stellhebel, führen je Gerät aus.
//
// Kontakte: potentialfreie Kontakte (RCR-Schema 0/30/60/100 %, FNN-Dreistufenschema
// W3/S1/S2 für die Einspeisung), Zielwert (loadPMin) wird vom Installateur in der Instanz
// konfiguriert, da die Hardware selbst keinen Zahlenwert liefert.
//
// EEBUS ist seit 0.6.0 ein eigenes Modul (SteuerboxHubEEBUS, Präfix SBHE, hängt direkt
// am NRG-Stack EEBUS-Gateway; Vertrag SBHE_GetState mit denselben Feldern). Grund war
// der Store-Review vom 11.11.2026: Hub und Brücke sprachen über öffentliche Funktionen
// statt über den Datenfluss. Dieses Modul bleibt deshalb ohne Elternteil. Das Gemeinsame
// (Formular-Konvention, Pmin-Rechenhilfe, Zahlen- und Zeitformat) steht in
// libs/SteuerboxHubBase.php. Die Eigenschaften des früheren EEBUS-Wegs bleiben nur
// registriert, damit bestehende Instanzen beim Update keine Eigenschaft verlieren.
// ===========================================================================

require_once __DIR__ . '/../libs/SteuerboxHubBase.php';

class SteuerboxHub extends SteuerboxHubBase
{
    private const NEWS_VERSIONS = [
        '0.4.0' => [
            '• 🪟 New: choose the form layout yourself. In the “Form layout” section (below) you decide for each area whether it is expanded, collapsed or hidden when opened, and where it appears.',
        ],
        '0.5.0' => [
            '• 🌐 New: SteuerboxHub is now also available in English (form, messages, documentation). The language follows the Symcon setting.',
        ],
        '0.6.0' => [
            '• EEBUS has moved into its own module, “NRG-Stack SteuerboxHub EEBUS”. This module now only handles the contacts path. An instance that is still set to EEBUS shows a note and delivers no signal.',
        ],
    ];

    private const FEEDIN_SINGLE_FIELDS = ['FeedInContactVarID', 'FeedInContactInverted', 'FeedInLimitPercent'];
    private const FEEDIN_FNN_FIELDS    = ['FeedInFNNLabel', 'FeedInW3VarID', 'FeedInS1VarID', 'FeedInS2VarID', 'FeedInFNNInverted'];

    protected function modulePrefix(): string
    {
        return 'SBH';
    }

    protected function moduleDir(): string
    {
        return __DIR__;
    }

    protected function newsVersions(): array
    {
        return self::NEWS_VERSIONS;
    }

    protected function purposeParagraphs(): array
    {
        return [
            'SteuerboxHub receives the power limit set by the grid operator under §14a EnWG through the contacts of a control box or ripple control receiver. It reports whether and how strongly consumption and feed-in are currently limited, with the time. EEBUS is handled by the separate module “NRG-Stack SteuerboxHub EEBUS”.',
            'It does not control anything itself. An energy management system has to enforce the limit, e.g. the NRG-Stack EMS (wallboxes, inverters), or your own script via SBH_GetState.',
        ];
    }

    protected function formPanels(): array
    {
        return ['PminPanel' => 'pmin', 'ContactsPanel' => 'contacts'];
    }

    protected function formPanelLabels(): array
    {
        return ['pmin' => 'Minimum power under §14a', 'contacts' => 'Potential-free contacts'];
    }

    public function Create()
    {
        parent::Create();
        $this->registerCommon();

        // --- Kontakte ---
        $this->RegisterPropertyInteger('LoadContactVarID', 0);
        $this->RegisterPropertyBoolean('LoadContactInverted', false);
        // Einspeiseseite: reale FNN-Steuerboxen (Vorbild: evcc-Referenz-Doku
        // "FNN-Steuerbox via GPIO") melden hier NICHT einen einzelnen binären
        // Trigger, sondern DREI separate Kontakte für die drei Stufen des
        // FNN-Vierstufenschemas (0/30/60 %, „alle offen" = 100 %/unbegrenzt):
        // W3 = 0 %, S1 = 60 %, S2 = 30 %. Beide Varianten bleiben wählbar, da
        // manche Digitalisierer/Steuerboxen nur einen einzelnen Trigger liefern.
        $this->RegisterPropertyInteger('FeedInMode', 0); // 0 = Einzelkontakt, 1 = FNN-Dreistufen (W3/S1/S2)
        $this->RegisterPropertyInteger('FeedInContactVarID', 0);
        $this->RegisterPropertyBoolean('FeedInContactInverted', false);
        $this->RegisterPropertyFloat('FeedInLimitPercent', 0.0);
        $this->RegisterPropertyInteger('FeedInW3VarID', 0);
        $this->RegisterPropertyInteger('FeedInS1VarID', 0);
        $this->RegisterPropertyInteger('FeedInS2VarID', 0);
        $this->RegisterPropertyBoolean('FeedInFNNInverted', false);
        // Signalverlust-Wächter: 0 = aus. Bei Überschreitung wird NUR gewarnt,
        // der zuletzt bekannte Zustand bleibt unverändert bestehen (kein
        // automatischer Rückfall auf "uneingeschränkt" — der Netzbetreiber
        // könnte weiter dimmen wollen, nur die Meldung fehlt). Empfehlung
        // OpenEMS Limiter14aImpl: >60 s ohne Signal gilt als kritisch.
        $this->RegisterPropertyInteger('WatchdogTimeout', 60);

        // Altlasten aus 0.2 bis 0.5 (EEBUS im selben Modul): ohne Funktion, bleiben registriert.
        // 0 = Kontakte, 1 = früherer EEBUS-Weg (zeigt nur einen Hinweis, liefert kein Signal).
        $this->RegisterPropertyInteger('Transport', 0);
        $this->RegisterPropertyInteger('EEBusBridgeID', 0);
        $this->RegisterPropertyString('EEBusBridgeURL', '');
        $this->RegisterPropertyString('EEBusSKI', '');
        $this->RegisterPropertyFloat('EEBusConsumptionNominalKw', 0.0);
        $this->RegisterPropertyFloat('EEBusProductionNominalKw', 0.0);
        $this->RegisterPropertyFloat('EEBusFailsafeProductionKw', 0.0);

        // Selbst geführter Zustand (Hardware liefert keinen Zeitstempel).
        $this->RegisterAttributeBoolean('LoadDimmActive', false);
        $this->RegisterAttributeInteger('LoadSince', 0);
        $this->RegisterAttributeBoolean('FeedInDimmActive', false);
        $this->RegisterAttributeInteger('FeedInSince', 0);
        $this->RegisterAttributeInteger('LoadLastSeen', 0);
        $this->RegisterAttributeInteger('FeedInLastSeen', 0);
        $this->RegisterAttributeFloat('FeedInPercent', 100.0); // FeedInMode=1 (FNN-Dreistufen)
        $this->RegisterAttributeString('EEBusState', ''); // Altlast

        $this->RegisterTimer('SBH_Watchdog', 0, 'SBH_CheckWatchdog($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $this->SetTimerInterval('SBH_Watchdog', 0);
        // Altlast: Instanzen aus 0.2 bis 0.5 haben noch den Timer des früheren EEBUS-Wegs.
        if (@IPS_GetObjectIDByIdent('SBH_EEBus', $this->InstanceID)) {
            $this->SetTimerInterval('SBH_EEBus', 0);
        }

        if ($this->ReadPropertyInteger('Transport') !== 0) {
            // Früherer EEBUS-Weg: das Formular zeigt einen Hinweis, kein Signal (Status 104, neutral).
            $this->SetStatus(104);
            return;
        }
        $this->applyContactsTransport();
    }

    /** Altlast: Ein früher angelegter Timer ruft diese Funktion noch auf. EEBUS ist umgezogen, hier passiert nichts mehr. */
    public function CheckEEBus()
    {
    }

    protected function prepareForm(array &$form): void
    {
        $this->applyFeedInVisibility($form['elements'], $this->ReadPropertyInteger('FeedInMode'));
        if ($this->ReadPropertyInteger('Transport') !== 0) {
            array_unshift(
                $form['elements'],
                ['type' => 'Label', 'caption' => '⚠️ This instance is still set to the former EEBUS transport. EEBUS now has its own module, “NRG-Stack SteuerboxHub EEBUS”: create it, attach it to the EEBUS Gateway and read its state with SBHE_GetState. This instance delivers no signal until you switch it to potential-free contacts below or delete it.'],
                ['type' => 'Select', 'name' => 'Transport', 'caption' => 'Transport path', 'options' => [
                    ['caption' => 'Potential-free contacts', 'value' => 0],
                    ['caption' => 'EEBUS (moved to its own module, no function here)', 'value' => 1],
                ]]
            );
        }
    }

    private function applyContactsTransport(): void
    {
        $loadVarID    = $this->ReadPropertyInteger('LoadContactVarID');
        $fnnMode      = $this->ReadPropertyInteger('FeedInMode') === 1;
        $feedInVarID  = $this->ReadPropertyInteger('FeedInContactVarID');
        $feedInW3     = $this->ReadPropertyInteger('FeedInW3VarID');
        $feedInS1     = $this->ReadPropertyInteger('FeedInS1VarID');
        $feedInS2     = $this->ReadPropertyInteger('FeedInS2VarID');
        $feedInWired  = $fnnMode ? ($feedInW3 !== 0 || $feedInS1 !== 0 || $feedInS2 !== 0) : ($feedInVarID !== 0);

        // Achse ohne Eingang: kein Signal (auch kein Rest vom EEBus-Weg in den gemeinsamen Attributen).
        if ($loadVarID === 0) {
            $this->WriteAttributeBoolean('LoadDimmActive', false);
            $this->WriteAttributeInteger('LoadSince', 0);
        }
        if (!$feedInWired) {
            $this->WriteAttributeBoolean('FeedInDimmActive', false);
            $this->WriteAttributeInteger('FeedInSince', 0);
            $this->WriteAttributeFloat('FeedInPercent', 100.0);
        }

        // Noch nichts gewählt: neutral "inaktiv" (104), kein Fehlercode — sonst zählt eine
        // frisch angelegte Instanz im Integrity-Check als Fehler (Checkliste 9d).
        if ($loadVarID === 0 && !$feedInWired) {
            $this->SetStatus(104);
            return;
        }

        // Gewählte Variable existiert nicht mehr: echter Konfigurationsmangel, sichtbar melden.
        foreach ([$loadVarID, $feedInVarID, $feedInW3, $feedInS1, $feedInS2] as $varID) {
            if ($varID !== 0 && !IPS_VariableExists($varID)) {
                $this->SetStatus(202);
                return;
            }
        }

        if ($loadVarID !== 0 && IPS_VariableExists($loadVarID)) {
            $this->RegisterMessage($loadVarID, VM_UPDATE);
            $this->syncContactState('Load', $loadVarID, $this->ReadPropertyBoolean('LoadContactInverted'));
        }

        if ($fnnMode) {
            foreach ([$feedInW3, $feedInS1, $feedInS2] as $varID) {
                if ($varID !== 0 && IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
            $this->syncFeedInFNN();
        } elseif ($feedInVarID !== 0 && IPS_VariableExists($feedInVarID)) {
            $this->RegisterMessage($feedInVarID, VM_UPDATE);
            $this->syncContactState('FeedIn', $feedInVarID, $this->ReadPropertyBoolean('FeedInContactInverted'));
        }

        $timeout = $this->ReadPropertyInteger('WatchdogTimeout');
        if ($timeout > 0) {
            $this->SetTimerInterval('SBH_Watchdog', max(5, intdiv($timeout, 2)) * 1000);
        }

        $this->SetStatus(102);
    }

    private function applyFeedInVisibility(array &$items, int $mode): void
    {
        foreach ($items as &$el) {
            $name = $el['name'] ?? '';
            if (in_array($name, self::FEEDIN_SINGLE_FIELDS, true)) {
                $el['visible'] = ($mode === 0);
            } elseif (in_array($name, self::FEEDIN_FNN_FIELDS, true)) {
                $el['visible'] = ($mode === 1);
            }
            if (isset($el['items'])) {
                $this->applyFeedInVisibility($el['items'], $mode);
            }
        }
        unset($el);
    }

    /** Formular: zeigt nur die Einspeisefelder, die zum gewählten Kontaktschema passen. */
    public function ToggleFeedInMode(int $mode)
    {
        foreach (self::FEEDIN_SINGLE_FIELDS as $name) {
            $this->UpdateFormField($name, 'visible', $mode === 0);
        }
        foreach (self::FEEDIN_FNN_FIELDS as $name) {
            $this->UpdateFormField($name, 'visible', $mode === 1);
        }
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        // Während des Neuladens liefern die SDK-Aufrufe false statt des Typs (Checkliste 9c).
        if ($message !== VM_UPDATE || IPS_GetKernelRunlevel() !== KR_READY || !IPS_InstanceExists($this->InstanceID)) {
            return;
        }

        if ($senderID === $this->ReadPropertyInteger('LoadContactVarID')) {
            $this->syncContactState('Load', $senderID, $this->ReadPropertyBoolean('LoadContactInverted'));
        }

        if ($this->ReadPropertyInteger('FeedInMode') === 1) {
            $fnnVarIDs = [
                $this->ReadPropertyInteger('FeedInW3VarID'),
                $this->ReadPropertyInteger('FeedInS1VarID'),
                $this->ReadPropertyInteger('FeedInS2VarID'),
            ];
            if (in_array($senderID, $fnnVarIDs, true)) {
                $this->syncFeedInFNN();
            }
        } elseif ($senderID === $this->ReadPropertyInteger('FeedInContactVarID')) {
            $this->syncContactState('FeedIn', $senderID, $this->ReadPropertyBoolean('FeedInContactInverted'));
        }
    }

    /**
     * FNN-Dreistufenschema (Vorbild: evcc "FNN-Steuerbox via GPIO"): drei
     * Kontakte W3 (0 %) / S1 (60 %) / S2 (30 %), alle offen = 100 % (unbegrenzt).
     * Niedrigster Prozentwert gewinnt, falls mehrere Kontakte gleichzeitig aktiv sind.
     */
    private function syncFeedInFNN(): void
    {
        $inverted = $this->ReadPropertyBoolean('FeedInFNNInverted');
        $percent  = 100.0;
        $anySeen  = false;

        foreach (['FeedInW3VarID' => 0.0, 'FeedInS2VarID' => 30.0, 'FeedInS1VarID' => 60.0] as $propName => $level) {
            $varID = $this->ReadPropertyInteger($propName);
            if ($varID === 0 || !IPS_VariableExists($varID)) {
                continue;
            }
            $anySeen = true;
            $raw    = (bool) GetValue($varID);
            $active = $inverted ? !$raw : $raw;
            if ($active && $level < $percent) {
                $percent = $level;
            }
        }

        if (!$anySeen) {
            return;
        }

        $active    = $percent < 100.0;
        $wasActive = $this->ReadAttributeBoolean('FeedInDimmActive');

        $this->WriteAttributeFloat('FeedInPercent', $percent);
        $this->WriteAttributeBoolean('FeedInDimmActive', $active);
        $this->WriteAttributeInteger('FeedInLastSeen', time());

        if ($active && !$wasActive) {
            $this->WriteAttributeInteger('FeedInSince', time());
        } elseif (!$active) {
            $this->WriteAttributeInteger('FeedInSince', 0);
        }

        if ($this->GetStatus() === 205) {
            $this->SetStatus(102);
        }
    }

    /** Liest den aktuellen Kontaktzustand und pflegt Active-Flag + Since-Zeitstempel. */
    private function syncContactState(string $axis, int $varID, bool $inverted): void
    {
        $raw    = (bool) GetValue($varID);
        $active = $inverted ? !$raw : $raw;

        $wasActive = $this->ReadAttributeBoolean($axis . 'DimmActive');
        $this->WriteAttributeBoolean($axis . 'DimmActive', $active);
        $this->WriteAttributeInteger($axis . 'LastSeen', time());

        if ($active && !$wasActive) {
            $this->WriteAttributeInteger($axis . 'Since', time());
        } elseif (!$active) {
            $this->WriteAttributeInteger($axis . 'Since', 0);
        }

        if ($this->GetStatus() === 205) {
            $this->SetStatus(102);
        }
    }

    /**
     * Signalverlust-Wächter: warnt nur, ändert NIEMALS DimmActive/PMin — der
     * zuletzt bekannte Zustand bleibt bestehen, bis wieder ein Signal kommt
     * (siehe Registrierung in Create() für die Begründung).
     */
    public function CheckWatchdog()
    {
        $timeout = $this->ReadPropertyInteger('WatchdogTimeout');
        if ($timeout <= 0 || $this->ReadPropertyInteger('Transport') !== 0) {
            return;
        }

        $now   = time();
        $stale = false;

        $fnnMode      = $this->ReadPropertyInteger('FeedInMode') === 1;
        $feedInWired  = $fnnMode
            ? ($this->ReadPropertyInteger('FeedInW3VarID') !== 0 || $this->ReadPropertyInteger('FeedInS1VarID') !== 0 || $this->ReadPropertyInteger('FeedInS2VarID') !== 0)
            : ($this->ReadPropertyInteger('FeedInContactVarID') !== 0);

        foreach (['Load' => $this->ReadPropertyInteger('LoadContactVarID') !== 0, 'FeedIn' => $feedInWired] as $axis => $wired) {
            if (!$wired) {
                continue;
            }
            $lastSeen = $this->ReadAttributeInteger($axis . 'LastSeen');
            if ($lastSeen === 0) {
                continue; // seit ApplyChanges() noch kein einziges Update erhalten
            }
            if ($now - $lastSeen > $timeout) {
                $stale = true;
            }
        }

        if ($stale) {
            $this->SetStatus(205);
        } elseif ($this->GetStatus() === 205) {
            $this->SetStatus(102);
        }
    }

    // Final freigegebener Vertrag für das EMS (Dietmar, 24.07.2026).
    // Zwei unabhängige Achsen, weil eine §14a-Steuerbox BEIDES überträgt:
    //   - Lastseite: Begrenzung steuerbarer Verbrauchseinrichtungen (Wallbox, WP, Speicher).
    //   - Erzeugungsseite: Reduktion der PV-Einspeisung (0 % = keine Einspeisung erlaubt,
    //     Eigenverbrauch/Speicherladung bleiben unberührt — KEIN Abschalten der Anlage).
    // Verteilung auf Einzelgeräte (inkl. Gleichzeitigkeitsfaktor-Formel der BNetzA bei
    // mehreren steuerbaren Verbrauchern) ist Aufgabe des EMS, nicht dieses Moduls.
    // Immer hinter function_exists('SBH_GetState') beim Aufrufer (EMS).
    // EEBUS liefert das eigene Modul SteuerboxHubEEBUS über SBHE_GetState (gleiche Felder).
    public function GetState(): array
    {
        // Früherer EEBUS-Weg (Transport 1): hier kein Signal, EEBUS steht in SBHE_GetState.
        if ($this->ReadPropertyInteger('Transport') !== 0) {
            return [
                'contractVersion'    => '1.0',
                'source'             => 'eebus',
                'loadDimmActive'     => false,
                'loadPMin'           => 0.0,
                'loadSince'          => 0,
                'feedInDimmActive'   => false,
                'feedInLimitPercent' => 100.0,
                'feedInSince'        => 0,
            ];
        }

        $loadActive   = (bool) $this->ReadAttributeBoolean('LoadDimmActive');
        $feedInActive = (bool) $this->ReadAttributeBoolean('FeedInDimmActive');

        return [
            'contractVersion'    => '1.0',
            'source'             => 'contacts',
            'loadDimmActive'     => $loadActive,
            'loadPMin'           => (float) $this->ReadPropertyFloat('LoadPMin'),
            'loadSince'          => (int) $this->ReadAttributeInteger('LoadSince'),
            'feedInDimmActive'   => $feedInActive,
            'feedInLimitPercent' => $this->ReadPropertyInteger('FeedInMode') === 1
                ? (float) $this->ReadAttributeFloat('FeedInPercent')
                : ($feedInActive ? (float) $this->ReadPropertyFloat('FeedInLimitPercent') : 100.0),
            'feedInSince'        => (int) $this->ReadAttributeInteger('FeedInSince'),
        ];
    }
}
