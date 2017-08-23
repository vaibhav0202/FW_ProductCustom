<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
  	echo "\nProcessing file: ".$fileName."\n";
  	
    
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
 	$productCollection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('type_id', array('eq' => 'downloadable'))->load();
 	
 	foreach ($productCollection as $product)
 	{
 		$skuProduct = Mage::getModel('catalog/product')->load($product->getId());
 		unset($linksToDelete);
		$productType = new Mage_Downloadable_Model_Product_Type;
		$links = $productType->getLinks($product);


 			if(count($links) > 1)
 			{
 				echo "Prod id:".$product->getId()."\n";
 				foreach ($links as $link)
	 			{
	 				if(!isset($linksToDelete[$link->getId()]))
	 				{
	 					foreach ($links as $link2)
			 			{
			 				if($link2->getId() != $link->getId())
			 				{
				 				if($link2->getLinkUrl() == $link->getLinkUrl())
				 				{
				 					$linksToDelete[$link2->getId()] = $link2;
				 				}
			 				}
			 			}
	 				}
		 	
	 				foreach ($linksToDelete as $id=>$linkToDelete)
	 				{
	 					echo "To delete Link URL:".$id."\n";
	 					$linkToDelete->delete();
	 				}
	 			}
	 			
 				foreach ($links as $link)
	 			{
	 				if(!isset($linksToDelete[$link->getId()]))
	 				{
	 					if($link->getLinkUrl() == "0")
	 					{
	 						$linksToDelete[$link->getId()] = $link;
	 					}
	 				}
		 	
	 				foreach ($linksToDelete as $id=>$linkToDelete)
	 				{
	 					echo "0 Link URL:".$id."\n";
	 					$linkToDelete->delete();
	 				}
	 			}
 			}

 	}
 
    ?>
