<?php

declare(strict_types=1);

// =====================================================================
//  Meross Gerät  -  IP-Symcon Modul (Geraet)
//  Schlanke Weiche: gemeinsame Funktionen hier, Geraete-Logik je Typ
//  in einer eigenen Datei unter devices/.
//  Version 1.2.1 / Build 19
// =====================================================================

require_once __DIR__ . '/devices/PlugDevice.php';
require_once __DIR__ . '/devices/RollerDevice.php';
require_once __DIR__ . '/devices/Light_MSL_MSS560.php';
require_once __DIR__ . '/devices/Garage_MSG100_MSG200.php';
require_once __DIR__ . '/devices/Hub_MSH300.php';
require_once __DIR__ . '/devices/Thermostat_MTS200_MTS215B_MTS960.php';

class MerossDevice extends IPSModule
{
    use PlugDevice;    // Steckdosen / Schalter (MSS210P / MSS620 / weitere)
    use RollerDevice;  // Rollladen (MRS100)
    use LightDevice;   // Lampe / Dimmer / LED (MSL-Serie, MSS560)
    use GarageDevice;  // Garagentoröffner (MSG100 / MSG200)
    use HubDevice;     // Hub MSH300 mit Sensoren / Thermostat-Ventilen
    use ThermostatDevice; // WLAN-Thermostate (MTS200 / MTS215B / MTS960)

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceType', 'mss210p');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('Uuid', '');
        $this->RegisterPropertyString('Key', '');
        $this->RegisterPropertyInteger('PollInterval', 10);

        $this->RegisterAttributeBoolean('LastLed', true);

        // Rollladen: zeitbasierter Positions-Mitlauf + praeziser Stop
        $this->RegisterAttributeInteger('FollowUntil', 0);
        $this->RegisterAttributeInteger('FollowSeconds', 22);
        $this->RegisterAttributeInteger('MoveFrom', 0);
        $this->RegisterAttributeInteger('MoveTo', 0);
        $this->RegisterAttributeInteger('MoveDurMs', 0);
        $this->RegisterAttributeString('MoveStart', '0');

        // Eigenständige WLAN-Thermostate: erkannte Variante/Skalierung merken
        $this->RegisterAttributeString('ThermoVariant', '');
        $this->RegisterAttributeInteger('ThermoScale', 10);

        $this->RegisterTimer('MERO_Poll', 0, 'MERO_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MERO_RollerFollow', 0, 'MERO_RollerFollowTick($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->CleanupLegacyVars();

        $type = $this->ReadPropertyString('DeviceType');
        $this->CleanupForeignVars($type);
        switch ($this->TypeGroup($type)) {
            case 'roller': $this->RollerApplyChanges(); break;
            case 'light':  $this->LightApplyChanges();  break;
            case 'garage': $this->GarageApplyChanges(); break;
            case 'hub':    $this->HubApplyChanges();    break;
            case 'thermostat': $this->ThermoApplyChanges(); break;
            default:       $this->PlugApplyChanges();   break;
        }

        // Name aus der Cloud uebernehmen (vom Konfigurator gesetzt)
        $devName = trim($this->ReadPropertyString('DeviceName'));
        if ($devName !== '' && IPS_GetName($this->InstanceID) !== $devName) {
            IPS_SetName($this->InstanceID, $devName);
        }

        $key  = $this->ReadPropertyString('Key');
        $host = $this->ReadPropertyString('Host');
        if ($key === '' || $host === '') {
            $this->SetStatus(202);
            $this->SetTimerInterval('MERO_Poll', 0);
            return;
        }

        $this->SetStatus(102);
        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('MERO_Poll', $interval > 0 ? $interval * 1000 : 0);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($this->TypeGroup($this->ReadPropertyString('DeviceType'))) {
            case 'roller': $this->RollerRequestAction($Ident, $Value); break;
            case 'light':  $this->LightRequestAction($Ident, $Value);  break;
            case 'garage': $this->GarageRequestAction($Ident, $Value); break;
            case 'hub':    $this->HubRequestAction($Ident, $Value);    break;
            case 'thermostat': $this->ThermoRequestAction($Ident, $Value); break;
            default:       $this->PlugRequestAction($Ident, $Value);   break;
        }
    }

    public function Update()
    {
        switch ($this->TypeGroup($this->ReadPropertyString('DeviceType'))) {
            case 'roller': $this->RollerUpdate(); break;
            case 'light':  $this->LightUpdate();  break;
            case 'garage': $this->GarageUpdate(); break;
            case 'hub':    $this->HubUpdate();    break;
            case 'thermostat': $this->ThermoUpdate(); break;
            default:       $this->PlugUpdate();   break;
        }
    }

    // Ordnet die (vielen) Geraetetypen einer Steuer-Gruppe zu
    private function TypeGroup(string $type): string
    {
        if ($type === 'mrs100') {
            return 'roller';
        }
        if ($type === 'light') {
            return 'light';
        }
        if ($type === 'garage') {
            return 'garage';
        }
        if ($type === 'hub') {
            return 'hub';
        }
        if ($type === 'thermostat') {
            return 'thermostat';
        }
        return 'plug'; // mss210p, mss620 und weitere Schalter/Steckdosen
    }

    // Timer-Callback fuer den sanften Positions-Mitlauf (nur Rollladen)
    public function RollerFollowTick()
    {
        if ($this->ReadPropertyString('DeviceType') === 'mrs100') {
            $this->RollerFollow();
        }
    }

    public function Abilities()
    {
        $resp = $this->LocalRequest('Appliance.System.Ability', 'GET', []);
        if ($resp === null) {
            echo $this->Translate('Gerät lokal nicht erreichbar. IP und Key prüfen.');
            return;
        }
        $ability = $resp['payload']['ability'] ?? [];
        $names = is_array($ability) ? array_keys($ability) : [];
        $this->SendDebug('Abilities', implode(', ', $names), 0);
        echo $this->Translate('Fähigkeiten ins Debug-Fenster geschrieben') . ":\n\n" . implode("\n", $names);
    }

    // =================================================================
    //  Gemeinsame Funktionen (fuer alle Geraetetypen)
    // =================================================================

    // Variablen entfernen, die nicht zum aktuellen Geraetetyp gehoeren
    // (z.B. uebrig gebliebene Steckdosen-Variable nach einem Typwechsel)
    private function CleanupForeignVars(string $type)
    {
        $sets = [
            'plug'       => ['STATE0', 'STATE1', 'STATE2', 'STATE3', 'STATE4', 'STATE5', 'POWER', 'VOLTAGE', 'CURRENT', 'ENERGY_TODAY'],
            'roller'     => ['MOVE', 'LEVEL'],
            'light'      => ['STATE', 'BRIGHT', 'COLOR', 'CTEMP'],
            'garage'     => ['DOOR'],
            'thermostat' => ['VISU', 'TEMP', 'SET', 'ONOFF', 'HEAT', 'MODE'],
        ];
        // Hub-Variablen sind dynamisch (subId-Suffix) und werden hier nicht
        // erfasst; sie bleiben beim Typwechsel ggf. stehen.
        $keep = $sets[$this->TypeGroup($type)] ?? $sets['plug'];
        $all  = array_merge($sets['plug'], $sets['roller'], $sets['light'], $sets['garage'], $sets['thermostat']);
        foreach (array_diff($all, $keep) as $id) {
            if (@$this->GetIDForIdent($id) !== false) {
                $this->UnregisterVariable($id);
            }
        }
    }

    // Diagnose: liest die Rollladen-Konfiguration (Kalibrierung) + Position
    public function RollerConfig()
    {
        $cfg = $this->LocalRequest('Appliance.RollerShutter.Config', 'GET', []);
        $this->SendDebug('Roller.Config', $cfg === null ? 'keine Antwort' : json_encode($cfg), 0);
        $pos = $this->LocalRequest('Appliance.RollerShutter.Position', 'GET', []);
        $this->SendDebug('Roller.Position', $pos === null ? 'keine Antwort' : json_encode($pos), 0);
        echo $this->Translate('Rollladen-Konfiguration ins Debug-Fenster geschrieben.');
    }

    // Diagnose: ermittelt zuerst, ob/was das Geraet kann, und liest dann den
    // passenden Thermostat-Namespace roh aus (Ist-Feld, mode-Werte je Modell).
    public function ThermoDiag()
    {
        $lines = [];

        // 0) Host/Key vorhanden?
        if ($this->ReadPropertyString('Host') === '' || $this->ReadPropertyString('Key') === '') {
            $out = 'Host oder Key ist leer -> bitte IP und Konto-Key setzen.';
            $this->SendDebug('Thermo.Diag', $out, 0);
            echo $this->Translate('Thermostat-Diagnose') . ":\n\n" . $out;
            return;
        }

        // 1) Faehigkeiten des Geraets (entscheidet ueber den Namespace)
        $ab = $this->LocalRequest('Appliance.System.Ability', 'GET', []);
        if ($ab === null) {
            $out = "Appliance.System.Ability: KEINE ANTWORT.\n"
                 . "Das Geraet antwortet nicht einmal auf die Basis-Abfrage -> es ist\n"
                 . "unter dieser IP nicht erreichbar (siehe Debug-Fenster: curl-Fehler).";
            $this->SendDebug('Thermo.Diag', $out, 0);
            echo $this->Translate('Thermostat-Diagnose') . ":\n\n" . $out;
            return;
        }
        $names = array_keys($ab['payload']['ability'] ?? []);
        sort($names);
        $thermo = array_values(array_filter($names, function ($n) {
            return stripos($n, 'thermostat') !== false || stripos($n, 'mts') !== false;
        }));
        $lines[] = '=== Unterstuetzte Namespaces (gesamt: ' . count($names) . ') ===';
        $lines[] = implode("\n", $names);
        $lines[] = '';
        $lines[] = '=== Thermostat-relevante Namespaces ===';
        $lines[] = $thermo ? implode("\n", $thermo) : '(keine gefunden -> evtl. kein eigenstaendiges WLAN-Thermostat)';
        $lines[] = '';

        // 2) digest aus System.All (Zustand steckt oft schon hier)
        $all = $this->LocalRequest('Appliance.System.All', 'GET', []);
        $digest = $all['payload']['all']['digest'] ?? null;
        if (is_array($digest)) {
            $lines[] = '=== System.All digest (Top-Level-Schluessel) ===';
            $lines[] = implode(', ', array_keys($digest));
            if (isset($digest['thermostat'])) {
                $lines[] = 'digest.thermostat = ' . json_encode($digest['thermostat']);
            }
            $lines[] = '';
        }

        // 3) Die gefundenen Thermostat-Namespaces roh auslesen
        $probe = $thermo ?: ['Appliance.Control.Thermostat.ModeB', 'Appliance.Control.Thermostat.Mode'];
        foreach ($probe as $ns) {
            $resp = $this->LocalRequest($ns, 'GET', []);
            if ($resp === null) {
                $lines[] = $ns . ': keine Antwort';
                continue;
            }
            $lines[] = '=== ' . $ns . ' (payload roh) ===';
            $lines[] = json_encode($resp['payload'] ?? null);
        }

        $out = implode("\n", $lines);
        $this->SendDebug('Thermo.Diag', $out, 0);
        echo $this->Translate('Thermostat-Diagnose (auch im Debug-Fenster)') . ":\n\n" . $out;
    }

    // Alte Variablen aus frueheren Versionen entfernen
    private function CleanupLegacyVars()
    {
        foreach (['LED', 'SHUTTER', 'CONTROL', 'POSITION'] as $old) {
            if (@$this->GetIDForIdent($old) !== false) {
                $this->UnregisterVariable($old);
            }
        }
    }

    // Lokaler signierter HTTP-Aufruf an das Geraet
    private function LocalRequest(string $namespace, string $method, array $payload)
    {
        $host = $this->ReadPropertyString('Host');
        $key  = $this->ReadPropertyString('Key');
        if ($host === '' || $key === '') {
            return null;
        }

        $messageId = md5(uniqid('', true));
        $timestamp = time();
        $sign      = md5($messageId . $key . $timestamp);

        $data = [
            'header' => [
                'messageId'      => $messageId,
                'namespace'      => $namespace,
                'method'         => $method,
                'payloadVersion' => 1,
                'from'           => 'http://' . $host . '/config',
                'timestamp'      => $timestamp,
                'timestampMs'    => 0,
                'sign'           => $sign
            ],
            'payload' => empty($payload) ? new stdClass() : $payload
        ];

        $body = json_encode($data);
        $this->SendDebug('TX ' . $namespace . ' [' . $method . ']', (string) $body, 0);

        $ch = curl_init('http://' . $host . '/config');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6
        ]);
        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $result === false) {
            $this->SendDebug('RX-Fehler ' . $namespace, 'keine Antwort (curl ' . $errno . ': ' . $cerr . ')', 0);
            return null;
        }
        $this->SendDebug('RX ' . $namespace, (string) $result, 0);
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    // Aufzählung als Buttons NEBENEINANDER (LAYOUT=1) - einheitliche Darstellung
    private function EnumPresentation(array $options): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options),
            'LAYOUT'       => 1,
            'DISPLAY'      => 0,
        ];
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('MERO.Plug')) {
            IPS_CreateVariableProfile('MERO.Plug', 0); // Boolean
            IPS_SetVariableProfileIcon('MERO.Plug', 'Plug');
            IPS_SetVariableProfileAssociation('MERO.Plug', 0, $this->Translate('Aus'), '', -1);
            IPS_SetVariableProfileAssociation('MERO.Plug', 1, $this->Translate('Ein'), '', 0x00C853);
        }
        if (!IPS_VariableProfileExists('MERO.RollerCtl')) {
            IPS_CreateVariableProfile('MERO.RollerCtl', 1); // Integer
            IPS_SetVariableProfileValues('MERO.RollerCtl', 0, 2, 1);
            IPS_SetVariableProfileAssociation('MERO.RollerCtl', 0, $this->Translate('Auf'), 'ArrowUp', 0x00C853);
            IPS_SetVariableProfileAssociation('MERO.RollerCtl', 1, $this->Translate('Stop'), 'Close', 0xFFA000);
            IPS_SetVariableProfileAssociation('MERO.RollerCtl', 2, $this->Translate('Zu'), 'ArrowDown', 0x2962FF);
        }
        if (!IPS_VariableProfileExists('MERO.Pos')) {
            IPS_CreateVariableProfile('MERO.Pos', 1); // Integer, reiner Prozent-Regler
            IPS_SetVariableProfileValues('MERO.Pos', 0, 100, 1);
            IPS_SetVariableProfileText('MERO.Pos', '', ' %');
            IPS_SetVariableProfileIcon('MERO.Pos', 'Jalousie');
        }
        if (!IPS_VariableProfileExists('MERO.Light')) {
            IPS_CreateVariableProfile('MERO.Light', 0); // Boolean
            IPS_SetVariableProfileIcon('MERO.Light', 'Bulb');
            IPS_SetVariableProfileAssociation('MERO.Light', 0, $this->Translate('Aus'), '', -1);
            IPS_SetVariableProfileAssociation('MERO.Light', 1, $this->Translate('Ein'), '', 0xFFC107);
        }
        if (!IPS_VariableProfileExists('MERO.Bright')) {
            IPS_CreateVariableProfile('MERO.Bright', 1); // Integer 0..100 %
            IPS_SetVariableProfileValues('MERO.Bright', 0, 100, 1);
            IPS_SetVariableProfileText('MERO.Bright', '', ' %');
            IPS_SetVariableProfileIcon('MERO.Bright', 'Sun');
        }
        if (!IPS_VariableProfileExists('MERO.CTemp')) {
            IPS_CreateVariableProfile('MERO.CTemp', 1); // Integer 0..100 %
            IPS_SetVariableProfileValues('MERO.CTemp', 0, 100, 1);
            IPS_SetVariableProfileText('MERO.CTemp', '', ' %');
            IPS_SetVariableProfileIcon('MERO.CTemp', 'Temperature');
        }
        if (!IPS_VariableProfileExists('MERO.Garage')) {
            IPS_CreateVariableProfile('MERO.Garage', 0); // Boolean
            IPS_SetVariableProfileIcon('MERO.Garage', 'Door');
            IPS_SetVariableProfileAssociation('MERO.Garage', 0, $this->Translate('Zu'), '', 0x2962FF);
            IPS_SetVariableProfileAssociation('MERO.Garage', 1, $this->Translate('Auf'), '', 0x00C853);
        }
        if (!IPS_VariableProfileExists('MERO.SetTemp')) {
            IPS_CreateVariableProfile('MERO.SetTemp', 2); // Float
            IPS_SetVariableProfileValues('MERO.SetTemp', 5, 35, 0.5);
            IPS_SetVariableProfileText('MERO.SetTemp', '', ' °C');
            IPS_SetVariableProfileIcon('MERO.SetTemp', 'Temperature');
        }
        if (!IPS_VariableProfileExists('MERO.Heat')) {
            IPS_CreateVariableProfile('MERO.Heat', 0); // Boolean: heizt gerade
            IPS_SetVariableProfileIcon('MERO.Heat', 'Flame');
            IPS_SetVariableProfileAssociation('MERO.Heat', 0, $this->Translate('Aus'), '', -1);
            IPS_SetVariableProfileAssociation('MERO.Heat', 1, $this->Translate('Heizt'), '', 0xFF6B35);
        }
        if (!IPS_VariableProfileExists('MERO.Mts100Mode')) {
            IPS_CreateVariableProfile('MERO.Mts100Mode', 1); // Integer
            IPS_SetVariableProfileValues('MERO.Mts100Mode', 0, 3, 1);
            IPS_SetVariableProfileAssociation('MERO.Mts100Mode', 0, $this->Translate('Manuell'), '', -1);
            IPS_SetVariableProfileAssociation('MERO.Mts100Mode', 1, $this->Translate('Komfort'), '', 0xFF7043);
            IPS_SetVariableProfileAssociation('MERO.Mts100Mode', 2, $this->Translate('Sparen'), '', 0x29B6F6);
            IPS_SetVariableProfileAssociation('MERO.Mts100Mode', 3, $this->Translate('Auto'), '', 0x66BB6A);
        }
    }
}
