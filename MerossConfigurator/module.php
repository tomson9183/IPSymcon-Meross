<?php

declare(strict_types=1);

// =====================================================================
//  Meross Geräte aus Cloud hinzufügen  -  Konfigurator
//
//  - Cloud-Login NUR per Knopfdruck ("Geräte aus der Cloud laden").
//    Holt Konto-Key + Geräteliste, speichert lokal im Attribut.
//  - "Lokale IPs suchen": durchsucht das angegebene Subnetz und ordnet
//    jedem Gerät anhand seiner UUID die lokale IP zu (kein Cloud-Zugriff).
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
        $this->RegisterPropertyString('Subnet', '');

        // Lokaler Zwischenspeicher: Key, Geraeteliste, IP-Zuordnung (JSON)
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
        $cache = json_decode($this->ReadAttributeString('DeviceCache'), true);
        $ipmap = (is_array($cache) && isset($cache['ipmap']) && is_array($cache['ipmap'])) ? $cache['ipmap'] : [];

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
                ['type' => 'PasswordTextBox', 'name' => 'Key', 'caption' => 'Konto-Key (wird beim Laden automatisch geholt; manuell nur falls gewünscht)'],
                ['type' => 'ValidationTextBox', 'name' => 'Subnet', 'caption' => 'Subnetz für IP-Suche, z. B. 192.168.178 (leer = automatisch)']
            ],
            'actions' => [],
            'status'  => []
        ];

        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('1. Zugangsdaten eintragen und „Übernehmen" drücken.  2. „Geräte aus der Cloud laden" (nur dann erfolgt eine Anmeldung bei Meross).  3. Optional „Lokale IPs suchen".')];
        $form['actions'][] = [
            'type'    => 'Button',
            'caption' => $this->Translate('Geräte aus der Cloud laden'),
            'onClick' => 'MEROC_LoadDevices($id);'
        ];
        $form['actions'][] = [
            'type'    => 'Button',
            'caption' => $this->Translate('Lokale IPs suchen (im Netzwerk, ohne Cloud)'),
            'onClick' => 'MEROC_ScanLocalIPs($id);'
        ];
        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Hinweis: Möglichst selten in der Cloud anmelden. Bei zu häufigen Anmeldungen sperrt Meross das Konto vorübergehend (ca. 5 Stunden). Die IP-Suche läuft rein lokal.')];

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
                $ip   = $ipmap[$uuid] ?? '';

                $configuration = [
                    'DeviceType' => $this->MapType($raw),
                    'DeviceName' => $name,
                    'Uuid'       => $uuid,
                    'Key'        => $injectKey
                ];
                if ($ip !== '') {
                    $configuration['Host'] = $ip;
                }

                $values[] = [
                    'name'       => $name,
                    'typ'        => $raw,
                    'ip'         => $ip,
                    'uuid'       => $uuid,
                    'instanceID' => $this->FindInstanceByUuid($uuid),
                    'create'     => [
                        'moduleID'      => self::DEVICE_MODULE_GUID,
                        'configuration' => $configuration
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
                    ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '220px'],
                    ['caption' => 'Typ', 'name' => 'typ', 'width' => '110px'],
                    ['caption' => $this->Translate('Lokale IP'), 'name' => 'ip', 'width' => '130px'],
                    ['caption' => 'UUID', 'name' => 'uuid', 'width' => 'auto']
                ],
                'values'   => $values
            ];
            $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Wird keine IP gefunden: feste IP in der Fritzbox vergeben und im Gerät eintragen.')];
        } else {
            $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Noch keine Geräte geladen. Oben auf „Geräte aus der Cloud laden" drücken.')];
        }

        return json_encode($form);
    }

    // EINZIGER Cloud-Zugriff: nur durch den Button ausgeloest.
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

        // bestehende IP-Zuordnung beibehalten
        $old   = json_decode($this->ReadAttributeString('DeviceCache'), true);
        $ipmap = (is_array($old) && isset($old['ipmap'])) ? $old['ipmap'] : [];

        $this->WriteAttributeString('DeviceCache', json_encode(['key' => $key, 'devices' => $slim, 'ipmap' => $ipmap, 'ts' => time()]));

        // Key sichtbar machen, falls nicht manuell gesetzt
        if ($this->ReadPropertyString('Key') === '' && $key !== '') {
            $this->UpdateFormField('Key', 'value', $key);
        }

        echo $this->Translate('Geräte geladen: ') . count($slim);
        $this->ReloadForm();
    }

    // Lokale IP-Suche per UUID-Abgleich (rein lokal, kein Cloud-Zugriff).
    public function ScanLocalIPs()
    {
        $cache = json_decode($this->ReadAttributeString('DeviceCache'), true);
        if (!is_array($cache) || empty($cache['devices'])) {
            echo $this->Translate('Bitte zuerst „Geräte aus der Cloud laden".');
            return;
        }
        $key = $this->ReadPropertyString('Key');
        if ($key === '') {
            $key = $cache['key'] ?? '';
        }
        if ($key === '') {
            echo $this->Translate('Kein Konto-Key vorhanden. Erst Geräte aus der Cloud laden.');
            return;
        }

        $base = trim($this->ReadPropertyString('Subnet'));
        if ($base === '') {
            $base = $this->GuessSubnet();
        }
        $base = rtrim($base, '.');
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $base)) {
            echo $this->Translate('Subnetz ungültig. Beispiel: 192.168.178');
            return;
        }

        $mh = curl_multi_init();
        $handles = [];
        for ($i = 1; $i <= 254; $i++) {
            $ip   = "$base.$i";
            $ts   = time();
            $mid  = md5(uniqid('', true));
            $sign = md5($mid . $key . $ts);
            $body = json_encode([
                'header'  => [
                    'messageId'      => $mid,
                    'namespace'      => 'Appliance.System.All',
                    'method'         => 'GET',
                    'payloadVersion' => 1,
                    'from'           => "http://$ip/config",
                    'timestamp'      => $ts,
                    'timestampMs'    => 0,
                    'sign'           => $sign
                ],
                'payload' => new stdClass()
            ]);
            $ch = curl_init("http://$ip/config");
            curl_setopt_array($ch, [
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $body,
                CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS        => 1500
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$ip] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.2);
            }
        } while ($active && $status == CURLM_OK);

        $ipmap = [];
        foreach ($handles as $ip => $ch) {
            $res = curl_multi_getcontent($ch);
            if (is_string($res) && $res !== '') {
                $d = json_decode($res, true);
                if (is_array($d)) {
                    $uuid = $d['header']['uuid'] ?? ($d['payload']['all']['system']['hardware']['uuid'] ?? '');
                    if ($uuid !== '') {
                        $ipmap[$uuid] = $ip;
                    }
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $cache['ipmap'] = $ipmap;
        $this->WriteAttributeString('DeviceCache', json_encode($cache));
        echo $this->Translate('Im Netz gefundene Meross-Geräte: ') . count($ipmap);
        $this->ReloadForm();
    }

    private function GuessSubnet(): string
    {
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID) as $iid) {
            $h = @IPS_GetProperty($iid, 'Host');
            if (is_string($h) && preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/', $h, $m)) {
                return $m[1];
            }
        }
        return '192.168.178';
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
