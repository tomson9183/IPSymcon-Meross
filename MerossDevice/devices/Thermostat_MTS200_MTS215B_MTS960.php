<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Eigenständige WLAN-Thermostate (kein Hub):
//  MTS200 / MTS200B / MTS215B  -> Appliance.Control.Thermostat.Mode
//  MTS960                       -> Appliance.Control.Thermostat.ModeB
//
//  Das Format unterscheidet sich:
//   * Mode  (MTS200): Temperaturen in Zehntelgrad  (240 = 24,0 °C)
//       Felder u.a.: onoff, mode, state(=heizt), currentSet (Soll),
//                    heatTemp/coolTemp/ecoTemp/manualTemp, min/max
//   * ModeB (MTS960): Temperaturen in Hundertstel  (2864 = 28,64 °C)
//       Felder u.a.: onoff, mode, working(=heizt), currentTemp, targetTemp
//   onoff: 1 = ein, 2 = aus
//
//  Die Variante wird beim Polling automatisch erkannt und für das
//  Senden gemerkt (Attribut ThermoVariant / ThermoScale).
//
//  HINWEIS: Protokoll dokumentiert, am Geraet noch zu testen. Vor allem
//  das Ist-Temperatur-Feld und die Modus-Werte koennen je Modell leicht
//  abweichen -> bei Bedarf per Debug-Log feinjustieren.
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
            ['Value' => 0, 'Caption' => $this->Translate('Auto'),   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 1, 'Caption' => $this->Translate('Heizen'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 2, 'Caption' => $this->Translate('Kühlen'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 3, 'Caption' => $this->Translate('Eco'),    'IconActive' => false, 'IconValue' => '', 'Color' => -1],
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
                $val  = (int) round(((float) $Value) * $scale);
                $resp = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, 'targetTemp' => $val]]]);
                if ($resp !== null) {
                    $this->SetValue('SET', round((float) $Value, 1));
                }
                break;

            case 'ONOFF':
                $onoff = $Value ? 1 : 2; // 1 = ein, 2 = aus
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
        // Variante B (MTS960): Hundertstelgrad
        $mb = $this->LocalRequest('Appliance.Control.Thermostat.ModeB', 'GET', []);
        if ($mb !== null && isset($mb['payload']['modeB'][0])) {
            $this->ThermoApply($mb['payload']['modeB'][0], 'modeB', 100);
            return;
        }

        // Variante A (MTS200): Zehntelgrad
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
