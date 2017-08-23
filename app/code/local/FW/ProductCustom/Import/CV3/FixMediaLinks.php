<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

 	$productCollection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('type_id', array('eq' => 'downloadable'))->load();

	$i = 0;
 	foreach ($productCollection as $product)
 	{
 		$skuProduct = Mage::getModel('catalog/product')->load($product->getId());

		$productType = new Mage_Downloadable_Model_Product_Type;
		$links = $productType->getLinks($product);

 				foreach ($links as $link)
	 			{
	 				$linkUrl = $link->getLinkUrl();
					if(strstr($linkUrl, 'media.fwpublications.com') != false)
					{
						$linkUrl = str_replace('media3.fwpublications.com', 'media2.fwpublications.com', $linkUrl);
						$link->setLinkUrl($linkUrl);
						$link->save(); 
						echo "Updated sku:".$skuProduct->getSku()."\n";				
					}
	 			}	
 	}
 
    ?>