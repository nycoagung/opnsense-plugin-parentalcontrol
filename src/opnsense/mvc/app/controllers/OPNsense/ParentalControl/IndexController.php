<?php

namespace OPNsense\ParentalControl;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm = $this->getForm("general");
        $this->view->deviceForm = $this->getForm("device");
        $this->view->pick('OPNsense/ParentalControl/index');
    }
}
