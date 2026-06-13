<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Steckdosen (MSS210P, MSS620): Ein/Aus, Strommessung, Status-LED.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait PlugDevice
{
    private function PlugApplyChanges()
    {
        $type = $this->ReadPropertyString('DeviceType');
        $channels = ($type === 'mss620') ? 2 : 1;
        for ($ch = 0; $ch < $channels; $ch++) {
            $caption = ($channels > 1) ? ('Kanal ' . ($ch + 1)) : 'Schalter';
            $this->RegisterVariableBoolean('STATE' . $ch, $caption, 'MERO.Plug', $ch);
            $this->EnableAction('STATE' . $ch);
        }
    }

    private function PlugRequestAction($Ident, $Value)
    {
        if (strpos($Ident, 'STATE') !== 0) {
            return;
        }
        $channel = (int) substr($Ident, 5);
        $onoff   = $Value ? 1 : 0;
        $resp = $this->LocalRequest('Appliance.Control.ToggleX', 'SET', ['togglex' => ['channel' => $channel, 'onoff' => $onoff]]);
        if ($resp === null) {
            return;
        }
        // tatsaechlichen Zustand aus der Antwort uebernehmen, sonst optimistisch
        $applied = null;
        $tx = $resp['payload']['togglex'] ?? null;
        if (is_array($tx)) {
            if (isset($tx['onoff'])) {
                $applied = (int) $tx['onoff'];
            } else {
                foreach ($tx as $entry) {
                    if ((int) ($entry['channel'] ?? -1) === $channel) {
                        $applied = (int) ($entry['onoff'] ?? $onoff);
                    }
                }
            }
        }
        $this->SetValue($Ident, $applied === null ? (bool) $Value : ($applied === 1));
        $this->SyncLed();
    }

    private function PlugUpdate()
    {
        $resp = $this->LocalRequest('Appliance.System.All', 'GET', []);
        if ($resp === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);

        $toggles = $resp['payload']['all']['digest']['togglex'] ?? null;
        if (is_array($toggles)) {
            foreach ($toggles as $t) {
                if (!isset($t['channel'])) {
                    continue;
                }
                $ch    = (int) $t['channel'];
                $ident = 'STATE' . $ch;
                if (@$this->GetIDForIdent($ident) === false) {
                    $this->RegisterVariableBoolean($ident, 'Kanal ' . ($ch + 1), 'MERO.Plug', $ch);
                    $this->EnableAction($ident);
                }
                $this->SetValue($ident, ((int) ($t['onoff'] ?? 0)) === 1);
            }
        }

        $this->PlugUpdateElectricity();
        $this->PlugUpdateConsumption();
        $this->SyncLed();
        $this->PlugPushVisu();
    }

    private function PlugUpdateElectricity()
    {
        $resp = $this->LocalRequest('Appliance.Control.Electricity', 'GET', ['channel' => 0]);
        if ($resp === null) {
            return;
        }
        $e = $resp['payload']['electricity'] ?? null;
        if (!is_array($e) || !isset($e['power'])) {
            return; // Geraet ohne Messfunktion
        }
        if (@$this->GetIDForIdent('POWER') === false) {
            $this->RegisterVariableFloat('POWER', 'Leistung', '~Watt', 30);
            $this->RegisterVariableFloat('VOLTAGE', 'Spannung', '~Volt', 31);
            $this->RegisterVariableFloat('CURRENT', 'Strom', '~Ampere', 32);
        }
        $this->SetValue('POWER', round(((float) $e['power']) / 1000, 2));
        $this->SetValue('VOLTAGE', round(((float) ($e['voltage'] ?? 0)) / 10, 1));
        $this->SetValue('CURRENT', round(((float) ($e['current'] ?? 0)) / 1000, 3));
    }

    private function PlugUpdateConsumption()
    {
        $resp = $this->LocalRequest('Appliance.Control.ConsumptionX', 'GET', []);
        if ($resp === null) {
            return;
        }
        $cx = $resp['payload']['consumptionx'] ?? null;
        if (!is_array($cx) || count($cx) === 0) {
            return;
        }
        $last = end($cx);
        if (!isset($last['value'])) {
            return;
        }
        if (@$this->GetIDForIdent('ENERGY_TODAY') === false) {
            $this->RegisterVariableFloat('ENERGY_TODAY', 'Verbrauch heute', '~Electricity', 33);
        }
        $this->SetValue('ENERGY_TODAY', round(((float) $last['value']) / 1000, 3));
    }

    // Status-LED folgt dem Schaltzustand: an -> DND aus (LED an), aus -> DND an (LED aus)
    private function SyncLed()
    {
        $anyOn = false;
        for ($ch = 0; $ch < 6; $ch++) {
            $id = @$this->GetIDForIdent('STATE' . $ch);
            if ($id !== false && GetValue($id)) {
                $anyOn = true;
            }
        }
        if ($anyOn !== $this->ReadAttributeBoolean('LastLed')) {
            $this->SetDND($anyOn);
            $this->WriteAttributeBoolean('LastLed', $anyOn);
        }
    }

    private function SetDND(bool $ledOn)
    {
        $mode = $ledOn ? 0 : 1;
        $this->LocalRequest('Appliance.System.DNDMode', 'SET', ['DNDMode' => ['mode' => $mode]]);
    }

    // ---- HTML-SDK: interaktive Kachel (Schalter + Messwerte) --------------

    private function PlugVisuPayload(): string
    {
        $channels = [];
        for ($i = 0; $i < 6; $i++) {
            $id = 'STATE' . $i;
            if (@$this->GetIDForIdent($id) !== false) {
                $channels[] = ['ch' => $i, 'on' => (bool) $this->GetValue($id)];
            }
        }
        $val = function (string $id) {
            return (@$this->GetIDForIdent($id) !== false) ? (float) $this->GetValue($id) : null;
        };
        return json_encode([
            'channels' => $channels,
            'power'    => $val('POWER'),
            'voltage'  => $val('VOLTAGE'),
            'current'  => $val('CURRENT'),
            'energy'   => $val('ENERGY_TODAY'),
        ]);
    }

    private function PlugPushVisu()
    {
        $this->UpdateVisualizationValue($this->PlugVisuPayload());
    }

    private function PlugVisualizationTile(): string
    {
        $html = <<<'HTML'
<style>
  body{margin:0;}
  .pg-card{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e8ebf0;text-align:center;
    width:100%;max-width:260px;margin:0 auto;box-sizing:border-box;padding:10px 8px;}
  .pg-ico{width:100%;max-width:104px;margin:0 auto;}
  .pg-ico svg{width:100%;height:auto;display:block;}
  .pg-ch{margin-top:10px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
  .pg-btn{min-width:86px;padding:8px 14px;border-radius:12px;border:none;cursor:pointer;
    font-size:13px;font-weight:700;background:#2b2f3a;color:#cfd4dd;}
  .pg-btn.on{background:#00C853;color:#08210f;}
  .pg-btn small{display:block;font-size:10px;font-weight:600;opacity:.85;margin-top:2px;}
  .pg-metrics{margin-top:12px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;}
  .pg-m{background:#20242e;border-radius:10px;padding:7px 11px;min-width:60px;}
  .pg-m b{display:block;font-size:15px;color:#fff;font-weight:700;}
  .pg-m span{color:#8a93a0;font-size:10px;}
</style>
<div class="pg-card" id="pgCard">
  <div class="pg-ico">
    <svg viewBox="0 0 120 120" preserveAspectRatio="xMidYMid meet">
      <circle id="pgGlow" cx="60" cy="60" r="46" fill="none" stroke="#2b2f3a" stroke-width="10"/>
      <path id="pgArc" d="M44 45 A23 23 0 1 0 76 45" fill="none" stroke="#6b7280" stroke-width="8" stroke-linecap="round"/>
      <line id="pgBar" x1="60" y1="33" x2="60" y2="63" stroke="#6b7280" stroke-width="8" stroke-linecap="round"/>
    </svg>
  </div>
  <div class="pg-ch" id="pgCh"></div>
  <div class="pg-metrics" id="pgMetrics"></div>
</div>
<script>
  window.pgState = {channels:[]};
  function pgToggle(ch){
    var arr=window.pgState.channels, on=false;
    for(var i=0;i<arr.length;i++){ if(arr[i].ch===ch){ on=arr[i].on; } }
    requestAction('STATE'+ch, !on);
  }
  function handleMessage(message){
    var d=(typeof message==='string')?JSON.parse(message):message;
    window.pgState=d;
    var ch=d.channels||[], anyOn=false;
    for(var i=0;i<ch.length;i++){ if(ch[i].on) anyOn=true; }
    var col=anyOn?'#00C853':'#6b7280';
    document.getElementById('pgArc').setAttribute('stroke',col);
    document.getElementById('pgBar').setAttribute('stroke',col);
    document.getElementById('pgGlow').setAttribute('stroke',anyOn?'rgba(0,200,83,.28)':'#2b2f3a');
    var h='';
    for(var i=0;i<ch.length;i++){
      var c=ch[i], lbl=(ch.length>1)?('Kanal '+(c.ch+1)):'Schalter';
      h+='<button class="pg-btn'+(c.on?' on':'')+'" onclick="pgToggle('+c.ch+')">'+lbl+'<small>'+(c.on?'Ein':'Aus')+'</small></button>';
    }
    document.getElementById('pgCh').innerHTML=h;
    var m='';
    function add(v,lbl,dec){ if(v!==null&&v!==undefined){ m+='<div class="pg-m"><b>'+Number(v).toFixed(dec).replace('.',',')+'</b><span>'+lbl+'</span></div>'; } }
    add(d.power,'Watt',1); add(d.voltage,'Volt',1); add(d.current,'Ampere',2); add(d.energy,'kWh heute',3);
    document.getElementById('pgMetrics').innerHTML=m;
  }
</script>
HTML;
        return $html . '<script>try{handleMessage(' . json_encode($this->PlugVisuPayload()) . ');}catch(e){}</script>';
    }
}
