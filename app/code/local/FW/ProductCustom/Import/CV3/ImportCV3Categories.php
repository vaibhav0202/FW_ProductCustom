<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';
    Mage::app();
   
    //Command Line Args
    $fileName = $argv[1];
    $storeViewName = $argv[2];
    
    //Global Vars
    $count=0;
    $logFile = 'CV3_Category_Import.log';
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    Mage::app('default');
    
	//Start Time metics
    $mtime = microtime();
    $mtime = explode(' ', $mtime);
    $mtime = $mtime[1] + $mtime[0];
    $starttime = $mtime;
    
  	//CSV MAP
    //data[0] = Name
    //data[2] = URL Key
    //data[3] = Description
    //data[4] = Meta Title
    //data[5] = Meta Description
    //data[6] = Meta Keywords
    //data[7] = IsInvisible
    //data[9] = SKUs
		
    if (($handle = fopen($fileName, "r")) !== FALSE)
    {
    	$loopCnt = 0;
    	$firstRowSkipped = false;
    	
    	// Store root category
		$store = Mage::getModel('core/store')->load($storeViewName, 'name');
		$storeId = $store->getId();
		$rootCategoryId = Mage::app()->getStore($storeId)->getRootCategoryId();
		$rootCategory = Mage::getModel('catalog/category')->load($rootCategoryId);
		
		$parentCategory = $rootCategory;
		$parentCategorys = array();
		
    	while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    	{
    		$catExists = FALSE;
			if($firstRowSkipped)
			{
				$level = 0;
				$catName = $data[0];
				$catExists = false;
				
				if(!$catExists)
				{
					$tildaPos = strpos($data[0], "~");
					$category = Mage::getModel('catalog/category');
					$category->setStoreId(0);
					$category->setIsActive(1);
					$category->setIsAnchor(0);
					$category->setDescription($data[3]);
					$category->setMetaTitle($data[4]);
					$category->setMetaDescription($data[5]);
					$category->setMetaKeywords($data[6]);
					
					if($data[8] == "TRUE")
					{
						$category->setIncludeInMenu(0);
					}
					else
					{
						$category->setIncludeInMenu(1);
					}

					if($tildaPos == "") //1st level
					{
						$category->setName($catName);
						$category->setPath($parentCategory->getPath());
						try 
						{
					
							$entityTypeId = Mage::getModel('eav/entity')->setType('catalog_category')->getTypeId();
							 
							$attributeId = Mage::getResourceModel('eav/entity_attribute_collection')
							                ->setCodeFilter('url_key')
							                ->setEntityTypeFilter($entityTypeId)
							                ->getFirstItem()->getId(); 
            
							$category->save();
							$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
							$conn->query("Update catalog_category_entity_varchar set value = '".$data[2]."' where store_id in (0,".$storeId.") and attribute_id = ".$attributeId." and entity_id = ".$category->getId());
				
							echo $category->getName()."\n";
							Mage::log($storeViewName."::Created Category ".$data[0],null, $logFile);
						} 
						catch (Exception $e) 
						{
							Mage::log($storeViewName."::Category Creation Error: ".$e->getMessage(),null, $logFile);
						}
	
						$parentCategorys[0] = $category;
					}
					else 
					{
						while ($tildaPos != "")
						{
							$level++;
							$catName = substr($catName, $tildaPos + 1);
							$tildaPos = strpos($catName, "~");
							
							if($data[8] == "TRUE")
							{
								$category->setIncludeInMenu(0);
							}
							else
							{
								$category->setIncludeInMenu(1);
							}
							
	
							if($tildaPos == "") 
							{
								$category->setName($catName);
								$category->setPath($parentCategorys[$level - 1]->getPath());
									
								try 
								{
									$category->save();
									
									$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
									$conn->query("Update catalog_category_entity_varchar set value = '".$data[2]."' where store_id in (0,".$storeId.") and attribute_id = ".$attributeId." and entity_id = ".$category->getId());
				
									echo $category->getName()."\n";
									Mage::log($storeViewName."::Created Category ".$data[0],null, $logFile);
								} 
								catch (Exception $e) 
								{
									Mage::log($storeViewName."::Category Creation Error: ".$e->getMessage(),null, $logFile);
								}
								
								$parentCategorys[$level] = $category;
							}
						}
					}
				}
				
				if($category)
				{
					//SKU Relation
					if($data[9] != "")
					{
						$skus = explode(",", $data[9]);
						$position = 1;
						foreach ($skus as &$sku)
						{
							$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
							if($product)
							{
								
								$productCategoryIds = $product->getCategoryIds();
								$productCategoryIds[count($productCategoryIds)] = $category->getId();
								$product->setCategoryIds($productCategoryIds);
								
								try 
								{
									$product->save();
									Mage::log($storeViewName."::Associate SKU ".$sku." with category ".$catName,null, $logFile);
									$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
									$conn->query("Update catalog_category_product set position = ".$position." where category_id = ".$category->getId()." and product_id = ".$product->getId());
									echo "Category SKU:".$sku." with position ".$position."\n";
									
								} 
								catch (Exception $e) 
								{
									Mage::log($storeViewName."::SKU Association Error: ".$e->getMessage(),null, $logFile);
								}
								$position++;
							}
							
						}
						
					}
				}
			}

    		$firstRowSkipped = true;	
    	}
    	fclose($handle);
    	
    	$mtime = microtime();
      	$mtime = explode(" ", $mtime);
      	$mtime = $mtime[1] + $mtime[0];
      	$endtime = $mtime;
      	$totaltime = ($endtime - $starttime);
      	Mage::log($storeViewName."::Total Process Time: ".$totaltime." seconds for ".$count." products",null, $logFile);
    }
    
?>