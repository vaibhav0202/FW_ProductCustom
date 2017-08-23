<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //Command Line Args
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    processProducts();

    function processProducts()
    {
    	$product = Mage::getModel('catalog/product')->load(67760);

    	$i = 0;
    	while(true)
    	{
			try
			{
				$product->setSku($i);
				$product->save();
    			echo "Saved:".$product->getSku()."\n";;
			} 
			catch(Exception $e)
			{
			    echo $e->getMessage()."\n";
			}	
							
    	$i++;
    	}
    }
    ?>
