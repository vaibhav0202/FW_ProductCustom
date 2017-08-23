<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //Global Vars
    $magentoIds = array();
    $categories = array(); 
    $categoryTree = array();
    $productCatfileName = $argv[1];
    $catfileName = $argv[2];
    $storeViewName = $argv[3];
    $logFile = 'MP_Category_Import.log';
    $count = 0;
    
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
	
    $mtime = microtime();
    $mtime = explode(' ', $mtime);
    $mtime = $mtime[1] + $mtime[0];
    $starttime = $mtime;
    
    //data[0] = Sku
    //data[1] = Category Name

    // Store root category
	$store = Mage::getModel('core/store')->load($storeViewName, 'name');
	$storeId = $store->getId();

	//Create All Categories first
    createCategories($storeId);

    if (($handle = fopen($productCatfileName, "r")) !== FALSE)
    {
    	$loopCnt = 0;
    	
    	while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    	{
    		if($i == 0)
    		{
    			$currentCategory = $data[1];
    			$previousCategory = $currentCategory;
    			$position = 1;
    		}
    		else
    		{
    			$previousCategory = $currentCategory;
    			$currentCategory = $data[1];
    			
    			if($currentCategory != $previousCategory)
    			{
    				$position = 1;
    			}
    		}
    		
			$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);

			//SKU Relation
			if($product)
			{
				if($magentoIds[$data[1]] != "")
				{
					$productCategoryIds = $product->getCategoryIds();
				
					$productCategoryIds[count($productCategoryIds)] = $magentoIds[$data[1]];
					$product->setCategoryIds($productCategoryIds);

					try 
					{
						$product->save();
						
						$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
						$conn->query("Update catalog_category_product set position = ".$position." where category_id = ".$magentoIds[$data[1]]." and product_id = ".$product->getId());
						echo "Category: ".$magentoIds[$data[1]]." SKU:".$product->getSku()." with position ".$position."\n";
									
						$count++;
					} 
					catch (Exception $e) 
					{
						Mage::log("Associating SKU Error: ".$e->getMessage(),null, $logFile);
					}
				}
				else
					echo "Can find CAT ID:".$data[1]."\n";
				$position++;
			}

			$i++;
    	}
    	
    	fclose($handle);
    	
    	$mtime = microtime();
      	$mtime = explode(" ", $mtime);
      	$mtime = $mtime[1] + $mtime[0];
      	$endtime = $mtime;
      	$totaltime = ($endtime - $starttime);
      	Mage::log($storeViewName."::Total Process Time: ".$totaltime." seconds for ".$count." products",null, $logFile);
    }
    
     /**
	 * Create all categories
	 * 
	 * @param $storeId String 
	 */
    function createCategories($storeId)
    {
    	global $catfileName;
    	global $logFile;
    	global $categories;
    	global $categoryTree;
    	global $magentoIds;
    	//data[0] = category id
    	//data[1] = parent Id
    	
    	$rootCategoryId = Mage::app()->getStore($storeId)->getRootCategoryId();
		$rootCategory = Mage::getModel('catalog/category')->load($rootCategoryId);
	
    	//Create Tree
       	if (($handle = fopen($catfileName, "r")) !== FALSE)
	    {
	    	$firstRowSkipped = false;
	    	$i = 0;
			while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
	    	{
	    		if($firstRowSkipped == true)
		    	{
					$categories[$i] = (object) array(
						'id' => $data[0], 
						'parent_id' => $data[1], 
						'name' => $data[2], 
						'sekeywords' => $data[3], 
						'sedescription' => $data[4], 
						'setitle' => $data[5]);
						$i++;
				}

    		  	$firstRowSkipped = true;
    		}
	    }
	    fclose($handle);
	    
	  /* $categories[0] = (object) array('id' => '1', 'parent_id' => '0', 'name' => 'chicken');
	    $categories[1] = (object) array('id' => '2', 'parent_id' => '0', 'name' => 'pork');
	    $categories[2] = (object) array('id' => '3', 'parent_id' => '1', 'name' => 'beef');
	    $categories[3] = (object) array('id' => '4', 'parent_id' => '1', 'name' => 'carrots');
	    $categories[4] = (object) array('id' => '5', 'parent_id' => '4', 'name' => 'tomatoes');
	    $categories[5] = (object) array('id' => '6', 'parent_id' => '2', 'name' => 'celery');
	    $categories[6] = (object) array('id' => '7', 'parent_id' => '4', 'name' => 'fish');*/
  
	    
	    //Create Tree Structure
	  	$childs = array();
		foreach($categories as $item)
		{
			$childs[$item->parent_id][] = $item;
		}

		foreach($categories as $item) if (isset($childs[$item->id]))
		{
			$item->childs = $childs[$item->id];
		}
		    
		$categoryTree = $childs[0];

		//Create all the categories with all subcategoreis
        foreach($categoryTree as $category)
		{
			$newCategory = initCategory($storeId, $category, $rootCategory);
			
			try 
			{
				$newCategory->save();
				Mage::log("Created Category ".$newCategory->name,null, $logFile);
				echo "Created Category ".$newCategory->name."\n";
				$magentoIds[$category->id] = $newCategory->getId();
			} 
			catch (Exception $e) 
			{
				Mage::log("Creating Category Error: ".$e->getMessage(),null, $logFile);
			}
				
			createSubCategories($storeId, $category, $newCategory);	
		}
    }
    
     /**Create Subcategories - RECURSIVE
	 * 
	 * @param $storeId String 
	 * @param $categoryTree array 
	 * @param $rootCategory Mage_Category 
	 */
    function createSubCategories($storeId, $categoryTree, $rootCategory)
    {
    	global $logFile;
    	global $magentoIds;
    	
		foreach($categoryTree->childs as $category)
		{
			$category;
	
			if(!$category->childs)//End Of the line
			{
				$newCategory = initCategory($storeId, $category, $rootCategory);
					
				try 
				{
					$newCategory->save();
					Mage::log("Created Category ".$newCategory->name,null, $logFile);
					echo "Created Category ".$newCategory->name."\n";
					$magentoIds[$category->id] = $newCategory->getId();
				} 
				catch (Exception $e) 
				{
					Mage::log("Creating Category Error: ".$e->getMessage(),null, $logFile);
				}
			}
			else 
			{
				$newCategory = initCategory($storeId, $category, $rootCategory);
					
				try 
				{
					$newCategory->save();
					Mage::log("Created Category ".$newCategory->name,null, $logFile);
					echo "Created Category ".$newCategory->name."\n";
					$magentoIds[$category->id] = $newCategory->getId();
				} 
				catch (Exception $e) 
				{
					Mage::log("Creating Category Error: ".$e->getMessage(),null, $logFile);
				}
				
				createSubCategories($storeId, $category, $newCategory);
			}
		}
    }
    
    
    /**Init Category object
	 * 
	 * @param $storeId String 
	 * @param $category array 
	 * @param $rootCategory Mage_Category 
	 */
    function initCategory($storeId, $category, $rootCategory)
    {
		$newCategory = Mage::getModel('catalog/category');
		$newCategory->setIsActive(1);
		$newCategory->setIsAnchor(0); 
		$newCategory->setStoreId(0);
		$newCategory->setName($category->name);
		$newCategory->setDescription($category->sedescription);
		$newCategory->setMetaTitle($category->setitle);
		$newCategory->setMetaDescription($category->sedescription);
		$newCategory->setMetaKeywords($category->sekeywords);
		$urlKey = $category->name;
		Mage::helper('catalog/product_url')->format($urlKey);
		preg_replace('#[^0-9a-z]+#i', '-',$urlKey);
		strtolower($urlKey);
		trim($urlKey, '-');
		
		$urlKey = "c-".$category->id."-".$urlKey;
		
		$newCategory->setUrlKey($urlKey);
		$newCategory->setPath($rootCategory->getPath());

		return $newCategory;
    }
    

  
?>
