<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Rollladen (MRS100) - lokale Steuerung ohne Cloud/MQTT.
//
//  Fahren (am Geraet verifiziert):
//   * Appliance.RollerShutter.Position [SET] mit OBJEKT-Payload
//     {"position":{"position":N,"channel":0}}  (N=0..100, 100=auf, 0=zu)
//   * State [SET] faehrt/stoppt auf diesem Geraet NICHT.
//
//  Darstellung:
//   * MOVE  : Aufzaehlung, LAYOUT=1 (Reihe) -> Buttons NEBENEINANDER
//             Werte: 0=Auf, 1=Stop, 2=Zu
//   * LEVEL : Positions-Schieberegler 0..100 %
//
//  Mitlauf + Stop (zeitbasiert):
//   * Beim Fahren werden Start (MoveStart), Start/Zielposition und
//     Fahrdauer (MoveDurMs) gemerkt. Die Anzeige laeuft daraus glatt mit.
//   * Stop: die exakte Ist-Position wird aus der verstrichenen Zeit
//     berechnet (kein 1-s-Raster mehr) und mit kleinem Vorhalt in
//     Fahrtrichtung angefahren -> haelt praktisch sofort, kein Ruecklauf.
//   * Waehrend der Fahrt (FollowUntil) ueberschreibt das Polling die
//     Anzeige nicht.
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
            $c     = $cfg['payload']['config'][0] ?? [];
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
                    $this->RollerStartMove(100, true);
                }
            } elseif ($v === 2) {      // Zu   -> Position 0
                $resp = $this->RollerSetPosition(0);
                $this->RollerLog('Zu (Position=0)', 0, $resp);
                if ($resp !== null) {
                    $this->SetValue('MOVE', 2);
                    $this->RollerStartMove(0, true);
                }
            } else {                   // Stop -> exakte Ist-Position anfahren
                $this->RollerStop();
            }
            return;
        }

        if ($Ident === 'LEVEL') {
            $pos  = max(0, min(100, (int) $Value));
            $resp = $this->RollerSetPosition($pos);
            $this->RollerLog('Position', $pos, $resp);
            if ($resp !== null) {
                // Zielposition des Sliders sofort uebernehmen (kein Ruecksprung),
                // Tracking fuer einen evtl. spaeteren Stop mitfuehren, aber NICHT animieren.
                $this->RollerStartMove($pos, false);
                $this->SetValue('LEVEL', $pos);
            }
            return;
        }
    }

    // Merkt Start/Ziel/Dauer fuer Mitlauf + Stop. $animate=true startet den
    // gleichmaessigen Anzeige-Mitlauf (Buttons). Bei false (Slider) zeigt die
    // Anzeige direkt das Ziel, das Tracking dient nur dem praezisen Stop.
    private function RollerStartMove(int $target, bool $animate)
    {
        $target = max(0, min(100, $target));
        $from   = (int) $this->GetValue('LEVEL');
        $secs   = max(2, (int) $this->ReadAttributeInteger('FollowSeconds'));
        $durMs  = (int) round($secs * 1000 * abs($target - $from) / 100);

        $this->WriteAttributeInteger('MoveFrom', $from);
        $this->WriteAttributeInteger('MoveTo', $target);
        $this->WriteAttributeInteger('MoveDurMs', $durMs);
        $this->WriteAttributeString('MoveStart', (string) microtime(true));

        // Polling soll die Anzeige bis Fahrtende + Puffer nicht ueberschreiben
        $this->WriteAttributeInteger('FollowUntil', time() + (int) ceil($durMs / 1000) + 3);

        if ($animate && $durMs > 0 && $from !== $target) {
            $this->SetTimerInterval('MERO_RollerFollow', 300);
        } else {
            $this->SetTimerInterval('MERO_RollerFollow', 0);
        }
    }

    // Praeziser Stop: Ist-Position aus verstrichener Zeit berechnen und mit
    // kleinem Vorhalt in Fahrtrichtung anfahren (kein Ruecklauf).
    private function RollerStop()
    {
        $from = (int) $this->ReadAttributeInteger('MoveFrom');
        $to   = (int) $this->ReadAttributeInteger('MoveTo');
        $dir  = ($to > $from) ? 1 : (($to < $from) ? -1 : 0);

        $est  = $this->RollerEstimatePosition();
        $stop = max(0, min(100, $est + (2 * $dir))); // ~2% Vorhalt gleicht die Befehlslaufzeit aus

        $resp = $this->RollerSetPosition($stop);
        $this->RollerLog("Stop (Position=$stop, Ist~$est)", $stop, $resp);
        if ($resp !== null) {
            $this->SetValue('MOVE', 1);
        }

        // Mitlauf beenden, Anzeige auf den berechneten Ist-Wert setzen
        $this->SetTimerInterval('MERO_RollerFollow', 0);
        $this->WriteAttributeInteger('MoveDurMs', 0);
        $this->SetValue('LEVEL', $est);
        // kurz nicht ueberschreiben, dann echte Position bestaetigen
        $this->WriteAttributeInteger('FollowUntil', time() + 3);
    }

    // Zeitbasierte Schaetzung der aktuellen Position waehrend einer Fahrt
    private function RollerEstimatePosition(): int
    {
        $durMs = (int) $this->ReadAttributeInteger('MoveDurMs');
        $from  = (int) $this->ReadAttributeInteger('MoveFrom');
        $to    = (int) $this->ReadAttributeInteger('MoveTo');
        $start = (float) $this->ReadAttributeString('MoveStart');
        if ($durMs <= 0 || $start <= 0.0) {
            return (int) $this->GetValue('LEVEL');
        }
        $elapsedMs = (microtime(true) - $start) * 1000.0;
        if ($elapsedMs < 0) {
            $elapsedMs = 0;
        }
        $frac = $elapsedMs / $durMs;
        if ($frac > 1) {
            $frac = 1;
        }
        return (int) round($from + ($to - $from) * $frac);
    }

    // Timer-Tick: Anzeige gleichmaessig zur Zielposition fuehren
    private function RollerFollow()
    {
        $durMs = (int) $this->ReadAttributeInteger('MoveDurMs');
        if ($durMs <= 0) {
            $this->SetTimerInterval('MERO_RollerFollow', 0);
            return;
        }
        $start = (float) $this->ReadAttributeString('MoveStart');
        $elapsedMs = (microtime(true) - $start) * 1000.0;
        $done = ($elapsedMs >= $durMs);

        $cur = $this->RollerEstimatePosition();
        $this->SetValue('LEVEL', max(0, min(100, $cur)));

        if ($done) {
            $this->SetTimerInterval('MERO_RollerFollow', 0);
            $this->WriteAttributeInteger('MoveDurMs', 0);
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
