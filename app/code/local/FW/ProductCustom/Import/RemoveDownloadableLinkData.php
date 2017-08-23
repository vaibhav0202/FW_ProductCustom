<?php
// include the Mage engine
require_once '../../../../../../app/Mage.php';
Mage::app();

//Command Line Args
$activityLog = 'DownloadableLinkDataDelete.log';
$errorLog = 'DownloadableLinkDeleteError.log';

Mage::register('isSecureArea', true);

$startTime = time();
deleteDownloadableLinkPurchasesWithNoOrder();
Mage::log("Cumulative Time Elapsed For Downloadables:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);

function deleteDownloadableLinkPurchasesWithNoOrder($limit=null) {
    global $activityLog, $errorLog;

    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');
    $sql = 'SELECT purchased_id FROM downloadable_link_purchased WHERE order_id is null';
    if($limit != null) {
        $sql .= " limit {$limit}";
    }
    $rows = $readonce->fetchAll($sql);
    $purchasedLinkCount = 0;
    foreach ($rows as $row)
    {
        try{
            $entity_id = $row['purchased_id'];

            $downloadablePurchasedLink = Mage::getModel('downloadable/link_purchased')->load($entity_id);
            $downloadablePurchasedLink->delete();
            $purchasedLinkCount++;

            Mage::log("Deleted Downloadable Link Purchased:".$entity_id . ":count:" . $purchasedLinkCount,null, $activityLog);

        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH PURCHASED LINK ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}



