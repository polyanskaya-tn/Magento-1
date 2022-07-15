<?php

/**
 * DDGOrders Observer
 * Run the transmission of orders to dotdigital by kron
 *
 * @category    DigitalSkynet
 * @package     DigitalSkynet_DDGOrders
 * @author      Polyanskaya T.A.
 */

class DigitalSkynet_DDGOrders_Model_Observer
{
    public function run()
    {
        Mage::getModel('ddgorders/OrderTransfer')->main();
        return true;
    }
}
