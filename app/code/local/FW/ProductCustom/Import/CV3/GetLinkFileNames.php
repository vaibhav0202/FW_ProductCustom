<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
	$logFile = Mage::getBaseDir() . '/app/code/local/FW/ProductCustom/Import/UrlExport.txt';
	
 	$productCollection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('type_id', array('eq' => 'downloadable'))->load();

	$i = 0;
	$fh = fopen($logFile, 'w'); 
 	foreach ($productCollection as $product)
 	{
 		$skuProduct = Mage::getModel('catalog/product')->load($product->getId());

		$productType = new Mage_Downloadable_Model_Product_Type;
		$links = $productType->getLinks($product);

		foreach ($links as $link)
		{
			echo "Link Url:".$link->getLinkUrl()."\n";		
			
			//if(strstr($link->getLinkUrl(), 'media.fwpublications.com') != false)
			//{
				fwrite($fh, $product->getId()."|".$product->getSku()."|".$link->getLinkUrl()."\r\n"); 				
			//}
		}
 	}
 	fclose($fh);
 
    ?>
