<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //Command Line Args
    $fileName = $argv[1];
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    processProducts();

    function processProducts()
    {
    	global $fileName;
		$count = 0;
		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
				{
					$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);

					if($product)
					{
						if($product->getDropShipMessage() != $data[1])
						{
							$product->setDropShipMessage($data[1]);
							$product->save();
							$count++;
							echo "Saved product ".$product->getSku()."\n";
						}	
					}				
				}
			$firstRowSkipped = true;
			}
    	}
    	fclose($handle);
    }
    ?>
