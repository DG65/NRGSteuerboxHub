<?php

// ===========================================================================
// SteuerboxHubBase — gemeinsame Oberklasse von SteuerboxHub (Kontakte, Präfix SBH)
// und SteuerboxHub EEBUS (Steuerhub am EEBUS-Gateway, Präfix SBHE).
//
// Gemeinsam sind die Formular-Konvention des NRG-Stack (Wozu, Neu, Dokumentation,
// wählbarer Aufbau, Rückmeldungen, Über dieses Modul), die Rechenhilfe für Pmin
// nach §14a, Zahlen- und Zeitformat nach Symcon-Sprache und die Versionsinformation.
// Die Module unterscheiden sich im Transport: Kontakte lesen Symcon-Variablen,
// der EEBUS-Hub spricht mit dem Gateway über den Datenfluss.
// ===========================================================================

abstract class SteuerboxHubBase extends IPSModule
{
    protected const LIBRARY_GUID = '{F790C1F0-4955-4C43-81F4-B4CBD415D0E1}';
    protected const REPO_URL     = 'https://github.com/DG65/NRGSteuerboxHub';
    protected const ISSUES_URL   = 'https://github.com/DG65/NRGSteuerboxHub/issues';
    protected const FORUM_URL    = 'https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-eebus-gateway-steuerboxhub-14a-steuerbox-per-eebus-in-reinem-php/144795';
    protected const LICENSE_URL  = 'https://github.com/DG65/NRGSteuerboxHub/blob/beta/LICENSE';
    protected const PAYPAL_URL   = 'https://paypal.me/DietmarGureth';

    /** Präfix der Modulfunktionen ('SBH' oder 'SBHE'), für die Knöpfe im Formular. */
    abstract protected function modulePrefix(): string;

    /** Ordner des Moduls (form.json, locale.json). */
    abstract protected function moduleDir(): string;

    /** Neuerungen je Version: Version => Zeilen (englischer Quelltext). */
    abstract protected function newsVersions(): array;

    /** Absätze der Zweck-Einführung (englischer Quelltext). */
    abstract protected function purposeParagraphs(): array;

    /** Fachpanels in Standardreihenfolge: Name im Formular => stabiler Schlüssel. */
    abstract protected function formPanels(): array;

    /** Schlüssel => Bezeichnung (englischer Quelltext) für die Liste im Formular-Aufbau. */
    abstract protected function formPanelLabels(): array;

    /** Modulspezifisches Befüllen des Formulars (Statuszeilen, Sichtbarkeit). */
    protected function prepareForm(array &$form): void
    {
    }

    /** Eigenschaften und Attribute, die beide Module teilen. */
    protected function registerCommon(): void
    {
        $this->RegisterPropertyFloat('LoadPMin', 4.2);
        $this->RegisterPropertyInteger('GZFConsumerCount', 1);
        $this->RegisterPropertyFloat('GZFFactor', 0.0); // 0 = automatisch nach BNetzA-Tabelle
        $this->RegisterPropertyString('FormLayout', '[]');

        // Formular-Hinweise (ausblendbar)
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    // Formular-Reihenfolge (SUITE.md „Einheitliche Formular-Optik“):
    // 0. „Wozu dieses Modul?“ (aufgeklappt, einmalig ausblendbar)
    // 1. „Neu bis Version“ (aufgeklappt, versionsscharf ausblendbar)
    // 2. „Dokumentation & Hilfe“ (eingeklappt, mit Versionsnummer, aus form.json)
    // 3. Fachpanels (form.json, wählbar im „Formular: Aufbau“)
    // 4. Rückmeldungen (einmalig ausblendbar)
    // 5. „Über dieses Modul“ (eingeklappt, nicht ausblendbar)
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents($this->moduleDir() . '/form.json'), true);
        $this->injectVersionIntoDocPanel($form);
        $this->prepareForm($form);
        // Fachpanels (Punkt 3) herauslösen: Reihenfolge und Aufklapp-Zustand wählt der Nutzer („Formular: Aufbau“).
        $panels = $this->formPanels();
        $head = [];
        $free = [];
        $tail = [];
        foreach ($form['elements'] as $el) {
            $key = $panels[$el['name'] ?? ''] ?? null;
            if ($key !== null) {
                $free[$key] = $el;
            } elseif ($free === []) {
                $head[] = $el;
            } else {
                $tail[] = $el;
            }
        }
        $layout = $this->formLayout(array_keys($free));
        $middle = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten.
                $panel['visible'] = false;
            }
            $middle[] = $panel;
        }
        $form['elements'] = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner()],
            $head,
            $middle,
            $tail,
            [$this->formLayoutPanel($layout), $this->formForumHint(), $this->formLicenseHint()]
        )));
        $this->translatePopups($form);
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

    protected function installedVersion(): string
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        if (is_array($lib) && !empty($lib['Version'])) {
            return (string) preg_replace('/-.*$/', '', (string) $lib['Version']);
        }
        $keys = array_keys($this->newsVersions());
        usort($keys, 'version_compare');
        return (string) end($keys);
    }

    protected function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $paragraphs = $this->purposeParagraphs();
        $items = [];
        foreach ($paragraphs as $text) {
            $items[] = ['type' => 'Label', 'caption' => $text];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => $this->modulePrefix() . '_AckPurposeIntro($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => $items,
        ];
    }

    protected function formNewsBanner(): ?array
    {
        $seen    = (string) $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach ($this->newsVersions() as $version => $lines) {
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
            $items[] = ['type' => 'Label', 'caption' => sprintf($this->Translate('Version %s:'), $version)];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => $this->modulePrefix() . '_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => sprintf($this->Translate('🆕  New up to version %s'), end($keys)),
            'items' => $items,
        ];
    }

    protected function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Which control box or ripple control receiver do you have installed, and does the signal arrive correctly? Feedback with manufacturer, model and grid operator helps a lot, especially on the EEBUS path.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Report a bug on GitHub', 'onClick' => "echo '" . self::ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => $this->modulePrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own system — and a few evenings spent at the keyboard. Still: hobby or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and want to give something back? I would be happy about a small donation — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in anzuwendender Reihenfolge
     */
    protected function formLayout(array $keys): array
    {
        $saved = json_decode((string) $this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string) $row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, kein Haken = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int) $r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    protected function formLayoutPanel(array $layout): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name   = $this->Translate($this->formPanelLabels()[$key] ?? $key);
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = $name;
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => sprintf($this->Translate('🙈 Hidden: %s — to show them again, clear the “Hidden” checkbox. Hidden areas keep working, only the display is missing.'), implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'name' => 'LayoutPanel',
            'caption' => $hidden ? sprintf($this->Translate('🪟  Form layout (%d hidden)'), count($hidden)) : '🪟  Form layout',
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which areas are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing by itself, so this setting applies every time the form is opened. It takes effect after “Apply Changes”, the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No checkbox set means “default”: as the module intends it. The position is a number; with equal numbers the default order is kept. “Hidden” removes an area from the display without losing anything: its settings are kept and stay in effect. To show it again, clear the “Hidden” checkbox or click “Reset to default”. If several checkboxes are set, “Hidden” beats “Collapsed” beats “Expanded”. The areas at the top (purpose, news, documentation), the choice of the transport path, and the areas at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Areas', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Area', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->modulePrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        foreach (array_values($this->formPanels()) as $i => $key) {
            $rows[] = ['Key' => $key, 'Panel' => $this->Translate($this->formPanelLabels()[$key]), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $this->Translate('ℹ️ Reset to default. Click “Apply Changes” below to save.');
    }

    /**
     * Übersetzt die Popup-Texte in `onClick` (echo '…'). Die Konsole übersetzt caption und Optionen des Formulars selbst
     * (aus locale.json), aber nicht den Text im Knopf-Skript.
     */
    protected function translatePopups(array &$node): void
    {
        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                $this->translatePopups($value);
            } elseif ($key === 'onClick' && is_string($value)) {
                $value = (string) preg_replace_callback("/echo '([^']*)';/u", function (array $m): string {
                    return "echo '" . $this->Translate($m[1]) . "';";
                }, $value);
            }
        }
        unset($value);
    }

    protected function injectVersionIntoDocPanel(array &$form): void
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ SteuerboxHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ SteuerboxHub';
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'DocPanel') {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                return;
            }
        }
        unset($el);
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
        return sprintf($this->Translate('✅ Pmin = %s kW (n = %d, simultaneity factor %s) entered in the field. The value is only saved with “Apply Changes”.'),
            $this->fmt($pMin), $n, $this->fmt($gzf));
    }

    /** Zahl mit Dezimaltrenner der Symcon-Sprache (Komma auf Deutsch, Punkt sonst). */
    protected function fmt(float $v): string
    {
        $sep = $this->Translate('decimal separator') === ',' ? ',' : '.';
        return rtrim(rtrim(number_format($v, 2, $sep, ''), '0'), $sep);
    }

    /** Zeitpunkt in der Schreibweise der Symcon-Sprache (Deutsch: TT.MM.JJJJ HH:MM). */
    protected function dt(int $ts): string
    {
        return date($this->Translate('Y-m-d H:i'), $ts);
    }

    /** Zeitquelle (im Prüfstand ersetzbar). */
    protected function now(): int
    {
        return time();
    }
}
