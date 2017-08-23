<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    require_once '../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
    //Command Line Args
    $fileName = $argv[1];
    $storeViewName = $argv[2];
    $webSiteName = $argv[3];
    $vendorFileName = $argv[4];
    
    //Global Vars
  	$parentProducts = array();
  	$parentChildren = array();
  	$vendors = array();
  	$count = 0;
  	$logFile = 'CV3_Product_Import.log';
  	echo "\nProcessing file: ".$fileName."\n";
  	
  	//Start Time Metrics
    $mtime = microtime();
    $mtime = explode(' ', $mtime);
    $mtime = $mtime[1] + $mtime[0];
    $starttime = $mtime;
    
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

	//Get all the vendor records from the CV3 Export file
	Mage::log($storeViewName."::Retrieving CV3 vendor export file records",null, $logFile);   
	//getVendors();
	  
    //create all the child option values based off sku name
    Mage::log($storeViewName."::Creating all fw_option attribute values based off export file records",null, $logFile);   
    createOptionValues();
  
    //Populate the format attribute option values
    Mage::log($storeViewName."::Creating all format attribute values based off export file records",null, $logFile);   
    //createFormatValues();

    //Create Products    
    //findParentProducts();
 
    Mage::log($storeViewName."::Creating all configurable products",null, $logFile);  
    //processParentChildren();
    

    Mage::log($storeViewName."::Creating all basic products",null, $logFile);   
    processProducts();
    
   	Mage::log($storeViewName."::Creating all related and upsell products",null, $logFile);  
    //processRelatedProducts();
    		
   	//Stop Time metrics
	$mtime = microtime();
	$mtime = explode(" ", $mtime);
	$mtime = $mtime[1] + $mtime[0];
	$endtime = $mtime;
	$totaltime = ($endtime - $starttime);
	Mage::log($storeViewName."::Total Process Time: ".$totaltime." seconds for ".$count." products",null, $logFile);   

	/**
	 * Create Basic Producs
	 * 
	 * 
	 */
    function processProducts()
    {
    	global $fileName;
    	global $logFile;
    	global $websiteId;
    	global $storeId;
    	global $storeViewName;
    	global $parentProducts;
    	global $count;
    	global $taxClassIds;

		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$col = array();
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
				if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data)); //header file has more empty csv fields that product lines
					
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
	
						if($data['ParentSKU'] == "") //not processing children
						{	
							if(!isset($parentProducts[$data['SKU']])) //not processing parents
							{
								if($data['SKU'] != "")
								{		
									$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
								
									if($product)
									{	
										addStore($product);
									}
									
									$product = initProduct($product, $data);
								
									try
									{

										$product->save();
				    					Mage::log($storeViewName."::Processed Product: ".$product->getSku(),null, $logFile);
				    					createStockItem($product); //creating stock item after product saved was a necessary order of steps
				    					
				    					//Download Links
										if (($data['Manufacturer'] == "W" or $data['Manufacturer'] == "3") && $data['HasElectronicDelivery'] == "TRUE") //Downloadable
										{
											$product->setDownloadableData(createDownloadableData($data));
											$product->setLinksPurchasedSeparately(0);
											$product->save();
											Mage::log($storeViewName."::Created Download Link(s) for : ".$product->getSku(),null, $logFile);
										}
										
										echo "Processed SKU:".$product->getSku()."\n";
										
										$count++;
									} 
									catch(Exception $e)
									{
									    Mage::log($storeViewName."::processProducts Error: ".$e->getMessage(),null, $logFile);
									}			
								}
							}
						}
					}	
				}
			}
    	}
    	fclose($handle);
    }

    /**
	 * Locate and store all products that are parents from csv file
	 * 
	 * 
	 */
    function findParentProducts()
    { 
    	global $fileName;
    	global $parentProducts;
    	global $parentChildren;
    	global $logFile;
    	
    	$childrenParentSkus = array();
    	$childProducts = array();
    	$parentSkus = array();
    	
		//first identify all parents
		$i = 0;
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    	    $col = array();
    	
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    		 	if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data)); //header file has more empty csv fields that product lines

					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
						
		
						if($data['ParentSKU'] != "")
			 			{
			 				if(!in_array($data['ParentSKU'], $parentSkus))
			 				{	
								$parentSkus[$i] = $data['ParentSKU']; //parent skus
			 				}
			 			}
					}
				}	  
				$i++;  	
    		}
    	
    	}
    	fclose($handle);
    	
    	
    	//Create Parent Product Objects
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    	   $col = array();
    	
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    		 	if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data));
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
						if(in_array($data['SKU'], $parentSkus))
						{
							$parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
							
							if($parentProduct)
							{	
								addStore($parentProduct);
								//$parentProduct = initProduct($parentProduct, $data);
								$parentProduct->setTypeId('configurable');	
								$parentProducts[$data['SKU']] = $parentProduct; //parent products
							}
							
							
						}	
					}
				}	    	
    		}	
    	}
    	fclose($handle);
    	
    	//Create Child Products
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    	   $col = array();
    	
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    		 	if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data));
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
						
						if($data['ParentSKU'] != "")
						{
							$childProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
							
							if($childProduct)
							{	
								addStore($childProduct);
								//$childProduct = initProduct($childProduct, $data);
								$childProducts[$data['SKU']] = $childProduct; 
								$childrenParentSkus[$data['SKU']] = $data['ParentSKU'];
							
							}
								

							
						}
					}
				}	    	
    		}	
    	}
    	fclose($handle);

    	//Loop throuh all the parent skus then find the matching childer skus
    	foreach ($parentSkus as $parentSku)
    	{
    		$children = array();
    		unset($children);
    		$i = 0;
    		
    		foreach ($childrenParentSkus as $key=>$value)
    		{
				if($value == $parentSku) //its a child associated with the parent at hand
				{
					$children[$i] = $childProducts[$key];
				}
				$i++;
    		}
    	
    		$parentChildren[$parentSku] = $children;
    	}
    }
       
     /**
	 * Create all of the configurable products with associated child skus
	 * 
	 * 
	 */
    function processParentChildren()
    {
    	global $fileName;
    	global $logFile;
    	global $websiteId;
    	global $storeId;
    	global $storeViewName;
    	global $parentProducts;
    	global $parentChildren;
    	global $count;
    	global $taxClassIds;
    	
    	//For CV3 products will be updating the fw_options attribute value with the name of the child product
    	$attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product','fw_options');

    	foreach($parentChildren as $parent=>$children)
    	{
    		$configAtt = array();
    		$configurableProductsData  = array();
    		$configurableAttributesData = array();
    	
    		$parentProduct = $parentProducts[$parent];

			 if($parentProduct)
			 {
	    		//get lowest child price to use for the parent
	    		$i = 0;
	    		
	    		foreach ($children as $child)
	    		{
	    			if($i == 0) 
	    			{
	    				$lowestPrice = $child->getPrice();
	    			}
	    			else 
	    			{
	    				if($child->getPrice() < $lowestPrice)
	    				{
	    					$lowestPrice = $child->getPrice();
	    				}
	    			}
	
					$i++;
				}

				$parentProduct->setPrice($lowestPrice);
				
		    	//get lowest child sepcial price to use for the parent
	    		$i = 0;
				foreach ($children as $child)
				{
	    			if($i == 0)
	    			{
	    				$lowestSpecialPrice = $child->getSpecialPrice();
	    			}
	    			else
	    			{
	    				if($child->getSpecialPrice() < $lowestSpecialPrice && $child->getSpecialPrice() != 0)
	    				{
	    					$lowestSpecialPrice = $child->getSpecialPrice();
	    				}
	    			}
	    			
					$i++;
				}
	
				if($lowestSpecialPrice != 0)
				{
					$parentProduct->setSpecialPrice($lowestSpecialPrice);
				}
					
	    		$i = 0;
	    		foreach ($children as $child)
	    		{	
	    			$optionId = attributeValueExists('fw_options', $child->getName());//name of child is also the option value
					$child->setFwOptions($optionId);
					$child->setVisibility(1);
					
					//set the drop ship vendor id for all children to be the same as the parent
					if($parentProduct->getDropShipVendorId() != "0" && $parentProduct->getDropShipVendorId() != "")
					{
						$child->setDropShipVendorId($parentProduct->getDropShipVendorId());
					}
	
					try
					{
						$child->save();
						
						assignTaxClass($child);
						
						
					$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child->getId());
	
    				if($stockItem->getId() == "")
    				{
    					createStockItem($child);
    				}
				
						Mage::log($storeViewName."::Processed Child Product: ".$child->getSku(),null, $logFile); 
						echo "Processed child SKU:".$child->getSku()."\n";
						$count++;
					} 
					catch(Exception $e)
					{
					    echo $e->getMessage();
					    Mage::log($storeViewName."::processParentChildren (create child):Error: ".$e->getMessage(),null, $logFile);
					}
					
					$deltaPrice = $child->getPrice() - $parentProduct->getPrice();
					
					if($parentProduct->getSpecialPrice() != 0 && $child->getSpecialPrice() != 0)
					{
						$deltaPrice = $child->getSpecialPrice() - $parentProduct->getSpecialPrice();
					}
							
					if($child->getAttributeSetId() == $parentProduct->getAttributeSetId())
					{
						$configurableProductsData[$child->getId()] = array('0'=>
							array(
								'attribute_id'	=> $attributeId,
								'label'			=> $child->getName(),
								'value_index'	=> $optionId,
								'is_percent'	=> 0,
								'pricing_value'	=> $deltaPrice
							)
						);
		
						$configAtt[$i] =  array(
							'attribute_id'		=> $attributeId, //The attribute id
							'label'				=> $child->getName(),
							'value_index'		=> $optionId, //The option id
							'is_percent'		=> 0,
							'pricing_value'		=> $deltaPrice				
						);
					}
					
					$i++;
				}

				$html_id = "config_super_product__attribute_".$count;
				//Create the configurable attributes data
				$configurableAttributesData = array(
					'0'	=> array(
						'id' 				=> NULL,
						'label'				=> 'Options', //optional, will be replaced by the modified api.php
						'position'			=> NULL,
						'values'			=> $configAtt,
						'attribute_id' 		=> $attributeId, //get this value from attributes api call
						'attribute_code'	=> 'fw_options', 
						'frontend_label'	=> '', //optional, will be replaced by the modifed api.php
						'html_id'			=> 'config_super_product__attribute_'.$html_id
					)
				);
				
				$testProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$parentProduct->getSku());
				

				//if(!$testProduct)
				//{
					//$parentProduct->setTypeId('configurable');
					$parentProduct->setConfigurableProductsData($configurableProductsData);
					$parentProduct->setConfigurableAttributesData($configurableAttributesData);
					$parentProduct->setCanSaveConfigurableAttributes(1);
				//}
						
				try 
				{	
					$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
					$conn->query("Delete from catalog_product_super_attribute where product_id = ".$parentProduct->getId());
				    			
					$parentProduct->save();
					
					$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentProduct->getId());
	
    				if($stockItem->getId() == "")
    				{
    					createStockItem($parentProduct);
    				}
					
					Mage::log($storeViewName."::Processed Parent Product: ".$parentProduct->getSku(),null, $logFile);  
					
					echo "Processed Parent SKU:".$parentProduct->getSku()."\n";
	
				}
				catch (Exception $e)
				{
					echo $e;
				    Mage::log($storeViewName."::processParentChildren (create parent):Error: ".$e->getMessage(),null, $logFile);
				}
	    			
				unset($configurableProductsData);
	    		unset($configAtt);
	    		unset($configurableAttributesData);
			 }
    	}
    }

    /**
	 * Assign all of the related and upsell skus to each sku
	 * 
	 * 
	 */
    function processRelatedProducts()
    {
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    	
    	//Process the Related and Upsell products...
	    if (($handle = fopen($fileName, "r")) !== FALSE)
	    {
	        $col = array();

    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    		 	if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data));
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);

			    		if($data['AdditionalProdSKUs'] != "")//Related SKUs
				 		{
				 			$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
				 			
				 			if($product)
				 			{
								$relatedSkus = explode(",", $data['AdditionalProdSKUs']);
								$position = 1;
								unset($param);
								foreach ($relatedSkus as &$sku)
								{
									$relatedProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
									if($relatedProduct)
									{
										$param[$relatedProduct->getId()] = array('position'=>$position);
										$position++;
									}
								}
								
			 					$product->setRelatedLinkData($param);
								
								try
								{
									$product->save();
			    					mage::log($storeViewName."::Relating SKU:".$sku." with SKU:".$data[0],null, $logFile);
								} 
								catch(Exception $e)
								{
								    Mage::log($storeViewName."::processRelatedProducts (related) Error: ".$e->getMessage(),null, $logFile);
								}
			 				}
				 		}
				 		
			    		if($data['RelatedProdSKUs'] != "")//Upsell SKUs
				 		{
				 			$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
				 			
		 					if($product)
		 					{
								$upSellSkus = explode(",", $data['RelatedProdSKUs']);
								unset($param);
								$position = 1;
								foreach ($upSellSkus as &$sku)
								{
									$upsellProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
									if($upsellProduct)
									{
										$param[$upsellProduct->getId()] = array('position'=>$position);
										$position++;
									}
								}	
	
	 							$product->setUpSellLinkData($param);
	
								try
								{
									$product->save();
			    					Mage::log($storeViewName."::Upselling SKU:".$sku." with SKU:".$data[0],null, $logFile);
								} 
								catch(Exception $e)
								{
								    Mage::log($storeViewName."::processRelatedProducts (upsett) Error: ".$e->getMessage(),null, $logFile);
								}
							}
				 		}
					}
				}	    	
    		}
   	 	}
   	 	
   	 	fclose($handle);
    }
 
    /**
	 * Create the links for downloadable products
	 * 
	 * 
	 */
    function createDownloadableData($data) {

    	$downloadableitems = array();
    	$links = array();

		for($i = 1; $i < 20; $i++)
		{
			$linkFieldName = 'ElectronicDeliveryLink'.$i;
			$titleFieldName = 'ElectronicDeliveryDescription'.$i;
			
			if(isset($data[$linkFieldName]) &&  $data[$linkFieldName] != "" && $data[$linkFieldName] != "0")
			{
				
				$links[$i] = array('title' => $data[$titleFieldName], 'link'=>$data[$linkFieldName]) ;
			}
		}

		$i = 0;

		foreach($links as $link) 
		{
	      	$downloadableitems['link'][$i]['is_delete'] = 0;
	        $downloadableitems['link'][$i]['link_id'] = 0;
	        $downloadableitems['link'][$i]['title'] = $link['title'];
	        $downloadableitems['link'][$i]['number_of_downloads'] = 0;
	        $downloadableitems['link'][$i]['is_shareable'] = 3;
	        $downloadableitems['link'][$i]['type'] = 'url';
	        $downloadableitems['link'][$i]['link_url'] = $link['link'];
			
			$i++;
		}

        return $downloadableitems;
}
    
    /**
	 * Create an option value for an attribute
	 * 
	 * @param $arg_attribute String ==> attribute name
	 * @param $arg_value String ==> option value
	 */
    function addAttributeValue($arg_attribute, $arg_value)
    {
    	global $logFile;
    	global $storeViewName;
    	
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);
        
        if(!attributeValueExists($arg_attribute, $arg_value))
        {
            $value['option'] = array($arg_value,$arg_value);
            $result = array('value' => $value);
            $attribute->setData('option',$result);

			try 
			{
				$attribute->save();
            	Mage::log($storeViewName."::Adding Option value: ".$arg_value,null, $logFile);
			} 
			catch (Exception $e) 
			{
				Mage::log($storeViewName."::addAttributeValue Error: ".$e->getMessage(),null, $logFile);
			}
        }
        
        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }
        return true;
    }
    
     /**
	 * Check to see if an option value exists for an attribute
	 * 
	 * 
	 */
    function attributeValueExists($arg_attribute, $arg_value)
    {
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);
        
        foreach($options as $option)
        {
        	
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }

        return false;
    }

     /**
	 * Create values for the custom attribute 'Option'
	 * 
	 * 
	 */   
    function createOptionValues()
    {  
    	global $fileName;
    	    	
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$col = array();

    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data)); //header file has more empty csv fields that product lines
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
						
						if($data['ParentSKU'] != "") //a child
				 		{
				 			addAttributeValue('fw_options', $data['ProdName']);
				 		}
					}
				}	
    		}
    		fclose($handle);
    	}
    }
    
    /**
	 * Create values for the custom attribute 'Format'
	 * 
	 * 
	 */
    function createFormatValues()
    {  
    	global $fileName;
    	
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$col = array();
    	
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    		 	if (empty($col)) 
				{
					$col = $data;
				} 
				else 
				{
					array_splice($col, count($data)); //header file has more empty csv fields that product lines
					if(count($col) == count($data)) 
					{
						$data = array_combine($col, $data);
						
						if($data['Custom3'] != "")
			 			{
			 				addAttributeValue('format', $data['Custom3']);
			 			}
					}
				}	    	
    		}
    		
    		fclose($handle);
    	}
    }

     /**
	 * Pre-Load all of the CV3 Vendor Ids that exist in the product export file
	 * 
	 * 
	 */
    function getVendors()
    {
    	global $vendorFileName;
    	global $vendors;
    	
		if (($handle = fopen($vendorFileName, "r")) !== FALSE)
    	{
    		$i = 0;
    		$firstRowSkipped = false;
    	
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    	    	if($firstRowSkipped)
	    		{
					$vendors[$data[1]] = $data[0];
	    		}
			    	
    	 		$firstRowSkipped = true;	
    		}
    		fclose($handle);
    	}
    }

     /**
	 * Initialize the Magento Product record - not saved though
	 * 
	 * @param $product Mage Product
	 * @param $data array ==> csv data row
	 */
    function initProduct($product, $data)
    {
		global $vendors;
		global $websiteId;
		global $storeId;
		global $taxClassIds;
		global $myEditionTypes;
		
    	if(!$product)
    	{
    		$initProduct = new Mage_Catalog_Model_Product();
    		$websiteIds[0] = $websiteId;
    		$storeIds[0] = $storeId;
    		$initProduct->setWebsiteIDs($websiteIds);
			$initProduct->setStoreIDs($storeIds);
    	}
    	else
    	{
    		$initProduct = clone $product;
    		//echo "Existing Prod:".$initProduct->getSku()."\n";
    	}
			
		if($data['Manufacturer'] == "T") //Virtual Product
		{
			$initProduct->setTypeId('virtual');
		}
		else if (($data['Manufacturer'] == "W" or $data['Manufacturer'] == "3") && $data['HasElectronicDelivery'] == "TRUE") //Downloadable
		{
			$initProduct->setTypeId('downloadable');	
		}
		else 
		{
			$initProduct->setTypeId('simple');	
		}

		//Attribute Set Id
		$attrSetName;
		switch($data['Manufacturer'])
		{
			case "A":
			case "1":
			case "2":
			case "3":
				$attrSetName = "Default";
				break;
			case "B":
			case "D":
			case "E":
			case "F":
			case "H":
			case "I":
			case "J":
			case "K":
			case "L":
			case "M":
			case "N":
			case "P":
			case "Q":
			case "S":
			case "U":
			case "X":
				$attrSetName = "Book";
				break;
			case "C":
				$attrSetName = "CD";
				break;
			case "G":
			case "Y":
				$attrSetName = "DVD";
				break;
			case "O":
				$attrSetName = "Subscription";
				break;
			case "R":
				$attrSetName = "Premium";
				break;
			case "T":
				$attrSetName = "Streaming";
				break;    
			case "V":
				$attrSetName = "VHS";
				break;  
			case "W":
				$attrSetName = "Download";
				break;
			case "Z":
				$attrSetName = "Magazine";
				break; 
			default:
				$attrSetName = "Default";
				break;	        				
		}
		
		//Load Attribute Set Id
		$entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
		$attributeSetName   = $attrSetName;
		$attributeSetId     = Mage::getModel('eav/entity_attribute_set')
			->getCollection()
			->setEntityTypeFilter($entityTypeId)
			->addFieldToFilter('attribute_set_name', $attributeSetName)
			->getFirstItem()->getAttributeSetId();
				
		$initProduct->setAttributeSetId($attributeSetId);
           	
		//SKU
		$initProduct->setSku($data['SKU']);		    	   
    	   	
		//VISTA Edition Type
		$initProduct->setVistaEditionType($data['Manufacturer']);
    	   	
		//Format
		$format_val = attributeValueExists('format', $data['Custom3']);//log for not exist
		$initProduct->setFormat($format_val);
    	   	
		//Name
		$initProduct->setName($data['ProdName']);
    	   	
		//Sub Title
		$initProduct->setSubTitle($data['Custom5']);
    	   	
		//Description
		$initProduct->setDescription($data['ProdDescription']);
    	   
		//Short Description
		if($data['DescriptionHeader'] != "")
		{
			$shortDesc = $data['DescriptionHeader'];
    	}
		else 
		{
			$shortDesc = strip_tags(substr($data['ProdDescription'], 0, 100)); 	
		}
    	   	
		$initProduct->setShortDescription($shortDesc);
			    	   	
		//Url Key
		if($data['ProductURLName'] == "")
		{
			$urlKey = $initProduct->getName();
			Mage::helper('catalog/product_url')->format($urlKey);
			preg_replace('#[^0-9a-z]+#i', '-',$urlKey);
			strtolower($urlKey);
			trim($urlKey, '-');
			$initProduct->setUrlKey($urlKey);
		}
		else
		{
			$initProduct->setUrlKey($data['ProductURLName']);
		}
 	
		//File/Trim Size
		$initProduct->setFileTrimSize($data['Custom2']);
    	   	
		//Special Price
		if($data['SpecialPrice1'] != "" && $data['SpecialPrice1'] != "0")
		{
			if($data['IsSpecialOngoing'] == "TRUE")	
			{
				$initProduct->setSpecialPrice($data['SpecialPrice1']);
			}
		}
    	   	
		//Brand
		$initProduct->setBrand($data['Brand']);
		
		//Drop Ship Vendor
		if($data['VendorID'] != "" && $data['VendorID'] != "0")
		{
			$vendorCol = Mage::getModel("dropship/vendor")->getCollection();
			foreach ($vendors as $key=>$value)
			{
				if($key == $data['VendorID'])
				{
					foreach ($vendorCol as $vendor)
					{
						if($vendor->getName() == $value)
						{
							$vendor_val = attributeValueExists('dropship_vendor_id', $vendor->getName());
							$initProduct->setDropshipVendorId($vendor->getId());
						}
					}
				}
			}
		}
    	   	
		//Drop Ship Message
		$initProduct->setDropShipMessage($data['Custom11']);
		
			    	   	
		//Drop Ship SKU
		$initProduct->setDropShipSku($data['Custom12']);
    	   	
		//Special Instructions
		$initProduct->setSpecialInstructions($data['Custom8']);
	    	   	
		//Special Requirements
		$initProduct->setSpecialRequirements($data['Custom9']);
    	   	
		//Additional Content
		$initProduct->setAdditionalContent($data['Custom10']);
			    	   	
		//Additional Feature
		$initProduct->setAdditionalFeature($data['Custom4']);
    	   	
		//US Ship Restricted
		if(strstr($data['Custom14'], "This product cannot be shipped outside of the United States") != false )
		{
			$initProduct->setUsShipRestrict(1);
		}
			    	   	
		//US & Canada Ship Restricted
		if(strstr($data['Custom14'], "This product cannot be shipped outside of the United States and Canada") != false )
		{
			$initProduct->setCanadaUsShipRestrict(1);
		}
    	   	
		//Can Free Ship
		if($data['VendorID'] == "" || $data['VendorID'] == "0")
		{
			switch($data['Manufacturer'])
			{
				case "A":
				case "B":
				case "C":
				case "D":
				case "E":
				case "F":
				case "G":
				case "H":
				case "I":
				case "J":
				case "K":
				case "L":
				case "M":
				case "N":
				case "P":
				case "Q":
				case "S":
				case "U":
				case "V":
				case "X":
				case "Y":
				case "Z":
					$initProduct->setCanFreeShip(1);
					break;         				
			}
		}

    		
		//Minimum Qty
		$initProduct->setMinimumQuantity($data['MinimumQuantity']);
    	   	
		//Maximum Qty
		$initProduct->setMaximumQuantity($data['MaximumQuantity']);
    	   	
		//Author Speaker Editor
		$initProduct->setAuthorSpeakerEditor($data['AltID']);
    	   	
		//Meta Title
		$metaTitle;
		if($data['MetaTitle'] != "")
		{
			$metaTitle = $data['MetaTitle'];
		}
		else
		{
			$metaTitle = $data['ProdName'];
		}
		$initProduct->setMetaTitle($metaTitle);
    	   	
		//Meta Keywords
		if($data['MetaKeywords'] != "")
		{
			$initProduct->setMetaKeywords($data['MetaKeywords']);
		}
    	   	
		//Meta Description
		$metaDescription;
		if($data['MetaDescription'] != "")
		{
			$metaDescription = $data['MetaDescription'];
		}
		else
		{
			$metaDescription =  strip_tags(substr($data['ProdDescription'], 0, 160)); 	
		}
		$initProduct->setMetaDescription($metaDescription);
  		$initProduct->setPrice($data['RetailPrice1']);
		$initProduct->setStatus(1);
		
    	$editionTypeId;
		foreach ($myEditionTypes as $editionType)
		{
			if($editionType['label'] == $data['Manufacturer'])
			{
				$editionTypeId = $editionType['value'];
			}						
		}
		
		if(isset($editionTypeId))
		{
			$initProduct->setVistaEditionType($editionTypeId);
		}
		
       $initProduct->setTaxClassId($taxClassIds["Taxable Goods"]);
    	
    	if($initProduct->getTypeId() == "downloadable")
    	{
			$initProduct->setTaxClassId($taxClassIds["Downloads"]);
    	}
    	
    	if($initProduct->getTypeId() == "virtual")
    	{
			$initProduct->setTaxClassId(0);
    	}
	
		return 	$initProduct;		
    }

     /**
	 * If Product already exists add the current website being imported to its website ids list
	 * 
	 * @param $product Mage Product
	 */
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

     /**
	 * Create,Save a Stock Item record
	 * 
	 * @param $product Mage Product
	 */
    function createStockItem($product)
    {
    	global $storeViewName;
    	global $logFile;   	
    	
		try
		{
	    	$stockItem = Mage::getModel('cataloginventory/stock_item');
			$stockItem->assignProduct($product);
			$stockItem->setData('stock_id', 1);
			$stockItem->setData('use_config_manage_stock', 0);
			$stockItem->setData('use_config_min_sale_qty', 0);
			$stockItem->setData('use_config_backorders', 0);
			$stockItem->save();
			echo "Created Stock Item for ".$product->getSku()."\n";
		} 
		catch(Exception $e)
		{
			Mage::log($storeViewName."::createStockItem Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
		}
    }

    /**
	 * Assign the tax class to a product
	 * 
	 * @param $product Mage Product
	 */
    function assignTaxClass($product)
    {
    	global $storeViewName;
    	global $taxClassIds;
    	global $logFile;
    	
    	$product->setTaxClassId($taxClassIds["Taxable Goods"]);
    	
    	if($product->getTypeId() == "downloadable")
    	{
			$product->setTaxClassId($taxClassIds["Downloads"]);
    	}
    	
    	if($product->getTypeId() == "virtual")
    	{
			$product->setTaxClassId(0);
    	}
    	  	
    	try
		{
			$product->save();
		} 
		catch(Exception $e)
		{
			Mage::log($storeViewName."::assignTaxClass Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
		}
    }
    
    ?>
