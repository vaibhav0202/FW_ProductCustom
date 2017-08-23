<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';
    Mage::app();
        Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
   
    //globals
    $count = 0;
    $logFile = 'IW_Product_Review_Import.log';
    $reviewDateFileName = "ReviewDate.csv";
    $reviewFileName = "ProductReviewExport.csv";
    $storeViewName = "InterweaveStore.com";
    $reviewDates = array();

    echo "\nProcessing file: ".$reviewFileName."\n";
    
    //Store Id
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
    $storeId = $store->getId();
	
    Mage::log("Creating product reviews",null, $logFile);  
    
    getReviewDates($reviewDateFileName);
    processProductReviews($reviewFileName,$storeId);
    
    function getReviewDates($reviewDateFileName)
    {
        global $reviewDates;
        if (($handle = fopen($reviewDateFileName, "r")) !== FALSE)
    	{
            $firstRowSkipped = false;

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
            {
                if($firstRowSkipped)
                {
                    $reviewDates[$data[0]] = $data[5];
                }
                $firstRowSkipped = true;
            }
    	}
    	fclose($handle);
    }
    
	/**
	 * Create Product Reviews
	 * 
	 * 
	 */
    function processProductReviews($reviewFileName,$storeId)
    {
    	global $logFile;
              global $reviewDates;
        
	if (($handle = fopen($reviewFileName, "r")) !== FALSE)
    	{
            $firstRowSkipped = false;

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
            {
                if($firstRowSkipped)
                {
                    $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[2]);
                    if($product)
                    {
                        if(strtoupper($data[17]) == 'APPROVED')
                        {
                            $productReview = createNewProductReview($product, $data,$storeId,$reviewDates);

                            try
                            {
                                $productReview->save();
                                $productReview->aggregate();
                                
                                $timestamp = strtotime($reviewDates[$data[0]]);
                                $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                                $conn->query("Update review set created_at = '".date("Y-m-d H:i:s", $timestamp)."' where review_id = ".$productReview->getId());
         
                                echo "Created Review for ".$data[2]."\n";
                            } 
                            catch(Exception $e)
                            {
                                Mage::log("processProductReviews Error: ".$e->getMessage(),null, $logFile);
                            }
                              
                        }
                    }
                }
                $firstRowSkipped = true;	
            }
    	}
    	fclose($handle);
    }
    
    function createNewProductReview($product,$data,$storeId)
    {
        //data[0] review id
        //data[4] = Title
        //data[5] = body
        //data[8] = rating
        //data[10] = first name
        //data[11] = last name

        $review = new Mage_Review_Model_Review();
        $review->setEntityPkValue($product->getId());//product id
        $review->setStatusId(1);
        $review->setTitle($data[4]);
        $review->setTitle($data[4]);
        $review->setDetail($data[5]);
        $review->setNickname($data[10]." ".$data[11]);
        $review->setEntityId($review->getEntityIdByCode(Mage_Review_Model_Review::ENTITY_PRODUCT_CODE));
        $review->setStoreId($storeId);                     
        $review->setStatusId(1); //approved
        $review->setStores($storeId);

	return $review;

    }

?>