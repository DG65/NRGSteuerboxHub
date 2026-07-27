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
// Zwei Transportwege (siehe CLAUDE.md für die Herleitung):
//   'contacts' — potentialfreie Kontakte (RCR-Schema 0/30/60/100 %), Zielwert
//                (loadPMin) wird vom Installateur in der Instanz konfiguriert,
//                da die Hardware selbst keinen Zahlenwert liefert.
//   'eebus'    — SPINE-Use-Case LPC über SHIP-Transport. SHIP (TLS-Handshake,
//                mDNS-Discovery, X.509-Zertifikats-Pairing) lässt sich in
//                PHP/IP-Symcon nicht sinnvoll selbst nachbauen — es existiert
//                keine PHP-Implementierung (nur Go: enbility/eebus-go, Java:
//                jEEBus/OpenMUC). Ein passender Ansatz wäre ein externer
//                Bridge-Dienst (Vorbild: volschin/eebus-ha-bridge), der SHIP/
//                SPINE hält und den LPC-Zustand lokal per HTTP/JSON bereitstellt.
//
//                ENTSCHEIDUNG (Dietmar, 24.07.2026, über EMS-Koordination):
//                Vorerst NUR Konfigurationsgerüst (Bridge-URL/SKI-Property),
//                KEIN Bridge-Dienst/Client bauen. Gründe: (1) noch keine
//                Steuerbox vorhanden, (2) unklar, ob Netzbetreiber beim
//                Erscheinen erster Steuerboxen überhaupt ansprechbereit sind,
//                (3) Symcon beobachtet EEBus selbst, hat laut Forenaussage von
//                "paresy" (community.symcon.de/t/symcon-eebus/136783) aber
//                aktuell keinen Fahrplan (Aufwand "extrem hoch", Priorität auf
//                KNX/Modbus) — ggf. übernimmt Symcon das Thema, bevor wir es
//                selbst bauen müssen. Bis eine reale SELEXA-Box vorliegt, bleibt
//                der EEBus-Zweig ein reines Wartegerüst (Status 204).
// ===========================================================================

class SteuerboxHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Transportweg: 0 = potentialfreie Kontakte, 1 = EEBus.
        $this->RegisterPropertyInteger('Transport', 0);

        // --- Kontakte-Weg ---
        $this->RegisterPropertyInteger('LoadContactVarID', 0);
        $this->RegisterPropertyBoolean('LoadContactInverted', false);
        $this->RegisterPropertyFloat('LoadPMin', 4.2);
        $this->RegisterPropertyInteger('GZFConsumerCount', 1);
        $this->RegisterPropertyFloat('GZFFactor', 1.0);
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

        // --- EEBus-Weg: reines Konfigurationsgerüst, kein Client (siehe Klassenkommentar) ---
        $this->RegisterPropertyString('EEBusBridgeURL', '');
        $this->RegisterPropertyString('EEBusSKI', '');

        // Selbst geführter Zustand (Hardware liefert keinen Zeitstempel).
        $this->RegisterAttributeBoolean('LoadDimmActive', false);
        $this->RegisterAttributeInteger('LoadSince', 0);
        $this->RegisterAttributeBoolean('FeedInDimmActive', false);
        $this->RegisterAttributeInteger('FeedInSince', 0);
        $this->RegisterAttributeInteger('LoadLastSeen', 0);
        $this->RegisterAttributeInteger('FeedInLastSeen', 0);
        $this->RegisterAttributeFloat('FeedInPercent', 100.0); // nur FeedInMode=1 (FNN-Dreistufen)

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

        if ($this->ReadPropertyInteger('Transport') === 0) {
            $this->applyContactsTransport();
        } else {
            $this->applyEEBusTransport();
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

        if ($loadVarID === 0 && !$feedInWired) {
            $this->SetStatus(201);
            return;
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

    private function applyEEBusTransport(): void
    {
        // Wartegerüst — kein Client implementiert, siehe Klassenkommentar.
        $this->SetStatus(204);
    }

    // Formular-Reihenfolge (Verbund-Konvention, EMS/Dietmar 24.07.2026):
    // 1. „Was ist neu" (aufgeklappt, versionsscharf dismissible) — hier noch
    //    kein Eintrag nötig, erste veröffentlichte Fassung.
    // 2. „Dokumentation & Hilfe" (eingeklappt, MIT Versionsnummer) — existiert
    //    bereits in form.json, Versionszeile wird dort nur eingefügt.
    // 3. Fachpanels (form.json)
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $this->injectVersionIntoDocPanel($form);
        return json_encode($form);
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib    = @IPS_GetLibrary('{F790C1F0-4955-4C43-81F4-B4CBD415D0E1}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ SteuerboxHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ SteuerboxHub';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                return;
            }
        }
        unset($el);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message !== VM_UPDATE) {
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

    /** Formular-Rechenhilfe: Pmin,14a = 4,2 kW + (n-1) × GZF × 4,2 kW. */
    public function CalculateGZF()
    {
        $n   = max(1, $this->ReadPropertyInteger('GZFConsumerCount'));
        $gzf = $this->ReadPropertyFloat('GZFFactor');
        $pMin = 4.2 + ($n - 1) * $gzf * 4.2;

        $this->UpdateFormField('LoadPMin', 'value', round($pMin, 2));
    }

    // Final freigegebener Vertrag für das EMS (Dietmar, 24.07.2026).
    // Zwei unabhängige Achsen, weil eine §14a-Steuerbox BEIDES überträgt:
    //   - Lastseite: Begrenzung steuerbarer Verbrauchseinrichtungen (Wallbox, WP, Speicher).
    //   - Erzeugungsseite: Reduktion der PV-Einspeisung (0 % = keine Einspeisung erlaubt,
    //     Eigenverbrauch/Speicherladung bleiben unberührt — KEIN Abschalten der Anlage).
    // Verteilung auf Einzelgeräte (inkl. Gleichzeitigkeitsfaktor-Formel der BNetzA bei
    // mehreren steuerbaren Verbrauchern) ist Aufgabe des EMS, nicht dieses Moduls.
    // Immer hinter function_exists('SBH_GetState') beim Aufrufer (EMS).
    public function GetState(): array
    {
        $isContacts = $this->ReadPropertyInteger('Transport') === 0;

        // EEBus-Weg ist Wartegerüst (siehe Klassenkommentar) — liefert immer
        // "kein Signal aktiv", bis ein Client existiert.
        $loadActive   = $isContacts && $this->ReadAttributeBoolean('LoadDimmActive');
        $feedInActive = $isContacts && $this->ReadAttributeBoolean('FeedInDimmActive');

        return [
            'contractVersion'    => '1.0',
            'source'             => $isContacts ? 'contacts' : 'eebus',
            'loadDimmActive'     => $loadActive,
            'loadPMin'           => $isContacts ? $this->ReadPropertyFloat('LoadPMin') : 0.0,
            'loadSince'          => $isContacts ? $this->ReadAttributeInteger('LoadSince') : 0,
            'feedInDimmActive'   => $feedInActive,
            'feedInLimitPercent' => $isContacts
                ? ($this->ReadPropertyInteger('FeedInMode') === 1
                    ? $this->ReadAttributeFloat('FeedInPercent')
                    : ($feedInActive ? $this->ReadPropertyFloat('FeedInLimitPercent') : 100.0))
                : 100.0,
            'feedInSince'        => $isContacts ? $this->ReadAttributeInteger('FeedInSince') : 0,
        ];
    }
}
