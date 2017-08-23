<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
	//Global Vars
    $parentSkuArray = array(); 
    $fileName = $argv[1];
    $webSiteName = $argv[2];

    echo "\nProcessing file: ".$fileName."\n";
    
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    //Load Store and Website Ids
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
	$storeId = $store->getId();
	
	$website = Mage::getModel('core/website')->load($webSiteName, 'name');
	$websiteId = $website->getId();
	

    Mage::log($storeViewName."::Creating all basic products",null, $logFile);   
    processProducts();
    

	Mage::log($storeViewName."::Total Process Time: ".$totaltime." seconds for ".$count." products",null, $logFile);   
    
	/**
	 * Create Basic Products
	 * 
	 * 
	 */
	function processProducts()
    {
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    	global $websiteId;
	
		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
				{
					unset($product);
					if ($data[8] != "" || $data[10] != "")
					{
						$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0].$data[6]);
					}
					elseif($data[13] == "104" && $data[8] == "" && $data[10] == "")
					{	
						if(substr($data[0], strlen($data[0]) - 1, 1) == "/")
						{
							$tempSku = substr($data[0], 0, strlen($data[0]) - 1);
						}
						else
						{
							$tempSku = $data[0];
						}
								
						$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$tempSku);	
					}
					else 
					{
						$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);
					}

					if($product)
					{	
						if($product->getStatus() == 1 &&  $product->getVisibility() != 0 &&  $product->getVisibility() != 1)
						{
							
							$websiteAssociated = false;
							$existingWebsiteIds = $product->getWebsiteIds();
		
							foreach ($existingWebsiteIds as $theWebsiteId)
							{
								if($websiteId == $theWebsiteId)
								{
									$websiteAssociated = TRUE;
								}
							}
		
							if($websiteAssociated == true)
							{
								$urlKey = "p-".$data[14]."-".$data[20];
								$product->setUrlKey($urlKey);
		
								try
								{
									$product->save();
							    	Mage::log("Created Product: ".$product->getSku(),null, $logFile);
					
							    	echo "Fixed SKU:".$product->getSku()."\n";
								} 
								catch(Exception $e)
								{
								    Mage::log("processProducts Error: ".$e->getMessage(),null, $logFile);
								}
							}
					
						}
						
					}	
			
				}
			$firstRowSkipped = true;
			}
    	}
    	fclose($handle);
    }
    
  
?>