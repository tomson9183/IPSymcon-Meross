<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Garagentoröffner (MSG100 / MSG200) - lokale Steuerung.
//
//  Fahren : Appliance.GarageDoor.State  SET
//           {"state":{"channel":0,"open":0|1,"uuid":"<uuid>"}}
//             open 1 = öffnen, 0 = schließen
//  Status : Appliance.GarageDoor.State  GET
//           -> {"state":[{"channel":0,"open":0|1}]}
//
//  HINWEIS: Protokoll dokumentiert (meross_iot), am Geraet noch zu testen.
//  Wird als Trait in die Klasse MerossDevice eingebunden.
// ---------------------------------------------------------------------

trait GarageDevice
{
    private function GarageApplyChanges()
    {
        $this->RegisterVariableBoolean('DOOR', $this->Translate('Garagentor'), 'MERO.Garage', 10);
        $this->EnableAction('DOOR');
    }

    private function GarageRequestAction($Ident, $Value)
    {
        if ($Ident === 'DOOR') {
            $open = $Value ? 1 : 0;
            $uuid = $this->ReadPropertyString('Uuid');
            $resp = $this->LocalRequest('Appliance.GarageDoor.State', 'SET', ['state' => ['channel' => 0, 'open' => $open, 'uuid' => $uuid]]);
            if ($resp !== null) {
                $this->SetValue('DOOR', (bool) $Value);
            }
        }
    }

    private function GarageUpdate()
    {
        $resp = $this->LocalRequest('Appliance.GarageDoor.State', 'GET', []);
        if ($resp === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $open = $resp['payload']['state'][0]['open'] ?? null;
        if ($open !== null && @$this->GetIDForIdent('DOOR') !== false) {
            $this->SetValue('DOOR', (int) $open === 1);
        }
    }
}
