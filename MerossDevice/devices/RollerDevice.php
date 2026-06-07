<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Rollladen (MRS100): nativer IP-Symcon-Rollladen-Look.
//  MOVE  : Integer mit Standardprofil ~ShutterMoveStop
//          0 = Öffnen (Auf), 2 = Stop, 4 = Schließen (Zu)
//  LEVEL : Integer 0..100 % (Position)
//
//  Wichtig (per Geraete-Log bestaetigt): der lokale Endpunkt akzeptiert
//  Appliance.RollerShutter.Position mit SET (SETACK), waehrend
//  Appliance.RollerShutter.State mit SET die Verbindung abbricht
//  ("Empty reply"). Daher: Auf/Zu ueber Position (100/0), Stop ueber State-PUSH.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait RollerDevice
{
    private function RollerApplyChanges()
    {
        $this->RegisterVariableInteger('MOVE', 'Rollladen', '~ShutterMoveStop', 10);
        $this->EnableAction('MOVE');
        $this->RegisterVariableInteger('LEVEL', 'Position', 'MERO.Pos', 20);
        $this->EnableAction('LEVEL');
    }

    private function RollerRequestAction($Ident, $Value)
    {
        if ($Ident === 'MOVE') {
            $v = (int) $Value;
            if ($v === 0) {            // Öffnen -> Position 100
                $resp = $this->RollerSetPosition(100);
                $this->RollerLog('Auf (Position=100)', 100, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 0);
                    $this->SetValue('LEVEL', 100);
                }
            } elseif ($v === 4) {      // Schließen -> Position 0
                $resp = $this->RollerSetPosition(0);
                $this->RollerLog('Zu (Position=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 4);
                    $this->SetValue('LEVEL', 0);
                }
            } else {                   // Stop -> State 0 (Best effort)
                $resp = $this->LocalRequest('Appliance.RollerShutter.State', 'PUSH', ['state' => [['state' => 0, 'channel' => 0]]]);
                $this->RollerLog('Stop', 0, $resp);
                $this->SetValue('MOVE', 2);
                $this->RollerUpdate();
            }
            return;
        }

        if ($Ident === 'LEVEL') {
            $pos  = max(0, min(100, (int) $Value));
            $resp = $this->RollerSetPosition($pos);
            $this->RollerLog('Position', $pos, $resp);
            if ($resp !== null) {
                $this->SetValue('LEVEL', $pos);
            }
            return;
        }
    }

    private function RollerSetPosition(int $pos)
    {
        return $this->LocalRequest('Appliance.RollerShutter.Position', 'SET', ['position' => [['position' => $pos, 'channel' => 0]]]);
    }

    private function RollerUpdate()
    {
        $resp = $this->LocalRequest('Appliance.RollerShutter.Position', 'GET', []);
        if ($resp === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $pos = $resp['payload']['position'][0]['position'] ?? null;
        if ($pos !== null && @$this->GetIDForIdent('LEVEL') !== false) {
            $this->SetValue('LEVEL', (int) $pos);
        }
    }

    // Diagnose: schreibt die Geraeteantwort in die Debug-Konsole der Instanz
    private function RollerLog($what, $value, $resp)
    {
        if ($resp === null) {
            $this->SendDebug('Rollladen', "$what: keine Antwort / abgelehnt", 0);
        } else {
            $this->SendDebug('Rollladen', "$what gesendet, Antwort: " . json_encode($resp), 0);
        }
    }
}
