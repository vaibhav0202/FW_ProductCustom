<?php
// include the Mage engine
require_once '../../../../../../app/Mage.php';
Mage::app();

//Command Line Args
$activityLog = 'SalesQuoteDataDelete.log';
$errorLog = 'SalesQuoteDataDeleteError.log';

Mage::register('isSecureArea', true);


$startTime = time();
deleteSalesQuoteWithNoStore();
Mage::log("Cumulative Time Elapsed For Quotes:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);

function deleteSalesQuoteWithNoStore($limit=null) {
    global $activityLog, $errorLog;
    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');
    $quoteCount = 0;

    $sql = "select entity_id from sales_flat_quote
				where store_id=0";

    if($limit != null) {
        $sql .= " limit {$limit}";
    }

    $rows = $readonce->fetchAll($sql);

    foreach ($rows as $row)
    {
        $entity_id = $row['entity_id'];

        try{
            $salesQuote = Mage::getModel('sales/quote')->setStoreId(0)->load($entity_id);
            $salesQuote->delete();
            $quoteCount++;

            Mage::log("Deleted Sales Quote: " . $entity_id . ":count:" . $quoteCount,null, $activityLog);
        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH QUOTE ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}


