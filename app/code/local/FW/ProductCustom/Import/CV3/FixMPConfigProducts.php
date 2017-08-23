<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
    //Command Line Args
    $webSiteName = $argv[1];
    $storeViewName = $argv[2];
     	$logFile = 'ProductFix_Import.log';

	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
	
	$obj = new Mage_Catalog_Block_Product_View_Media();							
	$product = Mage::getModel('catalog/product')->load('61198');
	
	//Load Tax Classes to be used in Product Creation
	$taxClassIds = array();
    $taxClasses = Mage::getModel('tax/class')->getCollection();
	
	foreach ($taxClasses as $taxClass)
	{
		$taxClassIds[$taxClass->getClassName()] = $taxClass->getId();
	}
 
   	$editionTypes = new FW_ProductCustom_Model_Resource_Eav_Source_VistaEditionCodes();
	$editionTypes->getAllOptions();
	$myEditionTypes = $editionTypes->getAllOptions();
    
    //Load Store and Website Ids
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
	$storeId = $store->getId();
	
	$website = Mage::getModel('core/website')->load($webSiteName, 'name');
	$websiteId = $website->getId();


    processProducts();


    function processProducts()
    {
    	global $logFile;
    	global $websiteId;
    	global $storeId;
    	global $storeViewName;
    	global $count;
    	global $taxClassIds;

		
		$products = Mage::getModel('catalog/product')->getCollection()->addWebsiteFilter($websiteId);
		
		foreach ($products as $partialProd)
		{

			$product = Mage::getModel('catalog/product')->load($partialProd->getId());
	    	if($product->getTypeId() == "configurable")
	    	{
				$product->setTaxClassId($taxClassIds["Taxable Goods"]);
				$product->save();
				echo "Saved:".$product->getSku()."\n";
	    	}
	    	
	    	//addStore($product);
	    	
		}
			
    }
    
     function addStore($product)
    {
    	global $storeViewName;
    	global $logFile;
    	global $websiteId;
    	global $storeId;
    	
		$websiteAssociated = FALSE;
		$storeAssociated = FALSE;
				
		$existingWebsiteIds = $product->getWebsiteIds();
		
		foreach ($existingWebsiteIds as &$aWebsiteId)
		{
			if($websiteId == $aWebsiteId)
			{
				$websiteAssociated = TRUE;
			}
		}
			
		$existingStoreIds = $product->getStoreIds();
		foreach ($existingStoreIds as &$aStoreId)
		{
			if($storeId == $aStoreId)
			{
				$storeAssociated = TRUE;
			}
		}
						
		if(!$websiteAssociated)
		{
			$existingWebsiteIds[] = count($existingWebsiteIds) + 1;
			$existingWebsiteIds[count($existingWebsiteIds) - 1] = $websiteId;
			
			try 
			{
				$product->setWebsiteIDs($existingWebsiteIds)->save();
				Mage::log($storeViewName."::Updated Product (website id) : ".$product->getSku(),null, $logFile);
			} 
			catch (Exception $e) 
			{
				Mage::log($storeViewName."::addStore (website id) Error: ".$e->getMessage(),null, $logFile);
			}

		}
		if(!$storeAssociated)
		{
			$existingStoreIds[] = count($existingStoreIds) + 1;
			$existingStoreIds[count($existingStoreIds) - 1] = $storeId;
			
			try 
			{
				$product->setStoreIDs($existingStoreIds)->save();
				Mage::log($storeViewName."::Updated Product (store id) : ".$product->getSku(),null, $logFile);
			} 
			catch (Exception $e) 
			{
				Mage::log($storeViewName."::addStore (store id) Error: ".$e->getMessage(),null, $logFile);
			}
		}
    }
    
    ?>
