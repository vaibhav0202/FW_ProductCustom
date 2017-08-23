<?php
// include the Mage engine
require_once '../../../../../../app/Mage.php';
Mage::app();

//Command Line Args
$activityLog = 'CustomerDataDelete.log';
$errorLog = 'CustomerDataDeleteError.log';

Mage::register('isSecureArea', true);

$startTime = time();
deleteCustomersWithNoStore();
Mage::log("Cumulative Time Elapsed For Customers:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);


function deleteCustomersWithNoStore($limit=null) {
    global $activityLog, $errorLog;

    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');
    $sql = 'SELECT entity_id FROM customer_entity WHERE website_id is null';
    if($limit != null) {
        $sql .= " limit {$limit}";
    }

    $rows = $readonce->fetchAll($sql);
    $customerCount = 0;

    foreach ($rows as $row)
    {
        try{
            $entity_id = $row['entity_id'];
            $customer = Mage::getModel('customer/customer')->load($entity_id);
            $customer->delete();
            $customerCount++;

            Mage::log("Deleted Customer:".$entity_id . ":count:" . $customerCount,null, $activityLog);

        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH CUSTOMER ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}


