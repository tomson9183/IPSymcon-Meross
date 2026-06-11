<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Hub MSH300 (auch MSH300HK) - lokale Steuerung der Unter-Geräte.
//
//  Der Hub ist das WLAN-Gerät (mit IP); Sensoren/Ventile hängen als
//  Funk-Unter-Geräte daran und werden über "id" (subId) adressiert.
//  Die Unter-Geräte werden beim Polling automatisch erkannt und je
//  Unter-Gerät als eigene Variablen angelegt (Ident enthält die subId).
//
//  Verwendete Namespaces (dokumentiert, meross_iot/ioBroker):
//   * Appliance.Hub.Mts100.All          GET  {"all":[]}
//       -> je Ventil: temperature.room / currentSet / heating (Zehntel°C),
//          mode.state, togglex.onoff
//   * Appliance.Hub.Mts100.Temperature  SET  {"temperature":[{"id","custom"}]}
//   * Appliance.Hub.Mts100.Mode         SET  {"mode":[{"id","state"}]}
//   * Appliance.Hub.ToggleX             SET  {"togglex":[{"id","onoff"}]}
//   * Appliance.Hub.Sensor.All          GET  {"all":[]}
//       -> je Sensor: temperature.latest / humidity.latest (Zehntel),
//          doorWindow.status
//   * Appliance.Hub.Battery             GET  {"battery":[]}  -> value (%)
//
//  HINWEIS: Protokoll dokumentiert, am Geraet noch zu testen.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait HubDevice
{
    private function HubApplyChanges()
    {
        // Variablen entstehen dynamisch beim Polling. Einmal direkt versuchen:
        $this->HubUpdate();
    }

    private function HubRequestAction($Ident, $Value)
    {
        if (strncmp($Ident, 'TSET_', 5) === 0) {           // Soll-Temperatur
            $sid  = substr($Ident, 5);
            $val  = (int) round(((float) $Value) * 10);
            $resp = $this->LocalRequest('Appliance.Hub.Mts100.Temperature', 'SET', ['temperature' => [['id' => $sid, 'custom' => $val]]]);
            if ($resp !== null) {
                $this->SetValue($Ident, round((float) $Value, 1));
            }
            return;
        }
        if (strncmp($Ident, 'VON_', 4) === 0) {            // Ventil ein/aus
            $sid  = substr($Ident, 4);
            $resp = $this->LocalRequest('Appliance.Hub.ToggleX', 'SET', ['togglex' => [['id' => $sid, 'onoff' => $Value ? 1 : 0]]]);
            if ($resp !== null) {
                $this->SetValue($Ident, (bool) $Value);
            }
            return;
        }
        if (strncmp($Ident, 'VMODE_', 6) === 0) {          // Modus
            $sid  = substr($Ident, 6);
            $resp = $this->LocalRequest('Appliance.Hub.Mts100.Mode', 'SET', ['mode' => [['id' => $sid, 'state' => (int) $Value]]]);
            if ($resp !== null) {
                $this->SetValue($Ident, (int) $Value);
            }
            return;
        }
    }

    private function HubUpdate()
    {
        $any = false;

        // --- Thermostat-Ventile (MTS100 / MTS150) ---
        $mts = $this->LocalRequest('Appliance.Hub.Mts100.All', 'GET', ['all' => []]);
        if ($mts !== null) {
            $any = true;
            foreach (($mts['payload']['all'] ?? []) as $d) {
                $sid = $this->HubSid($d['id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $t = $d['temperature'] ?? [];
                $this->HubFloat('TROOM_' . $sid, $this->Translate('Heizung Ist') . " ($sid)", '~Temperature', isset($t['room']) ? ((int) $t['room']) / 10.0 : null, 10, false);
                $this->HubFloat('TSET_' . $sid, $this->Translate('Heizung Soll') . " ($sid)", 'MERO.SetTemp', isset($t['currentSet']) ? ((int) $t['currentSet']) / 10.0 : null, 20, true);
                $this->HubBool('VON_' . $sid, $this->Translate('Heizung Ein') . " ($sid)", '~Switch', isset($d['togglex']['onoff']) ? ((int) $d['togglex']['onoff'] === 1) : null, 30, true);
                $this->HubBool('VHEAT_' . $sid, $this->Translate('Heizt gerade') . " ($sid)", '~Alert', isset($t['heating']) ? ((int) $t['heating'] === 1) : null, 40, false);
                $this->HubInt('VMODE_' . $sid, $this->Translate('Modus') . " ($sid)", $this->EnumPresentation([
                    ['Value' => 0, 'Caption' => $this->Translate('Manuell'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                    ['Value' => 1, 'Caption' => $this->Translate('Komfort'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                    ['Value' => 2, 'Caption' => $this->Translate('Sparen'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                    ['Value' => 3, 'Caption' => $this->Translate('Auto'),    'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ]), isset($d['mode']['state']) ? (int) $d['mode']['state'] : null, 50, true);
            }
        }

        // --- Sensoren (MS100 Temp/Feuchte, MS200 Tür/Fenster) ---
        $sen = $this->LocalRequest('Appliance.Hub.Sensor.All', 'GET', ['all' => []]);
        if ($sen !== null) {
            $any = true;
            foreach (($sen['payload']['all'] ?? []) as $d) {
                $sid = $this->HubSid($d['id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                if (isset($d['temperature']['latest'])) {
                    $this->HubFloat('STEMP_' . $sid, $this->Translate('Temperatur') . " ($sid)", '~Temperature', ((int) $d['temperature']['latest']) / 10.0, 10, false);
                }
                if (isset($d['humidity']['latest'])) {
                    $this->HubFloat('SHUM_' . $sid, $this->Translate('Luftfeuchte') . " ($sid)", '~Humidity.F', ((int) $d['humidity']['latest']) / 10.0, 20, false);
                }
                if (isset($d['doorWindow']['status'])) {
                    $this->HubBool('SDOOR_' . $sid, $this->Translate('Tür/Fenster') . " ($sid)", '~Window', ((int) $d['doorWindow']['status'] === 1), 30, false);
                }
            }
        }

        // --- Batterie der Unter-Geräte ---
        $bat = $this->LocalRequest('Appliance.Hub.Battery', 'GET', ['battery' => []]);
        if ($bat !== null) {
            foreach (($bat['payload']['battery'] ?? []) as $d) {
                $sid = $this->HubSid($d['id'] ?? '');
                if ($sid === '' || !isset($d['value'])) {
                    continue;
                }
                $this->HubInt('BAT_' . $sid, $this->Translate('Batterie') . " ($sid)", '~Battery.100', (int) $d['value'], 60, false);
            }
        }

        $this->SetStatus($any ? 102 : 201);
    }

    // ---- Helfer: idempotentes Anlegen + Wert setzen ----
    private function HubFloat(string $id, string $name, string $profile, $value, int $pos, bool $action)
    {
        $this->RegisterVariableFloat($id, $name, $profile, $pos);
        if ($action) {
            $this->EnableAction($id);
        }
        if ($value !== null) {
            $this->SetValue($id, round((float) $value, 1));
        }
    }

    private function HubBool(string $id, string $name, string $profile, $value, int $pos, bool $action)
    {
        $this->RegisterVariableBoolean($id, $name, $profile, $pos);
        if ($action) {
            $this->EnableAction($id);
        }
        if ($value !== null) {
            $this->SetValue($id, (bool) $value);
        }
    }

    private function HubInt(string $id, string $name, $presentation, $value, int $pos, bool $action)
    {
        $this->RegisterVariableInteger($id, $name, $presentation, $pos);
        if ($action) {
            $this->EnableAction($id);
        }
        if ($value !== null) {
            $this->SetValue($id, (int) $value);
        }
    }

    private function HubSid($raw): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $raw);
    }
}
