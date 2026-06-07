<?php

declare(strict_types=1);

// =====================================================================
//  Meross Geräte aus Cloud hinzufügen  -  Konfigurator
//  Zugangsdaten/Login EINMAL hier; listet die Konto-Geraete und legt
//  per Klick Geraete-Instanzen an (Key/Name/Typ werden mitgegeben).
//  Version 1.2.1 / Build 19
// =====================================================================

class MerossConfigurator extends IPSModule
{
    private const CLOUD_SECRET       = '23x17ahWarFH6w29';
    private const DEVICE_MODULE_GUID = '{E2F1EC32-BEE9-4EB7-B793-09043718458C}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('MFA', '');
        $this->RegisterPropertyString('ApiBase', 'https://iotx-eu.meross.com');
        $this->RegisterPropertyString('Key', '');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Email', 'caption' => 'Meross E-Mail'],
                ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Meross Passwort'],
                ['type' => 'ValidationTextBox', 'name' => 'MFA', 'caption' => '2FA-Code (nur falls Meross danach fragt)'],
                ['type' => 'Select', 'name' => 'ApiBase', 'caption' => 'Region', 'options' => [
                    ['caption' => 'Europa', 'value' => 'https://iotx-eu.meross.com'],
                    ['caption' => 'USA', 'value' => 'https://iotx-us.meross.com'],
                    ['caption' => 'Asien / Pazifik', 'value' => 'https://iotx-ap.meross.com']
                ]],
                ['type' => 'PasswordTextBox', 'name' => 'Key', 'caption' => 'Konto-Key (wird auf alle Geräte übertragen; leer = automatisch aus dem Login)']
            ],
            'actions' => [],
            'status'  => []
        ];

        $email = $this->ReadPropertyString('Email');
        $pass  = $this->ReadPropertyString('Password');

        if ($email === '' || $pass === '') {
            $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Zugangsdaten eintragen und „Übernehmen" drücken – danach erscheint hier die Geräteliste.')];
            return json_encode($form);
        }

        $key = '';
        $err = '';
        $devices = $this->CloudLoginAndList($email, $pass, $this->ReadPropertyString('MFA'), $this->ReadPropertyString('ApiBase'), $key, $err);

        if ($devices === null) {
            $form['actions'][] = ['type' => 'Label', 'caption' => '⚠ ' . $err];
            return json_encode($form);
        }

        // Gespeicherten Konto-Key bevorzugen, sonst den aus dem Login verwenden
        $injectKey = $this->ReadPropertyString('Key');
        if ($injectKey === '') {
            $injectKey = $key;
        }

        $values = [];
        foreach ($devices as $d) {
            $uuid = $d['uuid'] ?? '';
            $name = $d['devName'] ?? '?';
            $raw  = strtolower($d['deviceType'] ?? '');
            $type = $this->MapType($raw);
            $values[] = [
                'name'       => $name,
                'typ'        => $raw,
                'uuid'       => $uuid,
                'instanceID' => $this->FindInstanceByUuid($uuid),
                'create'     => [
                    'moduleID'      => self::DEVICE_MODULE_GUID,
                    'configuration' => [
                        'DeviceType' => $type,
                        'DeviceName' => $name,
                        'Uuid'       => $uuid,
                        'Key'        => $injectKey
                    ]
                ]
            ];
        }

        $form['actions'][] = [
            'type'     => 'Configurator',
            'name'     => 'config',
            'caption'  => $this->Translate('Geräte aus deinem Meross-Konto'),
            'rowCount' => 20,
            'add'      => false,
            'delete'   => true,
            'columns'  => [
                ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '250px'],
                ['caption' => 'Typ', 'name' => 'typ', 'width' => '120px'],
                ['caption' => 'UUID', 'name' => 'uuid', 'width' => 'auto']
            ],
            'values'   => $values
        ];
        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Nach dem Hinzufügen im Gerät die lokale IP eintragen. Tipp: feste IP in der Fritzbox vergeben, dann bleibt sie konstant.')];

        return json_encode($form);
    }

    private function MapType(string $t): string
    {
        if (strpos($t, '620') !== false) {
            return 'mss620';
        }
        if (strpos($t, 'mrs') !== false || strpos($t, 'rs100') !== false) {
            return 'mrs100';
        }
        return 'mss210p';
    }

    private function FindInstanceByUuid(string $uuid): int
    {
        if ($uuid === '') {
            return 0;
        }
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID) as $iid) {
            if (@IPS_GetProperty($iid, 'Uuid') === $uuid) {
                return $iid;
            }
        }
        return 0;
    }

    private function CloudLoginAndList(string $email, string $password, string $mfa, string $base, string &$key, string &$err)
    {
        $base = rtrim($base, '/');
        if ($base === '') {
            $base = 'https://iotx-eu.meross.com';
        }

        $params = [
            'email'              => $email,
            'password'           => md5($password),
            'accountCountryCode' => 'DE',
            'encryption'         => 1,
            'agree'              => 0
        ];
        if ($mfa !== '') {
            $params['mfaCode'] = $mfa;
        }

        $login = $this->CloudPost($base, '/v1/Auth/signIn', '', $params);

        if (isset($login['apiStatus']) && (int) $login['apiStatus'] === 1030) {
            $nb = $login['data']['domain'] ?? '';
            if ($nb !== '') {
                $base  = rtrim($nb, '/');
                $login = $this->CloudPost($base, '/v1/Auth/signIn', '', $params);
            }
        }

        if (isset($login['apiStatus']) && (int) $login['apiStatus'] === 1033) {
            $err = $this->Translate('Meross verlangt einen 2FA-Code. Code eintragen und erneut übernehmen.');
            return null;
        }

        if (!isset($login['apiStatus']) || (int) $login['apiStatus'] !== 0) {
            $this->LogMessage('signIn-Antwort: ' . json_encode($login), KL_WARNING);
            $login = $this->CloudPost($base, '/v1/Auth/Login', '', ['email' => $email, 'password' => $password]);
        }

        if (!isset($login['apiStatus']) || (int) $login['apiStatus'] !== 0) {
            $code = $login['apiStatus'] ?? '?';
            $info = $login['info'] ?? '?';
            $this->LogMessage('Login fehlgeschlagen: ' . json_encode($login), KL_ERROR);
            $err = $this->Translate('Login fehlgeschlagen') . " (apiStatus $code: $info).";
            return null;
        }

        $key   = $login['data']['key'] ?? '';
        $token = $login['data']['token'] ?? '';
        $this->LogMessage('Login OK. Key=' . $key, KL_NOTIFY);

        $list = $this->CloudPost($base, '/v1/Device/devList', $token, []);
        if (!isset($list['apiStatus']) || (int) $list['apiStatus'] !== 0) {
            $this->LogMessage('devList-Antwort: ' . json_encode($list), KL_ERROR);
            $err = $this->Translate('Geräteliste konnte nicht geladen werden.');
            return null;
        }

        $devices = $list['data'] ?? [];
        return is_array($devices) ? $devices : [];
    }

    private function CloudPost(string $base, string $path, string $token, array $params)
    {
        $paramStr = base64_encode(json_encode(empty($params) ? new stdClass() : $params));
        $ts       = (int) round(microtime(true) * 1000);
        $nonce    = strtoupper(bin2hex(random_bytes(8)));
        $sign     = md5(self::CLOUD_SECRET . $ts . $nonce . $paramStr);

        $body = json_encode([
            'params'    => $paramStr,
            'sign'      => $sign,
            'timestamp' => $ts,
            'nonce'     => $nonce
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . $token,
            'vender: Meross',
            'AppVersion: 1.3.0',
            'AppLanguage: EN',
            'User-Agent: okhttp/3.6.0'
        ];

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15
        ]);
        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $e      = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $result === false) {
            return ['apiStatus' => -1, 'info' => 'HTTP-Fehler: ' . $e];
        }
        $d = json_decode($result, true);
        return is_array($d) ? $d : ['apiStatus' => -1, 'info' => 'Antwort nicht lesbar'];
    }
}
