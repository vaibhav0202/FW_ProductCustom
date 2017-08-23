<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
	//Global Vars
    $parentSkuArray = array(); 
    $fileName = $argv[1];
    $storeViewName = $argv[2];
    $webSiteName = $argv[3];
    $logFile = 'MP_Product_Import.log';
    $count = 0;
    
    //Load Tax Classes to be used in Product Creation
    $taxClassIds = array();
    $taxClasses = Mage::getModel('tax/class')->getCollection();
	
	foreach ($taxClasses as $taxClass)
	{
		$taxClassIds[$taxClass->getClassName()] = $taxClass->getId();
	}
	
    echo "\nProcessing file: ".$fileName."\n";
    
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    
    //Start Time Metrics
    $mtime = microtime();
    $mtime = explode(' ', $mtime);
    $mtime = $mtime[1] + $mtime[0];
    $starttime = $mtime;

    //Load Store and Website Ids
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
	$storeId = $store->getId();
	
	$website = Mage::getModel('core/website')->load($webSiteName, 'name');
	$websiteId = $website->getId();
	
    //create all the color/size option values based off sku name
    Mage::log($storeViewName."::Creating all color and size attribute values based off export file records",null, $logFile);    
    createsizeColorValues();

    //Create Products
    Mage::log($storeViewName."::Creating all configurable products",null, $logFile);  
    processSizeColorProducts();

    Mage::log($storeViewName."::Creating all basic products",null, $logFile);   
    //processProducts();
    
    Mage::log($storeViewName."::Creating all download products",null, $logFile);  
    //processDownLoadLinks();
    
   	Mage::log($storeViewName."::Creating all upsell products",null, $logFile);  
    //processUpsellProducts();

    //Stop Time metrics
	$mtime = microtime();
	$mtime = explode(" ", $mtime); 
	$mtime = $mtime[1] + $mtime[0];
	$endtime = $mtime;
	$totaltime = ($endtime - $starttime);
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
    	global $count;
    	
		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
				{
					if($data[8] == "" && $data[10] == "") //no color/size children
					{	
						if($data[0] != "" && $data[13] != "104")
						{
							$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0].$data[6]);
	
							if(!$product)
							{	
								$product = CreateNewProduct($data);	
							}
							else
							{
								 addStore($product);
							}

							try
							{
								$product->save();
						    	Mage::log("Created Product: ".$product->getSku(),null, $logFile);
						    	
						    	createStockItem($product);
						    	assignTaxClass($product);
						    	echo "Created SKU:".$product->getSku()."\n";
						    	$count++;
							} 
							catch(Exception $e)
							{
							    Mage::log("processProducts Error: ".$e->getMessage(),null, $logFile);
							}	
						}
					}				
				}
			$firstRowSkipped = true;
			}
    	}
    	fclose($handle);
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
    
    /**
	 * Assign all of the upsell skus to each sku
	 * 
	 * 
	 */
    function processUpsellProducts()
    {
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    
    	$productSkuIds = array();
    	
    	//First Tie the SKU to the MP Product Id
	    if (($handle = fopen($fileName, "r")) !== FALSE)
	    {
	    	$firstRowSkipped = false;
	    	while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
	    	{
	    		if($firstRowSkipped)
				{
					$productSkuIds[$data[14]] = $data[0];
				}
				
				$firstRowSkipped = true;	
			}
   	 	}
   	 	
   	 	fclose($handle);
   	 	
    	//Process the Upsell products...
	    if (($handle = fopen($fileName, "r")) !== FALSE)
	    {
	    	$i = 0;
	    	$firstRowSkipped = false;
	    	while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
	    	{
	    		if($firstRowSkipped)
				{
					if($data[12] != "")//Upsell SKUs
			 		{
			 			$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);
			 			
	 					if($product)
	 					{
							$relatedSkuIds = explode("|", $data[12]);
							$position = 1;
							unset($param);
							foreach ($relatedSkuIds as &$aSkuId)
							{
								if($productSkuIds[$aSkuId] != "")
								{
									echo "Related SKU:".$productSkuIds[$aSkuId]." for sku ".$product->getSku()."\n";
									$sku = $productSkuIds[$aSkuId];
								
									if($sku != "")
									{
										$relatedProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
										if($relatedProduct)
										{
											$param[$relatedProduct->getId()] = array('position'=>$position);
											$position++;
										}
									}
								}
							}

	 						$product->setUpSellLinkData($param);
					
							try 
							{
								$product->save();
								Mage::log("Upselling SKU: ".$sku." with SKU:".$data[0],null, $logFile);
							}
							catch (Exception $e) 
							{
								Mage::log("processUpsellProducts Error: ".$e->getMessage(),null, $logFile);
							}
						}
					}
				}
	    		$firstRowSkipped = true;
			}
   	 	}
   	 	
   	 	fclose($handle);
    }
  
     /**
	 * Create downloadable products, MP data had separate SKUs for each download link representated as product variants, we are going to use these
	 * variants to create the links but not create the link SKU
	 * 
	 */
    function processDownLoadLinks()
    {
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    	global $count;
    	
    	$currentSku;
    	$previousSku;
    	$createdSkus = array();

		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;  	  
    		$i = 0;
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
				{
					if($i != 0)
	    			{
	    				$previousSku = $currentSku;
	    				$currentSku = $data[0].$data[6];
	    			}
	    			else
	    			{
						$currentSku = $data[0].$data[6];
						$previousSku = $currentSku;
	    			}
	    			
					if($data[8] == "" && $data[10] == "") //no children
					{	
						if($data[0] != "" && $data[13] == "104")
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
							
							if($currentSku != $previousSku) //new product
							{
								if(!$product)
								{	
									
									$product = CreateNewProduct($data);	
									echo "product doesnt exist: ".$product->getSku()."\n";
									if(substr($product->getSku(), strlen($product->getSku()) - 1, 1) == "/")
									{
										$product->setSku(substr($product->getSku(), 0, strlen($product->getSku()) - 1));
									}
									
									$product->setLinksPurchasedSeparately(0);
		
									try
									{
										$product->save();
								    	Mage::log("Created Product: ".$product->getSku(),null, $logFile);
								    	
								    	createStockItem($product);
								    	assignTaxClass($product);
								    	echo "Created SKU:".$product->getSku()."\n";
								    	$createdSkus[$product->getSku()] = $product->getId(); //track that the sku was created
								    	$count++;
									} 
									catch(Exception $e)
									{
									    Mage::log("processDownLoadLinks Error: ".$e->getMessage(),null, $logFile);
									}
								}
								else
								{
									addStore($product);
								}	
							}

							if($createdSkus[$product->getSku()] != "") //if sku already created, then this is an additional varient to use for link creation
							{
								if($data[16] != "")
								{
									$title = $product->getName()." ".$data[16];
								}	
								else
								{
									$title = $product->getName();
								}
								
								try
								{	
									if($data[15]!= "")
									{
										Mage::getModel('downloadable/link')->setData(array(
											'product_id' => $product->getId(),
											'sort_order' => 0,
											'number_of_downloads' => 0, // Unlimited downloads
											'is_shareable' => 3, // Not shareable
											'link_url' => "https://s3.amazonaws.com/marthapullen/downloads/".$data[15],
											'link_type' => 'url',
											'link_file' => '',
											'sample_url' => '',
											'sample_file' => '',
											'sample_type' => '',
											'use_default_title' => false,
											'title' => $title,
												))->save();
												echo "Created Download Link for:".$product->getSku()."\n";
										Mage::log($storeViewName."::Created Download Link for ".$product->getSku(),null, $logFile);
									}
								} 
								catch(Exception $e)
								{
								    Mage::log($storeViewName."::processDownLoadLinks (link creation) Error: ".$e->getMessage()." for SKU ". $product->getName()."\n",null, $logFile);
									return;
								}
							}
						}
					}	
				$i++;
				}
				$firstRowSkipped = true;

    		}
    	}
    	fclose($handle);
    	
    }

    /**
	 * Create the configurable products for Color and Size variants. MP data has product variants that are defined by SKU suffix and SKU modifier patterns, 
	 * we take those modifiers and suffixes and dynamically create parent/child products for a configurable product group
	 * 
	 */
    function processSizeColorProducts()
    {
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    	global $count;
    	global $taxClassIds;
    	
    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;  	  
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
    			{
					$isColor = false;
	    			$isSize = false;
	    			$optionName = "";
	    			
	    			if ($data[8] != "") //Size
	    			{
	    				$isSize = true;
	    				$optionIndex = 7;
	    				$modifierIndex = 8;
	    				$optionName = "size";
	    				$optionLabel = "Size";
	    			}
	    			else if($data[10] != "") //color
	    			{
	    				$isColor = true;
	    				$optionIndex = 9;
	    				$modifierIndex = 10;
	    				$optionName = "color";
	    				$optionLabel = "Color";
	    			}
	    	
	    		  	if ($isSize == true || 	$isColor == true)//Size
					{
						unset($configurableProductsData);
			    		unset($configAtt);
			    		unset($configurableAttributesData);
    		
						$attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product',$optionName);
						$parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0].$data[6]);
						
						if($parentProduct)
						{
							if($parentProduct->getSku() != 'RW-ROMP/OG/')
							{
								//continue;
							}
							//$parentProduct = CreateNewProduct($data);	
		
						    //Sizes/Colors
							$options = explode(",", $data[$optionIndex]);
		
							//SKU Modifiers
							$skuModifiers = explode(",", $data[$modifierIndex]);
							
							$i = 0;
		
							//Create Each Child Product
							foreach ($skuModifiers as &$aSkuModifier)
							{
								$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0].$data[6].$aSkuModifier);
								
								if($product)
								{
									//$product = CreateNewProduct($data);	
									//$product->setSku($product->getSku().$aSkuModifier);
									
									$option_val = attributeValueExists($optionName, ucwords($options[$i]));
														
									if($isSize == true)
									{
			            				$product->setSize($option_val);
									}
									else 
									{
			            				$product->setColor($option_val);
									}
									
									$product->setVisibility(1);
							
									try 
									{
										$product->save();
										Mage::log("Created Child Product: ".$product->getSku(),null, $logFile);
										
										$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getId());
	
					    				if($stockItem->getId() == "")
					    				{
					    					createStockItem($product);
					    				}
										createStockItem($product);
										assignTaxClass($product);
										echo "Created Child SKU:".$product->getSku()."\n";
										$count++;
									} 
									catch (Exception $e) 
									{
										Mage::log("processSizeColorProducts Error: ".$e->getMessage(),null, $logFile);	
									}
									
									$optionId = attributeValueExists($optionName, $options[$i]);
									
									$configurableProductsData[$product->getId()] = array('0'=>
			                        		array(
			                        			'attribute_id'=>$attributeId,
			                        			'label'=>ucwords($options[$i]),
			                        			'value_index'=>$optionId,
			                        			'is_percent'=>0,
			                        			'pricing_value'=>''
			                        		)
			                		);
			
			                		$configAtt[$i] =  array(
											'attribute_id'		=> $attributeId, //The attribute id
											'label'				=> ucwords($options[$i]),
											'value_index'		=> $optionId, //The option id
											'is_percent'		=> 0,
											'pricing_value'		=> ''				
										);
								
							    	$i++;	
								}
							}
							
							$html_id = "config_super_product__attribute_".$count;
							//Create the configurable attributes data
							$configurableAttributesData = array(
								'0'	=> array(
									'id' 				=> NULL,
									'label'				=> $optionLabel, //optional, will be replaced by the modified api.php
									'position'			=> NULL,
									'values'			=> $configAtt,
									'attribute_id' 		=> $attributeId, //get this value from attributes api call
									'attribute_code'	=> $optionName, //get this value from attributes api call
									'frontend_label'	=> '', //optional, will be replaced by the modifed api.php
									'html_id'			=> $html_id
								)
							);
							

							$parentProduct->setTypeId('configurable');	
							$parentProduct->setConfigurableProductsData($configurableProductsData);
							$parentProduct->setConfigurableAttributesData($configurableAttributesData);
							$parentProduct->setCanSaveConfigurableAttributes(1);
									
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

								Mage::log($storeViewName."::Created Parent Product: ".$parentProduct->getSku(),null, $logFile); 
								echo "Created Parent SKU:".$parentProduct->getSku()."\n";
								$count++;
							}
							catch (Exception $e)
							{
							    Mage::log($storeViewName."::processParentChildren->create parent:Error: ".$e->getMessage().": SKU:".$parentProduct->getSku(),null, $logFile);
							}
						}

					}
    			}
				$firstRowSkipped = true;
			}
    	}
    	
		fclose($handle);
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
            $value['option'] = array(ucwords($arg_value),ucwords($arg_value));
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
	 * @param $arg_attribute String ==> attribute name
	 * @param $arg_value String ==> option value
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
            if (ucwords($option['label']) == ucwords($arg_value))
            {
                return $option['value'];
            }
        }

        return false;
    }

    /**
	 * Create values for the custom attribute 'Color' & 'Size'
	 * 
	 * 
	 */  
    function createSizeColorValues()
    {  
    	global $fileName;

    	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$i = 0;
    		$firstRowSkipped = false;
    		
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
    			if($firstRowSkipped)
    			{
    			  	//Sizes
	    			if($data[7] != "")
					{
						$sizes = explode(",", $data[7]);
							
						foreach ($sizes as &$size)
						{
							if( $size != "")
							{
								addAttributeValue('size', $size);
							}
						}
					}	
					
	    			//Colors
	    			if($data[9] != "")
					{
						$colors = explode(",", $data[9]);
							
						foreach ($colors as &$color)
						{
							if( $color != "")
							{
								addAttributeValue('color', $color);
							}
						}
					}
    			}
  				$firstRowSkipped = true;
    		}
    		fclose($handle);
    	}
    }

    /**
	 * Initialize the Magento Product record - not saved though
	 * 
	 * @param $data array ==> csv data row
	 */
    function createNewProduct($data)
    {
    	//CSV Map
    	//data[0] = SKU
	    //data[1] = Name
	    //data[2] = Description
	    //data[3] = Sold By Length
	    //data[4] = Sale Price
	    //data[5] = Cost
	    //data[6] = SKU Suffix
	    //data[7] = Sizes
	    //data[8] = Size SKU Modifiers
	    //data[9] = Colors
	    //data[10] = Color SKU Modifiers
	    //data[11] = Minimum Qty
	    //data[12] = Upsell Products
	    //data[13] = Product Type Id
	    //data[14] = MP Product Id
	    //data[15] = DownLoad Location
	    //data[16] = Varient Name
	    //data[17] = Price
    
    	global $websiteId;
    	global $storeId;
    	
    	$websiteIds[0] = $websiteId;
    	$storeIds[0] = $storeId;
    	
		$isParent = false;
		$product = new Mage_Catalog_Model_Product();
		$product->setWebsiteIDs($websiteIds);
		$product->setStoreIDs($storeIds);
		$product->setTypeId('simple');	

		//Based off the SKU pattern, set the attribute set
		if(substr($data[0], 0, 2) == "B-")
		{
			$attrSetName = "Book";
		}
    	else if(substr($data[0], 0, 3) == "CD-")
		{
			$attrSetName = "CD";
		}
       	else if(substr($data[0], 0, 3) == "DA-" || substr($data[0], 0, 2) == "D-" || substr($data[0], 0, 2) == "G-" || substr($data[0], 0, 2) == "K-"  || substr($data[0], 0, 2) == "N-" || substr($data[0], 0, 3) == "SA-" || substr($data[0], 0, 2) == "T-")
		{
			$attrSetName = "Default";
		}
       	else if(substr($data[0], 0, 3) == "DL-")
		{
			$product->setTypeId('downloadable');
			$attrSetName = "Download";
		}
        else if(substr($data[0], 0, 4) == "DVD-" || substr($data[0], 0, 2) == "V-")
		{
			$attrSetName = "DVD";
		}
        else if(substr($data[0], 0, 2) == "F-" || substr($data[0], 0, 3) == "JP-" || substr($data[0], 0, 3) == "LT-" || substr($data[0], 0, 3) == "TG-")
		{
			$attrSetName = "Fabric";
		}
      	else if(substr($data[0], 0, 2) == "L-" || substr($data[0], 0, 2) == "S-")
		{
			$attrSetName = "Lace/Trim";
		}
        else if(substr($data[0], 0, 2) == "P-")
		{
			$attrSetName = "Pattern";
		}
        else if(substr($data[0], 0, 3) == "RW-")
		{
			$attrSetName = "Apparel";
		}
		else if($data[13] == "104")
		{
			$attrSetName = "Download";
			$product->setTypeId('downloadable');	
		}
        else
		{
			$attrSetName = "Default";
		}

		$entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
		$attributeSetName   = $attrSetName;
		$attributeSetId     = Mage::getModel('eav/entity_attribute_set')
			->getCollection()
			->setEntityTypeFilter($entityTypeId)
			->addFieldToFilter('attribute_set_name', $attributeSetName)
			->getFirstItem()->getAttributeSetId();
		
		$product->setAttributeSetId($attributeSetId);
           	
		//SKU
		if($data[6] == "" || $data[13] == "104")
		{
			$product->setSku($data[0]);
		}
		else
		{
			$product->setSku($data[0].$data[6]);
		}
			   	
		//Status
		$product->setStatus(1);

		//Name
    	$product->setName($data[1]);

		//Description
		$product->setDescription($data[2]);
    	   
		//Short Description
		$shortDesc = strip_tags(substr($data[2], 0, 100)); 	
    	$product->setShortDescription($shortDesc);
			    	   	
		//Special Price
		if($data[4] != "" && $data[4] != "0")
		{
			$product->setSpecialPrice($data[4]);
		}
		
		$urlKey = $product->getName();
		Mage::helper('catalog/product_url')->format($urlKey);
		preg_replace('#[^0-9a-z]+#i', '-',$urlKey);
		strtolower($urlKey);
		trim($urlKey, '-');
		$product->setUrlKey($urlKey);

		//Minimum Qty
		$product->setMinimumQuantity($data[11]);
    	   	
		//Meta Title
		$product->setMetaTitle($data[1]);
    	   	
		//Meta Description
		$metaDescription =  strip_tags(substr($data[2], 0, 160)); 	
		$product->setMetaTitle($metaDescription);
		
		//Sold By Length
		if($data[3] == "1")
		{
			$product->setSoldByLength(true);
		}
		
		$product->setPrice($data[17]);
		
		return 	$product;		
    }
    
  	/**
	 * Create,Save a Stock Item record
	 * 
	 * @param $product Mage Product
	 */
    function createStockItem($product)
    {
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
			Mage::log("Created Stock Item for product: ".$product->getSku(),null, $logFile);
		} 
		catch(Exception $e)
		{
			Mage::log("createStockItem Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
		}
    }
    
     /**
	 * Assign the tax class to a product
	 * 
	 * @param $product Mage Product
	 */
    function assignTaxClass($product)
    {
    	global $taxClassIds;
    	global $logFile;
    	
    	$product->setTaxClassId($taxClassIds["Taxable Goods"]);
    	
    	if($product->getTypeId == "downloadable")
    	{
			$product->setTaxClassId($taxClassIds["Downloads"]);
    	}
    	
    	try
		{
			$product->save();
			Mage::log("Associated Tax Class for product: ".$product->getSku(),null, $logFile);
		} 
		catch(Exception $e)
		{
			Mage::log("createStockItem Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
		}
    }
?>