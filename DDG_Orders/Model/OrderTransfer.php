<?php

set_time_limit(-1);
ini_set('memory_limit', '512M');

/**
 * DDGOrders Main source
 *
 * @category    DigitalSkynet
 * @package     DigitalSkynet_DDGOrders
 * @author      Polyanskaya T.A.
 */

class DigitalSkynet_DDGOrders_Model_OrderTransfer
{
    public $apiUsername = 'apiuser-8721acd12b16@apiconnector.com';
    public $apiPassword = 'apiuser-8721acd12b16';

    public $customers = array(
        "bc.brittanycraig@gmail.com",
        "wanda.redwine@yahoo.com",
        "antenh.kibret@dlaecomm.com",
        "Mitchelcollins55@yahoo.com",
        "Mcknight.janie15@gmail.com",
        "zkda1234@dandjbiz.com",
        "kellyanne.fitness@gmail.com",
        "kelliwisneski54@gmail.com",
        "amandaorr56@yahoo.com",
        "mirandakayleen@gmail.com",
        "emersons4sports@yahoo.com",
        "wenzheng.jia@gmail.com",
        "whitney.rollo@gmail.com",
        "Camila.m.briceno@gmail.com"
    );

    // A maximum of 10MB of transactional data can be uploaded in a request - from docs
    // Please note 2000 API calls per hour are allowed - from task

    public function main()
    {
        try {
            echo "Start Order transfer to dotdigital<br>";
            $limit = 1000;
            $offset = 0;
            $flag = true;
            $table = 'temp_ddgorders';

            $resource = Mage::getSingleton('core/resource');
            $connection = $resource->getConnection('core_write');
            $this->createTable($connection, $table);

            $offset = $this->getRecord($resource, $table);
            while ($flag) {
                $lastOrderId = $this->setOrderData($offset, $limit);
                if ($lastOrderId > 0) {
                    $this->addRecord($connection, $table, $lastOrderId);
                } else
                    $flag = false;

                $offset = $lastOrderId;
            }
        } catch (Exception $e) {
            echo 'ERROR: ';
            echo $e->getMessage();
        }
    }

    protected function getRecord($resource, $table)
    {
        $connection = $resource->getConnection('core_read');
        $sql = "SELECT * FROM $table order by created_at desc, order_last desc limit 1";
        $results = $connection->fetchAll($sql);
        if (is_array($results) && (count($results) > 0)) {
            $order_id = $results[0]['order_last'];
            return $order_id;
        }
        //$connection->closeConnection
        $connection = null;
        return 0;
    }

    protected function addRecord($connection, $table, $last)
    {
        $date = Mage::getSingleton('core/date')->gmtDate();

        $sql = "INSERT INTO $table (order_last, created_at) VALUES (:last, :created_at)";
        $bind = array(
            'last' => $last,
            'created_at' => $date
        );

        $connection->query($sql, $bind);
    }

    protected function createTable($connection, $table)
    {

        if (!$connection) return false;
        //create table
        $query = "CREATE TABLE IF NOT EXISTS `$table` (
                `id` int NOT NULL AUTO_INCREMENT,
                `order_last` int,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='temp table'
            ";

        return $connection->query($query);
    }

    /**
     * Main function for save Order Data to dotdigital.com
     */
    public function setOrderData($offset, $limit)
    {
        $collection = Mage::getModel('sales/order')->getCollection();

        if (!empty($this->customers)) {
            $collection->addFieldToFilter(
                'customer_email',
                array(
                    'in' => $this->customers
                )
            );
        }

        $collection->getSelect()->where('entity_id > ?', $offset)
            ->order('entity_id ASC')->limit($limit);

        if ($collection->count() > 0) {
            $data = array();
            $contacts = array();

            $addressBookId = $this->getAddressBookId();
            if ($addressBookId == 0) {
                return false;
            }

            foreach ($collection as $order) {
                $contacts[] = $this->getContactData($order);
                $data[] = $this->getCorrectOrderData($order);
            }
            //send group contacts to dotdigital 
            $filename = date('Y_m_d') . '_' . uniqid() . '.csv';
            $this->contactsToCsvFile($contacts, $filename);
            $this->sendFile($addressBookId, $filename);
            $contacts = null;
            //send group orders to dotdigital
            $response = $this->sendPost(
                'https://api.dotmailer.com/v2/contacts/transactional-data/import/orders',
                $data
            );
            echo 'SEND_ORDER: ';
            //echo $response;
            $data = null;
            return $collection->getLastItem()->getId();
        }
        $collection = null;
        return 0;
    }

    protected function addContactToAddressBook($addressBookId, $contact)
    {
        //add contact to address book
        $url = 'https://api.dotmailer.com/v2/address-books/' . $addressBookId . '/contacts';
        $response = $this->sendPost($url, $contact);
        echo 'ADD: ' . $response;
    }

    protected function getAddressBookId()
    {
        $addressBooks = $this->getAddressBooks();
        $addressBookId = $this->searchAddressBook('Customers', $addressBooks);
        if ($addressBookId <= 0) {
            echo 'Address book Customers not found';
            return 0;
        }
        return $addressBookId;
    }

    /**
     * @param $order Mage_Sales_Model_Order
     * @return array
     */
    protected function getContactData($order)
    {
        $email = $order->getCustomerEmail();

        $billingAddress = $order->getBillingAddress();
        $jsonData = array(
            $billingAddress->getFirstname(),
            $billingAddress->getLastname(),
            $email,
            'Html'
        );
        $billingAddress = null;

        return  $jsonData;
    }

    /**
     * @param $order Mage_Sales_Model_Order
     * @return array
     */
    //order_total, purchase_date, products, name, price, sku, qty, order_subtotal
    protected function getCorrectOrderData($order)
    {
        $created_at = new Zend_Date(
            $order->getCreatedAt(),
            Zend_Date::ISO_8601
        );

        $order_total = abs(
            $order->getData('grand_total') - $order->getTotalRefunded()
        );

        $order_subtotal = (float)number_format(
            $order->getData('subtotal'),
            2,
            '.',
            ''
        );

        $discount_amount = (float)number_format(
            $order->getData('discount_amount'),
            2,
            '.',
            ''
        );

        $delivery_total = (float)number_format(
            $order->getShippingAmount(),
            2,
            '.',
            ''
        );

        $payment = $this->getPayment($order);

        $order_id = $order->getIncrementId();

        //billing
        $billing = $this->getBillingData($order);
        //shipping
        $shipping = $this->getShippingData($order);

        //Get subtotal including tax in base currency
        $tax = (float)number_format(
            $order->getBaseSubtotalInclTax(),
            2,
            '.',
            ''
        );

        $jsonData = array(
            'key' => "P$order_id",
            'contactIdentifier' => $order->getCustomerEmail(),
            'json' =>
            array(
                'source' => 'Magento',
                'purchase_date' => $created_at->toString(Zend_Date::ISO_8601),
                'order_total' => (float)number_format($order_total, 2, '.', ''),
                'order_subtotal' => $order_subtotal,
                'base_subtotal_incl_tax' => $tax,
                'discount_amount' => $discount_amount,
                'payment' => $payment,
                'delivery_total' => $delivery_total,
                'currency' => 'USD', //$order->getStoreCurrencyCode()
                'billing_address' => $billing,
                'delivery_address' => $shipping,
                'products' => [],
            ),
        );

        $products = $order->getAllItems();
        foreach ($products as $product) {
            if ($product->getProductType() === 'configurable' || $product->getProductType() === 'bundle') {
                continue;
            }
            $jsonData['json']['products'][] = $this->getProduct($product);
        }

        $products = null;
        return $jsonData;
    }

    /**
     * Product
     * @param $product Mage_Sales_Model_Order_Item
     * @return array
     */
    protected function getProduct($product)
    {

        $price = $product->getPrice();
        $parent = $product->getParentItem();
        if ($parent) {
            if ((int)$price === 0 && !(float)($price / 10) > 0) {
                $parentPrice = $parent->getPrice();
            }
        }
        $parent = null;

        $categories = $this->getCategory($product);

        $productData = array(
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'qty' => (int)number_format(
                $product->getData('qty_ordered'),
                2
            ),
            'price' => (float)number_format(
                isset($parentPrice) ? $parentPrice : $price,
                2,
                '.',
                ''
            ),
            'categories' => $categories
        );
        return $productData;
    }

    public function getProdCategory($product_id)
    {

        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        $sql = "SELECT ccp.`category_id`, ccfs.`entity_id`, ccfs.`name` 
        FROM `catalog_category_product` as ccp INNER JOIN `catalog_category_flat_store_1` as ccfs 
        ON ccp.`category_id` = ccfs.`entity_id` WHERE (ccp.`product_id` = $product_id);";

        $results = $connection->fetchAll($sql);
        if (is_array($results) && (count($results) > 0)) {
            return $results;
        }
        return null;
    }

    /**
     * Category
     * @param $product Mage_Sales_Model_Order_Item
     * @return array
     */
    protected function getCategory($product)
    {

        //DeltaSoffe_TimePhasedInventoryUpdate_Model_Product
        $simple_id = $product->getProductId();
        //$simple_cats = $this->getProdCategory($simple_id);

        //get categories from configurable product
        $cfgIds = Mage::getResourceSingleton('catalog/product_type_configurable')
            ->getParentIdsByChild($simple_id);

        if (isset($cfgIds[0])) {
            $cfg_cats = $this->getProdCategory($cfgIds[0]);
        }

        $productCat = array();
        if (isset($cfg_cats)) {
            foreach ($cfg_cats as $cat) {

                $prodCategories = array();
                $prodCategories[] = $cat['name'];
                $productCat[]['Name'] = substr(
                    implode(', ', $prodCategories),
                    0,
                    244
                );
                $prodCategories = null;
                $cat = null;
            }
        }

        $cfg_cats = null;

        return $productCat;
    }

    /**
     * @param $order Mage_Sales_Model_Order
     * @return string
     */
    protected function getPayment($order)
    {
        $payment = $order->getPayment();
        if (($payment) && ($payment->getMethod())) {
            $res = $payment->getMethodInstance()->getTitle();
            return $res;
        }
        return '';
    }

    /**
     * Shipping address.
     * @param $order Mage_Sales_Model_Order
     * @return array
     */
    protected function getShippingData($order)
    {
        if ($order->getShippingAddress()) {
            $shippingData = $order->getShippingAddress()->getData();

            $delivery_address = array(
                'delivery_address_1' => $this->_getStreet(
                    $shippingData['street'],
                    1
                ),
                'delivery_address_2' => $this->_getStreet(
                    $shippingData['street'],
                    2
                ),
                'delivery_address_3' => $this->_getStreet(
                    $shippingData['street'],
                    3
                ),
                'delivery_city' => $shippingData['city'],
                'delivery_state' => $shippingData['region'],
                'delivery_country' => $shippingData['country_id'],
                'delivery_postcode' => $shippingData['postcode']
            );
            $shippingData = null;
            return $delivery_address;
        }
        return null;
    }

    /**
     * Billing address.
     * @param $order Mage_Sales_Model_Order
     * @return array
     */
    protected function getBillingData($order)
    {
        if ($order->getBillingAddress()) {
            $billingData = $order->getBillingAddress()->getData();

            $billing_address = array(
                'billing_address_1' => $this->_getStreet(
                    $billingData['street'],
                    1
                ),
                'billing_address_2' => $this->_getStreet(
                    $billingData['street'],
                    2
                ),
                'billing_address_3' => $this->_getStreet(
                    $billingData['street'],
                    3
                ),
                'billing_city' => $billingData['city'],
                'billing_state' => $billingData['region'],
                'billing_country' => $billingData['country_id'],
                'billing_postcode' => $billingData['postcode'],
            );
            $billingData = null;
            return $billing_address;
        }
        return null;
    }

    /**
     * get the street name by line number
     *
     * @param $street
     * @param $line
     *
     * @return string
     */
    protected function _getStreet($street, $line)
    {
        $street = explode("\n", $street);

        switch ($line) {
            case 1:
                return $street[0];
            case 2:
                if (isset($street[$line - 1])) {
                    return $street[$line - 1];
                } else {
                    return '';
                }
            case 3:
                if (isset($street[$line - 2])) {
                    return $street[$line - 2];
                } else {
                    return '';
                }
        }
    }

    protected function sendPost($uri, $content)
    {

        echo " Curl: send Post";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiUsername . ':' . $this->apiPassword);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));

        $response = curl_exec($ch);
        curl_close($ch);

        $json =  json_encode($content);
        echo 'REQUEST_DATA: ';
        //echo $json;
        return $response;
    }

    /**
     * Get all address books.
     *
     * @return null
     */
    protected function getAddressBooks()
    {

        echo " Curl: Get address book";
        $url = 'https://api.dotmailer.com/v2/address-books';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiUsername . ':' . $this->apiPassword);

        $response = json_decode(curl_exec($ch));
        curl_close($ch);

        return $response;
    }

    protected function searchAddressBook($name, $arrBooks)
    {
        foreach ($arrBooks as $book) {
            if ($book->name == $name)
                return $book->id;
        }
        return -1;
    }

    protected function outputCSV($filepath, $csv)
    {
        $handle = fopen($filepath, "a");
        if (fputcsv($handle, $csv, ',', '"') == 0) {
            echo 'Problem writing CSV file';
        }
        fclose($handle);
    }

    protected function contactsToCsvFile($csv, $filename)
    {
        $filename = Mage::getBaseDir('var') . DS . 'log' . DS . $filename;
        $header = array('FIRSTNAME', 'LASTNAME', 'Email', 'EmailType');
        $this->outputCSV($filename, $header);

        foreach ($csv as $fields) {
            $this->outputCSV($filename, $fields);
        }
    }

    protected function sendFile($addressBookId, $filename)
    {

        echo " Curl: send file";
        $url = 'https://api.dotmailer.com/v2/address-books/' . $addressBookId . '/contacts/import';

        $filename = Mage::getBaseDir('var') . DS . 'log' . DS . $filename;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiUsername . ':' . $this->apiPassword);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: multipart/form-data'
            )
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
        $args['file'] = curl_file_create(
            $filename,
            'text/csv'
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
        $response = json_decode(curl_exec($ch));
        curl_close($ch);
    }
}
