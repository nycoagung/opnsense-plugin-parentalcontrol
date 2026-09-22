<?php

namespace OPNsense\ParentalControl\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

/**
 * CRUD for the device list and general settings.
 *
 * Schedule evaluation deliberately lives in scripts/sync.php rather than here,
 * so the widget, the settings page and the cron run all resolve a device's
 * state through exactly one implementation.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'parentalcontrol';
    protected static $internalModelClass = '\OPNsense\ParentalControl\ParentalControl';

    public function searchDeviceAction()
    {
        return $this->searchBase(
            'devices',
            ['enabled', 'name', 'address', 'mode', 'weekdays', 'allow_from', 'allow_to',
             'override', 'description']
        );
    }

    public function getDeviceAction($uuid = null)
    {
        return $this->getBase('device', 'devices', $uuid);
    }

    public function addDeviceAction()
    {
        return $this->addBase('device', 'devices');
    }

    public function setDeviceAction($uuid)
    {
        return $this->setBase('device', 'devices', $uuid);
    }

    public function delDeviceAction($uuid)
    {
        return $this->delBase('devices', $uuid);
    }

    public function toggleDeviceAction($uuid, $enabled = null)
    {
        return $this->toggleBase('devices', $uuid, $enabled);
    }

    /**
     * Set a device's manual override and apply immediately. This is what the
     * dashboard widget's on/off switch calls.
     */
    public function setOverrideAction($uuid = null)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $value = $this->request->getPost('override', 'striptags', 'none');
        if (!in_array($value, ['none', 'allow', 'block'], true)) {
            return ['result' => 'failed', 'message' => 'invalid override'];
        }
        $node = $this->getModel()->getNodeByReference('devices.' . $uuid);
        if ($node === null) {
            return ['result' => 'failed', 'message' => 'unknown device'];
        }
        $node->override = $value;
        $validation = $this->getModel()->performValidation();
        if ($validation->count() > 0) {
            return ['result' => 'failed', 'message' => 'validation failed'];
        }
        $this->save();
        $backend = new Backend();
        $backend->configdRun('parentalcontrol sync');
        return ['result' => 'saved', 'override' => $value];
    }
}
