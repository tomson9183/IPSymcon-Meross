<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Rollladen (MRS100): nativer IP-Symcon-Rollladen-Look.
//  MOVE  : Integer mit Standardprofil ~ShutterMoveStop
//          0 = Öffnen (Auf), 2 = Stop, 4 = Schließen (Zu)
//  LEVEL : Integer 0..100 % (Position)
//
//  WICHTIG (per meross_iot-Referenz bestaetigt): der Fahr-Befehl ist
//  Appliance.RollerShutter.State mit method=SET und der Payload als
//  OBJEKT  {"state":{"state":N,"channel":0}}  -- NICHT als Array.
//    state 1 = Auf/Oeffnen, 2 = Zu/Schliessen, 0 = Stop
//  Ebenso Position als Objekt  {"position":{"position":N,"channel":0}}.
//  Die frueher gesehenen Array-PUSH-Meldungen waren nur Status-Meldungen
//  des Geraets (kein Steuerbefehl) -- daher quittierte/abgelehnte SETs.
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
            if ($v === 0) {            // Öffnen (Auf)  -> State 1
                $resp = $this->RollerSetState(1);
                $this->RollerLog('Auf (State=1)', 1, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 0);
                }
            } elseif ($v === 4) {      // Schließen (Zu) -> State 2
                $resp = $this->RollerSetState(2);
                $this->RollerLog('Zu (State=2)', 2, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 4);
                }
            } else {                   // Stop -> State 0
                $resp = $this->RollerSetState(0);
                $this->RollerLog('Stop (State=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 2);
                }
            }
            $this->RollerUpdate();
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

    // Fahrbefehl: Objekt-Form (NICHT Array). state 1=Auf, 2=Zu, 0=Stop
    private function RollerSetState(int $state)
    {
        return $this->LocalRequest('Appliance.RollerShutter.State', 'SET', ['state' => ['state' => $state, 'channel' => 0]]);
    }

    // Position anfahren: ebenfalls Objekt-Form (NICHT Array).
    private function RollerSetPosition(int $pos)
    {
        return $this->LocalRequest('Appliance.RollerShutter.Position', 'SET', ['position' => ['position' => $pos, 'channel' => 0]]);
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
