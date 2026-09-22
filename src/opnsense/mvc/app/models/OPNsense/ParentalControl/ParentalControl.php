<?php

namespace OPNsense\ParentalControl;

use OPNsense\Base\BaseModel;

/**
 * Per-device internet access control.
 *
 * The model holds intent only. Enforcement lives in scripts/sync.php, which
 * resolves each device to allowed/blocked for the current moment and syncs the
 * externally managed alias.
 */
class ParentalControl extends BaseModel
{
}
