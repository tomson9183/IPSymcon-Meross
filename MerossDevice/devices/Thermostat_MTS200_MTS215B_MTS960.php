<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Eigenständige WLAN-Thermostate (kein Hub):
//  MTS200 / MTS200B / MTS215B  -> Appliance.Control.Thermostat.Mode
//  MTS960                       -> Appliance.Control.Thermostat.ModeB
//
//  Gelesen wird der Zustand aus Appliance.System.All -> digest.thermostat
//  (die dedizierten Thermostat-GETs antworten auf vielen Modellen NICHT).
//
//  Das Format unterscheidet sich:
//   * Mode  (MTS200/215B): Temperaturen in Zehntelgrad (249 = 24,9 °C)
//       Felder (am MTS200 verifiziert): currentTemp(=Ist), targetTemp(=Soll),
//       state(=heizt gerade), onoff, mode, heatTemp/coolTemp/ecoTemp/manualTemp,
//       warning, min/max. onoff: 1 = ein, 0 = aus.
//       SET der Soll-Temperatur ueber manualTemp (Gerät wechselt in manuell).
//   * ModeB (MTS960): Temperaturen in Hundertstel (2864 = 28,64 °C)
//       Felder u.a.: currentTemp, targetTemp, working(=heizt), onoff, mode.
//       onoff: 1 = ein, 2 = aus. SET ueber targetTemp.
//
//  mode-Werte (meross_iot MTS200, am Gerät bestätigt mode:4 = Manuell):
//   0 = Heizen, 1 = Kühlen, 2 = Eco, 3 = Auto, 4 = Manuell
//
//  Die Variante wird beim Polling automatisch erkannt und für das
//  Senden gemerkt (Attribut ThermoVariant / ThermoScale).
//
//  HINWEIS: MTS200 am Gerät verifiziert (Lesen). SET (Soll/Modus/Ein-Aus)
//  und die MTS960-Variante noch praktisch zu bestätigen.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait ThermostatDevice
{
    private function ThermoApplyChanges()
    {
        // Schöne HTML-Karte ganz oben (read-only; gesteuert wird über die Variablen darunter)
        $this->RegisterVariableString('VISU', $this->Translate('Anzeige'), '~HTMLBox', 5);

        $this->RegisterVariableFloat('TEMP', $this->Translate('Temperatur'), '~Temperature', 10);
        $this->RegisterVariableFloat('SET', $this->Translate('Soll-Temperatur'), 'MERO.SetTemp', 20);
        $this->EnableAction('SET');
        $this->RegisterVariableBoolean('ONOFF', $this->Translate('Ein'), '~Switch', 30);
        $this->EnableAction('ONOFF');
        $this->RegisterVariableBoolean('HEAT', $this->Translate('Heizt gerade'), 'MERO.Heat', 40);
        $this->RegisterVariableInteger('MODE', $this->Translate('Modus'), $this->EnumPresentation([
            ['Value' => 0, 'Caption' => $this->Translate('Heizen'),  'IconActive' => false, 'IconValue' => '', 'Color' => 0xFF7043],
            ['Value' => 1, 'Caption' => $this->Translate('Kühlen'),  'IconActive' => false, 'IconValue' => '', 'Color' => 0x29B6F6],
            ['Value' => 2, 'Caption' => $this->Translate('Eco'),     'IconActive' => false, 'IconValue' => '', 'Color' => 0x66BB6A],
            ['Value' => 3, 'Caption' => $this->Translate('Auto'),    'IconActive' => false, 'IconValue' => '', 'Color' => 0x9E9E9E],
            ['Value' => 4, 'Caption' => $this->Translate('Manuell'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0xFFB300],
        ]), 50);
        $this->EnableAction('MODE');

        $this->ThermoUpdate();
    }

    private function ThermoRequestAction($Ident, $Value)
    {
        $variant = $this->ReadAttributeString('ThermoVariant');
        if ($variant === '') {
            $variant = 'mode';
        }
        $scale = (int) $this->ReadAttributeInteger('ThermoScale');
        if ($scale <= 0) {
            $scale = ($variant === 'modeB') ? 100 : 10;
        }
        $ns  = ($variant === 'modeB') ? 'Appliance.Control.Thermostat.ModeB' : 'Appliance.Control.Thermostat.Mode';
        $key = ($variant === 'modeB') ? 'modeB' : 'mode';

        switch ($Ident) {
            case 'SET':
                $val = (int) round(((float) $Value) * $scale);
                // MTS200 (Mode) nimmt die manuelle Soll-Temperatur in manualTemp
                // entgegen (Gerät wechselt dabei in den manuellen Betrieb);
                // MTS960 (ModeB) erwartet targetTemp.
                $tField = ($variant === 'modeB') ? 'targetTemp' : 'manualTemp';
                $resp   = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, $tField => $val]]]);
                if ($resp !== null) {
                    $this->SetValue('SET', round((float) $Value, 1));
                }
                break;

            case 'ONOFF':
                // Mode (MTS200): 1 = ein, 0 = aus   ·   ModeB (MTS960): 1 = ein, 2 = aus
                $onoff = $Value ? 1 : (($variant === 'modeB') ? 2 : 0);
                $resp  = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, 'onoff' => $onoff]]]);
                if ($resp !== null) {
                    $this->SetValue('ONOFF', (bool) $Value);
                }
                break;

            case 'MODE':
                $resp = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, 'mode' => (int) $Value]]]);
                if ($resp !== null) {
                    $this->SetValue('MODE', (int) $Value);
                }
                break;
        }
    }

    private function ThermoUpdate()
    {
        // Die dedizierten Thermostat-GETs antworten auf vielen Modellen NICHT
        // (leerer Payload). Der komplette Zustand steht aber in System.All
        // unter digest.thermostat -> das ist der zuverlaessige Weg.
        $all = $this->LocalRequest('Appliance.System.All', 'GET', []);
        $th  = $all['payload']['all']['digest']['thermostat'] ?? null;
        if (is_array($th)) {
            if (isset($th['modeB'][0])) {                 // MTS960: Hundertstelgrad
                $this->ThermoApply($th['modeB'][0], 'modeB', 100);
                return;
            }
            if (isset($th['mode'][0])) {                  // MTS200/215B: Zehntelgrad
                $this->ThermoApply($th['mode'][0], 'mode', 10);
                return;
            }
        }

        // Fallback: Modelle, die den dedizierten GET doch beantworten
        $mb = $this->LocalRequest('Appliance.Control.Thermostat.ModeB', 'GET', []);
        if ($mb !== null && isset($mb['payload']['modeB'][0])) {
            $this->ThermoApply($mb['payload']['modeB'][0], 'modeB', 100);
            return;
        }
        $m = $this->LocalRequest('Appliance.Control.Thermostat.Mode', 'GET', []);
        if ($m !== null && isset($m['payload']['mode'][0])) {
            $this->ThermoApply($m['payload']['mode'][0], 'mode', 10);
            return;
        }

        $this->SetStatus(201);
    }

    private function ThermoApply(array $d, string $variant, int $scale)
    {
        $this->SetStatus(102);
        $this->WriteAttributeString('ThermoVariant', $variant);
        $this->WriteAttributeInteger('ThermoScale', $scale);

        $cur = $d['currentTemp'] ?? ($d['temperature'] ?? ($d['roomTemp'] ?? null));
        $curC = ($cur !== null) ? round(((int) $cur) / $scale, 1) : null;
        if ($curC !== null) {
            $this->SetValue('TEMP', $curC);
        }

        $tgt = $d['targetTemp'] ?? ($d['currentSet'] ?? ($d['manualTemp'] ?? null));
        $setC = ($tgt !== null) ? round(((int) $tgt) / $scale, 1) : null;
        if ($setC !== null) {
            $this->SetValue('SET', $setC);
        }

        $on = isset($d['onoff']) ? ((int) $d['onoff'] === 1) : true;
        if (isset($d['onoff'])) {
            $this->SetValue('ONOFF', $on);
        }

        $heatRaw = $d['working'] ?? ($d['state'] ?? null);
        $heat    = ($heatRaw !== null) ? ((int) $heatRaw === 1) : false;
        if ($heatRaw !== null) {
            $this->SetValue('HEAT', $heat);
        }

        $mode = isset($d['mode']) ? (int) $d['mode'] : 3;
        if (isset($d['mode'])) {
            $this->SetValue('MODE', $mode);
        }

        // Grenzen für die Ringanzeige (sonst Standard 5–35 °C)
        $min = isset($d['min']) ? round(((int) $d['min']) / $scale, 1) : 5.0;
        $max = isset($d['max']) ? round(((int) $d['max']) / $scale, 1) : 35.0;

        if (@$this->GetIDForIdent('VISU') !== false) {
            $this->SetValue('VISU', $this->ThermoVisuHtml(
                $curC ?? 0.0,
                $setC ?? 0.0,
                $mode,
                $heat,
                $on,
                $min,
                $max
            ));
        }
    }

    // Schicke read-only Thermostat-Karte (Ring-Anzeige) als HTML/SVG für ~HTMLBox
    private function ThermoVisuHtml(float $cur, float $set, int $mode, bool $heat, bool $on, float $min, float $max): string
    {
        $modes = [
            0 => [$this->Translate('Heizen'),  '#FF7043'],
            1 => [$this->Translate('Kühlen'),  '#29B6F6'],
            2 => [$this->Translate('Eco'),     '#66BB6A'],
            3 => [$this->Translate('Auto'),    '#9E9E9E'],
            4 => [$this->Translate('Manuell'), '#FFB300'],
        ];
        [$mName, $mColor] = $modes[$mode] ?? ['—', '#9E9E9E'];

        $accent = !$on ? '#566' : ($heat ? '#FF6B35' : $mColor);
        $span   = ($max - $min) > 0 ? ($max - $min) : 1;
        $frac   = max(0.0, min(1.0, ($set - $min) / $span));

        $r    = 84.0;
        $circ = 2 * M_PI * $r;
        $prog = round($frac * $circ, 1);
        $circR = round($circ, 1);

        $de = function (float $v): string {
            return number_format($v, 1, ',', '.');
        };
        $curTxt = $on ? $de($cur) : '–';
        $opacity = $on ? '1' : '0.5';

        $heatRow = ($on && $heat)
            ? '<div style="margin-top:8px;font-size:13px;font-weight:600;color:#FF6B35;">🔥 ' . $this->Translate('heizt') . '</div>'
            : '<div style="margin-top:8px;font-size:13px;color:#7a8290;">' . ($on ? $this->Translate('Standby') : $this->Translate('Aus')) . '</div>';

        return ''
            . '<div style="max-width:260px;margin:0 auto;padding:18px 14px;border-radius:18px;'
            . 'background:linear-gradient(160deg,#1e2230,#161922);font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'text-align:center;color:#e8ebf0;box-shadow:0 6px 18px rgba(0,0,0,.35);opacity:' . $opacity . ';">'
            .   '<svg viewBox="0 0 200 200" style="width:180px;height:180px;">'
            .     '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="#2b2f3a" stroke-width="14"/>'
            .     '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="' . $accent . '" stroke-width="14" '
            .       'stroke-linecap="round" stroke-dasharray="' . $prog . ' ' . $circR . '" transform="rotate(-90 100 100)"/>'
            .     '<text x="100" y="96" text-anchor="middle" font-size="46" font-weight="700" fill="#ffffff">' . $curTxt . '</text>'
            .     '<text x="100" y="122" text-anchor="middle" font-size="16" fill="#9aa3b2">°C</text>'
            .   '</svg>'
            .   '<div style="font-size:15px;color:#c7ccd6;margin-top:2px;">' . $this->Translate('Soll') . ' <b style="color:#fff;">' . $de($set) . ' °C</b></div>'
            .   '<div style="display:inline-block;margin-top:10px;padding:5px 14px;border-radius:999px;'
            .     'background:' . $mColor . ';color:#10131a;font-size:13px;font-weight:700;letter-spacing:.3px;">' . $mName . '</div>'
            .   $heatRow
            . '</div>';
    }
}
