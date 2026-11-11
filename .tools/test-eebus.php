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
    new FakeSocket($id);
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
function SBH_CheckEEBus(int $id) { return $GLOBALS['INST'][$id]->CheckEEBus(); }
function SBHE_CheckEEBus(int $id) { return $GLOBALS['INST'][$id]->CheckEEBus(); }
function IPS_GetObjectIDByIdent(string $ident, int $parent) { return $GLOBALS['TIMER_IDENT'][$parent][$ident] ?? false; }
function IPS_GetLibrary(string $guid): array
{
    $dir = $guid === '{F790C1F0-4955-4C43-81F4-B4CBD415D0E1}' ? dirname(__DIR__) : $GLOBALS['GW_DIR'];
    $l = json_decode(file_get_contents($dir . '/library.json'), true);
    return ['Version' => $l['version'], 'Build' => $l['build']];
}

/** Gemeinsame Symcon-Attrappe für SteuerboxHub und (Teil 2) das echte Gateway. */
class IPSModule
{
    /** Symcon-Sprache im Prüfstand: 'de' (Standard) oder 'en' (Quelltext bleibt unübersetzt). */
    public static $locale = 'de';
    public static $translateCalls = 0;
    private static $translations = [];

    /** Wie Symcon: Quelltext (Englisch) → Übersetzung aus locale.json des Moduls; unbekannt = unverändert. */
    public function Translate(string $text): string
    {
        self::$translateCalls++;
        if (self::$locale === 'en') {
            return $text;
        }
        $c = new ReflectionClass($this);
        while ($c->getParentClass() && !is_file(dirname($c->getFileName()) . '/module.json')) {
            $c = $c->getParentClass();
        }
        $file = dirname($c->getFileName()) . '/locale.json';
        if (!isset(self::$translations[$file])) {
            self::$translations[$file] = is_file($file) ? (json_decode(file_get_contents($file), true)['translations'][self::$locale] ?? []) : [];
        }
        return self::$translations[$file][$text] ?? $text;
    }

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

/**
 * Bildet die Symcon-Konsole nach (Store-Review 25): sie übersetzt caption und Optionen des Formulars selbst aus locale.json.
 * Die Module übersetzen ihr Formular NICHT; Popups, Meldungen und Texte mit Werten übersetzen sie selbst.
 */
function konsoleUebersetzt(string $json, IPSModule $m): string
{
    $f = json_decode($json, true);
    $walk = function (&$n) use (&$walk, $m) {
        foreach ($n as $k => &$v) {
            if (is_array($v)) { $walk($v); }
            elseif ($k === 'caption' && is_string($v) && $v !== '') { $v = $m->Translate($v); }
        }
    };
    $walk($f);
    return json_encode($f);
}

class TestSBH extends SteuerboxHub
{
    public $name = 'SteuerboxHub (Test)';
    public $modul = '{B76BE0BA-DF99-4B81-81BD-636A610011EE}';
    protected function now(): int { return $GLOBALS['NOW']; }
    public function GetConfigurationForm() { return konsoleUebersetzt(parent::GetConfigurationForm(), $this); }
    public function GetConfigurationFormRoh() { return parent::GetConfigurationForm(); }
}

class TestSBHE extends SteuerboxHubEEBUS
{
    public $name = 'SteuerboxHub EEBUS (Test)';
    public $modul = '{1DEF9F7C-FFF8-4DA3-97ED-A1F6F9F587C5}';
    protected function now(): int { return $GLOBALS['NOW']; }
    public function GetConfigurationForm() { return konsoleUebersetzt(parent::GetConfigurationForm(), $this); }
    public function GetConfigurationFormRoh() { return parent::GetConfigurationForm(); }
}

/** Eigene Instanz-ID als Absender beim Gateway (der EEBUS-Hub meldet sich selbst an, keine Brücke mehr). */
function B(int $id): int { return $id; }

/**
 * Kontakte-Hub (SteuerboxHub) ohne Elternteil. Mit Gateway oder 'Transport' => 1 entsteht der EEBUS-Hub
 * (SteuerboxHubEEBUS), der direkt am Gateway hängt.
 */
function sbh(int $id, int $gateway, array $props = [])
{
    $eebus = $gateway > 0 || ($props['Transport'] ?? 0) === 1;
    unset($props['Transport']);
    $s = $eebus ? new TestSBHE($id) : new TestSBH($id);
    $s->parentId = $gateway;
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
pruefe(strpos($f, 'Noch kein EEBUS-Gateway zugeordnet') !== false && strpos($f, 'wird beim Öffnen berechnet') === false, 'Formular ohne Gateway: ℹ️-Zeile statt Platzhalter');

echo " Anmeldung\n";
$gw = new FakeGateway(31000);
$sb = sbh(31001, 31000, ['Transport' => 1, 'EEBusConsumptionNominalKw' => 22.0, 'EEBusProductionNominalKw' => 10.0, 'LoadPMin' => 5.88]);
$reg = $gw->anfragen[0] ?? [];
pruefe(($reg['Cmd'] ?? '') === 'Register' && $reg['DataID'] === TO_GATEWAY && $reg['Sender'] === B(31001), 'Register mit DataID und Sender = dieser Hub (Datenfluss)');
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
$neu = new TestSBHE(31001);
$neu->parentId = 31000;
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
pruefe(strpos($f, '✅ Verbunden über Gateway #31000') !== false && strpos($f, 'Demo-ControlBox-123') !== false, 'Formular: ✅-Zeile mit Gateway und Steuerbox');
pruefe(strpos($f, 'Ausfallzustand seit') !== false && strpos($f, 'Bezug 3,5 kW (von der Steuerbox)') !== false, 'Formular: Ausfallzustand und Herkunft der Ausfallwerte');
pruefe(strpos($f, 'wird beim Öffnen berechnet') === false, 'Formular: keine Platzhalter mehr');
$vis = [];
array_walk_recursive($form, function () {});
foreach ($form['elements'] as $e) {
    if (isset($e['name'])) {
        $vis[$e['name']] = $e['visible'] ?? true;
    }
}
pruefe(($vis['EEBusPanel'] ?? false) === true && !isset($vis['ContactsPanel']) && !isset($vis['EEBusBridgeID']), 'Formular: EEBUS-Hub zeigt nur das EEBUS-Panel, keine Anbindungs-Auswahl');
$codesE = array_column(json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHubEEBUS/form.json'), true)['status'], 'code');
$codesK = array_column(json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/form.json'), true)['status'], 'code');
pruefe(!array_diff([102, 104, 203, 204, 210], $codesE) && !array_diff([102, 104, 202, 205], $codesK), 'Jeder Statuscode hat einen Eintrag in der form.json seines Moduls');

echo " Zweisprachigkeit (Deutsch/Englisch)\n";
$deutsch = '/[äöüÄÖÜß]|\b(und|nicht|ist|wird|bei|mit|von|für|keine?|Steuerbox|Anbindung|Ausfall\w*|Grenze|Bezug|Einspeisung\w*|Formular|Verbunden|Zustand|Gleichzeitigkeit\w*)\b/u';
$neutral = function (string $t): bool {
    return !preg_match('/\p{L}{3}/u', $t) || preg_match('~^(https?://|echo |/)~', $t) || strpos($t, 'ℹ️ SteuerboxHub') === 0 || in_array($t, ['EEBUS', 'Position', 'SteuerboxHub'], true);
};
$sammle = function ($node) use (&$sammle): array {
    $out = [];
    foreach ((array) $node as $k => $v) {
        if (is_array($v)) { $out = array_merge($out, $sammle($v)); }
        elseif ($k === 'caption' && is_string($v) && $v !== '') { $out[] = $v; }
        elseif ($k === 'onClick' && is_string($v) && preg_match_all("/echo '([^']*)';/u", $v, $m)) { $out = array_merge($out, $m[1]); }
    }
    return $out;
};
$brueckeInst = sbh(35100, 0, []);   // Kontakte-Hub, das zweite Modul der Bibliothek
IPSModule::$locale = 'en';
$enTexte  = array_merge($sammle(json_decode($sb->GetConfigurationForm(), true)), $sammle(json_decode($brueckeInst->GetConfigurationForm(), true)));
$enRueck  = $sb->CalculateGZF(4, 0.0);
$enReset  = $sb->ResetFormLayout();
$sb->log  = [];
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 5000, 'scale' => 0]]]));
$enLog    = implode(' | ', $sb->log);
$enForm   = json_encode(json_decode($sb->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
$zeit     = new ReflectionMethod($sb, 'dt');
$zahl     = new ReflectionMethod($sb, 'fmt');
$enZeit   = $zeit->invoke($sb, mktime(14, 30, 0, 11, 5, 2026));
$enZahl   = $zahl->invoke($sb, 4.2);
IPSModule::$locale = 'de';
$deTexte  = array_merge($sammle(json_decode($sb->GetConfigurationForm(), true)), $sammle(json_decode($brueckeInst->GetConfigurationForm(), true)));
$deZeit   = $zeit->invoke($sb, mktime(14, 30, 0, 11, 5, 2026));
$deZahl   = $zahl->invoke($sb, 4.2);

$deutschImEn = array_values(array_filter($enTexte, function ($t) use ($deutsch, $neutral) { return !$neutral($t) && preg_match($deutsch, $t); }));
pruefe($deutschImEn === [], 'Englisches Formular (beide Module): kein deutscher Text' . ($deutschImEn ? ' — gefunden: ' . mb_substr($deutschImEn[0], 0, 70) : ''));
pruefe(strpos(implode('|', $enTexte), '📖 Documentation & Help') !== false && strpos(implode('|', $enTexte), 'Potential-free contacts') !== false, 'Englisches Formular: Dokumentations- und Kontakte-Panel englisch');
$unuebersetzt = array_values(array_filter(array_unique($enTexte), function ($t) use ($deTexte, $neutral) { return !$neutral($t) && in_array($t, $deTexte, true) && preg_match('/\p{L}+\s+\p{L}+\s+\p{L}+/u', $t); }));
pruefe($unuebersetzt === [], 'Deutsches Formular: jeder englische Quelltext ist übersetzt' . ($unuebersetzt ? ' — fehlt: ' . mb_substr($unuebersetzt[0], 0, 70) : ''));
pruefe(strpos($enForm, '✅ Connected via gateway #') !== false && (strpos($enForm, 'Controlled by the control box') !== false || strpos($enForm, 'Failsafe state since') !== false), 'Englisches Formular: Statuszeilen englisch (Verbindung, Zustand)');
pruefe(strpos($enRueck, '13.02 kW') !== false && strpos($enRueck, 'simultaneity factor') !== false && strpos($enRueck, 'Apply Changes') !== false && !preg_match($deutsch, $enRueck), 'Englisch: GZF-Rückmeldung mit Dezimalpunkt und „Apply Changes“');
pruefe(strpos($enReset, 'Apply Changes') !== false && !preg_match($deutsch, $enReset), 'Englisch: Rückmeldung „Reset to default“');
pruefe($enLog !== '' && strpos($enLog, 'EEBUS: consumption limit 5 kW active') !== false && !preg_match($deutsch, $enLog), 'Englisch: Protokollmeldungen englisch (Bezugsgrenze 5 kW)');
pruefe($enZahl === '4.2' && $deZahl === '4,2', 'Zahlen: Dezimalpunkt auf Englisch, Komma auf Deutsch');
pruefe(preg_match('/^\d{4}-\d{2}-\d{2} 14:30$/', $enZeit) === 1 && $deZeit === '05.11.2026 14:30', 'Zeitpunkt: JJJJ-MM-TT auf Englisch, TT.MM.JJJJ auf Deutsch');

// Statisch: jeder Text im Code und in form.json steht in locale.json, kein deutscher Text mehr im Code.
$wurzel = dirname(__DIR__);
$fehlt = [];
$deutschCode = [];
foreach (['SteuerboxHub', 'SteuerboxHubEEBUS'] as $mod) {
    $locDe = json_decode(file_get_contents("$wurzel/$mod/locale.json"), true)['translations']['de'] ?? [];
    foreach (["$wurzel/$mod/module.php", "$wurzel/libs/SteuerboxHubBase.php"] as $quelle) {
        foreach (token_get_all(file_get_contents($quelle)) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $str = stripcslashes(substr($t[1], 1, -1));
                if ($neutral($str) || !preg_match('/\p{L}.*\s.*\p{L}|[•✅⚠ℹ⛔🆕👋💬🧪🧡☕🪟🙈↩✏🔄]/u', $str)) { continue; }
                if (preg_match($deutsch, $str)) { $deutschCode[] = "$mod: " . mb_substr($str, 0, 50); }
                if (!isset($locDe[$str]) && !preg_match('/^(type|name|caption)\b/', $str)) { $fehlt[] = "$mod: " . mb_substr($str, 0, 50); }
            }
        }
    }
    $formFile = "$wurzel/$mod/form.json";
    if (is_file($formFile)) {
        foreach ($sammle(json_decode(file_get_contents($formFile), true)) as $str) {
            if (!$neutral($str) && !isset($locDe[$str])) { $fehlt[] = "$mod form.json: " . mb_substr($str, 0, 50); }
            if (!$neutral($str) && preg_match($deutsch, $str)) { $deutschCode[] = "$mod form.json: " . mb_substr($str, 0, 50); }
        }
    }
}
pruefe($deutschCode === [], 'Statisch: kein deutscher Text mehr im Code und in form.json' . ($deutschCode ? ' — ' . $deutschCode[0] : ''));
pruefe($fehlt === [], 'Statisch: jeder Text im Code und in form.json steht in locale.json' . ($fehlt ? ' — fehlt: ' . $fehlt[0] : ''));
$locAlle = json_decode(file_get_contents("$wurzel/SteuerboxHub/locale.json"), true)['translations'];
pruefe(array_keys($locAlle) === ['de'] && count(array_unique(array_values($locAlle['de']))) <= count($locAlle['de']), 'locale.json: Quelltext Englisch, Übersetzung Deutsch');
$ohneKopie = array_filter($locAlle['de'], function ($de, $en) { return $de === $en && mb_strlen($en) > 20; }, ARRAY_FILTER_USE_BOTH);
pruefe($ohneKopie === [], 'locale.json: keine unübersetzten Einträge (Deutsch gleich Englisch)');

$randLeer = [];
foreach (['SteuerboxHub', 'SteuerboxHubEEBUS'] as $mod) {
    foreach (array_keys(json_decode(file_get_contents("$wurzel/$mod/locale.json"), true)['translations']['de']) as $k) {
        if ($k !== trim($k)) { $randLeer[] = "$mod: '$k'"; }
    }
}
pruefe($randLeer === [], 'locale.json: kein Schlüssel mit Leerzeichen am Rand (Symcon-Suche exakt)' . ($randLeer ? ' — ' . $randLeer[0] : ''));

// Store-Review 25: Das Modul übersetzt das Formular nicht selbst (die Konsole tut es); Roh-Formular bleibt Englisch.
$rohSbh = json_decode($sb->GetConfigurationFormRoh(), true);
$rohBr  = json_decode($brueckeInst->GetConfigurationFormRoh(), true);
$rohTexte = array_merge($sammle($rohSbh), $sammle($rohBr));
$formStatisch = array_merge($sammle(json_decode(file_get_contents($wurzel . '/SteuerboxHub/form.json'), true)), $sammle(json_decode(file_get_contents($wurzel . '/SteuerboxHubEEBUS/form.json'), true)));
$ersetzt = ['(calculated when the form is opened)', '✅ ApplyChanges() executed.'];   // Platzhalter und Popup-Texte übersetzt das Modul selbst (Review 25)
$nichtRoh = array_values(array_filter($formStatisch, function ($t) use ($rohTexte, $neutral, $ersetzt) { return !$neutral($t) && !in_array($t, $ersetzt, true) && !in_array($t, $rohTexte, true); }));
pruefe($nichtRoh === [] && in_array('📖 Documentation & Help', $rohTexte, true), 'Store-Review 25: statische Captions bleiben im Roh-Formular englisch, das Modul übersetzt sie nicht selbst' . ($nichtRoh ? ' — ' . mb_substr($nichtRoh[0], 0, 60) : ''));
pruefe(strpos(file_get_contents($wurzel . '/libs/SteuerboxHubBase.php'), 'translateForm') === false, 'Store-Review 25: keine Formular-Übersetzungsschleife im Code');

// Stolperstein 24: der 10-s-Timer ruft kein Translate() auf.
IPSModule::$translateCalls = 0;
$sb->CheckEEBus();
pruefe(IPSModule::$translateCalls <= 2, 'Timerlauf CheckEEBus: höchstens 2 Translate()-Aufrufe (gemessen: ' . IPSModule::$translateCalls . ')');

echo " EEBUS-Hub: Datenfluss zum Gateway (Store-Review 11.11.2026)\n";
pruefe($sb->status === 102, 'EEBUS-Hub am Gateway: Status 102');
$lose = sbh(36000, 0, ['Transport' => 1]);
pruefe($lose->status === 104, 'EEBUS-Hub ohne Gateway: Status 104');
$fremd = sbh(36001, 31000, ['Transport' => 1]);
$gw->ereignis(lpcWrite(B(31001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 9999, 'scale' => 0]]]));
pruefe($fremd->GetState()['loadDimmActive'] === false && $lose->GetState()['loadDimmActive'] === false, 'Ereignis (Target) erreicht nur den angemeldeten Hub, nicht die anderen');
pruefe($sb->GetState()['loadPMin'] === 9.999, 'Der angemeldete Hub bekommt es (9,999 kW)');
$aufrufe = function (string $datei, string $praefix): array {
    $treffer = [];
    $tok = token_get_all(file_get_contents($datei));
    foreach ($tok as $i => $t) {
        if (is_array($t) && $t[0] === T_STRING && preg_match($praefix, $t[1])) {
            $n = $i + 1;
            while (isset($tok[$n]) && is_array($tok[$n]) && $tok[$n][0] === T_WHITESPACE) { $n++; }
            if (($tok[$n] ?? '') === '(') { $treffer[] = $t[1]; }
        }
    }
    return $treffer;
};
$wurzel0 = dirname(__DIR__);
pruefe($aufrufe($wurzel0 . '/SteuerboxHub/module.php', '/^SBHE_/') === [] && $aufrufe($wurzel0 . '/SteuerboxHubEEBUS/module.php', '/^SBH_/') === [] && $aufrufe($wurzel0 . '/libs/SteuerboxHubBase.php', '/^SBHE?_/') === [],
    'Store-Review: die beiden Module rufen keine Funktionen des anderen auf, der Austausch läuft über den Datenfluss');
pruefe(strpos((string) file_get_contents($wurzel0 . '/SteuerboxHubEEBUS/module.php'), 'SendDataToParent') !== false && strpos((string) file_get_contents($wurzel0 . '/SteuerboxHubEEBUS/module.php'), 'function ReceiveData') !== false, 'EEBUS-Hub spricht mit dem Gateway über SendDataToParent und ReceiveData');

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

echo " Altinstanz aus 0.2 bis 0.5 (Kontakte-Hub mit Transport EEBUS)\n";
$alt = sbh(34100, 0, []);
$alt->props['Transport'] = 1;
$alt->ApplyChanges();
$st = $alt->GetState();
pruefe($alt->status === 104 && $st['source'] === 'eebus' && $st['loadDimmActive'] === false && $st['feedInLimitPercent'] === 100.0 && $st['contractVersion'] === '1.0', 'Altinstanz auf EEBUS: Status 104 (neutral), keine Begrenzung, Vertrag unverändert');
$f = json_encode(json_decode($alt->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, 'steht noch auf dem früheren Transportweg EEBUS') !== false && strpos($f, 'SBHE_GetState') !== false, 'Altinstanz: Formular weist auf das neue EEBUS-Modul hin');
$alt->CheckEEBus();
pruefe($alt->status === 104, 'Altlast-Timer (SBH_CheckEEBus) läuft ohne Wirkung und ohne Fehler');
$alt->props['Transport'] = 0;
$alt->ApplyChanges();
$f = json_encode(json_decode($alt->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, 'steht noch auf dem früheren Transportweg') === false && $alt->GetState()['source'] === 'contacts', 'Zurück auf Kontakte: Hinweis weg, Quelle contacts');


echo " Formular-Konvention\n";
$fk = sbh(35001, 0, ['Transport' => 0]);
$form = json_decode($fk->GetConfigurationForm(), true);
$caps = array_map(function ($e) { return $e['caption'] ?? ($e['name'] ?? $e['type']); }, $form['elements']);
$idx = function (string $needle) use ($caps) { foreach ($caps as $i => $c) { if (strpos((string) $c, $needle) !== false) { return $i; } } return -1; };
pruefe($idx('Wozu dieses Modul') === 0 && $idx('Neu bis Version') === 1 && $idx('Dokumentation') === 2, 'Reihenfolge: Wozu → Neu bis Version → Dokumentation & Hilfe');
pruefe($idx('Rückmeldungen') === count($caps) - 2 && $idx('Über dieses Modul') === count($caps) - 1, 'Rückmeldungen und „Über dieses Modul“ am Ende');
pruefe(strpos($caps[1], '0.6.0') !== false, 'News-Panel nennt die höchste neue Version (0.6.0)');
$lic = json_encode($form["elements"][count($caps) - 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruefe(strpos($lic, '"link":true') !== false && strpos($lic, "echo 'https://github.com/DG65/NRGSteuerboxHub/blob/beta/LICENSE'") !== false, 'Lizenz-Knopf: echo-URL mit link=true, Branch beta');
$doc = json_encode($form['elements'][2], JSON_UNESCAPED_UNICODE);
pruefe(strpos($doc, 'SteuerboxHub Version 0.6.0 (Build 8)') !== false, 'Dokumentation & Hilfe zeigt Version und Build');
$fh = json_encode($form['elements'][count($caps) - 2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruefe(strpos($fh, "echo 'https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-eebus-gateway-steuerboxhub-14a-steuerbox-per-eebus-in-reinem-php/144795'") !== false && strpos($fh, 'Zum Forums-Thread') < strpos($fh, 'GitHub'), 'Rückmeldungen: Forums-Thread zuerst, GitHub-Issues als zweiter Knopf');
$fk->AckPurposeIntro();
$fk->AckNews();
$fk->AckForumHint();
$caps2 = array_map(function ($e) { return $e['caption'] ?? ''; }, json_decode($fk->GetConfigurationForm(), true)['elements']);
pruefe(strpos($caps2[0], 'Dokumentation') !== false && strpos(implode('|', $caps2), 'Rückmeldungen') === false && strpos(implode('|', $caps2), 'Über dieses Modul') !== false, 'Nach Bestätigen: Wozu/Neu/Rückmeldungen weg, Lizenz bleibt');
pruefe($fk->attrs['SeenNews'] === '0.6.0' && ($fk->form['NewsPanel']['visible'] ?? null) === false, 'AckNews speichert die installierte Version (0.6.0) und blendet sofort aus');
$fk->attrs['SeenNews'] = '0.4.0';
$caps3 = array_map(function ($e) { return $e['caption'] ?? ''; }, json_decode($fk->GetConfigurationForm(), true)['elements']);
$news = json_encode(json_decode($fk->GetConfigurationForm(), true)['elements'][0], JSON_UNESCAPED_UNICODE);
pruefe(strpos($caps3[0], 'Neu bis Version 0.6.0') !== false && strpos($news, 'Version 0.4.0:') === false && strpos($news, 'Version 0.5.0:') !== false, 'Wer 0.4.0 kannte, sieht nur die Neuerungen ab 0.5.0');

echo " Formular: Aufbau\n";
$panelNamen = function ($x, array $erlaubt): array {
    $r = [];
    foreach (json_decode($x->GetConfigurationForm(), true)['elements'] as $e) {
        if (isset($e['name']) && in_array($e['name'], $erlaubt, true)) { $r[] = $e; }
    }
    return $r;
};
$namen = function (array $ps) { return array_map(function ($e) { return $e['name']; }, $ps); };
$modulAufbau = [
    'Kontakte-Hub' => [sbh(35002, 0, []), ['PminPanel', 'ContactsPanel'], 'contacts', 'ContactsPanel', 'Potentialfreie Kontakte'],
    'EEBUS-Hub'    => [sbh(35003, 0, ['Transport' => 1]), ['PminPanel', 'EEBusPanel'], 'eebus', 'EEBusPanel', 'EEBUS'],
];
foreach ($modulAufbau as $modul => [$fa, $panelListe, $schluessel, $panelName, $label]) {
    $andere = $panelListe[0] === 'PminPanel' ? $panelListe[1] : $panelListe[0];
    $pn = function () use ($panelNamen, $fa, $panelListe) { return $panelNamen($fa, $panelListe); };
    pruefe($namen($pn()) === $panelListe, "Aufbau ($modul): ohne Einstellung Standardreihenfolge");
    $el = json_decode($fa->GetConfigurationForm(), true)['elements'];
    $caps = array_map(function ($e) { return $e['caption'] ?? ''; }, $el);
    $iA = -1; foreach ($caps as $i => $c) { if (strpos($c, 'Formular: Aufbau') !== false) { $iA = $i; } }
    pruefe($iA === count($caps) - 3 && strpos($caps[$iA + 1], 'Rückmeldungen') !== false && ($el[$iA]['expanded'] ?? true) === false, "Aufbau ($modul): Panel eingeklappt, direkt vor Rückmeldungen und Lizenz");
    $liste = array_values(array_filter($el[$iA]['items'], function ($i) { return ($i['name'] ?? '') === 'FormLayout'; }))[0] ?? [];
    pruefe(strpos(json_encode($liste, JSON_UNESCAPED_UNICODE), '"save":true') !== false && count($liste['values'] ?? []) === 2, "Aufbau ($modul): Schlüssel gespeichert, ein Eintrag je Fachpanel");
    $fa->props['FormLayout'] = json_encode([
        ['Key' => $schluessel, 'Opened' => true, 'Pos' => 1],
        ['Key' => 'pmin', 'Closed' => true, 'Pos' => 2],
    ]);
    $ps = $pn();
    pruefe($namen($ps) === [$panelName, 'PminPanel'], "Aufbau ($modul): Position bestimmt die Reihenfolge");
    pruefe(($ps[0]['expanded'] ?? false) === true && ($ps[1]['expanded'] ?? true) === false, "Aufbau ($modul): Aufgeklappt und Zugeklappt wirken");
    $fa->props['FormLayout'] = json_encode([['Key' => $schluessel, 'Hidden' => true, 'Opened' => true, 'Pos' => 1]]);
    $versteckt = array_values(array_filter($pn(), function ($e) use ($panelName) { return $e['name'] === $panelName; }))[0] ?? [];
    pruefe(($versteckt['visible'] ?? true) === false && count($versteckt['items'] ?? []) > 0, "Aufbau ($modul): Ausgeblendet = visible:false, Felder bleiben im Formular");
    $ueberschrift = json_encode(json_decode($fa->GetConfigurationForm(), true)['elements'], JSON_UNESCAPED_UNICODE);
    pruefe(strpos($ueberschrift, 'Formular: Aufbau (1 ausgeblendet)') !== false && strpos($ueberschrift, '🙈 Ausgeblendet: ' . $label) !== false, "Aufbau ($modul): Überschrift nennt die Zahl, eine Zeile nennt den ausgeblendeten Bereich");
    $fa->props['FormLayout'] = json_encode([['Key' => 'unbekannt', 'Hidden' => true, 'Pos' => 1], ['Key' => 'pmin', 'Pos' => 99]]);
    pruefe($namen($pn()) === [$andere, 'PminPanel'], "Aufbau ($modul): unbekannter Schlüssel wird ignoriert, fehlende fallen auf Standardposition");
    $fa->props['FormLayout'] = 'kaputt';
    pruefe($namen($pn()) === $panelListe, "Aufbau ($modul): ungültige Einstellung fällt auf Standard zurück");
    $fa->props['FormLayout'] = json_encode([['Key' => $schluessel, 'Closed' => true, 'Opened' => true, 'Hidden' => true]]);
    $versteckt = array_values(array_filter($pn(), function ($e) use ($panelName) { return $e['name'] === $panelName; }))[0] ?? [];
    pruefe(($versteckt['visible'] ?? true) === false, "Aufbau ($modul): bei Widerspruch gewinnt Ausgeblendet");
    $txt = $fa->ResetFormLayout();
    $zeilen = json_decode($fa->form['FormLayout']['values'] ?? '[]', true);
    pruefe(count($zeilen) === 2 && !array_filter($zeilen, function ($z) { return $z['Opened'] || $z['Closed'] || $z['Hidden']; }) && array_column($zeilen, 'Key') === ['pmin', $schluessel] && strpos($txt, 'Übernehmen') !== false, "Aufbau ($modul): Zurücksetzen setzt alles auf Standard und nennt „Übernehmen“");
}

$txt = $fk->CalculateGZF(4, 0.0);
pruefe(($fk->form['LoadPMin']['value'] ?? 0) === 13.02 && strpos($txt, '13,02 kW') !== false && strpos($txt, 'Übernehmen') !== false, 'GZF-Rechenhilfe: n = 4 → 13,02 kW, Rückmeldung nennt „Übernehmen“');
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.json'), true);
pruefe($mj['aliases'] === ['NRG-Stack SteuerboxHub'], 'Genau ein Alias (ein Eintrag im Anlege-Dialog)');
// SDK-Lebenszyklus-Funktionen haben eine von Symcon vorgegebene Signatur und werden nicht als SBH_-Funktion erzeugt.
$sdk = ['Create', 'ApplyChanges', 'Destroy', 'MessageSink', 'ReceiveData', 'GetConfigurationForm', 'RequestAction'];
$verstoesse = [];
foreach ([SteuerboxHub::class, SteuerboxHubEEBUS::class, SteuerboxHubBase::class] as $klasse) {
    foreach ((new ReflectionClass($klasse))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $klasse || in_array($m->getName(), $sdk, true)) { continue; }
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional() || !$p->hasType()) { $verstoesse[] = $klasse . '::' . $m->getName() . '($' . $p->getName() . ')'; }
        }
    }
}
pruefe($verstoesse === [], 'Öffentliche SBH_/SBHE_-Funktionen: keine Standardwerte, alle Parameter typisiert (Stolperstein 8/20)' . ($verstoesse ? ': ' . implode(', ', $verstoesse) : ''));
$k1 = sbh(35200, 0, []);
$k2 = sbh(35201, 0, ['Transport' => 1]);
$kontrakt = ['contractVersion', 'source', 'loadDimmActive', 'loadPMin', 'loadSince', 'feedInDimmActive', 'feedInLimitPercent', 'feedInSince'];
$t1 = $k1->GetState();
$t2 = $k2->GetState();
pruefe(array_keys($t1) === $kontrakt && array_keys($t2) === $kontrakt && array_map('gettype', $t1) === array_map('gettype', $t2) && $t1['source'] === 'contacts' && $t2['source'] === 'eebus' && $t1['contractVersion'] === '1.0' && $t2['contractVersion'] === '1.0',
    'Vertrag 1.0: SBH_GetState (Kontakte) und SBHE_GetState (EEBUS) liefern dieselben Felder und Typen');

echo " Ohne Gateway keine Begrenzung, neues Gateway beginnt neu (Live-Fund 27.10.2026)\n";
$gw7 = new FakeGateway(37000);
$gw7->state['connected'] = true;
$r7 = sbh(37001, 37000, ['Transport' => 1, 'LoadPMin' => 13.02]);
$gw7->ereignis(['Event' => 'connection', 'state' => 'connected', 'shipId' => 'Box-A']);
$gw7->ereignis(lpcWrite(B(37001), [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 5000, 'scale' => 0]]]));
$gw7->state['connected'] = false;
$gw7->ereignis(['Event' => 'connection', 'state' => 'disconnected']);
vor(4000);
$r7->CheckEEBus();
pruefe($r7->GetState()['loadPMin'] === 13.02 && $r7->status === 204, 'Ausgangslage: Steuerbox weg, Ausfallgrenze 13,02 kW');
$r7->parentId = 0;
$r7->ApplyChanges();
$st = $r7->GetState();
pruefe($st['loadDimmActive'] === false && $st['feedInDimmActive'] === false && $r7->status === 104, 'Gateway entfernt: keine Begrenzung, Status 104 (nicht mehr „Ausfall bei nicht konfiguriert“)');
$f = json_encode(json_decode($r7->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruefe(strpos($f, 'Ohne EEBUS-Gateway gilt keine Begrenzung') !== false && strpos($f, 'Ausfallzustand seit') === false, 'Formular sagt dasselbe');
$gw8 = new FakeGateway(38000);
$r7->parentId = 38000;
$r7->ApplyChanges();
$z = json_decode($r7->attrs['EEBusState'], true);
pruefe($z['mode'] === 'unpaired' && $z['limits'][0]['active'] === false && $z['gateway'] === 38000 && $r7->status === 210, 'Anderes Gateway: früherer Zustand verworfen, wartet auf erste Verbindung');
pruefe($r7->GetState()['loadDimmActive'] === false, 'Keine alte Grenze am neuen Gateway');
pruefe(count(array_filter($r7->log, function ($l) { return strpos($l, 'früherer Zustand verworfen') !== false; })) === 2, 'Verwerfen protokolliert (erstes und neues Gateway)');

echo " Kontakte-Weg unverändert\n";
$GLOBALS['VARS'][40010] = true;
$k = sbh(34001, 0, ['Transport' => 0, 'LoadContactVarID' => 40010, 'LoadPMin' => 5.04]);
$st = $k->GetState();
pruefe($st['source'] === 'contacts' && $st['loadDimmActive'] === true && $st['loadPMin'] === 5.04 && $k->status === 102, 'Kontakt aktiv: contacts, loadPMin aus der Instanz');
pruefe(strpos((string) file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.php'), 'IPS_LogMessage') === false, 'Logging nur über $this->LogMessage (Store-Checkliste 15)');
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHub/module.json'), true);
pruefe($mj['parentRequirements'] === [] && $mj['implemented'] === [], 'SteuerboxHub ohne parentRequirements (kein Balken „benötigt eine übergeordnete Instanz“)');
$bj = json_decode(file_get_contents(dirname(__DIR__) . '/SteuerboxHubEEBUS/module.json'), true);
pruefe($bj['parentRequirements'] === [TO_GATEWAY] && $bj['implemented'] === [TO_CHILD] && $bj['prefix'] === 'SBHE' && $bj['aliases'] === ['NRG-Stack SteuerboxHub EEBUS'], 'EEBUS-Hub trägt die Kind-Schnittstelle des EEBUS-Gateways, ein Alias');

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
    pruefe(isset($anm[(string) B(50003)]) && count($anm[(string) B(50003)]['useCases']) === 2, 'Gateway hat die Anmeldung des EEBUS-Hubs gespeichert');
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
