<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //globals
  	$count = 0;
  	$logFile = 'CV3_Product_Review_Import.log';
  	
  	//Command args
    $fileName = $argv[1];
    $storeViewName = $argv[2];

	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
   
    echo "\nProcessing file: ".$fileName."\n";
    
    //Store Id
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
	$storeId = $store->getId();
	
	
    Mage::log($storeViewName."::Creating product reviews",null, $logFile);   
    processProductReviews();
    
	/**
	 * Create Product Reviews
	 * 
	 * 
	 */
    function processProductReviews()
    {
    	global $fileName;
    	global $logFile;
    	global $storeId;
    	global $storeViewName;
    	global $parentProducts;
    	global $count;
    	
    	$tempProduct = new Mage_Catalog_Model_Product();
    	$reviewCount = 0;
    	    	
		if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
    		$firstRowSkipped = false;
    		$i = 0;
    		while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
    		{
				if($firstRowSkipped)
				{
					if($i == 0)
		    		{
		    			$currentSKU = $data[0];
		    			$previousSKU = $currentSKU;
		    			$reviewCount = 0;
		    		}
		    		else
		    		{
		    			$previousSKU = $currentSKU;
		    			$currentSKU = $data[0];

		    			if($currentSKU != $previousSKU)
		    			{
		    				if($tempProduct && $foundproduct)
		    				{
		    					echo "updating review count(".$reviewCount.") for product: ".$tempProduct->getSku()."\n";
			    				try
								{
									$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
									$conn->query("Insert Into review_entity_summary (entity_pk_value, entity_type, reviews_count, rating_summary, store_id) values (".$tempProduct->getId().",1,".$reviewCount.",0,".$storeId.")");
				    				
								} 
								catch(Exception $e)
								{
								    Mage::log($storeViewName."::processProductReviews Error: ".$e->getMessage(),null, $logFile);
								}
		    				}
	
	    					$reviewCount = 0;
		    			}
		    		}
    		
					if($data[0] != "" && $data[6] == "y")
					{	
						$product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);
						if($product)
						{
							$tempProduct = clone $product;
							$productReview = createNewProductReview($product, $data);	
							try
							{
								$productReview->save();
		    					Mage::log($storeViewName."::Created Product Review: ".$product->getSku(),null, $logFile);
		    					$timestamp = strtotime($data[7]);
		    			
		    					$conn = Mage::getSingleton('core/resource')->getConnection('core_write');
								$conn->query("Update review set created_at = '".date("Y-m-d H:i:s", $timestamp)."' where review_id = ".$productReview->getId());
							
								$foundproduct = true;
								$reviewCount++;
							} 
							catch(Exception $e)
							{
							    Mage::log($storeViewName."::processProductReviews Error: ".$e->getMessage(),null, $logFile);
							}
						}
						else 
						{
							$foundproduct = false;
						}		
					}
				}
				$firstRowSkipped = true;
				$i++;	
			}
    	}
    	fclose($handle);
    }
    
    function createNewProductReview($product, $data)
    {
    	global $vendors;
		global $storeId;
    	 //data[0] = SKU
	    //data[1] = Title
	    //data[2] = Comment
	    //data[3] = Rating
	    //data[4] = Name
	    //data[5] = Email
	    //data[6] = Approved
	    //data[7] = Date Created

    	$storeIds[0] = $storeId;

		$review = new Mage_Review_Model_Review();
		$review->setEntityPkValue($product->getId());//product id
		$review->setStatusId(1);
		$review->setTitle($data[1]);
		$review->setDetail($data[2]);
		$review->setEntityId($review->getEntityIdByCode(Mage_Review_Model_Review::ENTITY_PRODUCT_CODE));
		$review->setStoreId($storeId);                     
		$review->setStatusId(1); //approved
		$review->setNickname($data[4]);
		$review->setCreateDate($data[9]);
		$review->setStores($storeId);

		return $review;

    }

?>