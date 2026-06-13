<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Eigenständige WLAN-Thermostate (kein Hub):
//  MTS200 / MTS200B / MTS215B  -> Appliance.Control.Thermostat.Mode
//  MTS960                       -> Appliance.Control.Thermostat.ModeB
//
//  Gelesen wird der Zustand aus Appliance.System.All -> digest.thermostat
//  (die dedizierten Thermostat-GETs antworten auf vielen Modellen NICHT).
//
//  Das Format unterscheidet sich:
//   * Mode  (MTS200/215B): Temperaturen in Zehntelgrad (249 = 24,9 °C)
//       Felder (am MTS200 verifiziert): currentTemp(=Ist), targetTemp(=Soll),
//       state(=heizt gerade), onoff, mode, heatTemp/coolTemp/ecoTemp/manualTemp,
//       warning, min/max. onoff: 1 = ein, 0 = aus.
//       SET der Soll-Temperatur ueber manualTemp (Gerät wechselt in manuell).
//   * ModeB (MTS960): Temperaturen in Hundertstel (2864 = 28,64 °C)
//       Felder u.a.: currentTemp, targetTemp, working(=heizt), onoff, mode.
//       onoff: 1 = ein, 2 = aus. SET ueber targetTemp.
//
//  mode-Werte (meross_iot MTS200, am Gerät bestätigt mode:4 = Manuell):
//   0 = Heizen, 1 = Kühlen, 2 = Eco, 3 = Auto, 4 = Manuell
//
//  Die Variante wird beim Polling automatisch erkannt und für das
//  Senden gemerkt (Attribut ThermoVariant / ThermoScale).
//
//  HINWEIS: MTS200 am Gerät verifiziert (Lesen). SET (Soll/Modus/Ein-Aus)
//  und die MTS960-Variante noch praktisch zu bestätigen.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait ThermostatDevice
{
    private function ThermoApplyChanges()
    {
        // Die schöne, interaktive Kachel kommt über das HTML-SDK
        // (SetVisualizationType/GetVisualizationTile in module.php).
        $this->RegisterVariableFloat('TEMP', $this->Translate('Temperatur'), '~Temperature', 10);
        $this->RegisterVariableFloat('SET', $this->Translate('Soll-Temperatur'), 'MERO.SetTemp', 20);
        $this->EnableAction('SET');
        $this->RegisterVariableBoolean('ONOFF', $this->Translate('Ein'), '~Switch', 30);
        $this->EnableAction('ONOFF');
        $this->RegisterVariableBoolean('HEAT', $this->Translate('Heizt gerade'), 'MERO.Heat', 40);
        $this->RegisterVariableInteger('MODE', $this->Translate('Modus'), $this->EnumPresentation([
            ['Value' => 0, 'Caption' => $this->Translate('Heizen'),  'IconActive' => false, 'IconValue' => '', 'Color' => 0xFF7043],
            ['Value' => 1, 'Caption' => $this->Translate('Kühlen'),  'IconActive' => false, 'IconValue' => '', 'Color' => 0x29B6F6],
            ['Value' => 2, 'Caption' => $this->Translate('Eco'),     'IconActive' => false, 'IconValue' => '', 'Color' => 0x66BB6A],
            ['Value' => 3, 'Caption' => $this->Translate('Auto'),    'IconActive' => false, 'IconValue' => '', 'Color' => 0x9E9E9E],
            ['Value' => 4, 'Caption' => $this->Translate('Manuell'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0xFFB300],
        ]), 50);
        $this->EnableAction('MODE');

        $this->ThermoUpdate();
    }

    private function ThermoRequestAction($Ident, $Value)
    {
        $variant = $this->ReadAttributeString('ThermoVariant');
        if ($variant === '') {
            $variant = 'mode';
        }
        $scale = (int) $this->ReadAttributeInteger('ThermoScale');
        if ($scale <= 0) {
            $scale = ($variant === 'modeB') ? 100 : 10;
        }
        $ns  = ($variant === 'modeB') ? 'Appliance.Control.Thermostat.ModeB' : 'Appliance.Control.Thermostat.Mode';
        $key = ($variant === 'modeB') ? 'modeB' : 'mode';

        switch ($Ident) {
            case 'SET':
                $val = (int) round(((float) $Value) * $scale);
                // MTS200 (Mode) nimmt die manuelle Soll-Temperatur in manualTemp
                // entgegen (Gerät wechselt dabei in den manuellen Betrieb);
                // MTS960 (ModeB) erwartet targetTemp.
                $tField = ($variant === 'modeB') ? 'targetTemp' : 'manualTemp';
                $resp   = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, $tField => $val]]]);
                if ($resp !== null) {
                    $this->SetValue('SET', round((float) $Value, 1));
                }
                break;

            case 'ONOFF':
                // Mode (MTS200): 1 = ein, 0 = aus   ·   ModeB (MTS960): 1 = ein, 2 = aus
                $onoff = $Value ? 1 : (($variant === 'modeB') ? 2 : 0);
                $resp  = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, 'onoff' => $onoff]]]);
                if ($resp !== null) {
                    $this->SetValue('ONOFF', (bool) $Value);
                }
                break;

            case 'MODE':
                $resp = $this->LocalRequest($ns, 'SET', [$key => [['channel' => 0, 'mode' => (int) $Value]]]);
                if ($resp !== null) {
                    $this->SetValue('MODE', (int) $Value);
                }
                break;
        }
    }

    private function ThermoUpdate()
    {
        // Die dedizierten Thermostat-GETs antworten auf vielen Modellen NICHT
        // (leerer Payload). Der komplette Zustand steht aber in System.All
        // unter digest.thermostat -> das ist der zuverlaessige Weg.
        $all = $this->LocalRequest('Appliance.System.All', 'GET', []);
        $th  = $all['payload']['all']['digest']['thermostat'] ?? null;
        if (is_array($th)) {
            if (isset($th['modeB'][0])) {                 // MTS960: Hundertstelgrad
                $this->ThermoApply($th['modeB'][0], 'modeB', 100);
                return;
            }
            if (isset($th['mode'][0])) {                  // MTS200/215B: Zehntelgrad
                $this->ThermoApply($th['mode'][0], 'mode', 10);
                return;
            }
        }

        // Fallback: Modelle, die den dedizierten GET doch beantworten
        $mb = $this->LocalRequest('Appliance.Control.Thermostat.ModeB', 'GET', []);
        if ($mb !== null && isset($mb['payload']['modeB'][0])) {
            $this->ThermoApply($mb['payload']['modeB'][0], 'modeB', 100);
            return;
        }
        $m = $this->LocalRequest('Appliance.Control.Thermostat.Mode', 'GET', []);
        if ($m !== null && isset($m['payload']['mode'][0])) {
            $this->ThermoApply($m['payload']['mode'][0], 'mode', 10);
            return;
        }

        $this->SetStatus(201);
    }

    private function ThermoApply(array $d, string $variant, int $scale)
    {
        $this->SetStatus(102);
        $this->WriteAttributeString('ThermoVariant', $variant);
        $this->WriteAttributeInteger('ThermoScale', $scale);

        $cur = $d['currentTemp'] ?? ($d['temperature'] ?? ($d['roomTemp'] ?? null));
        $curC = ($cur !== null) ? round(((int) $cur) / $scale, 1) : null;
        if ($curC !== null) {
            $this->SetValue('TEMP', $curC);
        }

        $tgt = $d['targetTemp'] ?? ($d['currentSet'] ?? ($d['manualTemp'] ?? null));
        $setC = ($tgt !== null) ? round(((int) $tgt) / $scale, 1) : null;
        if ($setC !== null) {
            $this->SetValue('SET', $setC);
        }

        $on = isset($d['onoff']) ? ((int) $d['onoff'] === 1) : true;
        if (isset($d['onoff'])) {
            $this->SetValue('ONOFF', $on);
        }

        $heatRaw = $d['working'] ?? ($d['state'] ?? null);
        $heat    = ($heatRaw !== null) ? ((int) $heatRaw === 1) : false;
        if ($heatRaw !== null) {
            $this->SetValue('HEAT', $heat);
        }

        $mode = isset($d['mode']) ? (int) $d['mode'] : 3;
        if (isset($d['mode'])) {
            $this->SetValue('MODE', $mode);
        }

        // Kachel live aktualisieren
        $this->ThermoPushVisu();
    }

    // ---- HTML-SDK: interaktive Kachel -------------------------------------

    // Aktuelle Werte als JSON-String (Format frei wählbar) für die Kachel
    private function ThermoVisuPayload(): string
    {
        $get = function (string $id, $fallback) {
            return (@$this->GetIDForIdent($id) !== false) ? $this->GetValue($id) : $fallback;
        };
        return json_encode([
            'cur'  => (float) $get('TEMP', 0.0),
            'set'  => (float) $get('SET', 20.0),
            'mode' => (int) $get('MODE', 3),
            'heat' => (bool) $get('HEAT', false),
            'on'   => (bool) $get('ONOFF', true),
            'min'  => 5,
            'max'  => 35,
        ]);
    }

    // Aktuellen Zustand an die geöffnete Kachel senden
    private function ThermoPushVisu()
    {
        $this->UpdateVisualizationValue($this->ThermoVisuPayload());
    }

    // HTML + JS der Kachel; Steuerung per requestAction() direkt ins Modul
    private function ThermoVisualizationTile(): string
    {
        $html = <<<'HTML'
<style>
  html,body{height:100%;margin:0;padding:0;}
  #mtWrap{position:fixed;inset:0;width:100vw;height:100vh;display:flex;align-items:center;
    justify-content:center;overflow:hidden;}
  .mt-card{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e8ebf0;text-align:center;
    width:240px;box-sizing:border-box;padding:6px;transform-origin:center center;flex:0 0 auto;}
  .mt-dial{width:100%;margin:0 auto;}
  .mt-dial svg{width:100%;height:auto;display:block;}
  .mt-steps{display:flex;gap:12px;align-items:center;justify-content:center;margin-top:8px;flex-wrap:wrap;}
  .mt-step{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;font-size:20px;
    font-weight:700;background:#2b2f3a;color:#fff;line-height:34px;padding:0;}
  .mt-step:active{transform:scale(.9);}
  .mt-set{font-size:14px;color:#c7ccd6;} .mt-set b{color:#fff;}
  .mt-modes{margin-top:10px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;}
  .mt-mode{padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;
    background:#2b2f3a;color:#c7ccd6;}
  .mt-mode.active{color:#10131a;}
  .mt-power{margin-top:10px;}
  .mt-power button{padding:5px 20px;border-radius:999px;border:none;cursor:pointer;
    font-size:13px;font-weight:700;}
</style>
<div id="mtWrap"><div class="mt-card" id="mtCard">
  <div class="mt-dial">
    <svg viewBox="0 0 200 200" preserveAspectRatio="xMidYMid meet">
      <defs>
        <linearGradient id="mtGrad" x1="0" y1="1" x2="0" y2="0">
          <stop id="mtG0" offset="0%" stop-color="#2E7CF6"/>
          <stop id="mtG1" offset="100%" stop-color="#FF3B30"/>
        </linearGradient>
      </defs>
      <circle cx="100" cy="100" r="84" fill="none" stroke="#2b2f3a" stroke-width="16"/>
      <circle id="mtRing" cx="100" cy="100" r="84" fill="none" stroke="url(#mtGrad)" stroke-width="16"
        stroke-linecap="round" stroke-dasharray="0 528" transform="rotate(-90 100 100)"/>
      <text id="mtCur" x="100" y="97" text-anchor="middle" font-family="Arial,Helvetica,sans-serif"
        font-size="44" font-weight="700" fill="#ffffff">–</text>
      <text x="100" y="122" text-anchor="middle" font-family="Arial,Helvetica,sans-serif"
        font-size="15" fill="#9aa3b2">°C</text>
      <text id="mtHeat" x="100" y="150" text-anchor="middle" font-family="Arial,Helvetica,sans-serif"
        font-size="13" font-weight="700" fill="#FF6B35"></text>
    </svg>
  </div>
  <div class="mt-steps">
    <button class="mt-step" onclick="mtStep(-0.5)">&minus;</button>
    <span class="mt-set">Soll <b id="mtSet">–</b> °C</span>
    <button class="mt-step" onclick="mtStep(0.5)">+</button>
  </div>
  <div class="mt-modes" id="mtModes">
    <span class="mt-mode" data-m="0" onclick="requestAction('MODE',0)">Heizen</span>
    <span class="mt-mode" data-m="1" onclick="requestAction('MODE',1)">Kühlen</span>
    <span class="mt-mode" data-m="2" onclick="requestAction('MODE',2)">Eco</span>
    <span class="mt-mode" data-m="3" onclick="requestAction('MODE',3)">Auto</span>
    <span class="mt-mode" data-m="4" onclick="requestAction('MODE',4)">Manuell</span>
  </div>
  <div class="mt-power"><button id="mtPower" onclick="mtToggle()">…</button></div>
</div></div>
<script>
  window.mtState = {cur:0,set:20,mode:3,heat:false,on:true,min:5,max:35};
  var MT_COL = {0:'#FF7043',1:'#29B6F6',2:'#66BB6A',3:'#9E9E9E',4:'#FFB300'};
  // Temperatur -> Farbe: blau (kalt) ueber violett zu rot (warm); als Hex (SVG-sicher)
  function mtHsl(h,s,l){ h=((h%360)+360)%360/360; var q=l<0.5?l*(1+s):l+s-l*s,p=2*l-q;
    function H(t){ if(t<0)t+=1; if(t>1)t-=1; if(t<1/6)return p+(q-p)*6*t; if(t<0.5)return q; if(t<2/3)return p+(q-p)*(2/3-t)*6; return p; }
    function X(v){ v=Math.round(v*255).toString(16); return v.length<2?'0'+v:v; }
    return '#'+X(H(h+1/3))+X(H(h))+X(H(h-1/3)); }
  function mtTempCol(t,min,max){
    var f=Math.min(1,Math.max(0,(t-min)/((max-min)||1)));
    return mtHsl(240+120*f,0.72,0.55);   // 240=blau ... 360=rot
  }
  function mtStep(d){
    var s=window.mtState, v=Math.min(s.max,Math.max(s.min,s.set+d));
    v=Math.round(v*2)/2;
    requestAction('SET', v);
  }
  function mtToggle(){ requestAction('ONOFF', !window.mtState.on); }
  function handleMessage(message){
    var d = (typeof message==='string') ? JSON.parse(message) : message;
    window.mtState = d;
    var C = 2*Math.PI*84;
    var span = (d.max-d.min)>0 ? (d.max-d.min) : 1;
    var frac = Math.min(1,Math.max(0,(d.set-d.min)/span));
    var ring = document.getElementById('mtRing');
    // Ring-Verlauf blau->rot; warmes Ende folgt der Temperatur (Soll)
    if (d.on) {
      document.getElementById('mtG0').setAttribute('stop-color', '#2E7CF6');
      document.getElementById('mtG1').setAttribute('stop-color', mtTempCol(d.set, d.min, d.max));
      ring.style.filter = d.heat ? 'drop-shadow(0 0 5px #FF6B35)' : 'none';
    } else {
      document.getElementById('mtG0').setAttribute('stop-color', '#556270');
      document.getElementById('mtG1').setAttribute('stop-color', '#7a8290');
      ring.style.filter = 'none';
    }
    ring.setAttribute('stroke-dasharray', (frac*C).toFixed(1)+' '+C.toFixed(1));
    var heatEl = document.getElementById('mtHeat');
    if (!d.on){ heatEl.textContent='aus'; heatEl.setAttribute('fill','#7a8290'); }
    else if (d.heat){ heatEl.textContent='● heizt'; heatEl.setAttribute('fill','#FF6B35'); }
    else { heatEl.textContent=''; }
    // Ist-Temperatur immer anzeigen – auch wenn das Thermostat ausgeschaltet ist
    document.getElementById('mtCur').textContent = Number(d.cur).toFixed(1).replace('.',',');
    document.getElementById('mtSet').textContent = Number(d.set).toFixed(1).replace('.',',');
    var ms = document.querySelectorAll('#mtModes .mt-mode');
    for (var i=0;i<ms.length;i++){
      var m = parseInt(ms[i].getAttribute('data-m'),10);
      if (m===d.mode){ ms[i].classList.add('active'); ms[i].style.background = MT_COL[m]; }
      else { ms[i].classList.remove('active'); ms[i].style.background='#2b2f3a'; }
    }
    var pw = document.getElementById('mtPower');
    pw.textContent = d.on ? 'Ein' : 'Aus';
    pw.style.background = d.on ? '#00C853' : '#444a57';
    pw.style.color = d.on ? '#08210f' : '#cfd4dd';
    document.getElementById('mtCard').style.opacity = d.on ? '1' : '0.9';
    mtFit();
  }
  // Inhalt automatisch auf die eingestellte Kachelgroesse skalieren (immer ganz sichtbar)
  function mtFit(){
    var w=document.getElementById('mtWrap'), c=document.getElementById('mtCard');
    if(!w||!c) return;
    c.style.transform='none';
    var cw=c.offsetWidth||1, ch=c.offsetHeight||1;
    var s=Math.min(w.clientWidth/cw, w.clientHeight/ch);
    if(!isFinite(s)||s<=0) s=1;
    c.style.transform='scale('+s+')';
  }
  window.addEventListener('resize', mtFit);
  window.addEventListener('load', function(){ mtFit(); setTimeout(mtFit,60); setTimeout(mtFit,300); });
</script>
HTML;
        return $html . '<script>try{handleMessage(' . json_encode($this->ThermoVisuPayload()) . ');}catch(e){}</script>';
    }
}
