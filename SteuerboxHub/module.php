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
//   'eebus'    — Anwendungsfälle LPC/LPP (Begrenzung von Bezug/Einspeisung)
//                über SHIP/SPINE. Den Protokollstapel hält das NRG-Stack
//                EEBUS-Gateway (Präfix EEBGW, eigenes Modul, reines PHP über
//                Symcons Client Socket, geprüft gegen eebus-go controlbox am
//                27.10.2026). Am Gateway hängt die Brücke SteuerboxHubEEBUS
//                (Präfix SBHE, gleiche Bibliothek), nicht SteuerboxHub selbst:
//                Mit parentRequirements am Hauptmodul zeigte jede Instanz auf
//                dem Kontakte-Weg den Balken „benötigt eine übergeordnete
//                Instanz“ (live 27.10.2026). Über die Brücke (Kind-Schnittstelle
//                1.0, SBHE_Forward / SBH_ProcessEEBus) meldet sich SteuerboxHub
//                als Entität mit LPC und
//                LPP in der Rolle "ControllableSystem" an, liefert die
//                Feature-Daten und übersetzt Schreibbefehle und Heartbeat-
//                Ausfall in SBH_GetState. Das Gateway kennt keine §14a-Logik,
//                die Zustandsmaschine (gesteuert / Ausfall / autonom) liegt hier.
//                Die früheren Eigenschaften EEBusBridgeURL/EEBusSKI (Wartegerüst
//                für einen externen Bridge-Dienst, 24.07.2026) sind ohne
//                Funktion und bleiben nur registriert, damit bestehende
//                Instanzen beim Update keine Eigenschaft verlieren.
// ===========================================================================

class SteuerboxHub extends IPSModule
{
    // Kind-Schnittstelle 1.0 des EEBUS-Gateways (EEBUSGateway/.docs/kind-schnittstelle.md).
    private const EEBGW_MODULE_ID       = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    private const EEBGW_DATA_TO_CHILD   = '{12D8B8A8-3EE7-491F-A68A-D632304165D4}';
    private const BRIDGE_MODULE_ID      = '{1DEF9F7C-FFF8-4DA3-97ED-A1F6F9F587C5}';

    // LPC/LPP: Heartbeat der Steuerbox länger als 120 s weg = Ausfall (Szenario 3/4);
    // Mindestdauer des Ausfallzustands 2 h bis 24 h, Vorgabe 2 h (Szenario 2).
    private const EEBUS_HEARTBEAT_TIMEOUT_S = 120;
    private const EEBUS_FAILSAFE_MIN_S      = 7200;
    private const EEBUS_FAILSAFE_MAX_S      = 86400;
    private const EEBUS_TICK_S              = 10;

    // Eigene Kennungen in LoadControl bzw. DeviceConfiguration (wie eebus-go).
    private const LIMIT_CONSUMPTION        = 0;
    private const LIMIT_PRODUCTION         = 1;
    private const KEY_FAILSAFE_CONSUMPTION = 0;
    private const KEY_FAILSAFE_DURATION    = 1;
    private const KEY_FAILSAFE_PRODUCTION  = 2;

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
        $this->RegisterPropertyFloat('GZFFactor', 0.0); // 0 = automatisch nach BNetzA-Tabelle
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

        // --- EEBus-Weg (über die Brücke SteuerboxHubEEBUS am EEBUS-Gateway) ---
        // 0 = automatisch, wenn genau eine Brücke existiert.
        $this->RegisterPropertyInteger('EEBusBridgeID', 0);
        // Ohne Funktion, siehe Klassenkommentar.
        $this->RegisterPropertyString('EEBusBridgeURL', '');
        $this->RegisterPropertyString('EEBusSKI', '');
        // Nennwerte der gesteuerten Anlage, 0 = nicht angegeben. Die Erzeugungs-Nennleistung ist
        // zugleich Bezugsgröße für feedInLimitPercent (EEBUS liefert Watt, der Vertrag Prozent).
        // Sie muss die installierte PV-Leistung (kWp) sein: Das EMS rechnet den Prozentwert mit
        // genau dieser Größe in Watt zurück (live geprüft 27.10.2026: 3000 W → 32,7 % → 3002 W).
        $this->RegisterPropertyFloat('EEBusConsumptionNominalKw', 0.0);
        $this->RegisterPropertyFloat('EEBusProductionNominalKw', 0.0);
        // Vorgabe der Ausfallgrenze Einspeisung, bis die Steuerbox eine eigene schreibt.
        // 0 = keine Vorgabe (im Ausfall bleibt die Einspeisung unbegrenzt).
        // Die Vorgabe der Ausfallgrenze Bezug ist LoadPMin (Pmin nach §14a).
        $this->RegisterPropertyFloat('EEBusFailsafeProductionKw', 0.0);

        // Selbst geführter Zustand (Hardware liefert keinen Zeitstempel).
        $this->RegisterAttributeBoolean('LoadDimmActive', false);
        $this->RegisterAttributeInteger('LoadSince', 0);
        $this->RegisterAttributeBoolean('FeedInDimmActive', false);
        $this->RegisterAttributeInteger('FeedInSince', 0);
        $this->RegisterAttributeInteger('LoadLastSeen', 0);
        $this->RegisterAttributeInteger('FeedInLastSeen', 0);
        $this->RegisterAttributeFloat('FeedInPercent', 100.0); // FeedInMode=1 (FNN-Dreistufen) und EEBus
        $this->RegisterAttributeString('EEBusState', '');

        // Formular-Hinweise (ausblendbar)
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('SBH_Watchdog', 0, 'SBH_CheckWatchdog($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SBH_EEBus', 0, 'SBH_CheckEEBus($_IPS[\'TARGET\']);');
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
        $this->SetTimerInterval('SBH_EEBus', 0);

        if ($this->ReadPropertyInteger('Transport') === 0) {
            $this->applyContactsTransport();
            // Brücke vorhanden, aber Kontakte gewählt: beim Gateway keine LPC/LPP-Entität anbieten.
            if ($this->eebusGatewayID() > 0 && IPS_GetKernelRunlevel() === KR_READY) {
                $this->eebusCall(['Cmd' => 'Unregister']);
            }
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

    private function applyEEBusTransport(): void
    {
        $this->SetTimerInterval('SBH_EEBus', self::EEBUS_TICK_S * 1000);

        // Beim Symcon-Start ist das Gateway evtl. noch nicht da: Anmeldung nach dem Start nachholen.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->eebusRegister();
        $this->eebusTick(false);
    }

    // Formular-Reihenfolge (SUITE.md „Einheitliche Formular-Optik“):
    // 0. „Wozu dieses Modul?“ (aufgeklappt, einmalig ausblendbar)
    // 1. „Neu bis Version“ (aufgeklappt, versionsscharf ausblendbar, NEWS_VERSIONS)
    // 2. „Dokumentation & Hilfe“ (eingeklappt, mit Versionsnummer, aus form.json)
    // 3. Fachpanels (form.json)
    // 4. Rückmeldungen (einmalig ausblendbar)
    // 5. „Über dieses Modul“ (eingeklappt, nicht ausblendbar)
    private const NEWS_VERSIONS = [
        '0.2.0' => [
            '• Neuer Transportweg EEBUS: SteuerboxHub hängt als Kind-Instanz am NRG-Stack EEBUS-Gateway und nimmt Grenzen der Steuerbox für Bezug (LPC) und Einspeisung (LPP) entgegen, auch befristet.',
            '• Fällt die Steuerbox länger als 120 s aus, gelten die Ausfallgrenzen, bis sie einen neuen Grenzwert schickt oder die Mindestdauer abgelaufen ist.',
            '• Pmin nach §14a mit Rechenhilfe steht jetzt in einem eigenen Abschnitt und gilt für beide Wege.',
        ],
        '0.3.0' => [
            '• 👋 Neue Zweck-Einführung ganz oben im Formular, dazu Rückmeldungs- und Lizenzhinweis.',
            '• EEBUS: Die Einspeisegrenze wird auf die installierte PV-Leistung (kWp) bezogen, dieselbe Größe, mit der das EMS rechnet.',
            '• EEBUS: Ans Gateway hängt jetzt die eigene Instanz „NRG-Stack SteuerboxHub EEBUS-Anbindung“, nicht mehr SteuerboxHub selbst. Damit verschwindet auf dem Kontakte-Weg der Hinweis „benötigt eine übergeordnete Instanz“. Anlegen per Knopf im Abschnitt EEBUS.',
        ],
    ];
    private const LIBRARY_GUID = '{F790C1F0-4955-4C43-81F4-B4CBD415D0E1}';
    private const REPO_URL     = 'https://github.com/DG65/NRGSteuerboxHub';
    private const ISSUES_URL   = 'https://github.com/DG65/NRGSteuerboxHub/issues';
    private const LICENSE_URL  = 'https://github.com/DG65/NRGSteuerboxHub/blob/beta/LICENSE';
    private const PAYPAL_URL   = 'https://paypal.me/DietmarGureth';

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $this->injectVersionIntoDocPanel($form);
        $this->applyFeedInVisibility($form['elements'], $this->ReadPropertyInteger('FeedInMode'));
        $this->applyTransportVisibility($form['elements'], $this->ReadPropertyInteger('Transport'));
        $this->injectEEBusStatus($form['elements']);
        $form['elements'] = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner()],
            $form['elements'],
            [$this->formForumHint(), $this->formLicenseHint()]
        )));
        return json_encode($form);
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews()
    {
        // Gespeichert wird die installierte Version, nicht der zuletzt gezeigte Eintrag (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->installedVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    private function installedVersion(): string
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        if (is_array($lib) && !empty($lib['Version'])) {
            return (string) preg_replace('/-.*$/', '', (string) $lib['Version']);
        }
        $keys = array_keys(self::NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string) end($keys);
    }

    private function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'SteuerboxHub nimmt die Leistungsbegrenzung des Netzbetreibers nach §14a EnWG entgegen: über die Kontakte einer Steuerbox oder eines Rundsteuerempfängers oder über EEBUS. Es meldet, ob und wie stark Bezug und Einspeisung gerade begrenzt sind, mit Zeitpunkt.'],
                ['type' => 'Label', 'caption' => 'Es regelt selbst nichts. Umsetzen muss die Grenze ein Energiemanagement, z. B. das NRG-Stack EMS (Wallboxen, Wechselrichter), oder ein eigenes Skript über SBH_GetState. Für EEBUS werden zusätzlich das NRG-Stack EEBUS-Gateway und die EEBUS-Anbindung aus diesem Modul gebraucht.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SBH_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function formNewsBanner(): ?array
    {
        $seen    = (string) $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string) $version, $seen === '' ? '0' : $seen, '>')) {
                $pending[(string) $version] = $lines;
            }
        }
        if ($pending === []) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SBH_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu bis Version ' . end($keys),
            'items' => $items,
        ];
    }

    private function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread folgt mit der Veröffentlichung; bis dahin GitHub.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Welche Steuerbox bzw. welcher Rundsteuerempfänger ist bei dir verbaut, und kommt das Signal richtig an? Rückmeldungen mit Hersteller, Modell und Netzbetreiber helfen sehr, gerade beim EEBUS-Weg.'],
                ['type' => 'Button', 'caption' => 'Fehler oder Erfahrung auf GitHub melden', 'onClick' => "echo '" . self::ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SBH_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    private function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    private const FEEDIN_SINGLE_FIELDS = ['FeedInContactVarID', 'FeedInContactInverted', 'FeedInLimitPercent'];
    private const FEEDIN_FNN_FIELDS    = ['FeedInFNNLabel', 'FeedInW3VarID', 'FeedInS1VarID', 'FeedInS2VarID', 'FeedInFNNInverted'];

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

    private const TRANSPORT_PANELS = ['ContactsPanel' => 0, 'EEBusPanel' => 1];

    private function applyTransportVisibility(array &$items, int $transport): void
    {
        foreach ($items as &$el) {
            $name = $el['name'] ?? '';
            if (isset(self::TRANSPORT_PANELS[$name])) {
                $el['visible'] = (self::TRANSPORT_PANELS[$name] === $transport);
            }
            if (isset($el['items'])) {
                $this->applyTransportVisibility($el['items'], $transport);
            }
        }
        unset($el);
    }

    /** Formular: zeigt nur das Panel des gewählten Transportwegs. */
    public function ToggleTransport(int $transport)
    {
        foreach (self::TRANSPORT_PANELS as $name => $value) {
            $this->UpdateFormField($name, 'visible', $value === $transport);
        }
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
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
        if ($message === IPS_KERNELSTARTED) {
            if ($this->ReadPropertyInteger('Transport') === 1) {
                $this->eebusRegister();
                $this->eebusTick(false);
            }
            return;
        }

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

    /**
     * Formular-Rechenhilfe: Pmin,14a = 4,2 kW + (n-1) × GZF × 4,2 kW.
     * GZF = 0 (Standard) bedeutet automatisch nach BNetzA-Tabelle: max(0,9 - 0,05 × n; 0,45),
     * also n=2: 0,80 ... n>=9: 0,45 (deckt sich mit dem Symcon-Energiemanager, Quelle KEDi/dena).
     * Werte kommen direkt aus dem Formular, damit auch ungespeicherte Eingaben zählen.
     * Rückgabe: Hinweistext fürs Formular (echo), nicht maschinell auswerten.
     */
    public function CalculateGZF(int $consumerCount, float $gzfOverride)
    {
        $n   = max(1, $consumerCount);
        $gzf = $gzfOverride > 0 ? min(1.0, $gzfOverride) : max(0.9 - 0.05 * $n, 0.45);
        $pMin = 4.2 + ($n - 1) * $gzf * 4.2;

        $this->UpdateFormField('LoadPMin', 'value', round($pMin, 2));
        return sprintf('✅ Pmin = %s kW (n = %d, Gleichzeitigkeitsfaktor %s) ins Feld eingetragen. Gespeichert ist der Wert erst mit „Übernehmen“.',
            self::fmt($pMin), $n, self::fmt($gzf));
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
        if ($this->ReadPropertyInteger('Transport') !== 0) {
            // EEBus: Wirkung zum Abrufzeitpunkt (abgelaufene Befristung zählt sofort nicht mehr).
            $eff = $this->eebusRefresh();
            return [
                'contractVersion'    => '1.0',
                'source'             => 'eebus',
                'loadDimmActive'     => $eff['loadActive'],
                'loadPMin'           => $eff['loadActive'] ? round($eff['loadW'] / 1000, 3) : 0.0,
                'loadSince'          => (int) $this->ReadAttributeInteger('LoadSince'),
                'feedInDimmActive'   => $eff['feedInActive'],
                'feedInLimitPercent' => $eff['feedInActive'] ? $eff['feedInPercent'] : 100.0,
                'feedInSince'        => (int) $this->ReadAttributeInteger('FeedInSince'),
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

    // =======================================================================
    // EEBus-Weg: Kind-Instanz des NRG-Stack EEBUS-Gateways
    //
    // Zustände (LPC/LPP, Abschnitt Zustandsmaschine der Anwendungsfälle):
    //   unpaired   — noch nie mit einer Steuerbox verbunden: keine Begrenzung.
    //   normal     — gesteuert: es gelten die geschriebenen Grenzen (mit Befristung).
    //                Nach einem Neustart läuft der letzte Stand weiter, bis 120 s
    //                ohne Heartbeat vergangen sind (Init-Zustand).
    //   failsafe   — Heartbeat > 120 s weg (Ereignis des Gateways oder Verbindung
    //                getrennt): es gelten die Ausfallgrenzen. Ende nur durch einen
    //                neuen Grenzwert der Steuerbox oder nach der Mindestdauer.
    //   autonomous — Mindestdauer abgelaufen, weiter keine Steuerbox: unbegrenzt,
    //                bis sie wieder da ist (dann normal, ohne alte Grenzen).
    // =======================================================================

    /** Timer und Ereignisse: Verbindung abfragen, Zustandsübergänge, Befristungsende. */
    public function CheckEEBus()
    {
        $this->eebusTick(true);
    }

    private function eebusTick(bool $retryRegister): void
    {
        if ($this->ReadPropertyInteger('Transport') !== 1) {
            return;
        }
        if ($retryRegister && !$this->eebusIsRegistered()) {
            $this->eebusRegister();
        }
        $remote = $this->eebusGatewayID() > 0 ? json_decode((string) $this->eebusCall(['Cmd' => 'GetState']), true) : null;

        $sync = $this->eebusUpdate(function (array &$st, int $now) use ($remote) {
            if (is_array($remote) && isset($remote['connected'])) {
                $st['connected']    = (bool) $remote['connected'];
                $st['trustPending'] = (bool) ($remote['trustPending'] ?? false);
                $st['remoteShipId'] = (string) ($remote['remoteShipId'] ?? $st['remoteShipId']);
                $st['heartbeatLost'] = (bool) ($remote['heartbeatLost'] ?? false);
            } elseif ($this->eebusGatewayID() === 0) {
                $st['connected'] = false;
            }
            return [];
        });
        $this->eebusSend($sync);
    }

    /** SBH_ProcessEEBus($id, $bridgeID, $json): Ereignis des Gateways, von der Brücke weitergereicht. */
    public function ProcessEEBus(int $bridgeID, string $json)
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['DataID'] ?? '') !== self::EEBGW_DATA_TO_CHILD || $this->ReadPropertyInteger('Transport') !== 1
            || $bridgeID === 0 || $bridgeID !== $this->eebusBridgeID()) {
            return '';
        }
        // Target = Instanz, die beim Gateway angemeldet ist (die Brücke), 0 = an alle.
        $target = (int) ($d['Target'] ?? 0);
        if ($target !== 0 && $target !== $bridgeID) {
            return '';
        }
        $event = (string) ($d['Event'] ?? '');
        $this->SendDebug('EEBUS', $event . ': ' . json_encode($d), 0);

        $sync = $this->eebusUpdate(function (array &$st, int $now) use ($event, $d) {
            $sync = [];
            switch ($event) {
                case 'connection':
                    $st['connected'] = ($d['state'] ?? '') === 'connected';
                    if ($st['connected']) {
                        $st['remoteShipId']  = (string) ($d['shipId'] ?? $st['remoteShipId']);
                        $st['trustPending']  = false;
                        $st['heartbeatLost'] = false;
                        $sync = ['all'];
                    }
                    break;
                case 'trust':
                    $st['trustPending'] = true;
                    break;
                case 'remote':
                    $st['remoteUseCases'] = array_values(array_unique(array_map(function ($u) {
                        return (string) ($u['name'] ?? '');
                    }, (array) ($d['useCases'] ?? []))));
                    break;
                case 'heartbeat':
                    $st['heartbeatLost'] = ($d['state'] ?? '') === 'lost';
                    if ($st['heartbeatLost']) {
                        $this->eebusEnterFailsafe($st, $now);
                    } else {
                        $st['lastAlive'] = $now;
                    }
                    break;
                case 'write':
                    $sync = $this->eebusApplyWrite($st, $now, (string) ($d['function'] ?? ''), (array) ($d['changes'] ?? []), (array) ($d['data'] ?? []));
                    if (($d['bound'] ?? true) === false) {
                        $this->SendDebug('EEBUS', 'Schreibbefehl ohne vorherige Bindung (zugelassen)', 0);
                    }
                    break;
            }
            return $sync;
        });
        $this->eebusSend($sync);
        return '';
    }

    /** Schreibbefehl der Steuerbox übernehmen; liefert nötige Rückmeldungen (SetData). */
    private function eebusApplyWrite(array &$st, int $now, string $function, array $changes, array $data): array
    {
        if ($function === 'loadControlLimitListData') {
            $changed = false;
            foreach ($changes as $entry) {
                $id = (int) ($entry['limitId'] ?? -1);
                if ($id !== self::LIMIT_CONSUMPTION && $id !== self::LIMIT_PRODUCTION) {
                    continue;
                }
                $old = $st['limits'][$id];
                $w   = isset($entry['value']) ? abs(self::scaledToFloat($entry['value'])) : $old['w'];
                $dur = isset($entry['timePeriod']['endTime']) ? self::endTimeToSeconds((string) $entry['timePeriod']['endTime'], $now) : 0;
                $st['limits'][$id] = [
                    'active' => (bool) ($entry['isLimitActive'] ?? $old['active']),
                    'w'      => $w,
                    'until'  => $dur > 0 ? $now + $dur : 0,
                ];
                $changed = true;
                $this->LogMessage(sprintf(
                    'EEBUS: %s %s%s',
                    $id === self::LIMIT_CONSUMPTION ? 'Bezugsgrenze' : 'Einspeisegrenze',
                    $st['limits'][$id]['active'] ? round($w / 1000, 3) . ' kW aktiv' : 'aufgehoben',
                    $dur > 0 ? ' für ' . $dur . ' s' : ''
                ), KL_MESSAGE);
            }
            // Ein neuer Grenzwert beendet den Ausfallzustand (LPC/LPP Szenario 3).
            if ($changed) {
                $st['lastWriteAt'] = $now;
                $st['lastAlive']   = $now;
                if ($st['mode'] !== 'normal') {
                    $this->LogMessage('EEBUS: Steuerbox steuert wieder, Ausfallgrenze aufgehoben.', KL_MESSAGE);
                }
                $st['mode'] = 'normal';
                $st['failsafeSince'] = 0;
            }
            return [];
        }

        if ($function === 'deviceConfigurationKeyValueListData') {
            $fix = false;
            foreach ((array) ($data['deviceConfigurationKeyValueData'] ?? []) as $entry) {
                $key = (int) ($entry['keyId'] ?? -1);
                if ($key === self::KEY_FAILSAFE_CONSUMPTION && isset($entry['value']['scaledNumber'])) {
                    $st['failsafeRemote']['consumptionW'] = abs(self::scaledToFloat($entry['value']['scaledNumber']));
                } elseif ($key === self::KEY_FAILSAFE_PRODUCTION && isset($entry['value']['scaledNumber'])) {
                    $st['failsafeRemote']['productionW'] = abs(self::scaledToFloat($entry['value']['scaledNumber']));
                } elseif ($key === self::KEY_FAILSAFE_DURATION && isset($entry['value']['duration'])) {
                    $s = self::durationToSeconds((string) $entry['value']['duration']);
                    $clamped = max(self::EEBUS_FAILSAFE_MIN_S, min(self::EEBUS_FAILSAFE_MAX_S, $s));
                    // Außerhalb 2 h bis 24 h: nicht umsetzbar, tatsächlichen Stand zurückmelden.
                    $fix = $fix || $clamped !== $s;
                    $st['failsafeRemote']['durationS'] = $clamped;
                }
            }
            $this->LogMessage('EEBUS: Ausfallwerte von der Steuerbox übernommen: ' . $this->eebusFailsafeText($st), KL_MESSAGE);
            return $fix ? ['failsafe'] : [];
        }
        return [];
    }

    private function eebusEnterFailsafe(array &$st, int $now): void
    {
        if ($st['mode'] !== 'normal') {
            return; // unpaired: nie gekoppelt; failsafe/autonomous: schon ausgelöst
        }
        $st['mode'] = 'failsafe';
        $st['failsafeSince'] = $now;
        $this->LogMessage('EEBUS: Steuerbox seit über 120 s nicht erreichbar, Ausfallgrenze gilt: ' . $this->eebusFailsafeText($st), KL_WARNING);
    }

    /**
     * Zustand unter Sperre laden, $change anwenden, Zustandsübergänge und Befristungsende
     * auswerten, speichern. Liefert die nötigen Rückmeldungen ans Gateway; gesendet wird
     * erst NACH der Sperre (das Gateway kann im selben Aufruf Ereignisse zurückgeben).
     */
    private function eebusUpdate(callable $change): array
    {
        $sem = 'SBH_EEBus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 2000)) {
            $this->SendDebug('EEBUS', 'Sperre belegt, Aufruf verworfen', 0);
            return [];
        }
        try {
            $now  = $this->now();
            $st   = $this->eebusLoadState();
            $sync = $change($st, $now);

            $alive = $st['connected'] && !$st['heartbeatLost'];
            if ($alive) {
                $st['lastAlive'] = $now;
                if ($st['mode'] === 'unpaired') {
                    $st['mode'] = 'normal';
                } elseif ($st['mode'] === 'autonomous') {
                    $st['mode'] = 'normal';
                    $this->LogMessage('EEBUS: Steuerbox wieder erreichbar, Betrieb ohne Begrenzung bis zum nächsten Grenzwert.', KL_MESSAGE);
                }
            } elseif ($st['mode'] === 'normal' && $st['lastAlive'] > 0 && $now - $st['lastAlive'] > self::EEBUS_HEARTBEAT_TIMEOUT_S) {
                $this->eebusEnterFailsafe($st, $now);
            }

            if ($st['mode'] === 'failsafe' && $now - $st['failsafeSince'] >= $this->eebusFailsafeDuration($st)) {
                // Mindestdauer vorbei: Grenzen der Steuerbox gelten nicht mehr (LPC/LPP Szenario 3).
                $st['limits'][self::LIMIT_CONSUMPTION]['active'] = false;
                $st['limits'][self::LIMIT_PRODUCTION]['active']  = false;
                $st['mode'] = $alive ? 'normal' : 'autonomous';
                $st['failsafeSince'] = 0;
                $sync[] = 'limits';
                $this->LogMessage('EEBUS: Mindestdauer des Ausfallzustands abgelaufen, keine Begrenzung mehr.', KL_MESSAGE);
            }

            // Befristung abgelaufen: Grenze inaktiv und der Steuerbox den tatsächlichen Stand melden.
            foreach ([self::LIMIT_CONSUMPTION, self::LIMIT_PRODUCTION] as $id) {
                $l = $st['limits'][$id];
                if ($l['active'] && $l['until'] > 0 && $now >= $l['until']) {
                    $st['limits'][$id]['active'] = false;
                    $st['limits'][$id]['until']  = 0;
                    $sync[] = 'limits';
                    $this->LogMessage('EEBUS: Befristung der ' . ($id === self::LIMIT_CONSUMPTION ? 'Bezugsgrenze' : 'Einspeisegrenze') . ' abgelaufen.', KL_MESSAGE);
                }
            }

            $this->WriteAttributeString('EEBusState', json_encode($st));
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        $this->eebusRefresh();
        $this->eebusUpdateStatus();
        return array_values(array_unique($sync));
    }

    /** Wirkung jetzt berechnen und die Vertrags-Attribute (aktiv/seit) nachführen. */
    private function eebusRefresh(): array
    {
        $now = $this->now();
        $st  = $this->eebusLoadState();
        $eff = ['loadActive' => false, 'loadW' => 0.0, 'feedInActive' => false, 'feedInPercent' => 100.0];

        if ($st['mode'] === 'normal') {
            $lc = $st['limits'][self::LIMIT_CONSUMPTION];
            if ($lc['active'] && ($lc['until'] === 0 || $now < $lc['until'])) {
                $eff['loadActive'] = true;
                $eff['loadW'] = (float) $lc['w'];
            }
            $lp = $st['limits'][self::LIMIT_PRODUCTION];
            if ($lp['active'] && ($lp['until'] === 0 || $now < $lp['until'])) {
                $eff['feedInActive'] = true;
                $eff['feedInPercent'] = $this->eebusPercent((float) $lp['w']);
            }
        } elseif ($st['mode'] === 'failsafe') {
            $fs = $this->eebusFailsafeValues($st);
            $eff['loadActive'] = true;
            $eff['loadW'] = $fs['consumptionW'];
            if ($fs['productionW'] !== null) {
                $eff['feedInActive'] = true;
                $eff['feedInPercent'] = $this->eebusPercent($fs['productionW']);
            }
        }

        foreach (['Load' => $eff['loadActive'], 'FeedIn' => $eff['feedInActive']] as $axis => $active) {
            if ($active !== $this->ReadAttributeBoolean($axis . 'DimmActive')) {
                $this->WriteAttributeBoolean($axis . 'DimmActive', $active);
                $this->WriteAttributeInteger($axis . 'Since', $active ? $now : 0);
            }
        }
        $this->WriteAttributeFloat('FeedInPercent', $eff['feedInPercent']);
        return $eff;
    }

    /**
     * EEBUS liefert Watt, der Vertrag Prozent der installierten PV-Leistung (Bezugsgröße des EMS). Ohne Nennleistung
     * bleibt nur 0 % (sicher für das Netz); das Formular meldet das Feld dann als Pflicht.
     */
    private function eebusPercent(float $w): float
    {
        $nominalW = $this->ReadPropertyFloat('EEBusProductionNominalKw') * 1000;
        if ($nominalW <= 0) {
            return 0.0;
        }
        return round(max(0.0, min(100.0, $w / $nominalW * 100)), 1);
    }

    private function eebusFailsafeValues(array $st): array
    {
        $prodPreset = $this->ReadPropertyFloat('EEBusFailsafeProductionKw') > 0 ? $this->ReadPropertyFloat('EEBusFailsafeProductionKw') * 1000 : null;
        return [
            'consumptionW' => (float) ($st['failsafeRemote']['consumptionW'] ?? $this->ReadPropertyFloat('LoadPMin') * 1000),
            'productionW'  => isset($st['failsafeRemote']['productionW']) ? (float) $st['failsafeRemote']['productionW'] : $prodPreset,
            'durationS'    => $this->eebusFailsafeDuration($st),
        ];
    }

    private function eebusFailsafeDuration(array $st): int
    {
        return (int) ($st['failsafeRemote']['durationS'] ?? self::EEBUS_FAILSAFE_MIN_S);
    }

    private function eebusFailsafeText(array $st): string
    {
        $fs = $this->eebusFailsafeValues($st);
        $r  = $st['failsafeRemote'];
        return sprintf(
            'Bezug %s kW (%s), Einspeisung %s, Mindestdauer %s h (%s)',
            self::fmt($fs['consumptionW'] / 1000),
            isset($r['consumptionW']) ? 'von der Steuerbox' : 'Vorgabe Pmin',
            $fs['productionW'] === null ? 'unbegrenzt (keine Vorgabe)' : self::fmt($fs['productionW'] / 1000) . ' kW (' . (isset($r['productionW']) ? 'von der Steuerbox' : 'Vorgabe') . ')',
            self::fmt($fs['durationS'] / 3600),
            isset($r['durationS']) ? 'von der Steuerbox' : 'Vorgabe'
        );
    }

    private function eebusLoadState(): array
    {
        $st = json_decode((string) $this->ReadAttributeString('EEBusState'), true);
        $default = [
            'mode' => 'unpaired', 'connected' => false, 'heartbeatLost' => false, 'trustPending' => false,
            'lastAlive' => 0, 'failsafeSince' => 0, 'lastWriteAt' => 0,
            'remoteShipId' => '', 'remoteUseCases' => [],
            'registeredWith' => 0, 'entity' => 0, 'registerError' => '',
            'limits' => [
                self::LIMIT_CONSUMPTION => ['active' => false, 'w' => 0.0, 'until' => 0],
                self::LIMIT_PRODUCTION  => ['active' => false, 'w' => 0.0, 'until' => 0],
            ],
            'failsafeRemote' => ['consumptionW' => null, 'productionW' => null, 'durationS' => null],
        ];
        if (!is_array($st)) {
            return $default;
        }
        $st = array_replace($default, $st);
        // JSON macht aus den Kennungen 0/1 Schlüssel "0"/"1", PHP liest sie wieder als int.
        $st['limits'] = array_replace($default['limits'], (array) $st['limits']);
        $st['failsafeRemote'] = array_replace($default['failsafeRemote'], (array) $st['failsafeRemote']);
        return $st;
    }

    /** Brücke SteuerboxHubEEBUS: eingetragen oder, wenn genau eine existiert, automatisch. */
    private function eebusBridgeID(): int
    {
        $id = $this->ReadPropertyInteger('EEBusBridgeID');
        if ($id === 0) {
            $all = array_values(array_filter((array) @IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_ID), 'IPS_InstanceExists'));
            return count($all) === 1 ? $all[0] : 0;
        }
        if (!IPS_InstanceExists($id) || (string) (IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') !== self::BRIDGE_MODULE_ID) {
            return 0;
        }
        return $id;
    }

    /** Gateway, an dem die Brücke hängt (0 = keine Brücke oder kein Gateway). */
    private function eebusGatewayID(): int
    {
        $bridge = $this->eebusBridgeID();
        if ($bridge === 0) {
            return 0;
        }
        $gw = (int) (IPS_GetInstance($bridge)['ConnectionID'] ?? 0);
        if ($gw <= 0 || !IPS_InstanceExists($gw)) {
            return 0;
        }
        return (string) (IPS_GetInstance($gw)['ModuleInfo']['ModuleID'] ?? '') === self::EEBGW_MODULE_ID ? $gw : 0;
    }

    /** Anfrage über die Brücke an das Gateway; Antwort als JSON-String, '' ohne Brücke/Gateway oder bei Fehler. */
    private function eebusCall(array $request): string
    {
        $bridge = $this->eebusBridgeID();
        if ($bridge === 0 || !function_exists('SBHE_Forward')) {
            return '';
        }
        try {
            return (string) SBHE_Forward($bridge, json_encode($request));
        } catch (Throwable $e) {
            $this->LogMessage('EEBUS: Anfrage ' . ($request['Cmd'] ?? '?') . ' über die Anbindung #' . $bridge . ' fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            return '';
        }
    }

    private function eebusIsRegistered(): bool
    {
        $gw = $this->eebusGatewayID();
        return $gw > 0 && $this->eebusLoadState()['registeredWith'] === $gw;
    }

    /** Entität mit LPC und LPP anmelden und alle Feature-Daten melden. */
    private function eebusRegister(): void
    {
        $parent = $this->eebusGatewayID();
        if ($parent === 0) {
            $this->eebusUpdateStatus();
            return;
        }
        $useCases = [];
        foreach (['limitationOfPowerConsumption', 'limitationOfPowerProduction'] as $name) {
            $useCases[] = ['actor' => 'ControllableSystem', 'name' => $name, 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1, 2, 3, 4]];
        }
        $entity = [
            'type'     => 'CEM',
            'useCases' => $useCases,
            'features' => [
                ['type' => 'LoadControl', 'role' => 'server', 'functions' => [
                    'loadControlLimitDescriptionListData' => ['read'], 'loadControlLimitListData' => ['read', 'write']]],
                ['type' => 'DeviceConfiguration', 'role' => 'server', 'functions' => [
                    'deviceConfigurationKeyValueDescriptionListData' => ['read'], 'deviceConfigurationKeyValueListData' => ['read', 'write']]],
                ['type' => 'ElectricalConnection', 'role' => 'server', 'functions' => [
                    'electricalConnectionCharacteristicListData' => ['read']]],
            ],
        ];
        $r = json_decode($this->eebusCall(['Cmd' => 'Register', 'Entity' => $entity]), true);

        $st = $this->eebusLoadState();
        if (is_array($r) && ($r['ok'] ?? false) === true) {
            $st['registeredWith'] = $parent;
            $st['entity'] = (int) ($r['entity'] ?? 0);
            $st['registerError'] = '';
        } else {
            $st['registeredWith'] = 0;
            $st['registerError'] = is_array($r) ? (string) ($r['error'] ?? 'unbekannte Antwort') : 'keine Antwort';
            $this->LogMessage('EEBUS: Anmeldung beim Gateway #' . $parent . ' fehlgeschlagen: ' . $st['registerError'], KL_WARNING);
        }
        $this->WriteAttributeString('EEBusState', json_encode($st));
        if ($st['registeredWith'] > 0) {
            $this->eebusSend(['all']);
        }
        $this->eebusUpdateStatus();
    }

    /** Feature-Daten ans Gateway melden: 'all', 'limits' oder 'failsafe'. */
    private function eebusSend(array $what): void
    {
        if ($what === [] || !$this->eebusIsRegistered()) {
            return;
        }
        $st  = $this->eebusLoadState();
        $now = $this->now();
        $all = in_array('all', $what, true);
        $calls = [];

        if ($all) {
            $desc = function (int $id, string $dir) {
                return ['limitId' => $id, 'limitType' => 'signDependentAbsValueLimit', 'limitCategory' => 'obligation',
                    'limitDirection' => $dir, 'unit' => 'W', 'scopeType' => 'activePowerLimit'];
            };
            $calls[] = ['LoadControl', 'loadControlLimitDescriptionListData', ['loadControlLimitDescriptionData' => [
                $desc(self::LIMIT_CONSUMPTION, 'consume'), $desc(self::LIMIT_PRODUCTION, 'produce')]]];
            $calls[] = ['DeviceConfiguration', 'deviceConfigurationKeyValueDescriptionListData', ['deviceConfigurationKeyValueDescriptionData' => [
                ['keyId' => self::KEY_FAILSAFE_CONSUMPTION, 'keyName' => 'failsafeConsumptionActivePowerLimit', 'valueType' => 'scaledNumber', 'unit' => 'W'],
                ['keyId' => self::KEY_FAILSAFE_DURATION, 'keyName' => 'failsafeDurationMinimum', 'valueType' => 'duration'],
                ['keyId' => self::KEY_FAILSAFE_PRODUCTION, 'keyName' => 'failsafeProductionActivePowerLimit', 'valueType' => 'scaledNumber', 'unit' => 'W']]]];
            $chars = [];
            foreach ([0 => ['contractualConsumptionNominalMax', 'EEBusConsumptionNominalKw'], 1 => ['contractualProductionNominalMax', 'EEBusProductionNominalKw']] as $cid => [$type, $prop]) {
                $c = ['electricalConnectionId' => 0, 'parameterId' => 0, 'characteristicId' => $cid, 'characteristicContext' => 'entity', 'characteristicType' => $type, 'unit' => 'W'];
                if ($this->ReadPropertyFloat($prop) > 0) {
                    $c['value'] = self::floatToScaled($this->ReadPropertyFloat($prop) * 1000);
                }
                $chars[] = $c;
            }
            $calls[] = ['ElectricalConnection', 'electricalConnectionCharacteristicListData', ['electricalConnectionCharacteristicData' => $chars]];
        }
        if ($all || in_array('limits', $what, true)) {
            $limits = [];
            foreach ([self::LIMIT_CONSUMPTION => 1, self::LIMIT_PRODUCTION => -1] as $id => $sign) {
                $l = $st['limits'][$id];
                // LPP: Einspeisegrenze laut Spezifikation <= 0 W.
                $e = ['limitId' => $id, 'isLimitChangeable' => true, 'isLimitActive' => $l['active'], 'value' => self::floatToScaled($sign * $l['w'])];
                if ($l['active'] && $l['until'] > $now) {
                    $e['timePeriod'] = ['endTime' => 'PT' . ($l['until'] - $now) . 'S'];
                }
                $limits[] = $e;
            }
            $calls[] = ['LoadControl', 'loadControlLimitListData', ['loadControlLimitData' => $limits]];
        }
        if ($all || in_array('failsafe', $what, true)) {
            $fs = $this->eebusFailsafeValues($st);
            $prod = ['keyId' => self::KEY_FAILSAFE_PRODUCTION, 'isValueChangeable' => true];
            if ($fs['productionW'] !== null) {
                $prod['value'] = ['scaledNumber' => self::floatToScaled($fs['productionW'])];
            }
            $calls[] = ['DeviceConfiguration', 'deviceConfigurationKeyValueListData', ['deviceConfigurationKeyValueData' => [
                ['keyId' => self::KEY_FAILSAFE_CONSUMPTION, 'value' => ['scaledNumber' => self::floatToScaled($fs['consumptionW'])], 'isValueChangeable' => true],
                ['keyId' => self::KEY_FAILSAFE_DURATION, 'value' => ['duration' => self::secondsToDuration($fs['durationS'])], 'isValueChangeable' => true],
                $prod]]];
        }

        foreach ($calls as [$feature, $function, $data]) {
            $r = json_decode($this->eebusCall(['Cmd' => 'SetData', 'Feature' => $feature, 'Function' => $function, 'Data' => $data]), true);
            if (!is_array($r) || ($r['ok'] ?? false) !== true) {
                $this->LogMessage('EEBUS: Daten ' . $function . ' vom Gateway nicht übernommen: ' . (is_array($r) ? ($r['error'] ?? '?') : 'keine Antwort'), KL_WARNING);
            }
        }
    }

    private function eebusUpdateStatus(): void
    {
        if ($this->ReadPropertyInteger('Transport') !== 1) {
            return;
        }
        $st = $this->eebusLoadState();
        if ($this->eebusGatewayID() === 0) {
            $status = 104;
        } elseif ($st['registeredWith'] === 0 && $st['registerError'] !== '') {
            $status = 203;
        } elseif ($st['mode'] === 'failsafe' || $st['mode'] === 'autonomous') {
            $status = 204;
        } elseif ($st['mode'] === 'unpaired') {
            $status = 210;
        } else {
            $status = 102;
        }
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }

    /**
     * Formular-Knopf: legt die Brücke SteuerboxHubEEBUS an, verbindet sie mit dem EEBUS-Gateway,
     * wenn genau eines existiert, und trägt sie in die offene Maske ein (gespeichert erst mit
     * „Übernehmen“). Rückgabe: Hinweistext fürs Formular (echo).
     */
    public function CreateEEBusBridge()
    {
        $id = IPS_CreateInstance(self::BRIDGE_MODULE_ID);
        IPS_SetName($id, 'EEBUS-Anbindung ' . IPS_GetName($this->InstanceID));
        IPS_SetParent($id, IPS_GetParent($this->InstanceID));
        $gws = array_values(array_filter((array) @IPS_GetInstanceListByModuleID(self::EEBGW_MODULE_ID), 'IPS_InstanceExists'));
        $text = '✅ EEBUS-Anbindung #' . $id . ' angelegt';
        if (count($gws) === 1) {
            IPS_ConnectInstance($id, $gws[0]);
            $text .= ' und mit dem EEBUS-Gateway #' . $gws[0] . ' verbunden';
        } else {
            $text .= '. ' . ($gws === [] ? 'Noch kein EEBUS-Gateway vorhanden: zuerst ein „NRG-Stack EEBUS-Gateway“ anlegen, dann bei der Anbindung „Gateway ändern“.' : 'Mehrere EEBUS-Gateways: bei der Anbindung per „Gateway ändern“ das richtige zuordnen.');
        }
        IPS_ApplyChanges($id);
        $this->UpdateFormField('EEBusBridgeID', 'value', $id);
        return $text . '. Zum Speichern der Auswahl „Übernehmen“ klicken.';
    }

    /** Formular: Statuszeilen des EEBus-Panels live füllen. */
    private function injectEEBusStatus(array &$items): void
    {
        $lines = $this->eebusStatusLines();
        foreach ($items as &$el) {
            $name = $el['name'] ?? '';
            if (isset($lines[$name])) {
                $el['caption'] = $lines[$name]['caption'];
                if (isset($lines[$name]['color'])) {
                    $el['color'] = $lines[$name]['color'];
                }
            }
            if (isset($el['items'])) {
                $this->injectEEBusStatus($el['items']);
            }
        }
        unset($el);
    }

    private function eebusStatusLines(): array
    {
        $st     = $this->eebusLoadState();
        $bridge = $this->eebusBridgeID();
        $parent = $this->eebusGatewayID();
        $now    = $this->now();
        $bridgeCount = count((array) @IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_ID));

        if ($bridge === 0) {
            $conn = $bridgeCount > 1 && $this->ReadPropertyInteger('EEBusBridgeID') === 0
                ? '⚠️ Mehrere EEBUS-Anbindungen vorhanden: unten die gewünschte auswählen.'
                : 'ℹ️ Noch keine EEBUS-Anbindung: unten „EEBUS-Anbindung anlegen“ klicken. Sie wird mit dem EEBUS-Gateway verbunden (oder dort per „Gateway ändern“ zugeordnet). Ohne sie liefert dieser Weg keine Signale.';
        } elseif ($parent === 0) {
            $conn = 'ℹ️ Die EEBUS-Anbindung #' . $bridge . ' hängt an keinem NRG-Stack EEBUS-Gateway: dort „Gateway ändern“ wählen und das Gateway zuordnen (oder neu anlegen).';
        } elseif ($st['registeredWith'] === 0) {
            $conn = '⚠️ Gateway #' . $parent . ' (' . IPS_GetName($parent) . ') hat die Anmeldung nicht bestätigt: ' . ($st['registerError'] !== '' ? $st['registerError'] : 'noch keine Antwort') . '.';
        } elseif ($st['trustPending']) {
            $conn = '⚠️ Gateway #' . $parent . ' (' . IPS_GetName($parent) . '): die Steuerbox hat das Gateway noch nicht freigegeben (SKI des Gateways beim Netz- bzw. Messstellenbetreiber eintragen lassen).';
        } elseif ($st['connected']) {
            $uc = $st['remoteUseCases'] !== [] ? ', bietet ' . implode('/', $st['remoteUseCases']) : '';
            $conn = '✅ Verbunden über Anbindung #' . $bridge . ' und Gateway #' . $parent . ' (' . IPS_GetName($parent) . ') mit Steuerbox ' . ($st['remoteShipId'] !== '' ? $st['remoteShipId'] : '?') . $uc . '; angemeldet als Entität ' . $st['entity'] . ' (LPC und LPP).';
        } else {
            $conn = '⚠️ Angemeldet bei Gateway #' . $parent . ' (' . IPS_GetName($parent) . '), aber gerade keine Verbindung zur Steuerbox' . ($st['lastAlive'] > 0 ? ' (zuletzt erreichbar ' . date('d.m.Y H:i', $st['lastAlive']) . ' Uhr)' : '') . '.';
        }

        $limitText = function (int $id) use ($st, $now): string {
            $l = $st['limits'][$id];
            if (!$l['active'] || ($l['until'] > 0 && $now >= $l['until'])) {
                return 'keine';
            }
            return self::fmt($l['w'] / 1000) . ' kW' . ($l['until'] > 0 ? ' bis ' . date('d.m.Y H:i', $l['until']) . ' Uhr' : ' (unbefristet)');
        };
        switch ($st['mode']) {
            case 'failsafe':
                $fsEnd = $st['failsafeSince'] + $this->eebusFailsafeDuration($st);
                $state = '⛔ Ausfallzustand seit ' . date('d.m.Y H:i', $st['failsafeSince']) . ' Uhr, spätestens bis ' . date('d.m.Y H:i', $fsEnd) . ' Uhr (oder bis die Steuerbox einen neuen Grenzwert schickt).';
                break;
            case 'autonomous':
                $state = '⚠️ Ausfall länger als die Mindestdauer: keine Begrenzung, bis die Steuerbox wieder erreichbar ist.';
                break;
            case 'unpaired':
                $state = 'ℹ️ Noch nie mit einer Steuerbox verbunden: keine Begrenzung.';
                break;
            default:
                $state = 'Gesteuert durch die Steuerbox. Bezugsgrenze: ' . $limitText(self::LIMIT_CONSUMPTION) . '. Einspeisegrenze: ' . $limitText(self::LIMIT_PRODUCTION) . '.';
        }

        $lines = [
            'EEBusConnectionLine' => ['caption' => $conn],
            'EEBusStateLine'      => ['caption' => $state],
            'EEBusFailsafeLine'   => ['caption' => 'Ausfallwerte: ' . $this->eebusFailsafeText($st) . '.'],
        ];
        $lines['EEBusProductionNominalLine'] = $this->ReadPropertyFloat('EEBusProductionNominalKw') > 0
            ? ['caption' => '✏️ Bezugsgröße für die Einspeisegrenze in Prozent: ' . self::fmt($this->ReadPropertyFloat('EEBusProductionNominalKw')) . ' kWp (eigene Eingabe, muss zur PV-Leistung im EMS passen).']
            : ['caption' => '⛔ Pflichtangabe, sobald die Steuerbox die Einspeisung begrenzt: ohne PV-Leistung meldet SteuerboxHub bei jeder Einspeisegrenze 0 % (keine Einspeisung), weil sich Watt nicht in Prozent umrechnen lassen.', 'color' => 0xFF0000];
        return $lines;
    }

    // --- Zahlen- und Zeitformate (SPINE) ---

    private static function scaledToFloat(array $v): float
    {
        return (float) ($v['number'] ?? 0) * (10 ** (int) ($v['scale'] ?? 0));
    }

    private static function floatToScaled(float $w): array
    {
        return ['number' => (int) round($w), 'scale' => 0];
    }

    /** ISO-8601-Dauer (z. B. PT1H2M3S, P1D) in Sekunden, 0 bei Fehler. */
    private static function durationToSeconds(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $iso, $m)) {
            return 0;
        }
        return (int) ((int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (float) ($m[4] ?? 0));
    }

    private static function secondsToDuration(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $r = $s % 60;
        return 'PT' . ($h > 0 ? $h . 'H' : '') . ($m > 0 ? $m . 'M' : '') . ($r > 0 || $s === 0 ? $r . 'S' : '');
    }

    /** endTime ist relativ (Dauer ab Empfang) oder ein absoluter Zeitpunkt; Rückgabe Sekunden ab jetzt. */
    private static function endTimeToSeconds(string $endTime, int $now): int
    {
        if (str_starts_with($endTime, 'P')) {
            return self::durationToSeconds($endTime);
        }
        $ts = strtotime($endTime);
        return $ts === false ? 0 : max(1, $ts - $now);
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    /** Zeitquelle (im Prüfstand ersetzbar). */
    protected function now(): int
    {
        return time();
    }
}
