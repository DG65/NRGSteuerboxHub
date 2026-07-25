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
        $this->RegisterPropertyInteger('FeedInContactVarID', 0);
        $this->RegisterPropertyBoolean('FeedInContactInverted', false);
        $this->RegisterPropertyFloat('FeedInLimitPercent', 0.0);

        // --- EEBus-Weg: reines Konfigurationsgerüst, kein Client (siehe Klassenkommentar) ---
        $this->RegisterPropertyString('EEBusBridgeURL', '');
        $this->RegisterPropertyString('EEBusSKI', '');

        // Selbst geführter Zustand (Hardware liefert keinen Zeitstempel).
        $this->RegisterAttributeBoolean('LoadDimmActive', false);
        $this->RegisterAttributeInteger('LoadSince', 0);
        $this->RegisterAttributeBoolean('FeedInDimmActive', false);
        $this->RegisterAttributeInteger('FeedInSince', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        if ($this->ReadPropertyInteger('Transport') === 0) {
            $this->applyContactsTransport();
        } else {
            $this->applyEEBusTransport();
        }
    }

    private function applyContactsTransport(): void
    {
        $loadVarID   = $this->ReadPropertyInteger('LoadContactVarID');
        $feedInVarID = $this->ReadPropertyInteger('FeedInContactVarID');

        if ($loadVarID === 0 && $feedInVarID === 0) {
            $this->SetStatus(201);
            return;
        }

        if ($loadVarID !== 0 && IPS_VariableExists($loadVarID)) {
            $this->RegisterMessage($loadVarID, VM_UPDATE);
            $this->syncContactState('Load', $loadVarID, $this->ReadPropertyBoolean('LoadContactInverted'));
        }
        if ($feedInVarID !== 0 && IPS_VariableExists($feedInVarID)) {
            $this->RegisterMessage($feedInVarID, VM_UPDATE);
            $this->syncContactState('FeedIn', $feedInVarID, $this->ReadPropertyBoolean('FeedInContactInverted'));
        }

        $this->SetStatus(102);
    }

    private function applyEEBusTransport(): void
    {
        // Wartegerüst — kein Client implementiert, siehe Klassenkommentar.
        $this->SetStatus(204);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message !== VM_UPDATE) {
            return;
        }

        if ($senderID === $this->ReadPropertyInteger('LoadContactVarID')) {
            $this->syncContactState('Load', $senderID, $this->ReadPropertyBoolean('LoadContactInverted'));
        }
        if ($senderID === $this->ReadPropertyInteger('FeedInContactVarID')) {
            $this->syncContactState('FeedIn', $senderID, $this->ReadPropertyBoolean('FeedInContactInverted'));
        }
    }

    /** Liest den aktuellen Kontaktzustand und pflegt Active-Flag + Since-Zeitstempel. */
    private function syncContactState(string $axis, int $varID, bool $inverted): void
    {
        $raw    = (bool) GetValue($varID);
        $active = $inverted ? !$raw : $raw;

        $wasActive = $this->ReadAttributeBoolean($axis . 'DimmActive');
        $this->WriteAttributeBoolean($axis . 'DimmActive', $active);

        if ($active && !$wasActive) {
            $this->WriteAttributeInteger($axis . 'Since', time());
        } elseif (!$active) {
            $this->WriteAttributeInteger($axis . 'Since', 0);
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
            'feedInLimitPercent' => $feedInActive ? $this->ReadPropertyFloat('FeedInLimitPercent') : 100.0,
            'feedInSince'        => $isContacts ? $this->ReadAttributeInteger('FeedInSince') : 0,
        ];
    }
}
