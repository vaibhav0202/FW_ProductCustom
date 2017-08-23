<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //Command Line Args
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    processProducts();

    function processProducts()
    {
    	while(true)
    	{
    		$readonce = Mage::getSingleton('core/resource')->getConnection('core_read');
    		$rows = $readonce->fetchAll('SELECT COUNT(*) AS _cnt FROM sales_flat_order_item sfoi INNER JOIN catalog_product_entity cpe ON cpe.entity_id = sfoi.product_id WHERE cpe.type_id = \'downloadable\' AND sfoi.item_id NOT IN (SELECT order_item_id FROM downloadable_link_purchased)');
        	foreach ($rows as $row)
            {
            		echo "Count:".$row['_cnt']."\n";
                       
            }

    	}
    }
    ?>
