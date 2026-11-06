<?php

// ===========================================================================
// SteuerboxHubEEBUS — Brücke zwischen dem NRG-Stack EEBUS-Gateway und
// SteuerboxHub (Transportweg 'eebus').
//
// Warum eine eigene Instanz: Damit sich eine Instanz in der Konsole an das
// Gateway hängen lässt, braucht ihre module.json parentRequirements und
// implemented. Bei SteuerboxHub selbst hätte das jede Instanz auf dem
// Kontakte-Weg mit dem Balken „benötigt eine übergeordnete Instanz“ versehen
// (live gesehen 27.10.2026, gleicher Fund wie MeterHub/InverterHub 19.09.2026).
// Die Brücke trägt die Anforderung allein; SteuerboxHub bleibt ohne Elternteil.
//
// Die Brücke kennt keine §14a-Logik und hält keinen Zustand: Anfragen von
// SteuerboxHub (SBHE_Forward) gehen mit DataID und Sender = Brücke ans
// Gateway, Ereignisse des Gateways gehen an SBH_ProcessEEBus der
// SteuerboxHub-Instanzen. Die Zustandsmaschine bleibt in SteuerboxHub.
// ===========================================================================

class SteuerboxHubEEBUS extends IPSModule
{
    private const SBH_MODULE_ID         = '{B76BE0BA-DF99-4B81-81BD-636A610011EE}';
    private const EEBGW_MODULE_ID       = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    private const EEBGW_DATA_TO_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';

    public function Create()
    {
        parent::Create();
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
        $this->updateStatus();
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if (($message === FM_CONNECT || $message === FM_DISCONNECT) && IPS_GetKernelRunlevel() === KR_READY) {
            $this->updateStatus();
            // SteuerboxHub meldet sich beim neuen Gateway an (sonst spätestens mit seinem 10-s-Timer).
            foreach ($this->users() as $sbh) {
                if (function_exists('SBH_CheckEEBus')) {
                    try {
                        SBH_CheckEEBus($sbh);
                    } catch (Throwable $e) {
                        $this->LogMessage(sprintf($this->Translate('SteuerboxHub #%d unreachable: %s'), $sbh, $e->getMessage()), KL_WARNING);
                    }
                }
            }
        }
    }

    /** SBHE_Forward($id, $json): Anfrage nach Kind-Schnittstelle 1.0 ans Gateway; Antwort als JSON-String, '' ohne Gateway. */
    public function Forward(string $json): string
    {
        if ($this->gatewayID() === 0) {
            return '';
        }
        $request = json_decode($json, true);
        if (!is_array($request)) {
            return '';
        }
        $request = ['DataID' => self::EEBGW_DATA_TO_GATEWAY, 'Sender' => $this->InstanceID] + $request;
        try {
            return (string) $this->SendDataToParent(json_encode($request));
        } catch (Throwable $e) {
            $this->LogMessage(sprintf($this->Translate('Request %s to the gateway failed: %s'), $request['Cmd'] ?? '?', $e->getMessage()), KL_WARNING);
            return '';
        }
    }

    public function ReceiveData($JSONString)
    {
        foreach ($this->users() as $sbh) {
            if (!function_exists('SBH_ProcessEEBus')) {
                break;
            }
            try {
                SBH_ProcessEEBus($sbh, $this->InstanceID, (string) $JSONString);
            } catch (Throwable $e) {
                $this->LogMessage(sprintf($this->Translate('Forwarding to SteuerboxHub #%d failed: %s'), $sbh, $e->getMessage()), KL_WARNING);
            }
        }
        return '';
    }

    /** Alle SteuerboxHub-Instanzen; welche diese Brücke nutzt, entscheidet SteuerboxHub selbst (Brücken-ID im Aufruf). */
    private function users(): array
    {
        return array_values(array_filter((array) @IPS_GetInstanceListByModuleID(self::SBH_MODULE_ID), 'IPS_InstanceExists'));
    }

    private function gatewayID(): int
    {
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return 0;
        }
        return (string) (IPS_GetInstance($parent)['ModuleInfo']['ModuleID'] ?? '') === self::EEBGW_MODULE_ID ? $parent : 0;
    }

    private function updateStatus(): void
    {
        $this->SetStatus($this->gatewayID() > 0 ? 102 : 104);
    }

    public function GetConfigurationForm()
    {
        $gw = $this->gatewayID();
        $line = $gw > 0
            ? sprintf($this->Translate('✅ Connected to EEBUS gateway #%d (%s).'), $gw, IPS_GetName($gw))
            : 'ℹ️ No EEBUS gateway assigned yet: choose “Change gateway” above and select the NRG-Stack EEBUS Gateway (or create a new one).';
        $users = array_map(function ($i) {
            return '#' . $i . ' (' . IPS_GetName($i) . ')';
        }, $this->users());
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'This connector links SteuerboxHub to the NRG-Stack EEBUS Gateway. It only passes data on: settings, state and limits are in the SteuerboxHub instance (transport path EEBUS).'],
                ['type' => 'Label', 'caption' => $line],
                ['type' => 'Label', 'caption' => $users === []
                    ? 'ℹ️ No SteuerboxHub instance found.'
                    : sprintf($this->Translate('SteuerboxHub instances: %s. Which one uses this connector is shown there in the EEBUS section.'), implode(', ', $users))],
            ],
            'actions' => [],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Connected to the EEBUS gateway.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No EEBUS gateway assigned yet.'],
            ],
        ]);
    }
}
