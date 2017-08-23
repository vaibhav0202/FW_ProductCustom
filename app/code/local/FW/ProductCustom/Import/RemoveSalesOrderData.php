<?php
// include the Mage engine
require_once '../../../../../../app/Mage.php';
Mage::app();

//Command Line Args
$activityLog = 'StoreDataDelete.log';
$errorLog = 'StoreDataDeleteError.log';

Mage::register('isSecureArea', true);

$startTime = time();
deleteOrdersWithNoStore();
Mage::log("Cumulative Time Elapsed For Orders:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);


function deleteOrdersWithNoStore($limit=null) {
    global $activityLog, $errorLog;

    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');

    $sql = "select entity_id from sales_flat_order
				where store_id is null";

    if($limit != null) {
        $sql .= " limit {$limit}";
    }

    $rows = $readonce->fetchAll($sql);
    $orderCount = 0;
    foreach ($rows as $row)
    {
        $quoteRecordsFound = true;
        $entity_id = $row['entity_id'];

        try{
            $salesOrder = Mage::getModel('sales/order')->load($entity_id);
            $salesOrder->delete();
            $salesOrder=null;
            $orderCount++;

            Mage::log("Deleted Sales Order: " . $entity_id . ":count:" . $orderCount,null, $activityLog);
        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH ORDER ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}
