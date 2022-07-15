<?php

class DigitalSkynet_DDGOrders_IndexController extends Mage_Core_Controller_Front_Action
{
    /**
     * @var int
     */

    public function indexAction() {
        //Mage::getModel('DigitalSkynet_DDGOrders_Model_OrderTransfer')->main();
        Mage::getModel('ddgorders/OrderTransfer')->main();
        //ddgorders
    }

    // A maximum of 10MB of transactional data can be uploaded in a request - from docs
    // Please note 2000 API calls per hour are allowed - from task
    public function index1Action()
    {
        try {
            echo "Welcome!";

            $this->addressBookId = 0;
            $limit = 100;
            $offset = 0;
            $flag = true;
            $table = 'temp_ddgorders';

            $resource = Mage::getSingleton('core/resource');
            $connection = $resource->getConnection('core_write');
            $res = $this->createTable($connection, $table);

            $offset = $this->getRecord($resource, $table);
            $temp = 1;
            while ($flag) {
                $lastOrderId = $this->setOrderData($offset, $limit);
                if ($lastOrderId > 0) {
                    $this->addRecord($connection, $table, $lastOrderId);
                }
                else
                    $flag = false;

                $offset = $lastOrderId;
                $temp++;
                if ($temp > 5) $flag = false;
            }
        }
        catch (Exception $e) {
            echo 'ERROR: ';
            echo $e->getMessage();
        }
    }

    protected function getRecord($resource, $table) {
        $connection = $resource->getConnection('core_read');
        $sql = "SELECT * FROM $table order by created_at desc, order_last desc limit 1";
        $results = $connection->fetchAll($sql);
        if (is_array($results) && (count($results) > 0)) {
            $order_id = $results[0]['order_last'];
            return $order_id;
        }
        return 0;
    }

    protected function addRecord($connection, $table, $last) {
        $date = Mage::getSingleton('core/date')->gmtDate();

        $sql = "INSERT INTO $table (order_last, created_at) VALUES (:last, :created_at)";
        $bind = array(
            'last' => $last,
            'created_at' => $date
            );

        $connection->query($sql, $bind);
    }

    protected function createTable($connection, $table) {
        //$resource = Mage::getSingleton('core/resource');
        //$connection = $resource->getConnection('core_write');

        //delete table
        //$query = "DROP TABLE IF EXISTS `$table`;";
        //$connection->query($query);

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
        //$max = $offset + $limit;
       // return rand ( $offset , $max ); 

        $collection = Mage::getModel('sales/order')->getCollection();
        $collection->getSelect()->where('entity_id > ?', $offset)
            ->order('entity_id', 'ASC')->limit($limit);
        
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
            $filename = date('Y_m_d').'_'.uniqid().'.csv';
            $this->contactsToCsvFile($contacts, $filename);
            $this->sendFile($addressBookId, $filename);
            //send group orders to dotdigital
            $response = $this->sendPost(
                'https://api.dotmailer.com/v2/contacts/transactional-data/import/orders',
                $data
            );
            echo 'SEND_ORDER: ';
            echo $response;
            return $collection->getLastItem()->getId();
        }
        return 0;
    }

    protected function addContactToAddressBook($addressBookId, $contact)
    {
        //add contact to address book
        $url = 'https://api.dotmailer.com/v2/address-books/' . $addressBookId . '/contacts';
        $response = $this->sendPost($url, $contact);
        echo 'ADD: '.$response;
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

    protected function getContactData($order)
    {
        $billingAddress = $order->getBillingAddress();

        $jsonData = array(
            $billingAddress->getFirstname(),
            $billingAddress->getLastname(),
            $order->getCustomerEmail(),
            'Html'
        );
        return  $jsonData;
    }

    //order_total, purchase_date, products, name, price, sku, qty, order_subtotal
    protected function getCorrectOrderData($order) {

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

        $order_id = $order->getIncrementId();

        /*
        foreach ($product in [])
        {
            $jsonData['json']['Product'] []=  array (
                'Name' => 'Cashmere cable-knit beanie',
                'Brand' => 'Paul Smith',
                'Department' => 'Menswear',
                'Category' => 'Hat',
                'PriceExTax' => 88.0,
                'ProductID' => '24937',
              )
        }
        $jsonData['json']['Product']
*/

        $jsonData = array(
            'key' => "P$order_id",
            'contactIdentifier' => $order->getCustomerEmail(),
            'json' =>
            array(
                'purchase_date' => $created_at->toString(Zend_Date::ISO_8601),
                'order_total' => (float)number_format($order_total, 2, '.', ''),
                'order_subtotal' => $order_subtotal,
                'products' => [],
            ),
        );

        foreach ($order->getAllItems() as $productItem) {
            $jsonData['json']['products'][] = $this->getProduct($productItem);
        }

        return $jsonData;
    }

    protected function getProduct($productItem) {

        if ($productItem->getParentItem()) {
            if ((int)$productItem->getPrice() === 0 && !(float)($productItem->getPrice() / 10) > 0) {
                $parentPrice = $productItem->getParentItem()->getPrice();
            }
        }

        $productData = array(
            'name' => $productItem->getName(),
            'sku' => $productItem->getSku(),
            'qty' => (int)number_format(
                $productItem->getData('qty_ordered'),
                2
            ),
            'price' => (float)number_format(
                isset($parentPrice) ? $parentPrice : $productItem->getPrice(),
                2,
                '.',
                ''
            ),
        );
        return $productData;
    }

    protected function sendPost($uri, $content) {

        $apiUsername = 'apiuser-a21367508cfb@apiconnector.com';
        $apiPassword = 'qwerty123';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $apiUsername . ':' . $apiPassword);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));

        $response = curl_exec($ch);
        curl_close($ch);

        $json =  json_encode($content);
        echo 'REQUEST_DATA: ';
        echo $json;
        return $response;
    }


    /**
     * Get all address books.
     *
     * @return null
     */
    protected function getAddressBooks() {
        $url = 'https://api.dotmailer.com/v2/address-books';
        $apiUsername = 'apiuser-a21367508cfb@apiconnector.com';
        $apiPassword = 'qwerty123';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $apiUsername . ':' . $apiPassword);

        $response = json_decode(curl_exec($ch));
        curl_close($ch);

        return $response;
    }

    protected function searchAddressBook($name, $arrBooks) {
        foreach ($arrBooks as $book) {
            if ($book->name == $name)
                return $book->id;
        }
        return -1;
    }

    protected function outputCSV($filepath, $csv) {
        //@codingStandardsIgnoreStart
        // Open for writing only; place the file pointer at the end of the file.
        // If the file does not exist, attempt to create it.
        $handle = fopen($filepath, "a");
        // for some reason passing the preset delimiter/enclosure variables results in error
        if (fputcsv($handle, $csv, ',', '"') == 0) {
            echo 'Problem writing CSV file';
        }

        fclose($handle);
        //@codingStandardsIgnoreEnd
    }

    protected function contactsToCsvFile($csv, $filename) {
        $filename = Mage::getBaseDir('var') . DS . 'log' . DS . $filename;
        $header = array ('FIRSTNAME','LASTNAME','Email','EmailType');
        $this->outputCSV($filename, $header);

        foreach ($csv as $fields) {
            $this->outputCSV($filename, $fields);
        }
    }

    protected function sendFile($addressBookId, $filename) {

        $apiUsername = 'apiuser-a21367508cfb@apiconnector.com';
        $apiPassword = 'qwerty123';
        $url = 'https://api.dotmailer.com/v2/address-books/' . $addressBookId . '/contacts/import'; 
        
        $filename = Mage::getBaseDir('var') . DS . 'log' . DS . $filename;
        
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLAUTH_BASIC, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $apiUsername . ':' . $apiPassword);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: multipart/form-data')
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
        $args['file'] = curl_file_create(
            $filename, 'text/csv'
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
        $response = json_decode(curl_exec($ch));
    
        var_dump($response);
    }
}

