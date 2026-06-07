<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Steckdosen (MSS210P, MSS620): Ein/Aus, Strommessung, Status-LED.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait PlugDevice
{
    private function PlugApplyChanges()
    {
        $type = $this->ReadPropertyString('DeviceType');
        $channels = ($type === 'mss620') ? 2 : 1;
        for ($ch = 0; $ch < $channels; $ch++) {
            $caption = ($channels > 1) ? ('Kanal ' . ($ch + 1)) : 'Schalter';
            $this->RegisterVariableBoolean('STATE' . $ch, $caption, 'MERO.Plug', $ch);
            $this->EnableAction('STATE' . $ch);
        }
    }

    private function PlugRequestAction($Ident, $Value)
    {
        if (strpos($Ident, 'STATE') !== 0) {
            return;
        }
        $channel = (int) substr($Ident, 5);
        $onoff   = $Value ? 1 : 0;
        $resp = $this->LocalRequest('Appliance.Control.ToggleX', 'SET', ['togglex' => ['channel' => $channel, 'onoff' => $onoff]]);
        if ($resp === null) {
            return;
        }
        // tatsaechlichen Zustand aus der Antwort uebernehmen, sonst optimistisch
        $applied = null;
        $tx = $resp['payload']['togglex'] ?? null;
        if (is_array($tx)) {
            if (isset($tx['onoff'])) {
                $applied = (int) $tx['onoff'];
            } else {
                foreach ($tx as $entry) {
                    if ((int) ($entry['channel'] ?? -1) === $channel) {
                        $applied = (int) ($entry['onoff'] ?? $onoff);
                    }
                }
            }
        }
        $this->SetValue($Ident, $applied === null ? (bool) $Value : ($applied === 1));
        $this->SyncLed();
    }

    private function PlugUpdate()
    {
        $resp = $this->LocalRequest('Appliance.System.All', 'GET', []);
        if ($resp === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);

        $toggles = $resp['payload']['all']['digest']['togglex'] ?? null;
        if (is_array($toggles)) {
            foreach ($toggles as $t) {
                if (!isset($t['channel'])) {
                    continue;
                }
                $ch    = (int) $t['channel'];
                $ident = 'STATE' . $ch;
                if (@$this->GetIDForIdent($ident) === false) {
                    $this->RegisterVariableBoolean($ident, 'Kanal ' . ($ch + 1), 'MERO.Plug', $ch);
                    $this->EnableAction($ident);
                }
                $this->SetValue($ident, ((int) ($t['onoff'] ?? 0)) === 1);
            }
        }

        $this->PlugUpdateElectricity();
        $this->PlugUpdateConsumption();
        $this->SyncLed();
    }

    private function PlugUpdateElectricity()
    {
        $resp = $this->LocalRequest('Appliance.Control.Electricity', 'GET', ['channel' => 0]);
        if ($resp === null) {
            return;
        }
        $e = $resp['payload']['electricity'] ?? null;
        if (!is_array($e) || !isset($e['power'])) {
            return; // Geraet ohne Messfunktion
        }
        if (@$this->GetIDForIdent('POWER') === false) {
            $this->RegisterVariableFloat('POWER', 'Leistung', '~Watt', 30);
            $this->RegisterVariableFloat('VOLTAGE', 'Spannung', '~Volt', 31);
            $this->RegisterVariableFloat('CURRENT', 'Strom', '~Ampere', 32);
        }
        $this->SetValue('POWER', round(((float) $e['power']) / 1000, 2));
        $this->SetValue('VOLTAGE', round(((float) ($e['voltage'] ?? 0)) / 10, 1));
        $this->SetValue('CURRENT', round(((float) ($e['current'] ?? 0)) / 1000, 3));
    }

    private function PlugUpdateConsumption()
    {
        $resp = $this->LocalRequest('Appliance.Control.ConsumptionX', 'GET', []);
        if ($resp === null) {
            return;
        }
        $cx = $resp['payload']['consumptionx'] ?? null;
        if (!is_array($cx) || count($cx) === 0) {
            return;
        }
        $last = end($cx);
        if (!isset($last['value'])) {
            return;
        }
        if (@$this->GetIDForIdent('ENERGY_TODAY') === false) {
            $this->RegisterVariableFloat('ENERGY_TODAY', 'Verbrauch heute', '~Electricity', 33);
        }
        $this->SetValue('ENERGY_TODAY', round(((float) $last['value']) / 1000, 3));
    }

    // Status-LED folgt dem Schaltzustand: an -> DND aus (LED an), aus -> DND an (LED aus)
    private function SyncLed()
    {
        $anyOn = false;
        for ($ch = 0; $ch < 4; $ch++) {
            $id = @$this->GetIDForIdent('STATE' . $ch);
            if ($id !== false && GetValue($id)) {
                $anyOn = true;
            }
        }
        if ($anyOn !== $this->ReadAttributeBoolean('LastLed')) {
            $this->SetDND($anyOn);
            $this->WriteAttributeBoolean('LastLed', $anyOn);
        }
    }

    private function SetDND(bool $ledOn)
    {
        $mode = $ledOn ? 0 : 1;
        $this->LocalRequest('Appliance.System.DNDMode', 'SET', ['DNDMode' => ['mode' => $mode]]);
    }
}
