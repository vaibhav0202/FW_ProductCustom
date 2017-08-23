<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
  	$webSiteName = "Store.MarthaPullen.com";
    $website = Mage::getModel('core/website')->load($webSiteName, 'name');
	$websiteId = $website->getId();
    
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

 	$productCollection = Mage::getModel('catalog/product')->getCollection()->addWebsiteFilter($websiteId)->addAttributeToFilter('type_id', array('eq' => 'downloadable'))->load();

	$i = 0;
 	foreach ($productCollection as $product)
 	{
 		$skuProduct = Mage::getModel('catalog/product')->load($product->getId());

		$productType = new Mage_Downloadable_Model_Product_Type;
		$links = $productType->getLinks($product);

 				echo "Prod id:".$product->getId()."\n";
 				foreach ($links as $link)
	 			{
	 				
	 				$fixedUrl = substr($link->getLinkUrl(),strripos($link->getLinkUrl(), "/"));
	 				$fixedUrl = "http://s3.amazonaws.com/marthapullen/downloads".$fixedUrl;
	 				$link->setLinkUrl($fixedUrl);
	 				$link->save();
	 			}
	 			echo "Updated sku:".$skuProduct->getSku()."\n";

 			
 	}
 
    ?>
