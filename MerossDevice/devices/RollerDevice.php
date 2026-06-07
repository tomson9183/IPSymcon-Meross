<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Rollladen (MRS100) - lokale Steuerung ohne Cloud/MQTT.
//
//  WICHTIG (am Geraet verifiziert):
//   * Der Motor faehrt ueber  Appliance.RollerShutter.Position [SET]
//     mit OBJEKT-Payload  {"position":{"position":N,"channel":0}}.
//     (N = 0..100, 100 = ganz auf, 0 = ganz zu)
//   * Appliance.RollerShutter.State [SET] wird auf diesem Geraet NICHT
//     ausgefuehrt -> daher Auf/Zu ebenfalls ueber Position (100 / 0).
//
//  Darstellung der Bedien-Variable MOVE:
//   * VARIABLE_PRESENTATION_ENUMERATION (Aufzaehlung)
//   * LAYOUT = 1 (Reihe) -> Buttons NEBENEINANDER statt Dropdown
//   * DISPLAY = 0 (Beschriftung)
//     MOVE-Werte:  0 = Auf, 1 = Stop, 2 = Zu
//   * LEVEL bleibt der Positions-Schieberegler (0..100 %).
//
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait RollerDevice
{
    private function RollerApplyChanges()
    {
        // Buttons nebeneinander (Aufzaehlung, Reihe)
        $options = json_encode([
            ['Value' => 0, 'Caption' => 'Auf',  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 1, 'Caption' => 'Stop', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 2, 'Caption' => 'Zu',   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ]);
        $this->RegisterVariableInteger('MOVE', 'Rollladen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $options,
            'LAYOUT'       => 1, // 0=Spalte, 1=Reihe (nebeneinander), 2=Gitter
            'DISPLAY'      => 0, // 0=Beschriftung, 1=Icon, 2=beides
        ], 10);
        $this->EnableAction('MOVE');

        $this->RegisterVariableInteger('LEVEL', 'Position', 'MERO.Pos', 20);
        $this->EnableAction('LEVEL');
    }

    private function RollerRequestAction($Ident, $Value)
    {
        if ($Ident === 'MOVE') {
            $v = (int) $Value;
            if ($v === 0) {            // Auf  -> Position 100
                $resp = $this->RollerSetPosition(100);
                $this->RollerLog('Auf (Position=100)', 100, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 0);
                }
            } elseif ($v === 2) {      // Zu   -> Position 0
                $resp = $this->RollerSetPosition(0);
                $this->RollerLog('Zu (Position=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 2);
                }
            } else {                   // Stop -> State 0 (Best effort)
                $resp = $this->RollerSetState(0);
                $this->RollerLog('Stop (State=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 1);
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

    // Fahrbefehl: Objekt-Form (NICHT Array). Dieser bewegt den Motor.
    private function RollerSetPosition(int $pos)
    {
        return $this->LocalRequest('Appliance.RollerShutter.Position', 'SET', ['position' => ['position' => $pos, 'channel' => 0]]);
    }

    // Stop (Best effort). state 0 = Stop. Objekt-Form.
    private function RollerSetState(int $state)
    {
        return $this->LocalRequest('Appliance.RollerShutter.State', 'SET', ['state' => ['state' => $state, 'channel' => 0]]);
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
