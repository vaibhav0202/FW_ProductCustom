<?php
// include the Mage engine
require_once '../../../../../../app/Mage.php';
Mage::app();

//Command Line Args
$activityLog = 'ProductDataDelete.log';
$errorLog = 'ProductDataDeleteError.log';

Mage::register('isSecureArea', true);

$startTime = time();
deleteProductsFromStore(1, 35);
Mage::log("Cumulative Time Elapsed For Product Delete:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);

$startTime = time();
deleteProductsWithNoStore();
Mage::log("Cumulative Time Elapsed For Product Delete:".round(abs(time() - $startTime) /60,2)." minutes\r\n",null,$activityLog);


function deleteProductsFromStore($storeId, $excludeStoreId) {
    global $activityLog, $errorLog;

    $productCount = 0;
    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');
    $sql = 'select cpw.product_id, cs.store_id
            from catalog_product_website cpw
            inner join core_store cs on cs.website_id = cpw.website_id
            Where cs.store_id in (' . $storeId . ')
            and cpw.product_id not in
            (
                select cpw2.product_id
                from catalog_product_website cpw2
                inner join core_store cs2 on cs2.website_id = cpw2.website_id
                where cs2.store_id in (' . $excludeStoreId . ')
            )';

    $rows = $readonce->fetchAll($sql);

    if(count($rows) == 0){
        $productsFound = false;
    }

    foreach ($rows as $row)
    {
        try{
            $entity_id = $row['product_id'];
            $store_id = $row['store_id'];
            $product = Mage::getModel('catalog/product')->load($entity_id);
            $product->delete();
            $product = null;
            $productCount++;

            Mage::log("Deleted Product:".$entity_id . ":store id:" . $store_id . ":count:" . $productCount,null, $activityLog);
        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH PRODUCT ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}

function deleteProductsWithNoStore($limit=null) {
    global $activityLog, $errorLog;
    $productCount = 0;
    $readonce = Mage::getSingleton('core/resource')->getConnection('core_read');

    $sql = "select entity_id from catalog_product_entity
				where entity_id not in (select product_id from catalog_product_website)";
    if($limit != null) {
        $sql .= " limit {$limit}";
    }

    $rows = $readonce->fetchAll($sql);

    if(count($rows) == 0){
        $productsFound = false;
    }

    foreach ($rows as $row)
    {
        try{
            $productsFound = true;
            $entity_id = $row['entity_id'];
            $product = Mage::getModel('catalog/product')->load($entity_id);
            $product->delete();
            $product = null;
            $productCount++;

            Mage::log("Deleted Product:".$entity_id . ":count:" . $productCount,null, $activityLog);
        }
        catch(Exception $e)
        {
            Mage::log("ERROR WITH PRODUCT ID: ". $entity_id . ", ERROR MESSAGE =>" . $e->getMessage(),null, $errorLog);
        }
    }
}