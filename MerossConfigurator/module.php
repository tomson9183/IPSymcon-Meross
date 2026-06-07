<?php

declare(strict_types=1);

// =====================================================================
//  Meross Geräte aus Cloud hinzufügen  -  Konfigurator
//
//  WICHTIG: Der Cloud-Login erfolgt AUSSCHLIESSLICH per Knopfdruck
//  ("Geräte aus der Cloud laden"). Das Formular selbst greift NIE
//  automatisch auf die Meross-Cloud zu (verhindert die zeitweise
//  Konto-Sperre durch zu haeufige Anmeldungen). Die Geraeteliste wird
//  nach dem Laden lokal in einem Attribut zwischengespeichert.
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

        // Lokaler Zwischenspeicher der zuletzt geladenen Geraeteliste (JSON).
        // So muss das Formular NIE selbst in die Cloud.
        $this->RegisterAttributeString('DeviceCache', '');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Bewusst KEIN Cloud-Zugriff hier.
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

        // Schritt-fuer-Schritt-Hinweis + Lade-Knopf (loest als EINZIGES den Cloud-Login aus)
        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('1. Zugangsdaten eintragen und „Übernehmen" drücken.  2. Danach „Geräte aus der Cloud laden" drücken (nur dann erfolgt eine Anmeldung bei Meross).')];
        $form['actions'][] = [
            'type'    => 'Button',
            'caption' => $this->Translate('Geräte aus der Cloud laden'),
            'onClick' => 'MEROC_LoadDevices($id);'
        ];
        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Hinweis: Möglichst selten anmelden. Bei zu häufigen Anmeldungen sperrt Meross das Konto vorübergehend (ca. 5 Stunden).')];

        // Geraeteliste NUR aus dem lokalen Cache aufbauen (kein Cloud-Zugriff)
        $cache = json_decode($this->ReadAttributeString('DeviceCache'), true);
        if (is_array($cache) && !empty($cache['devices'])) {
            $injectKey = $this->ReadPropertyString('Key');
            if ($injectKey === '') {
                $injectKey = $cache['key'] ?? '';
            }

            $values = [];
            foreach ($cache['devices'] as $d) {
                $uuid = $d['uuid'] ?? '';
                $name = $d['name'] ?? '?';
                $raw  = strtolower($d['raw'] ?? '');
                $values[] = [
                    'name'       => $name,
                    'typ'        => $raw,
                    'uuid'       => $uuid,
                    'instanceID' => $this->FindInstanceByUuid($uuid),
                    'create'     => [
                        'moduleID'      => self::DEVICE_MODULE_GUID,
                        'configuration' => [
                            'DeviceType' => $this->MapType($raw),
                            'DeviceName' => $name,
                            'Uuid'       => $uuid,
                            'Key'        => $injectKey
                        ]
                    ]
                ];
            }

            if (!empty($cache['ts'])) {
                $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Zuletzt aus der Cloud geladen: ') . date('d.m.Y H:i', (int) $cache['ts'])];
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
        } else {
            $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Noch keine Geräte geladen. Oben auf „Geräte aus der Cloud laden" drücken.')];
        }

        return json_encode($form);
    }

    // EINZIGER Cloud-Zugriff: wird nur durch den Button ausgeloest.
    public function LoadDevices()
    {
        $email = $this->ReadPropertyString('Email');
        $pass  = $this->ReadPropertyString('Password');
        if ($email === '' || $pass === '') {
            echo $this->Translate('Bitte zuerst E-Mail und Passwort eintragen und „Übernehmen" drücken.');
            return;
        }

        $key = '';
        $err = '';
        $devices = $this->CloudLoginAndList($email, $pass, $this->ReadPropertyString('MFA'), $this->ReadPropertyString('ApiBase'), $key, $err);
        if ($devices === null) {
            echo '⚠ ' . $err;
            return;
        }

        $slim = [];
        foreach ($devices as $d) {
            $slim[] = [
                'name' => $d['devName'] ?? '?',
                'raw'  => strtolower($d['deviceType'] ?? ''),
                'uuid' => $d['uuid'] ?? ''
            ];
        }
        $this->WriteAttributeString('DeviceCache', json_encode(['key' => $key, 'devices' => $slim, 'ts' => time()]));
        echo $this->Translate('Geräte geladen: ') . count($slim);
        $this->ReloadForm();
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
