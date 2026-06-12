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
        $this->RegisterVariableFloat('TEMP', $this->Translate('Temperatur'), '~Temperature', 10);
        $this->RegisterVariableFloat('SET', $this->Translate('Soll-Temperatur'), 'MERO.SetTemp', 20);
        $this->EnableAction('SET');
        $this->RegisterVariableBoolean('ONOFF', $this->Translate('Ein'), '~Switch', 30);
        $this->EnableAction('ONOFF');
        $this->RegisterVariableBoolean('HEAT', $this->Translate('Heizt gerade'), '~Alert', 40);
        $this->RegisterVariableInteger('MODE', $this->Translate('Modus'), $this->EnumPresentation([
            ['Value' => 0, 'Caption' => $this->Translate('Heizen'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 1, 'Caption' => $this->Translate('Kühlen'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 2, 'Caption' => $this->Translate('Eco'),     'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 3, 'Caption' => $this->Translate('Auto'),    'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 4, 'Caption' => $this->Translate('Manuell'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
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
        if ($cur !== null) {
            $this->SetValue('TEMP', round(((int) $cur) / $scale, 1));
        }

        $tgt = $d['targetTemp'] ?? ($d['currentSet'] ?? ($d['manualTemp'] ?? null));
        if ($tgt !== null) {
            $this->SetValue('SET', round(((int) $tgt) / $scale, 1));
        }

        if (isset($d['onoff'])) {
            $this->SetValue('ONOFF', (int) $d['onoff'] === 1);
        }

        $heat = $d['working'] ?? ($d['state'] ?? null);
        if ($heat !== null) {
            $this->SetValue('HEAT', (int) $heat === 1);
        }

        if (isset($d['mode'])) {
            $this->SetValue('MODE', (int) $d['mode']);
        }
    }
}
