<?php

namespace OPNsense\ParentalControl\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiControllerBase
{
    /**
     * Recompute every device's state and sync the alias.
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output = trim($backend->configdRun('parentalcontrol sync'));
        return ['status' => 'ok', 'output' => $output];
    }

    /**
     * Resolved state for every device, plus the alias contents actually loaded
     * into pf. Read-only; makes no changes.
     */
    public function statusAction()
    {
        $backend = new Backend();
        $raw = trim($backend->configdRun('parentalcontrol status'));
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return ['status' => 'failed', 'message' => 'could not read status', 'raw' => $raw];
        }
        return $decoded;
    }
}
