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

class MerossDevice extends IPSModule
{
    use PlugDevice;    // Steckdosen (MSS210P / MSS620)
    use RollerDevice;  // Rollladen (MRS100)

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

        $this->RegisterTimer('MERO_Poll', 0, 'MERO_Update($_IPS[\'TARGET\']);');
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
        if ($type === 'mrs100') {
            $this->RollerApplyChanges();
        } else {
            $this->PlugApplyChanges();
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
        $type = $this->ReadPropertyString('DeviceType');
        if ($type === 'mrs100') {
            $this->RollerRequestAction($Ident, $Value);
        } else {
            $this->PlugRequestAction($Ident, $Value);
        }
    }

    public function Update()
    {
        $type = $this->ReadPropertyString('DeviceType');
        if ($type === 'mrs100') {
            $this->RollerUpdate();
        } else {
            $this->PlugUpdate();
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
    // (z.B. uebrig gebliebene Steckdosen-Variable in einem Rollladen)
    private function CleanupForeignVars(string $type)
    {
        if ($type === 'mrs100') {
            $remove = ['STATE0', 'STATE1', 'STATE2', 'STATE3', 'POWER', 'VOLTAGE', 'CURRENT', 'ENERGY_TODAY'];
        } else {
            $remove = ['MOVE', 'LEVEL', 'CONTROL', 'POSITION'];
        }
        foreach ($remove as $id) {
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
    }
}
