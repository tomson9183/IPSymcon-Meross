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
                $this->RollerReflect($pos);
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
        $this->RollerReflect($est);
        $this->RollerPushVisu();
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
        $this->RollerPushVisu();

        if ($done) {
            $this->SetTimerInterval('MERO_RollerFollow', 0);
            $this->WriteAttributeInteger('MoveDurMs', 0);
            $this->WriteAttributeInteger('FollowUntil', time() + 2);
            $this->RollerReflect((int) $this->GetValue('LEVEL'));
        }
    }

    // Fahrbefehl: Objekt-Form (NICHT Array). Dieser bewegt den Motor.
    private function RollerSetPosition(int $pos)
    {
        return $this->LocalRequest('Appliance.RollerShutter.Position', 'SET', ['position' => ['position' => $pos, 'channel' => 0]]);
    }

    // Spiegelt die aktuelle Position in die Buttons (MOVE):
    //   offen (>=99) -> 0 = Auf,  geschlossen (<=1) -> 2 = Zu,  sonst 1 = Stop.
    // Reine Anzeige-Aktualisierung (loest keine Aktion aus).
    private function RollerReflect(int $pos)
    {
        if (@$this->GetIDForIdent('MOVE') === false) {
            return;
        }
        $m = ($pos >= 99) ? 0 : (($pos <= 1) ? 2 : 1);
        if ((int) $this->GetValue('MOVE') !== $m) {
            $this->SetValue('MOVE', $m);
        }
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
            $this->RollerReflect((int) $pos);
            $this->RollerPushVisu();
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

    // ---- HTML-SDK: interaktive Kachel (Fenster + Rollladen-Animation) -----

    private function RollerVisuPayload(): string
    {
        $lvl = (@$this->GetIDForIdent('LEVEL') !== false) ? (int) $this->GetValue('LEVEL') : 0;
        return json_encode(['level' => $lvl]);
    }

    // An die geoeffnete Kachel senden (No-Op, wenn keine Visu offen ist)
    private function RollerPushVisu()
    {
        $this->UpdateVisualizationValue($this->RollerVisuPayload());
    }

    // HTML + JS: Fenster mit herabfahrendem Rollladen; Steuerung per requestAction()
    private function RollerVisualizationTile(): string
    {
        $html = <<<'HTML'
<style>
  html,body{margin:0;padding:0;overflow:hidden;}
  :root{ --num:#ffffff; --txt:#c7ccd6; --sub:#8a93a0; --chip:#2b2f3a; --chiptx:#e8ebf0; }
  @media (prefers-color-scheme: light){
    :root{ --num:#13202b; --txt:#3a4753; --sub:#6b7782; --chip:#e6eaf0; --chiptx:#3a4753; }
  }
  #rsBox{position:relative;width:100%;overflow:hidden;}
  .rs-card{position:absolute;left:50%;top:50%;transform-origin:center center;
    font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--txt);text-align:center;
    width:220px;box-sizing:border-box;padding:6px;background:transparent;}
  .rs-win{width:100%;margin:0 auto;}
  .rs-win svg{width:100%;height:auto;display:block;}
  .rs-state{margin-top:6px;font-size:13px;color:var(--txt);}
  .rs-state b{color:var(--num);}
  .rs-btns{margin-top:8px;display:flex;gap:6px;justify-content:center;}
  .rs-btns button{padding:6px 12px;border-radius:9px;border:none;cursor:pointer;
    font-size:12px;font-weight:700;background:var(--chip);color:var(--chiptx);}
  .rs-btns button:active{transform:scale(.94);}
  .rs-up{color:#2e9e4f;} .rs-stop{color:#d99100;} .rs-dn{color:#2f7fd0;}
  .rs-slider{margin-top:10px;display:flex;align-items:center;gap:8px;justify-content:center;
    font-size:10px;color:var(--sub);}
  .rs-slider input{width:108px;}
</style>
<div id="rsBox"><div class="rs-card" id="rsCard">
  <div class="rs-win">
    <svg viewBox="0 0 200 184" preserveAspectRatio="xMidYMid meet">
      <defs>
        <linearGradient id="rsGlass" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#bfe3ff"/><stop offset="100%" stop-color="#eaf6ff"/>
        </linearGradient>
        <linearGradient id="rsSlat" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#eceff3"/><stop offset="100%" stop-color="#c2c7cf"/>
        </linearGradient>
        <pattern id="rsSlats" width="92" height="9" patternUnits="userSpaceOnUse" x="54" y="22">
          <rect width="92" height="9" fill="url(#rsSlat)"/>
          <rect width="92" height="1.3" y="7.7" fill="#a7adb7"/>
        </pattern>
        <clipPath id="rsClip"><rect x="54" y="22" width="92" height="112" rx="3"/></clipPath>
      </defs>
      <rect x="40" y="150" width="120" height="11" rx="3" fill="#8a8f98"/>
      <rect x="46" y="14" width="108" height="128" rx="6" fill="#727884"/>
      <rect x="54" y="22" width="92" height="112" rx="3" fill="url(#rsGlass)"/>
      <circle cx="126" cy="48" r="11" fill="#ffd76b" clip-path="url(#rsClip)"/>
      <g clip-path="url(#rsClip)">
        <g id="rsShutter" style="transition:transform .45s ease">
          <rect x="54" y="22" width="92" height="112" fill="url(#rsSlats)"/>
          <rect x="54" y="128" width="92" height="6" rx="1" fill="#9aa0aa"/>
        </g>
      </g>
      <rect x="54" y="22" width="92" height="112" rx="3" fill="none" stroke="#5c616b" stroke-width="1.2"/>
    </svg>
  </div>
  <div class="rs-state" id="rsState">–</div>
  <div class="rs-btns">
    <button class="rs-up"   onclick="requestAction('MOVE',0)">&#9650; Auf</button>
    <button class="rs-stop" onclick="requestAction('MOVE',1)">&#9632; Stop</button>
    <button class="rs-dn"   onclick="requestAction('MOVE',2)">&#9660; Zu</button>
  </div>
  <div class="rs-slider">
    <span>Zu</span>
    <input id="rsSlider" type="range" min="0" max="100" step="1"
      oninput="rsLbl(this.value)" onchange="requestAction('LEVEL', parseInt(this.value,10))">
    <span>Auf</span>
  </div>
</div></div>
<script>
  window.rsLevel = 0;
  function rsLbl(v){ document.getElementById('rsState').innerHTML = rsTxt(parseInt(v,10)); }
  function rsTxt(l){ return l>=99 ? '<b>Offen</b>' : (l<=1 ? '<b>Geschlossen</b>' : 'Position <b>'+l+' %</b>'); }
  function handleMessage(message){
    var d=(typeof message==='string')?JSON.parse(message):message;
    var l=Math.max(0,Math.min(100,Math.round(d.level)));
    window.rsLevel=l;
    var H=112;
    document.getElementById('rsShutter').style.transform='translateY('+(-(l/100)*H).toFixed(1)+'px)';
    document.getElementById('rsState').innerHTML=rsTxt(l);
    var sl=document.getElementById('rsSlider');
    if(document.activeElement!==sl){ sl.value=l; }
    rsFit();
  }
  // Inhalt passgenau auf die Kachel (iframe) skalieren (waechst/schrumpft mit, kein Scrollen)
  function rsFit(){
    var box=document.getElementById('rsBox'), c=document.getElementById('rsCard');
    if(!box||!c) return;
    var w=window.innerWidth||1, h=window.innerHeight||1;
    box.style.height=h+'px';
    c.style.transform='translate(-50%,-50%)';
    var s=Math.min(w/(c.offsetWidth||1), h/(c.offsetHeight||1));
    if(!isFinite(s)||s<=0) s=1;
    c.style.transform='translate(-50%,-50%) scale('+s+')';
  }
  window.addEventListener('resize', rsFit);
  window.addEventListener('load', function(){ rsFit(); setTimeout(rsFit,80); setTimeout(rsFit,300); });
</script>
HTML;
        return $html . '<script>try{handleMessage(' . json_encode($this->RollerVisuPayload()) . ');}catch(e){}</script>';
    }
}
