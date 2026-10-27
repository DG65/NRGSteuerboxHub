<?php
/**
 * test-eebus.php — Prüfstand für den EEBUS-Weg von SteuerboxHub ohne laufendes Symcon.
 *
 * Teil 1 (immer): Gateway-Attrappe nach Kind-Schnittstelle 1.0 (EEBUSGateway/.docs/
 *   kind-schnittstelle.md). Prüft Eigenständigkeit (ohne Gateway), Anmeldung (LPC/LPP als
 *   ControllableSystem, Feature-Daten), Grenzen, Befristungsende, Heartbeat-Ausfall,
 *   Verbindungsabbruch, Neustart, Mindestdauer, Formular und den Kontakte-Weg.
 * Teil 2 (--gateway <Pfad zum EEBUSGateway-Repo>): dasselbe Kind am echten Gateway-Code
 *   (Anmeldung, SetData ins Gerätemodell, Schreibbefehl über das echte Zusammenführen,
 *   Heartbeat-Ausfall als echtes Gateway-Ereignis).
 *
 * Aufruf:   php .tools/test-eebus.php [--gateway /pfad/EEBUSGateway]
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IPS_KERNELSTARTED = 10001;
const IM_CHANGESTATUS = 10505;
const FM_CONNECT = 11101;
const FM_DISCONNECT = 11102;
const VM_UPDATE = 10603;
const IS_ACTIVE = 102;
const KL_MESSAGE = 10201;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10205;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_STRING = 3;

const GW_ID = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
const TO_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
const TO_CHILD = '{12D8B8A8-3EE7-491F-A68A-D632304165D4}';

$GLOBALS['INST'] = [];
$GLOBALS['VARS'] = [];
$GLOBALS['NOW'] = 1793000000;
$GLOBALS['FEHLER'] = 0;
$GLOBALS['OK'] = 0;

function pruefe(bool $b, string $text): void
{
    if ($b) {
        $GLOBALS['OK']++;
        echo "  ok   $text\n";
    } else {
        $GLOBALS['FEHLER']++;
        echo "  FEHL $text\n";
    }
}

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['INST'][$id]); }
function IPS_VariableExists(int $id): bool { return array_key_exists($id, $GLOBALS['VARS']); }
function GetValue(int $id) { return $GLOBALS['VARS'][$id]; }
function IPS_GetName(int $id): string { return $GLOBALS['INST'][$id]->name ?? ('#' . $id); }
function IPS_GetInstance(int $id): array
{
    $i = $GLOBALS['INST'][$id];
    return ['ConnectionID' => $i->parentId ?? 0, 'InstanceStatus' => $i->status ?? 102, 'ModuleInfo' => ['ModuleID' => $i->modul ?? '']];
}
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config); }
function IPS_SetConfiguration(int $id, string $c): bool { $GLOBALS['INST'][$id]->config = array_merge($GLOBALS['INST'][$id]->config, json_decode($c, true)); return true; }
function IPS_SetProperty(int $id, string $n, $v): bool { $GLOBALS['INST'][$id]->config[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $i = $GLOBALS['INST'][$id]; if (method_exists($i, 'anwenden')) { $i->anwenden(); } return true; }
function IPS_CreateInstance(string $guid): int
{
    $id = 20000 + count($GLOBALS['INST']);
    if ($guid === '{1DEF9F7C-FFF8-4DA3-97ED-A1F6F9F587C5}') {
        (new TestBridge($id))->Create();
    } else {
        new FakeSocket($id);
    }
    return $id;
}
function IPS_ConnectInstance(int $id, int $parent): bool { $GLOBALS['INST'][$id]->parentId = $parent; return true; }
function IPS_DeleteInstance(int $id): bool { unset($GLOBALS['INST'][$id]); return true; }
function IPS_SetName(int $id, string $n): bool { $GLOBALS['INST'][$id]->name = $n; return true; }
function IPS_GetParent(int $id): int { return 0; }
function IPS_SetParent(int $id, int $p): bool { return true; }
function IPS_GetInstanceListByModuleID(string $guid): array
{
    $ids = [];
    foreach ($GLOBALS['INST'] as $id => $i) {
        if (($i->modul ?? '') === $guid) { $ids[] = $id; }
    }
    return $ids;
}
// Von Symcon erzeugte Präfix-Funktionen der beiden Modultypen.
function SBH_ProcessEEBus(int $id, int $bridge, string $json) { return $GLOBALS['INST'][$id]->ProcessEEBus($bridge, $json); }
function SBH_CheckEEBus(int $id) { return $GLOBALS['INST'][$id]->CheckEEBus(); }
function SBHE_Forward(int $id, string $json): string { return $GLOBALS['INST'][$id]->Forward($json); }
function IPS_GetLibrary(string $guid): array
{
    $dir = $guid === '{F790C1F0-4955-4C43-81F4-B4CBD415D0E1}' ? dirname(__DIR__) : $GLOBALS['GW_DIR'];
    $l = json_decode(file_get_contents($dir . '/library.json'), true);
    return ['Version' => $l['version'], 'Build' => $l['build']];
}

/** Gemeinsame Symcon-Attrappe für SteuerboxHub und (Teil 2) das echte Gateway. */
class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $buffers = [];
    public $timers = [];
    public $vars = [];
    public $form = [];
    public $status = 0;
    public $parentId = 0;
    public $name = 'Instanz';
    public $modul = '';
    public $log = [];
    public $debug = [];
    public $messages = [];
    public $gesendet = [];
    public $anKinder = [];
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeFloat($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return (float) $this->props[$n]; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    protected function ReadAttributeFloat($n) { return (float) $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeFloat($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RequireParent($g) {}
    protected function GetMessageList() { return $this->messages; }
    protected function RegisterMessage($id, $m) { $this->messages[$id][] = $m; }
    protected function UnregisterMessage($id, $m) { $this->messages[$id] = array_values(array_diff($this->messages[$id] ?? [], [$m])); if ($this->messages[$id] === []) { unset($this->messages[$id]); } }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetStatus() { return $this->status; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function SendDebug($t, $m, $f) { $this->debug[] = "$t: $m"; }
    protected function LogMessage($m, $t) { $this->log[] = $m; }
    protected function MaintainVariable($ident, $name, $typ, $profil, $pos, $behalten) { $this->vars[$ident] = $this->vars[$ident] ?? null; }
    protected function GetValue($ident) { return $this->vars[$ident] ?? null; }
    protected function SetValue($ident, $v) { $this->vars[$ident] = $v; }
    protected function UpdateFormField($f, $p, $v) { $this->form[$f][$p] = $v; }
    protected function SendDataToParent($json)
    {
        $parent = $GLOBALS['INST'][$this->parentId] ?? null;
        if ($parent !== null && method_exists($parent, 'ForwardData')) {
            return $parent->ForwardData($json);
        }
        // Gateway → Client Socket: Bytes mitschreiben.
        $d = json_decode($json, true);
        $this->gesendet[] = mb_convert_encoding($d['Buffer'] ?? '', 'ISO-8859-1', 'UTF-8');
        return '';
    }
    protected function SendDataToChildren($json)
    {
        $this->anKinder[] = json_decode($json, true);
        foreach ($GLOBALS['INST'] as $i) {
            if (($i->parentId ?? 0) === $this->InstanceID && method_exists($i, 'ReceiveData')) {
                $i->ReceiveData($json);
            }
        }
    }
}

class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false, 'Host' => '', 'Port' => 0];
    public $status = 104;
    public $modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public $name = 'Client Socket';
    public $parentId = 0;
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function anwenden(): void { $this->status = $this->config['Open'] ? 102 : 104; }
}

/** Gateway-Attrappe: nimmt Anfragen nach Kind-Schnittstelle 1.0 an, verteilt Ereignisse. */
class FakeGateway
{
    public $InstanceID;
    public $parentId = 0;
    public $status = 102;
    public $modul = GW_ID;
    public $name = 'EEBUS-Gateway (Attrappe)';
    public $anfragen = [];
    public $daten = [];
    public $state = ['connected' => false, 'heartbeatLost' => false, 'trustPending' => false, 'remoteShipId' => '', 'contractVersion' => '1.0'];
    public $registerAntwort = null;
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function ForwardData($json)
    {
        $d = json_decode($json, true);
        $this->anfragen[] = $d;
        switch ($d['Cmd']) {
            case 'Register':
                return json_encode($this->registerAntwort ?? ['ok' => true, 'interface' => '1.0', 'entity' => 1, 'features' => []]);
            case 'SetData':
                $this->daten[$d['Function']] = $d['Data'];
                return json_encode(['ok' => true]);
            case 'GetState':
                return json_encode($this->state);
        }
        return json_encode(['ok' => true]);
    }
    public function ereignis(array $e): void
    {
        $paket = json_encode(['DataID' => TO_CHILD, 'Target' => (int) ($e['owner'] ?? 0)] + $e);
        foreach ($GLOBALS['INST'] as $i) {
            if (($i->parentId ?? 0) === $this->InstanceID && method_exists($i, 'ReceiveData')) {
                $i->ReceiveData($paket);
            }
        }
    }
    public function cmds(): array { return array_column($this->anfragen, 'Cmd'); }
    public function letzte(string $function): ?array
    {
        foreach (array_reverse($this->anfragen) as $a) {
            if (($a['Function'] ?? '') === $function) {
                return $a['Data'];
            }
        }
        return null;
    }
}

require dirname(__DIR__) . '/SteuerboxHub/module.php';
require dirname(__DIR__) . '/SteuerboxHubEEBUS/module.php';

class TestSBH extends SteuerboxHub
{
    public $name = 'SteuerboxHub (Test)';
    public $modul = '{B76BE0BA-DF99-4B81-81BD-636A610011EE}';
    protected function now(): int { return $GLOBALS['NOW']; }
}

class TestBridge extends SteuerboxHubEEBUS
{
    public $name = 'EEBUS-Anbindung (Test)';
    public $modul = '{1DEF9F7C-FFF8-4DA3-97ED-A1F6F9F587C5}';
}

/** ID der Brücke zu einer SteuerboxHub-Instanz im Prüfstand. */
function B(int $sbhId): int { return $sbhId + 100000; }

/** SteuerboxHub ohne Elternteil; mit Gateway hängt davor eine Brücke (ID = B($id)) am Gateway. */
function sbh(int $id, int $gateway, array $props = []): TestSBH
{
    if ($gateway > 0) {
        $b = new TestBridge(B($id));
        $b->parentId = $gateway;
        $b->Create();
        $b->ApplyChanges();
        $props = ['EEBusBridgeID' => B($id)] + $props;
    }
    $s = new TestSBH($id);
    $s->Create();
    $s->props = array_merge($s->props, $props);
    $s->ApplyChanges();
    return $s;
}

function lpcWrite(int $owner, array $changes, ?array $data = null): array
{
    return ['Event' => 'write', 'owner' => $owner, 'entity' => 1, 'feature' => 'LoadControl', 'function' => 'loadControlLimitListData',
        'changes' => $changes, 'data' => $data ?? ['loadControlLimitData' => $changes], 'bound' => true];
}

function vor(int $s): void { $GLOBALS['NOW'] += $s; }

// ---------------------------------------------------------------------------
echo "Teil 1: SteuerboxHub am Gateway (Attrappe)\n";

echo " Eigenständigkeit\n";
$solo = sbh(30001, 0, ['Transport' => 1]);
$st = $solo->GetState();
pruefe($solo->status === 104, 'EEBUS ohne Gateway: Status 104 (inaktiv, kein Fehler)');
pruefe($st['source'] === 'eebus' && $st['loadDimmActive'] === false && $st['feedInLimitPercent'] === 100.0 && $st['contractVersion'] === '1.0', 'EEBUS ohne Gateway: GetState liefert „keine Begrenzung“, Vertrag 1.0');
$solo->CheckEEBus();
pruefe($solo->status === 104, 'Timer ohne Gateway: läuft ohne Fehler durch');
$f = json_encode(json_decode($solo->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, 'Noch keine EEBUS-Anbindung') !== false && strpos($f, 'EEBUS-Anbindung anlegen') !== false && strpos($f, 'wird beim Öffnen berechnet') === false, 'Formular ohne Gateway: ℹ️-Zeile statt Platzhalter');

echo " Anmeldung\n";
$gw = new FakeGateway(31000);
$sb = sbh(31001, 31000, ['Transport' => 1, 'EEBusConsumptionNominalKw' => 22.0, 'EEBusProductionNominalKw' => 10.0, 'LoadPMin' => 5.88]);
$reg = $gw->anfragen[0] ?? [];
pruefe(($reg['Cmd'] ?? '') === 'Register' && $reg['DataID'] === TO_GATEWAY && $reg['Sender'] === B(31001), 'Register mit DataID und Sender = Brücke');
$ucs = $reg['Entity']['useCases'] ?? [];
pruefe(($reg['Entity']['type'] ?? '') === 'CEM' && count($ucs) === 2
    && array_column($ucs, 'name') === ['limitationOfPowerConsumption', 'limitationOfPowerProduction']
    && array_unique(array_column($ucs, 'actor')) === ['ControllableSystem'] && $ucs[0]['scenarios'] === [1, 2, 3, 4], 'Entität CEM mit LPC und LPP als ControllableSystem, Szenarien 1–4');
pruefe(array_column($reg['Entity']['features'], 'type') === ['LoadControl', 'DeviceConfiguration', 'ElectricalConnection'], 'Features LoadControl, DeviceConfiguration, ElectricalConnection (Server)');
$desc = $gw->daten['loadControlLimitDescriptionListData']['loadControlLimitDescriptionData'] ?? [];
pruefe(count($desc) === 2 && $desc[0]['limitId'] === 0 && $desc[0]['limitDirection'] === 'consume' && $desc[1]['limitDirection'] === 'produce' && $desc[0]['scopeType'] === 'activePowerLimit', 'Grenzbeschreibung: 0 = Bezug, 1 = Einspeisung');
$lim = $gw->daten['loadControlLimitListData']['loadControlLimitData'] ?? [];
pruefe(count($lim) === 2 && $lim[0]['isLimitActive'] === false && $lim[1]['isLimitActive'] === false, 'Aktuelle Grenzen gemeldet (beide inaktiv)');
$kv = array_column($gw->daten['deviceConfigurationKeyValueListData']['deviceConfigurationKeyValueData'] ?? [], null, 'keyId');
pruefe(($kv[0]['value']['scaledNumber']['number'] ?? 0) === 5880 && ($kv[1]['value']['duration'] ?? '') === 'PT2H' && !isset($kv[2]['value']), 'Ausfallwerte: Bezug = Pmin 5880 W, Mindestdauer PT2H, Einspeisung ohne Vorgabe');
$kn = array_column($gw->daten['deviceConfigurationKeyValueDescriptionListData']['deviceConfigurationKeyValueDescriptionData'] ?? [], 'keyName');
pruefe($kn === ['failsafeConsumptionActivePowerLimit', 'failsafeDurationMinimum', 'failsafeProductionActivePowerLimit'], 'Ausfallwert-Beschreibung mit den drei keyNames');
$ch = $gw->daten['electricalConnectionCharacteristicListData']['electricalConnectionCharacteristicData'] ?? [];
pruefe(($ch[0]['characteristicType'] ?? '') === 'contractualConsumptionNominalMax' && $ch[0]['value']['number'] === 22000 && $ch[1]['value']['number'] === 10000, 'Nennleistung Bezug 22 kW, Erzeugung 10 kW');
pruefe($sb->status === 210, 'Vor der ersten Verbindung: Status 210 (wartet)');
pruefe($sb->GetState()['loadDimmActive'] === false, 'Nie gekoppelt: keine Begrenzung');

$gw->state = ['connected' => true, 'heartbeatLost' => false, 'trustPending' => false, 'remoteShipId' => 'Demo-ControlBox-123'];
$vorher = count($gw->anfragen);
$gw->ereignis(['Event' => 'connection', 'state' => 'connected', 'shipId' => 'Demo-ControlBox-123']);
pruefe($sb->status === 102, 'Verbindung steht: Status 102');
pruefe(count(array_filter(array_slice($gw->anfragen, $vorher), function ($a) { return $a['Cmd'] === 'SetData'; })) >= 5, 'Nach Verbindungsaufbau alle Feature-Daten erneut gemeldet');

echo " Grenzen\n";
$t0 = $GLOBALS['NOW'];
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => 42, 'scale' => 2]]]));
$st = $sb->GetState();
pruefe($st['loadDimmActive'] === true && $st['loadPMin'] === 4.2 && $st['loadSince'] === $t0, 'LPC 4200 W (number 42, scale 2): loadDimmActive, loadPMin 4,2 kW, loadSince');
pruefe($st['feedInDimmActive'] === false && $st['feedInLimitPercent'] === 100.0, 'Einspeisung davon unberührt');
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 1, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => -3000, 'scale' => 0]]]));
$st = $sb->GetState();
pruefe($st['feedInDimmActive'] === true && $st['feedInLimitPercent'] === 30.0 && $st['loadPMin'] === 4.2, 'LPP −3000 W bei 10 kW Nennleistung: 30 %, Bezugsgrenze bleibt');
$gw->ereignis(lpcWrite(99999, [['limitId' => 0, 'isLimitActive' => false, 'value' => ['number' => 0, 'scale' => 0]]]));
pruefe($sb->GetState()['loadDimmActive'] === true, 'Ereignis für eine andere Instanz (Target) wird ignoriert');
vor(60);
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => 6000, 'scale' => 0]]]));
$st = $sb->GetState();
pruefe($st['loadPMin'] === 6.0 && $st['loadSince'] === $t0, 'Neuer Wert bei aktiver Grenze: loadPMin 6 kW, loadSince bleibt');
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => 6000, 'scale' => 0]]]));
$st = $sb->GetState();
pruefe($st['loadDimmActive'] === false && $st['loadPMin'] === 0.0 && $st['loadSince'] === 0, 'Grenze aufgehoben: keine Begrenzung, loadSince 0');
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 1, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => -3000, 'scale' => 0]]]));
pruefe($sb->GetState()['feedInDimmActive'] === false, 'Einspeisegrenze aufgehoben');

echo " Befristungsende\n";
$t1 = $GLOBALS['NOW'];
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => 100, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT1H2M3S']]]));
pruefe($sb->GetState()['loadPMin'] === 0.1, 'Befristete Grenze 100 W für PT1H2M3S aktiv');
vor(3722);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadDimmActive'] === true, '1 s vor Ablauf noch aktiv');
vor(1);
pruefe($sb->GetState()['loadDimmActive'] === false, 'Mit Ablauf sofort inaktiv (GetState, ohne auf den Timer zu warten)');
$sb->CheckEEBus();
$lim = array_column($gw->letzte('loadControlLimitListData')['loadControlLimitData'] ?? [], null, 'limitId');
pruefe(($lim[0]['isLimitActive'] ?? true) === false && !isset($lim[0]['timePeriod']), 'Timer meldet der Steuerbox die abgelaufene Grenze als inaktiv zurück');
pruefe(count(array_filter($sb->log, function ($l) { return strpos($l, 'Befristung der Bezugsgrenze abgelaufen') !== false; })) === 1, 'Befristungsende dauerhaft protokolliert (einmal)');
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 2000, 'scale' => 0], 'timePeriod' => ['endTime' => gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['NOW'] + 600)]]]));
vor(599);
pruefe($sb->GetState()['loadDimmActive'] === true, 'Absolute endTime: vor Ablauf aktiv');
vor(1);
pruefe($sb->GetState()['loadDimmActive'] === false, 'Absolute endTime: danach inaktiv');
$sb->CheckEEBus();

echo " Ausfallwerte der Steuerbox\n";
$gw->ereignis(['Event' => 'write', 'owner' => B(31001), 'entity' => 1, 'feature' => 'DeviceConfiguration', 'function' => 'deviceConfigurationKeyValueListData',
    'changes' => [], 'bound' => true, 'data' => ['deviceConfigurationKeyValueData' => [
        ['keyId' => 0, 'value' => ['scaledNumber' => ['number' => 3500, 'scale' => 0]], 'isValueChangeable' => true],
        ['keyId' => 1, 'value' => ['duration' => 'PT1H'], 'isValueChangeable' => true],
        ['keyId' => 2, 'value' => ['scaledNumber' => ['number' => 5000, 'scale' => 0]], 'isValueChangeable' => true]]]]);
$kv = array_column($gw->letzte('deviceConfigurationKeyValueListData')['deviceConfigurationKeyValueData'] ?? [], null, 'keyId');
pruefe(($kv[1]['value']['duration'] ?? '') === 'PT2H', 'Mindestdauer PT1H (unter 2 h) nicht umsetzbar: tatsächlicher Stand PT2H zurückgemeldet');
pruefe(($kv[0]['value']['scaledNumber']['number'] ?? 0) === 3500 && ($kv[2]['value']['scaledNumber']['number'] ?? 0) === 5000, 'Geschriebene Ausfallgrenzen 3500 W / 5000 W übernommen');

echo " Heartbeat-Ausfall\n";
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 8000, 'scale' => 0]]]));
$tf = $GLOBALS['NOW'];
$gw->state['heartbeatLost'] = true;
$gw->ereignis(['Event' => 'heartbeat', 'state' => 'lost', 'since' => $tf - 121]);
$st = $sb->GetState();
pruefe($st['loadDimmActive'] === true && $st['loadPMin'] === 3.5, 'Heartbeat weg: Ausfallgrenze Bezug 3,5 kW statt 8 kW');
pruefe($st['feedInDimmActive'] === true && $st['feedInLimitPercent'] === 50.0, 'Ausfallgrenze Einspeisung 5 kW = 50 %');
pruefe($sb->status === 204, 'Status 204 (Ausfall)');
pruefe(count(array_filter($sb->log, function ($l) { return strpos($l, 'Ausfallgrenze gilt') !== false; })) === 1, 'Ausfall dauerhaft protokolliert');
vor(300);
$gw->state['heartbeatLost'] = false;
$gw->ereignis(['Event' => 'heartbeat', 'state' => 'ok']);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadPMin'] === 3.5, 'Heartbeat wieder da, aber kein neuer Grenzwert: Ausfallgrenze bleibt');
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 7000, 'scale' => 0]]]));
$st = $sb->GetState();
pruefe($st['loadPMin'] === 7.0 && $st['feedInDimmActive'] === false && $sb->status === 102, 'Neuer Grenzwert: Ausfall beendet, 7 kW, Status 102');

echo " Verbindungsabbruch ohne Heartbeat-Ereignis\n";
$gw->state['connected'] = false;
$gw->ereignis(['Event' => 'connection', 'state' => 'disconnected']);
vor(115);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadPMin'] === 7.0 && $sb->status === 102, 'Bis 120 s nach dem letzten Lebenszeichen gilt die Grenze weiter');
vor(10);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadPMin'] === 3.5 && $sb->status === 204, 'Danach Ausfallgrenze (Timer erkennt den Ausfall selbst)');

echo " Mindestdauer\n";
vor(7199);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadDimmActive'] === true, 'Vor Ablauf der Mindestdauer: Ausfallgrenze');
vor(1);
$sb->CheckEEBus();
$st = $sb->GetState();
pruefe($st['loadDimmActive'] === false && $st['feedInDimmActive'] === false && $sb->status === 204, 'Nach 2 h ohne Steuerbox: unbegrenzt (autonom), Status bleibt 204');
vor(3600);
$sb->CheckEEBus();
pruefe($sb->GetState()['loadDimmActive'] === false, 'Autonom: kein erneuter Ausfallzustand ohne Verbindung');
$gw->state['connected'] = true;
$gw->ereignis(['Event' => 'connection', 'state' => 'connected', 'shipId' => 'Demo-ControlBox-123']);
pruefe($sb->status === 102 && $sb->GetState()['loadDimmActive'] === false, 'Steuerbox zurück: gesteuert, ohne alte Grenzen');

echo " Neustart\n";
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 4200, 'scale' => 0]]]));
$attrs = $sb->attrs;
$gw->state['connected'] = false;
vor(60);
$neu = new TestSBH(31001);
$neu->Create();
$neu->props = $sb->props;
$neu->attrs = $attrs;
$neu->ApplyChanges();
pruefe($neu->GetState()['loadPMin'] === 4.2 && $neu->status === 102, 'Neustart nach 60 s: letzter Stand gilt weiter (Init)');
vor(61);
$neu->CheckEEBus();
pruefe($neu->status === 204 && $neu->GetState()['loadPMin'] === 3.5, 'Keine Steuerbox binnen 120 s: Ausfallgrenze');
$sb = $neu;


echo " Formular\n";
$gw->state['connected'] = true;
$gw->ereignis(['Event' => 'connection', 'state' => 'connected', 'shipId' => 'Demo-ControlBox-123']);
$form = json_decode($sb->GetConfigurationForm(), true);
$f = json_encode($form, JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, '✅ Verbunden über Anbindung #' . B(31001) . ' und Gateway #31000') !== false && strpos($f, 'Demo-ControlBox-123') !== false, 'Formular: ✅-Zeile mit Gateway und Steuerbox');
pruefe(strpos($f, 'Ausfallzustand seit') !== false && strpos($f, 'Bezug 3,5 kW (von der Steuerbox)') !== false, 'Formular: Ausfallzustand und Herkunft der Ausfallwerte');
pruefe(strpos($f, 'wird beim Öffnen berechnet') === false, 'Formular: keine Platzhalter mehr');
$vis = [];
array_walk_recursive($form, function () {});
foreach ($form['elements'] as $e) {
    if (isset($e['name'])) {
        $vis[$e['name']] = $e['visible'] ?? true;
    }
}
pruefe(($vis['EEBusPanel'] ?? false) === true && ($vis['ContactsPanel'] ?? true) === false, 'Formular: nur das EEBUS-Panel sichtbar');
$sb->ToggleTransport(0);
pruefe(($sb->form['ContactsPanel']['visible'] ?? null) === true && ($sb->form['EEBusPanel']['visible'] ?? null) === false, 'Umschalten des Transportwegs blendet die Panels live um');
$codes = array_column(json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/form.json'), true)['status'], 'code');
pruefe(!array_diff([102, 104, 202, 203, 204, 205, 210], $codes), 'Jeder Statuscode hat einen Eintrag in form.json');

echo " Brücke (EEBUS-Anbindung)\n";
pruefe($GLOBALS['INST'][B(31001)]->status === 102, 'Brücke am Gateway: Status 102');
$lose = new TestBridge(36000);
$lose->Create();
$lose->ApplyChanges();
pruefe($lose->status === 104, 'Brücke ohne Gateway: Status 104');
$vorher = $sb->GetState()['loadPMin'];
$fremd = sbh(36001, 0, ['Transport' => 1, 'EEBusBridgeID' => 36000]);
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 9999, 'scale' => 0]]]));
pruefe($fremd->GetState()['loadDimmActive'] === false && $fremd->status === 104, 'Ereignis über fremde Brücke erreicht die andere SteuerboxHub-Instanz nicht');
pruefe($sb->GetState()['loadPMin'] === 9.999, 'Eigene Instanz bekommt es (9,999 kW)');
$gws = count(IPS_GetInstanceListByModuleID(GW_ID));
$neuB = $fremd->CreateEEBusBridge();
$nb = $fremd->form['EEBusBridgeID']['value'] ?? 0;
pruefe($nb > 0 && ($GLOBALS['INST'][$nb]->modul ?? '') === '{1DEF9F7C-FFF8-4DA3-97ED-A1F6F9F587C5}' && strpos($neuB, 'angelegt') !== false && strpos($neuB, 'Übernehmen') !== false, '„EEBUS-Anbindung anlegen“: Brücke angelegt, in die Maske eingetragen, Hinweis auf „Übernehmen“');
pruefe($gws > 1 ? ($GLOBALS['INST'][$nb]->parentId === 0 && strpos($neuB, 'Mehrere EEBUS-Gateways') !== false) : true, 'Bei mehreren Gateways nicht raten, sondern Hinweis');


echo " Einspeisung ohne Nennleistung\n";
$gw2 = new FakeGateway(32000);
$gw2->state['connected'] = true;
$ohne = sbh(32001, 32000, ['Transport' => 1]);
$gw2->ereignis(lpcWrite(B(32001), [['limitId' => 1, 'isLimitActive' => true, 'value' => ['number' => -4000, 'scale' => 0]]]));
pruefe($ohne->GetState()['feedInLimitPercent'] === 0.0, 'Ohne Nennleistung: 0 % (sicher für das Netz)');
$f = json_encode(json_decode($ohne->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, '⛔ Pflichtangabe') !== false && strpos($f, '"color":16711680') !== false, 'Formular: Nennleistung als ⛔ Pflichtangabe in Rot');

echo " Anmeldung abgelehnt, Umschalten auf Kontakte\n";
$gw3 = new FakeGateway(33000);
$gw3->registerAntwort = ['ok' => false, 'error' => 'Sender fehlt oder unbekannt'];
$abg = sbh(33001, 33000, ['Transport' => 1]);
pruefe($abg->status === 203 && count(array_filter($abg->log, function ($l) { return strpos($l, 'Anmeldung beim Gateway #33000 fehlgeschlagen') !== false; })) === 1, 'Abgelehnte Anmeldung: Status 203 und Protokolleintrag');
$gw3->registerAntwort = null;
$abg->CheckEEBus();
pruefe($abg->status === 210, 'Timer wiederholt die Anmeldung');
$abg->props['Transport'] = 0;
$abg->ApplyChanges();
pruefe(end($gw3->anfragen)['Cmd'] === 'Unregister' && $abg->status === 104, 'Kontakte gewählt: beim Gateway abgemeldet');
$ohne->props['Transport'] = 0;
$ohne->props['LoadContactVarID'] = 0;
$ohne->ApplyChanges();
$st = $ohne->GetState();
pruefe($st['source'] === 'contacts' && $st['feedInDimmActive'] === false && $st['feedInLimitPercent'] === 100.0, 'Zurück auf Kontakte: keine EEBUS-Grenze bleibt hängen');


echo " Formular-Konvention\n";
$fk = sbh(35001, 0, ['Transport' => 0]);
$form = json_decode($fk->GetConfigurationForm(), true);
$caps = array_map(function ($e) { return $e['caption'] ?? ($e['name'] ?? $e['type']); }, $form['elements']);
$idx = function (string $needle) use ($caps) { foreach ($caps as $i => $c) { if (strpos((string) $c, $needle) !== false) { return $i; } } return -1; };
pruefe($idx('Wozu dieses Modul') === 0 && $idx('Neu bis Version') === 1 && $idx('Dokumentation') === 2, 'Reihenfolge: Wozu → Neu bis Version → Dokumentation & Hilfe');
pruefe($idx('Rückmeldungen') === count($caps) - 2 && $idx('Über dieses Modul') === count($caps) - 1, 'Rückmeldungen und „Über dieses Modul“ am Ende');
pruefe(strpos($caps[1], '0.3.0') !== false, 'News-Panel nennt die höchste neue Version (0.3.0)');
$lic = json_encode($form["elements"][count($caps) - 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruefe(strpos($lic, '"link":true') !== false && strpos($lic, "echo 'https://github.com/DG65/NRGSteuerboxHub/blob/beta/LICENSE'") !== false, 'Lizenz-Knopf: echo-URL mit link=true, Branch beta');
$doc = json_encode($form['elements'][2], JSON_UNESCAPED_UNICODE);
pruefe(strpos($doc, 'SteuerboxHub Version 0.3.0 (Build 3)') !== false, 'Dokumentation & Hilfe zeigt Version und Build');
$fk->AckPurposeIntro();
$fk->AckNews();
$fk->AckForumHint();
$caps2 = array_map(function ($e) { return $e['caption'] ?? ''; }, json_decode($fk->GetConfigurationForm(), true)['elements']);
pruefe(strpos($caps2[0], 'Dokumentation') !== false && strpos(implode('|', $caps2), 'Rückmeldungen') === false && strpos(implode('|', $caps2), 'Über dieses Modul') !== false, 'Nach Bestätigen: Wozu/Neu/Rückmeldungen weg, Lizenz bleibt');
pruefe($fk->attrs['SeenNews'] === '0.3.0' && ($fk->form['NewsPanel']['visible'] ?? null) === false, 'AckNews speichert die installierte Version und blendet sofort aus');
$fk->attrs['SeenNews'] = '0.2.0';
$caps3 = array_map(function ($e) { return $e['caption'] ?? ''; }, json_decode($fk->GetConfigurationForm(), true)['elements']);
$news = json_encode(json_decode($fk->GetConfigurationForm(), true)['elements'][0], JSON_UNESCAPED_UNICODE);
pruefe(strpos($caps3[0], 'Neu bis Version 0.3.0') !== false && strpos($news, 'Version 0.2.0:') === false, 'Wer 0.2.0 kannte, sieht nur die Neuerungen von 0.3.0');

$txt = $fk->CalculateGZF(4, 0.0);
pruefe(($fk->form['LoadPMin']['value'] ?? 0) === 13.02 && strpos($txt, '13,02 kW') !== false && strpos($txt, 'Übernehmen') !== false, 'GZF-Rechenhilfe: n = 4 → 13,02 kW, Rückmeldung nennt „Übernehmen“');
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.json'), true);
pruefe($mj['aliases'] === ['NRG-Stack SteuerboxHub'], 'Genau ein Alias (ein Eintrag im Anlege-Dialog)');
// SDK-Lebenszyklus-Funktionen haben eine von Symcon vorgegebene Signatur und werden nicht als SBH_-Funktion erzeugt.
$sdk = ['Create', 'ApplyChanges', 'Destroy', 'MessageSink', 'ReceiveData', 'GetConfigurationForm', 'RequestAction'];
$verstoesse = [];
foreach ((new ReflectionClass(SteuerboxHub::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if ($m->getDeclaringClass()->getName() !== SteuerboxHub::class || in_array($m->getName(), $sdk, true)) { continue; }
    foreach ($m->getParameters() as $p) {
        if ($p->isOptional() || !$p->hasType()) { $verstoesse[] = $m->getName() . '($' . $p->getName() . ')'; }
    }
}
pruefe($verstoesse === [], 'Öffentliche SBH_-Funktionen: keine Standardwerte, alle Parameter typisiert (Stolperstein 8/20)' . ($verstoesse ? ': ' . implode(', ', $verstoesse) : ''));

echo " Kontakte-Weg unverändert\n";
$GLOBALS['VARS'][40010] = true;
$k = sbh(34001, 0, ['Transport' => 0, 'LoadContactVarID' => 40010, 'LoadPMin' => 5.04]);
$st = $k->GetState();
pruefe($st['source'] === 'contacts' && $st['loadDimmActive'] === true && $st['loadPMin'] === 5.04 && $k->status === 102, 'Kontakt aktiv: contacts, loadPMin aus der Instanz');
pruefe(strpos((string) file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.php'), 'IPS_LogMessage') === false, 'Logging nur über $this->LogMessage (Store-Checkliste 15)');
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.json'), true);
pruefe($mj['parentRequirements'] === [] && $mj['implemented'] === [], 'SteuerboxHub ohne parentRequirements (kein Balken „benötigt eine übergeordnete Instanz“)');
$bj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHubEEBUS/module.json'), true);
pruefe($bj['parentRequirements'] === [TO_GATEWAY] && $bj['implemented'] === [TO_CHILD] && $bj['prefix'] === 'SBHE' && count($bj['aliases']) === 1, 'Brücke trägt die Kind-Schnittstelle des EEBUS-Gateways, ein Alias');

// ---------------------------------------------------------------------------
$gwArg = array_search('--gateway', $argv, true);
if ($gwArg !== false) {
    $GLOBALS['GW_DIR'] = rtrim((string) $argv[$gwArg + 1], '/');
    echo "\nTeil 2: SteuerboxHub am echten EEBUSGateway-Code ({$GLOBALS['GW_DIR']})\n";
    require $GLOBALS['GW_DIR'] . '/EEBUSGateway/module.php';
    $GLOBALS['NOW'] = time();

    $sock = new FakeSocket(50001);
    $egw = new EEBUSGateway(50002);
    $egw->parentId = 50001;
    $egw->name = 'EEBUS-Gateway';
    $egw->modul = GW_ID;
    $egw->Create();
    $egw->ApplyChanges();
    $kind = sbh(50003, 50002, ['Transport' => 1, 'EEBusProductionNominalKw' => 10.0]);

    $anm = json_decode($egw->attrs['Anmeldungen'], true);
    pruefe(isset($anm[(string) B(50003)]) && count($anm[(string) B(50003)]['useCases']) === 2, 'Gateway hat die Anmeldung der Brücke gespeichert');
    pruefe(json_decode($kind->attrs['EEBusState'], true)['entity'] === 1, 'Gateway vergibt Entität 1');
    $nichtUebernommen = array_filter($kind->log, function ($l) { return strpos($l, 'nicht übernommen') !== false; });
    pruefe($nichtUebernommen === [], 'Alle Feature-Daten vom Gateway angenommen' . ($nichtUebernommen ? ': ' . implode(' | ', $nichtUebernommen) : ''));
    $daten = json_decode($egw->attrs['Daten'], true);
    $modell = EEBGWSession::buildModel($anm);
    $lcIndex = null;
    foreach ($modell['entities'][0]['features'] as $ft) {
        if ($ft['type'] === 'LoadControl') {
            $lcIndex = $ft['index'];
        }
    }
    $schluessel = '1/' . $lcIndex;
    pruefe(isset($daten[$schluessel]['loadControlLimitListData']), 'Grenzen liegen im Gerätemodell des Gateways (' . $schluessel . ')');
    $mDeviceDiag = array_filter($modell['entities'][0]['features'], function ($ft) { return $ft['type'] === 'DeviceDiagnosis'; });
    pruefe(count($mDeviceDiag) === 2, 'Gateway ergänzt DeviceDiagnosis (Heartbeat) selbst');

    // Schreibbefehl wie von der controlbox: ganze Liste, LPP mit altem Stand, LPC neu.
    [$gesamt, $geaendert] = EEBGWSession::zusammenfuehren('loadControlLimitListData', $daten[$schluessel]['loadControlLimitListData'], ['loadControlLimitData' => [
        ['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 100, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT1H2M3S']],
        ['limitId' => 1, 'isLimitActive' => false, 'value' => ['number' => 0, 'scale' => 0]]]], true);
    pruefe(count($geaendert) === 1 && $geaendert[0]['limitId'] === 0, 'Gateway meldet nur die geänderte Grenze');
    $verteilen = new ReflectionMethod(EEBUSGateway::class, 'ereignisVerteilen');
    $verteilen->invoke($egw, ['event' => 'connection', 'state' => 'connected', 'shipId' => 'Demo-ControlBox-123']);
    $verteilen->invoke($egw, ['event' => 'write', 'owner' => B(50003), 'entity' => 1, 'feature' => 'LoadControl', 'function' => 'loadControlLimitListData',
        'changes' => $geaendert, 'data' => $gesamt, 'bound' => true]);
    $st = $kind->GetState();
    pruefe($st['loadDimmActive'] === true && $st['loadPMin'] === 0.1 && $st['feedInDimmActive'] === false, 'Echtes Schreibereignis: LPC 100 W kommt als loadPMin 0,1 kW an');
    $verteilen->invoke($egw, ['event' => 'heartbeat', 'state' => 'lost', 'since' => time() - 130]);
    $st = $kind->GetState();
    pruefe($st['loadPMin'] === 4.2 && $kind->status === 204, 'Echtes Heartbeat-Ereignis: Ausfallgrenze 4,2 kW (Vorgabe Pmin)');
    $egw->attrs['Stand'] = json_encode(['phase' => 'data', 'heartbeatVerloren' => true, 'remoteShipId' => 'Demo-ControlBox-123']);
    $kind->CheckEEBus();
    $zustand = json_decode($kind->attrs['EEBusState'], true);
    pruefe($zustand['connected'] === true && $zustand['heartbeatLost'] === true && $zustand['remoteShipId'] === 'Demo-ControlBox-123', 'GetState des Gateways wird gelesen');
}

echo "\n" . $GLOBALS['OK'] . ' bestanden, ' . $GLOBALS['FEHLER'] . " fehlgeschlagen\n";
exit($GLOBALS['FEHLER'] > 0 ? 1 : 0);
