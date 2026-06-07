<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Rollladen (MRS100) - lokale Steuerung ohne Cloud/MQTT.
//
//  Fahren (am Geraet verifiziert):
//   * Appliance.RollerShutter.Position [SET] mit OBJEKT-Payload
//     {"position":{"position":N,"channel":0}}  (N=0..100, 100=auf, 0=zu)
//   * State [SET] faehrt auf diesem Geraet NICHT -> Auf/Zu via Position.
//
//  Darstellung:
//   * MOVE  : Aufzaehlung, LAYOUT=1 (Reihe) -> Buttons NEBENEINANDER
//             Werte: 0=Auf, 1=Stop, 2=Zu
//   * LEVEL : Positions-Schieberegler 0..100 %
//
//  Sanfter Mitlauf:
//   * Auf/Zu lassen LEVEL ueber die bekannte Fahrzeit hochlaufen.
//   * Waehrend der Fahrt (FollowUntil) ueberschreibt das Polling die
//     Anzeige NICHT (verhindert das Zurueckspringen).
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

        // Fahrzeit aus der Geraete-Konfiguration uebernehmen (lokal, keine Cloud)
        $cfg = $this->LocalRequest('Appliance.RollerShutter.Config', 'GET', []);
        if ($cfg !== null) {
            $c = $cfg['payload']['config'][0] ?? [];
            $open  = (int) ($c['signalOpen'] ?? 0);
            $close = (int) ($c['signalClose'] ?? 0);
            $ms    = max($open, $close);
            if ($ms > 0) {
                $this->WriteAttributeInteger('FollowSeconds', max(2, (int) round($ms / 1000)));
            }
        }
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
                    $this->RollerStartFollow(100);
                }
            } elseif ($v === 2) {      // Zu   -> Position 0
                $resp = $this->RollerSetPosition(0);
                $this->RollerLog('Zu (Position=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 2);
                    $this->RollerStartFollow(0);
                }
            } else {                   // Stop -> aktuelle Position anfahren = anhalten
                $this->RollerStopFollow();
                $cur  = (int) $this->GetValue('LEVEL');
                $resp = $this->RollerSetPosition($cur);
                $this->RollerLog("Stop (Position=$cur)", $cur, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 1);
                }
                $this->SetValue('LEVEL', $cur);
                // kurz nicht ueberschreiben, dann echte Position bestaetigen
                $this->WriteAttributeInteger('FollowUntil', time() + 3);
            }
            return;
        }

        if ($Ident === 'LEVEL') {
            $pos  = max(0, min(100, (int) $Value));
            $resp = $this->RollerSetPosition($pos);
            $this->RollerLog('Position', $pos, $resp);
            if ($resp !== null) {
                // Vom Nutzer gesetzte Zielposition direkt uebernehmen (kein Ruecksprung).
                $this->SetValue('LEVEL', $pos);
                // Waehrend der Fahrt das Polling die Anzeige nicht ueberschreiben lassen.
                $this->WriteAttributeInteger('FollowStep', 0);
                $this->SetTimerInterval('MERO_RollerFollow', 0);
                $secs = max(2, (int) $this->ReadAttributeInteger('FollowSeconds'));
                $this->WriteAttributeInteger('FollowUntil', time() + $secs + 3);
            }
            return;
        }
    }

    // Startet den sanften Mitlauf der LEVEL-Anzeige von der aktuellen
    // Position zur Zielposition ueber die bekannte Fahrzeit.
    private function RollerStartFollow(int $target)
    {
        $target = max(0, min(100, $target));
        $cur    = (int) $this->GetValue('LEVEL');
        $secs   = max(2, (int) $this->ReadAttributeInteger('FollowSeconds'));
        // Anzeige soll waehrend der gesamten Fahrt nicht vom Polling ueberschrieben werden
        $this->WriteAttributeInteger('FollowUntil', time() + $secs + 3);

        if ($cur === $target) {
            $this->WriteAttributeInteger('FollowStep', 0);
            $this->SetTimerInterval('MERO_RollerFollow', 0);
            return;
        }
        $step = (int) max(1, (int) round(100 / $secs)); // % pro Sekunde
        if ($target < $cur) {
            $step = -$step;
        }
        $this->WriteAttributeInteger('FollowTo', $target);
        $this->WriteAttributeInteger('FollowStep', $step);
        $this->SetTimerInterval('MERO_RollerFollow', 1000); // 1-Sekunden-Schritte
    }

    private function RollerStopFollow()
    {
        $this->WriteAttributeInteger('FollowStep', 0);
        $this->WriteAttributeInteger('FollowUntil', 0);
        $this->SetTimerInterval('MERO_RollerFollow', 0);
    }

    // Timer-Tick: schiebt LEVEL einen Schritt Richtung Ziel
    private function RollerFollow()
    {
        $step = (int) $this->ReadAttributeInteger('FollowStep');
        if ($step === 0) {
            $this->SetTimerInterval('MERO_RollerFollow', 0);
            return;
        }
        $to  = (int) $this->ReadAttributeInteger('FollowTo');
        $cur = (int) $this->GetValue('LEVEL') + $step;

        $done = ($step > 0 && $cur >= $to) || ($step < 0 && $cur <= $to);
        if ($done) {
            $cur = $to;
            $this->WriteAttributeInteger('FollowStep', 0);
            $this->SetTimerInterval('MERO_RollerFollow', 0);
        }
        $this->SetValue('LEVEL', max(0, min(100, $cur)));

        if ($done) {
            // Kurz nachlaufen lassen, dann echte Position bestaetigen
            $this->WriteAttributeInteger('FollowUntil', time() + 2);
        }
    }

    // Fahrbefehl: Objekt-Form (NICHT Array). Dieser bewegt den Motor.
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

        // Waehrend einer laufenden Fahrt die Anzeige nicht ueberschreiben
        if (time() < (int) $this->ReadAttributeInteger('FollowUntil')) {
            return;
        }

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
