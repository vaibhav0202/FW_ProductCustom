<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    $fileName = $argv[1];
    
	global $fileName;
		$count = 0;
		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		while (($data = fgetcsv($handle, 10000, "|")) !== FALSE)
    		{
				$product = Mage::getModel('catalog/product')->load($data[0]);
				echo "processing id:".$data[0]."\n";
				if($product)
				{
					$productType = new Mage_Downloadable_Model_Product_Type;
					$links = $productType->getLinks($product);

 					foreach ($links as $link)
	 				{
	 					if($link->getLinkUrl() == $data[1])
	 					{
	 						$link->setLinkUrl($data[2]);
 							$link->save();
 							
 							echo "Saved Product:".$product->getSku()." with link:".$link->getLinkUrl()."\n";
	 					}
	 				}
				}				
			}
    	}
    	fclose($handle);

    ?>
