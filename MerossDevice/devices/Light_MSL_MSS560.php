<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Lampe / Dimmer / LED  (MSL-Serie wie MSL120/MSL320/MSL420, dimmbare
//  Schalter wie MSS560) - lokale Steuerung.
//
//  An/Aus : Appliance.Control.ToggleX  {"togglex":{"onoff":N,"channel":0}}
//  Licht  : Appliance.Control.Light    {"light":{"channel":0,"capacity":C, ...}}
//    capacity-Bits: 1 = RGB-Farbe, 2 = Farbtemperatur, 4 = Helligkeit
//    luminance / temperature: 0..100   ·   rgb: Ganzzahl 0xRRGGBB
//
//  Beim Anlegen werden die tatsaechlich unterstuetzten Funktionen aus
//  dem Geraet gelesen (digest.light.capacity) und nur passende Variablen
//  angelegt. Status per Polling aus Appliance.System.All.
//
//  HINWEIS: Protokoll dokumentiert (meross_iot), am Geraet noch zu testen.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait LightDevice
{
    private function LightApplyChanges()
    {
        $this->RegisterVariableBoolean('STATE', $this->Translate('Licht'), 'MERO.Light', 10);
        $this->EnableAction('STATE');

        $cap    = $this->LightCapability();
        $hasLum = ($cap < 0) ? true  : (($cap & 4) === 4);
        $hasRgb = ($cap < 0) ? false : (($cap & 1) === 1);
        $hasTmp = ($cap < 0) ? false : (($cap & 2) === 2);

        $this->LightMaintain('BRIGHT', $hasLum, $this->Translate('Helligkeit'), 'MERO.Bright', 20);
        $this->LightMaintain('COLOR', $hasRgb, $this->Translate('Farbe'), '~HexColor', 30);
        $this->LightMaintain('CTEMP', $hasTmp, $this->Translate('Farbtemperatur'), 'MERO.CTemp', 40);
    }

    private function LightMaintain(string $ident, bool $want, string $name, string $profile, int $pos)
    {
        if ($want) {
            $this->RegisterVariableInteger($ident, $name, $profile, $pos);
            $this->EnableAction($ident);
        } elseif (@$this->GetIDForIdent($ident) !== false) {
            $this->UnregisterVariable($ident);
        }
    }

    private function LightCapability(): int
    {
        $resp = $this->LocalRequest('Appliance.System.All', 'GET', []);
        if ($resp === null) {
            return -1;
        }
        $cap = $resp['payload']['all']['digest']['light']['capacity'] ?? -1;
        return (int) $cap;
    }

    private function LightRequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'STATE':
                $on   = $Value ? 1 : 0;
                $resp = $this->LocalRequest('Appliance.Control.ToggleX', 'SET', ['togglex' => ['onoff' => $on, 'channel' => 0]]);
                if ($resp !== null) {
                    $this->SetValue('STATE', (bool) $Value);
                }
                break;

            case 'BRIGHT':
                $l    = max(0, min(100, (int) $Value));
                $resp = $this->LocalRequest('Appliance.Control.Light', 'SET', ['light' => ['channel' => 0, 'capacity' => 4, 'luminance' => $l]]);
                if ($resp !== null) {
                    $this->SetValue('BRIGHT', $l);
                    if ($l > 0 && @$this->GetIDForIdent('STATE') !== false) {
                        $this->SetValue('STATE', true);
                    }
                }
                break;

            case 'COLOR':
                $rgb  = max(0, (int) $Value) & 0xFFFFFF;
                $resp = $this->LocalRequest('Appliance.Control.Light', 'SET', ['light' => ['channel' => 0, 'capacity' => 1, 'rgb' => $rgb]]);
                if ($resp !== null) {
                    $this->SetValue('COLOR', $rgb);
                }
                break;

            case 'CTEMP':
                $t    = max(0, min(100, (int) $Value));
                $resp = $this->LocalRequest('Appliance.Control.Light', 'SET', ['light' => ['channel' => 0, 'capacity' => 2, 'temperature' => $t]]);
                if ($resp !== null) {
                    $this->SetValue('CTEMP', $t);
                }
                break;
        }
    }

    private function LightUpdate()
    {
        $resp = $this->LocalRequest('Appliance.System.All', 'GET', []);
        if ($resp === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);

        $digest = $resp['payload']['all']['digest'] ?? [];

        if (isset($digest['togglex']) && is_array($digest['togglex']) && @$this->GetIDForIdent('STATE') !== false) {
            foreach ($digest['togglex'] as $tx) {
                if ((int) ($tx['channel'] ?? -1) === 0) {
                    $this->SetValue('STATE', (int) ($tx['onoff'] ?? 0) === 1);
                }
            }
        }

        $light = $digest['light'] ?? null;
        if (is_array($light)) {
            if (@$this->GetIDForIdent('BRIGHT') !== false && isset($light['luminance'])) {
                $this->SetValue('BRIGHT', (int) $light['luminance']);
            }
            if (@$this->GetIDForIdent('COLOR') !== false && isset($light['rgb']) && (int) $light['rgb'] >= 0) {
                $this->SetValue('COLOR', (int) $light['rgb']);
            }
            if (@$this->GetIDForIdent('CTEMP') !== false && isset($light['temperature']) && (int) $light['temperature'] >= 0) {
                $this->SetValue('CTEMP', (int) $light['temperature']);
            }
        }
    }
}
