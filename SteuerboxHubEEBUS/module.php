<?php

// ===========================================================================
// SteuerboxHub EEBUS — Steuerhub für die Begrenzung nach §14a EnWG über EEBUS.
//
// Kind-Instanz des NRG-Stack EEBUS-Gateways (Präfix EEBGW, eigenes Modul, reines
// PHP über Symcons Client Socket, geprüft gegen eebus-go controlbox am 27.10.2026).
// Das Gateway hält SHIP/SPINE, Zertifikat und Kopplung; dieser Hub meldet sich über
// den Datenfluss (Kind-Schnittstelle 1.0, SendDataToParent/ReceiveData) als Entität
// mit den Anwendungsfällen LPC und LPP in der Rolle "ControllableSystem" an, liefert
// die Feature-Daten und übersetzt Schreibbefehle und Heartbeat-Ausfall in den Vertrag
// SBHE_GetState (gleiche Felder wie SBH_GetState, contractVersion 1.0, source 'eebus').
// Das Gateway kennt keine §14a-Logik, die Zustandsmaschine (gesteuert / Ausfall /
// autonom) liegt hier.
//
// Aufteilung (Store-Review 11.11.2026): Bisher sprach SteuerboxHub über eine Brücke
// (SBHE_Forward / SBH_ProcessEEBus) mit dem Gateway, also über öffentliche Funktionen
// statt über den Datenfluss. Jetzt ist dieser Hub selbst der EEBUS-Steuerhub und hängt am
// Gateway; SteuerboxHub (Kontakte) bleibt ohne Elternteil. Das Gemeinsame steht in
// libs/SteuerboxHubBase.php.
// ===========================================================================

require_once __DIR__ . '/../libs/SteuerboxHubBase.php';

class SteuerboxHubEEBUS extends SteuerboxHubBase
{
    // Kind-Schnittstelle 1.0 des EEBUS-Gateways (EEBUSGateway/.docs/kind-schnittstelle.md).
    private const EEBGW_MODULE_ID       = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    private const EEBGW_DATA_TO_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    private const EEBGW_DATA_TO_CHILD   = '{12D8B8A8-3EE7-491F-A68A-D632304165D4}';

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

    private const NEWS_VERSIONS = [
        '0.6.0' => [
            '• New: SteuerboxHub EEBUS is now a full control hub that attaches directly to the NRG-Stack EEBUS Gateway. The connector instance between the hub and the gateway is no longer needed.',
            '• The settings and the state of the EEBUS path (rated values, failsafe limits) now live in this module. The EMS reads the state with SBHE_GetState.',
        ],
    ];

    protected function modulePrefix(): string
    {
        return 'SBHE';
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
            'SteuerboxHub EEBUS receives the power limit set by the grid operator under §14a EnWG from the control box over EEBUS (use cases LPC and LPP). It reports whether and how strongly consumption and feed-in are currently limited, with the time.',
            'It does not control anything itself. An energy management system has to enforce the limit, e.g. the NRG-Stack EMS (wallboxes, inverters), or your own script via SBHE_GetState. This module attaches to the NRG-Stack EEBUS Gateway, which holds the pairing and the connection to the control box.',
        ];
    }

    protected function formPanels(): array
    {
        return ['PminPanel' => 'pmin', 'EEBusPanel' => 'eebus'];
    }

    protected function formPanelLabels(): array
    {
        return ['pmin' => 'Minimum power under §14a', 'eebus' => 'EEBUS'];
    }

    public function Create()
    {
        parent::Create();
        $this->registerCommon();

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

        // Selbst geführter Zustand.
        $this->RegisterAttributeBoolean('LoadDimmActive', false);
        $this->RegisterAttributeInteger('LoadSince', 0);
        $this->RegisterAttributeBoolean('FeedInDimmActive', false);
        $this->RegisterAttributeInteger('FeedInSince', 0);
        $this->RegisterAttributeFloat('FeedInPercent', 100.0);
        $this->RegisterAttributeString('EEBusState', '');

        $this->RegisterTimer('SBHE_EEBus', 0, 'SBHE_CheckEEBus($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetTimerInterval('SBHE_EEBus', self::EEBUS_TICK_S * 1000);

        // Beim Symcon-Start ist das Gateway evtl. noch nicht da: Anmeldung nach dem Start nachholen.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->eebusRegister();
        $this->eebusTick(false);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        // Gateway neu verbunden oder getrennt, Symcon fertig gestartet: Anmeldung nachholen (Checkliste 9c/27).
        if (in_array($message, [FM_CONNECT, FM_DISCONNECT, IPS_KERNELSTARTED], true)
            && IPS_GetKernelRunlevel() === KR_READY && IPS_InstanceExists($this->InstanceID)) {
            $this->eebusRegister();
            $this->eebusTick(false);
        }
    }

    /** Ereignisse des Gateways (Datenfluss). */
    public function ReceiveData($JSONString)
    {
        $this->processEvent((string) $JSONString);
        return '';
    }

    // Vertrag für das EMS: gleiche Felder wie SBH_GetState (SteuerboxHub, Kontakte), contractVersion 1.0.
    // Immer hinter function_exists('SBHE_GetState') beim Aufrufer (EMS).
    public function GetState(): array
    {
        // Wirkung zum Abrufzeitpunkt (abgelaufene Befristung zählt sofort nicht mehr).
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

    protected function prepareForm(array &$form): void
    {
        $this->injectEEBusStatus($form['elements']);
    }

    /** Timer und Ereignisse: Verbindung abfragen, Zustandsübergänge, Befristungsende. */
    public function CheckEEBus()
    {
        $this->eebusTick(true);
    }

    private function eebusTick(bool $retryRegister): void
    {
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

    /** Ereignis des Gateways (Datenfluss, Kind-Schnittstelle 1.0). */
    private function processEvent(string $json): void
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['DataID'] ?? '') !== self::EEBGW_DATA_TO_CHILD) {
            return;
        }
        // Target = Instanz, die beim Gateway angemeldet ist (diese), 0 = an alle.
        $target = (int) ($d['Target'] ?? 0);
        if ($target !== 0 && $target !== $this->InstanceID) {
            return;
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
                        $this->SendDebug('EEBUS', $this->Translate('Write command without prior binding (accepted)'), 0);
                    }
                    break;
            }
            return $sync;
        });
        $this->eebusSend($sync);
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
                $consumption = $id === self::LIMIT_CONSUMPTION;
                if ($st['limits'][$id]['active']) {
                    $msg = $dur > 0
                        ? sprintf($this->Translate($consumption ? 'EEBUS: consumption limit %s kW active for %d s' : 'EEBUS: feed-in limit %s kW active for %d s'), round($w / 1000, 3), $dur)
                        : sprintf($this->Translate($consumption ? 'EEBUS: consumption limit %s kW active' : 'EEBUS: feed-in limit %s kW active'), round($w / 1000, 3));
                } else {
                    $msg = $dur > 0
                        ? sprintf($this->Translate($consumption ? 'EEBUS: consumption limit lifted for %d s' : 'EEBUS: feed-in limit lifted for %d s'), $dur)
                        : $this->Translate($consumption ? 'EEBUS: consumption limit lifted' : 'EEBUS: feed-in limit lifted');
                }
                $this->LogMessage($msg, KL_MESSAGE);
            }
            // Ein neuer Grenzwert beendet den Ausfallzustand (LPC/LPP Szenario 3).
            if ($changed) {
                $st['lastWriteAt'] = $now;
                $st['lastAlive']   = $now;
                if ($st['mode'] !== 'normal') {
                    $this->LogMessage($this->Translate('EEBUS: control box is back in control, failsafe limit lifted.'), KL_MESSAGE);
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
            $this->LogMessage(sprintf($this->Translate('EEBUS: failsafe values taken over from the control box: %s'), $this->eebusFailsafeText($st)), KL_MESSAGE);
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
        $this->LogMessage(sprintf($this->Translate('EEBUS: control box unreachable for more than 120 s, failsafe limit applies: %s'), $this->eebusFailsafeText($st)), KL_WARNING);
    }

    /**
     * Zustand unter Sperre laden, $change anwenden, Zustandsübergänge und Befristungsende
     * auswerten, speichern. Liefert die nötigen Rückmeldungen ans Gateway; gesendet wird
     * erst NACH der Sperre (das Gateway kann im selben Aufruf Ereignisse zurückgeben).
     */
    private function eebusUpdate(callable $change): array
    {
        $sem = 'SBHE_EEBus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 2000)) {
            $this->SendDebug('EEBUS', $this->Translate('Lock busy, call discarded'), 0);
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
                    $this->LogMessage($this->Translate('EEBUS: control box reachable again, operating without a limit until the next limit value.'), KL_MESSAGE);
                }
            } elseif ($st['mode'] === 'normal' && $st['lastAlive'] > 0 && $now - $st['lastAlive'] > self::EEBUS_HEARTBEAT_TIMEOUT_S
                && $this->eebusGatewayID() > 0) {
                $this->eebusEnterFailsafe($st, $now);
            }

            if ($st['mode'] === 'failsafe' && $now - $st['failsafeSince'] >= $this->eebusFailsafeDuration($st)) {
                // Mindestdauer vorbei: Grenzen der Steuerbox gelten nicht mehr (LPC/LPP Szenario 3).
                $st['limits'][self::LIMIT_CONSUMPTION]['active'] = false;
                $st['limits'][self::LIMIT_PRODUCTION]['active']  = false;
                $st['mode'] = $alive ? 'normal' : 'autonomous';
                $st['failsafeSince'] = 0;
                $sync[] = 'limits';
                $this->LogMessage($this->Translate('EEBUS: minimum duration of the failsafe state elapsed, no limit any more.'), KL_MESSAGE);
            }

            // Befristung abgelaufen: Grenze inaktiv und der Steuerbox den tatsächlichen Stand melden.
            foreach ([self::LIMIT_CONSUMPTION, self::LIMIT_PRODUCTION] as $id) {
                $l = $st['limits'][$id];
                if ($l['active'] && $l['until'] > 0 && $now >= $l['until']) {
                    $st['limits'][$id]['active'] = false;
                    $st['limits'][$id]['until']  = 0;
                    $sync[] = 'limits';
                    $this->LogMessage($this->Translate($id === self::LIMIT_CONSUMPTION ? 'EEBUS: the consumption limit has expired (time limit reached).' : 'EEBUS: the feed-in limit has expired (time limit reached).'), KL_MESSAGE);
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

        // Ohne Anbindung und Gateway ist der EEBus-Weg nicht eingerichtet (Status 104): keine Begrenzung,
        // auch nicht aus einem früher gespeicherten Zustand (Live-Fund 27.10.2026: Ausfallgrenze bei Status 104).
        if ($this->eebusGatewayID() === 0) {
            $st['mode'] = 'none';
        }

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
        $fromBox = $this->Translate('from the control box');
        $default = $this->Translate('default');
        return sprintf(
            $this->Translate('Consumption %s kW (%s), feed-in %s, minimum duration %s h (%s)'),
            $this->fmt($fs['consumptionW'] / 1000),
            isset($r['consumptionW']) ? $fromBox : $this->Translate('default Pmin'),
            $fs['productionW'] === null ? $this->Translate('unlimited (no default)') : sprintf($this->Translate('%s kW (%s)'), $this->fmt($fs['productionW'] / 1000), isset($r['productionW']) ? $fromBox : $default),
            $this->fmt($fs['durationS'] / 3600),
            isset($r['durationS']) ? $fromBox : $default
        );
    }

    private function eebusLoadState(): array
    {
        $st = json_decode((string) $this->ReadAttributeString('EEBusState'), true);
        $default = [
            'mode' => 'unpaired', 'connected' => false, 'heartbeatLost' => false, 'trustPending' => false,
            'lastAlive' => 0, 'failsafeSince' => 0, 'lastWriteAt' => 0,
            'remoteShipId' => '', 'remoteUseCases' => [],
            'registeredWith' => 0, 'entity' => 0, 'registerError' => '', 'gateway' => 0,
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

    /** Gateway, an dem dieser Hub hängt (0 = keines oder kein EEBUS-Gateway). */
    private function eebusGatewayID(): int
    {
        $gw = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($gw <= 0 || !IPS_InstanceExists($gw)) {
            return 0;
        }
        return (string) (IPS_GetInstance($gw)['ModuleInfo']['ModuleID'] ?? '') === self::EEBGW_MODULE_ID ? $gw : 0;
    }

    /** Anfrage an das Gateway (Datenfluss, Kind-Schnittstelle 1.0); Antwort als JSON-String, '' ohne Gateway oder bei Fehler. */
    private function eebusCall(array $request): string
    {
        if ($this->eebusGatewayID() === 0) {
            return '';
        }
        $request = ['DataID' => self::EEBGW_DATA_TO_GATEWAY, 'Sender' => $this->InstanceID] + $request;
        try {
            return (string) $this->SendDataToParent(json_encode($request));
        } catch (Throwable $e) {
            $this->LogMessage(sprintf($this->Translate('EEBUS: request %s to the gateway failed: %s'), $request['Cmd'] ?? '?', $e->getMessage()), KL_WARNING);
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
            if ($st['gateway'] !== $parent) {
                // Anderes (oder erstes) Gateway: Grenzen, Ausfallwerte und Ausfallzustand einer früheren
                // Steuerbox gelten hier nicht. Neu beginnen wie eine nie gekoppelte Instanz.
                $this->WriteAttributeString('EEBusState', '');
                $st = $this->eebusLoadState();
                $st['gateway'] = $parent;
                $this->LogMessage(sprintf($this->Translate('EEBUS: registered with gateway #%d, previous state discarded.'), $parent), KL_MESSAGE);
            }
            $st['registeredWith'] = $parent;
            $st['entity'] = (int) ($r['entity'] ?? 0);
            $st['registerError'] = '';
        } else {
            $st['registeredWith'] = 0;
            $st['registerError'] = is_array($r) ? (string) ($r['error'] ?? $this->Translate('unknown response')) : $this->Translate('no response');
            $this->LogMessage(sprintf($this->Translate('EEBUS: registration with gateway #%d failed: %s'), $parent, $st['registerError']), KL_WARNING);
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
                $this->LogMessage(sprintf($this->Translate('EEBUS: data %s not accepted by the gateway: %s'), $function, is_array($r) ? ($r['error'] ?? '?') : $this->Translate('no response')), KL_WARNING);
            }
        }
    }

    private function eebusUpdateStatus(): void
    {
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
        $parent = $this->eebusGatewayID();
        $now    = $this->now();

        if ($parent === 0) {
            $conn = 'ℹ️ No EEBUS gateway assigned yet: choose “Change gateway” above and select the NRG-Stack EEBUS Gateway (or create a new one).';
        } elseif ($st['registeredWith'] === 0) {
            $conn = sprintf($this->Translate('⚠️ Gateway #%d (%s) has not confirmed the registration: %s.'), $parent, IPS_GetName($parent), $st['registerError'] !== '' ? $st['registerError'] : $this->Translate('no response yet'));
        } elseif ($st['trustPending']) {
            $conn = sprintf($this->Translate('⚠️ Gateway #%d (%s): the control box has not yet released the gateway (ask the grid or metering point operator to enter the gateway SKI).'), $parent, IPS_GetName($parent));
        } elseif ($st['connected']) {
            $box = $st['remoteShipId'] !== '' ? $st['remoteShipId'] : '?';
            $conn = $st['remoteUseCases'] !== []
                ? sprintf($this->Translate('✅ Connected via gateway #%d (%s) to control box %s, offers %s; registered as entity %d (LPC and LPP).'),
                    $parent, IPS_GetName($parent), $box, implode('/', $st['remoteUseCases']), $st['entity'])
                : sprintf($this->Translate('✅ Connected via gateway #%d (%s) to control box %s; registered as entity %d (LPC and LPP).'),
                    $parent, IPS_GetName($parent), $box, $st['entity']);
        } else {
            $conn = $st['lastAlive'] > 0
                ? sprintf($this->Translate('⚠️ Registered with gateway #%d (%s), but currently no connection to the control box (last reachable %s).'), $parent, IPS_GetName($parent), $this->dt($st['lastAlive']))
                : sprintf($this->Translate('⚠️ Registered with gateway #%d (%s), but currently no connection to the control box.'), $parent, IPS_GetName($parent));
        }

        $limitText = function (int $id) use ($st, $now): string {
            $l = $st['limits'][$id];
            if (!$l['active'] || ($l['until'] > 0 && $now >= $l['until'])) {
                return $this->Translate('none');
            }
            return $l['until'] > 0
                ? sprintf($this->Translate('%s kW until %s'), $this->fmt($l['w'] / 1000), $this->dt($l['until']))
                : sprintf($this->Translate('%s kW (no time limit)'), $this->fmt($l['w'] / 1000));
        };
        switch ($parent === 0 ? 'none' : $st['mode']) {
            case 'none':
                $state = 'ℹ️ Without an EEBUS gateway, no limit applies.';
                break;
            case 'failsafe':
                $fsEnd = $st['failsafeSince'] + $this->eebusFailsafeDuration($st);
                $state = sprintf($this->Translate('⛔ Failsafe state since %s, at the latest until %s (or until the control box sends a new limit).'), $this->dt($st['failsafeSince']), $this->dt($fsEnd));
                break;
            case 'autonomous':
                $state = '⚠️ Failure longer than the minimum duration: no limit until the control box is reachable again.';
                break;
            case 'unpaired':
                $state = 'ℹ️ Never connected to a control box: no limit.';
                break;
            default:
                $state = sprintf($this->Translate('Controlled by the control box. Consumption limit: %s. Feed-in limit: %s.'), $limitText(self::LIMIT_CONSUMPTION), $limitText(self::LIMIT_PRODUCTION));
        }

        $lines = [
            'EEBusConnectionLine' => ['caption' => $conn],
            'EEBusStateLine'      => ['caption' => $state],
            'EEBusFailsafeLine'   => ['caption' => sprintf($this->Translate('Failsafe values: %s.'), $this->eebusFailsafeText($st))],
        ];
        $lines['EEBusProductionNominalLine'] = $this->ReadPropertyFloat('EEBusProductionNominalKw') > 0
            ? ['caption' => sprintf($this->Translate('✏️ Reference value for the feed-in limit in percent: %s kWp (your own entry, must match the PV capacity in the EMS).'), $this->fmt($this->ReadPropertyFloat('EEBusProductionNominalKw')))]
            : ['caption' => '⛔ Mandatory once the control box limits feed-in: without the PV capacity SteuerboxHub reports 0 % (no feed-in) for every feed-in limit, because watts cannot be converted into percent.', 'color' => 0xFF0000];
        return $lines;
    }
}
