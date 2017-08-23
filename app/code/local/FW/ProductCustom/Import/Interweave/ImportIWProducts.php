<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';
    require_once '../../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
    $fileName = "ProductExport.csv";
    $storeViewName = "InterweaveStore.com";
    $webSiteName = "InterweaveStore.com";
    
    //Global Vars
    $count = 0;
    $logFile = 'IW_Product_Import.log';
    $missingSkulogFile = 'IW_SKUS_NOTINVISTA.log';
    echo "\nProcessing file: ".$fileName."\n";
    
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    
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

    /**
     * Update IW Products
     * data[1] = sku (ItemNumber)
     * data[2] = ItemName
     * data[3] = CategoryList
     * data[6] = ShortDescriptionForCategoryList
     * data[10] = Manufacturer
     * data[12] = AdvancedPricing
     * data[13] = ItemImages
     * data[21] = LongDescription1
     * data[23] = LongDescription2
     * data[25] = LongDescription3
     * data[27] = LongDescription4
     * data[29] = LongDescription5
     * data[51] = CustomMetaKeywords
     * data[52] = CustomMetaDescription
     * data[53] = CustomPageTitle
     * data[100] = Hide
     * data[101] = AttributeList
     * data[103] = CustomUrl
     * data[104] =RelatedItemsList
     * data[123] = DoNotDiscount
     * data[131] = eProductURL
     * data[154] = PaymentMethodsDisabled
     * data[159] = AvailableFormatsAppStore
     * 
     * 
     */
    function processProducts()
    {
    	global $fileName;
    	global $logFile;
        global $missingSkulogFile;
    	global $storeId;
        global $websiteId;
        
        $productCount = 0;

	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
            $firstRowSkipped = false;
            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
            {
                if($firstRowSkipped)
                { 
                    if($data[1] != "") //check if sku is present
                    {	
                        $alreadyAssignedToIW = false;
                        $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[1]);

                        if($product) //Existing SKU, if its doesnt exist then log it but do nothing else
                        {	
                            $product = Mage::getModel('catalog/product')->load($product->getId()); //This fully loads the product to include the media gallery              
                            //Check if product is only assigned to Main Website, if so, do a full product setup
                            $websites = Mage::getModel('core/website')->getCollection();
                            foreach ($websites as $website) 
                            {
                                if($website->getName() == "Main Website")
                                {
                                   $mainWebsiteId = $website->getId();
                                }
                            }

                            $productWebsiteIds = $product->getWebsiteIds();
                            
                            foreach($productWebsiteIds as $productWebsiteId)
                            {
                                if($productWebsiteId == $websiteId)
                                {
                                    $alreadyAssignedToIW = true;
                                }
                            }

                            if($alreadyAssignedToIW == false)
                            {
                                //Product is not assigned to active F+W yet, so its in the Magento DB but not in any real stores yet
                               if(count($productWebsiteIds) == 1 && $productWebsiteIds[0] == $mainWebsiteId ) 
                               {
                                   //Set all data
                                   initNonActiveStoreProduct($product, $data);

                                     //Download Links
                                   if ($data[131] != "") //Downloadable
                                   {
                                       //Delete any previously existing links
                                       $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                                       $conn->query("Delete FROM downloadable_link WHERE product_id = ".$product->getId());

                                       $product->setDownloadableData(createDownloadableData($data));
                                       $product->setLinksPurchasedSeparately(0);
                                   }


                                   $product->setVisibility(4);//products were orginally no visible when imported from Vista
                                 
                               }
                               else
                               {
                                  //Product is  assigned to active F+W so do not update the base data, only Store Assignment, Categories, Reviews and new IW Attributes
                                 echo "Sku in other Store:".$product->getSku()."\n";
                                   addStore($product);
                                  AddIWAttributes($product,$data);
                               }
                                processImages($product, $data);
                                processCategories($product, $data, $storeId);

                                try 
                                {
                                    //Start Time Metrics
                                    $mtime = microtime();
                                    $mtime = explode(' ', $mtime);
                                    $mtime = $mtime[1] + $mtime[0];
                                    $starttime = $mtime;
                                    $product->save();
                                   
                                    //Stop Time metrics
                                    $mtime = microtime();
                                    $mtime = explode(" ", $mtime);
                                    $mtime = $mtime[1] + $mtime[0];
                                    $endtime = $mtime;
                                    $totaltime = ($endtime - $starttime);
                                    echo "Total Time for Save:".$totaltime."\n"; 
                                    Mage::log("Total Time for Save:".$totaltime,null, $logFile);
                                    
                                   $productCount++;
                                   echo "TOTAL:".$productCount."-Processed SKU: ".$product->getSku()."\n";
                                   Mage::log("TOTAL:".$productCount."-Processed SKU: ".$product->getSku(),null, $logFile);
                                } 
                                catch (Exception $e) 
                                {
                                    Mage::log("Category Assocation Error: ".$e->getMessage(),null, $logFile);
                                }
                            } 
                        }
                        else
                        {
                            Mage::log("Sku not in vista| ".$data[1],null, $missingSkulogFile);
                        }
                    }
                }
                $firstRowSkipped = true;
            }
    	}
    	fclose($handle);
    }
    
      /**
	 * Associate all the categories to product
	 * 
	 * 
	 */
    function processCategories($product, $data, $storeId)
    {
         //CATEGORIES 
        $catCount = 0;

        $storeRootCat = Mage::getModel('catalog/category')->load(Mage::app()->getStore($storeId)->getRootCategoryId());
        $storeRootChildrenIds = explode(",", $storeRootCat->getChildren());

        if($data[3] != "")
        {
            $categoryList = explode("|", $data[3]);
            $foundValidCategory = false;
            foreach ($categoryList as $complexCategory)
            {
                $singleCategories = explode(">", $complexCategory);

                $i = 0;
                if(validTopLevel(trim($singleCategories[0])))
                {
                    $foundCorrectCategory = false;
                    foreach($singleCategories as $singleCategory) 
                    {
                        if($i == 0) //root category for this complex category
                        {
                            foreach ($storeRootChildrenIds as $rootChildId)
                            {
                                $rootChild = Mage::getModel('catalog/category')->load($rootChildId);

                                if($rootChild->getName() == trim($singleCategory))
                                {
                                    $currentRootCategory = $rootChild;

                                    if($i == (count($singleCategories) - 1))
                                    {
                                        $foundCorrectCategory = true;
                                        $foundValidCategory = true;
                                    }
                                }
                            }
                        }
                        else
                        {
                            $subChildren = $categories = Mage::getModel('catalog/category')
                                ->getCollection()
                                ->addFieldToFilter('parent_id', array('eq'=>$currentRootCategory->getId()))
                                ->addFieldToFilter('is_active',array('in'=>array('0', '1')))
                                ->addAttributeToSelect('*');

                            foreach ($subChildren as $subChild)
                            {
                                if($subChild->getName() == trim($singleCategory))
                                {
                                    $currentRootCategory = $subChild;

                                    if($i == (count($singleCategories) - 1))
                                    {
                                        $foundCorrectCategory = true;
                                        $foundValidCategory = true;
                                    }
                                }
                            }
                        }
                        $i++;
                    }

                     //Associate product with Category
                    if($foundCorrectCategory == true)
                    {
                        $productCategoryIds[$catCount] = $currentRootCategory->getId();
                    }
                    $catCount++;  
                }
            }

            if($foundValidCategory == true)
            {
                $product->setCategoryIds($productCategoryIds);
            }  
        }
    }
    
    /**
    * Remove old images, retrieve new image(s) and add to product
    * 
    * 
    */
    function processImages($product, $data)
    {
         //IMAGES
        //$path = 'C:\\Magento\\enterprise-1.12.0.2\\temp_iw_images\\';
        $path = 'images'.DS;

        //Check for pre-existing images and remove them     
        $mediaGalleryData = $product->getData('media_gallery');

        if ($mediaGalleryData['images'])
        {
            //Get the images

            echo "Found pre-existing images: ".$product->getsku()."\n";
             $attributes = $product->getTypeInstance ()->getSetAttributes ();
             $gallery = $attributes ['media_gallery'];
            foreach ( $mediaGalleryData ['images'] as $image ) {
                //If image exists
                if ($gallery->getBackend ()->getImage ( $product, $image ['file'] )) 
                {
                    $gallery->getBackend ()->removeImage ( $product, $image ['file'] );
                }
            }
            $product->save ();

            foreach ($gallery['images'] as &$image) {
                $image['removed'] = 1;
            }
            $product->setData('media_gallery', $gallery);
        }

        $imageNameCollection = explode("|", $data[13]);

            echo "Adding image(s) to Sku: ".$product->getsku()."\n";
            foreach($imageNameCollection as $imageString)
            {
                $imageArray = explode("~", $imageString);
                $img = @file_get_contents('http://eimages.interweave.com/products/450/'.$imageArray[0]);

                if ($img) 
                {	
                    if (file_exists($path.$imageArray[0])) unlink($path.$imageArray[0]);
                    file_put_contents($path.$imageArray[0], $img);
                } 
                else 
                {
                    echo ' -> Not Found'.PHP_EOL;
                    Mage::log("SKU:".$product->getSku(), null, "ProductImageImport-NotFound.log");
                }

                if (file_exists($path.$imageArray[0]) && $imageArray[0] != "") 
                {
                    $exclude = false;
                    if(strtoupper($imageArray[1]) != "TRUE")
                    {
                        $exclude = true;
                    }

                    echo "Adding image ".$path.$imageArray[0]." to ".$product->getSku()."\n";
                    if(!$exclude)
                    {
                        $product->addImageToMediaGallery($path.$imageArray[0], array('thumbnail','small_image','image'), TRUE, FALSE);
                    }
                    else
                    {
                        $product->addImageToMediaGallery(realpath($path.$imageArray[0]), null, TRUE, FALSE);
                    }
                } 
                else 
                {
                    echo "Image Not Imported for ".$product->getSku()."\n";
                    Mage::log("SKU:".$product->getSku(),null, "ProductImageImport-Failed.log");
                } 
            }
    }


    /**
	 * Create the link data for downloadable products
	 * 
	 * 
	 */
    function createDownloadableData($data) 
    {

    	$downloadableitems = array();

	$linkBlocks = explode(";", $data[131]);
     
        $i = 0;
        foreach($linkBlocks as $link) 
        {
            $linkArray = explode("|", $link);
            $downloadableitems['link'][$i]['is_delete'] = 0;
            $downloadableitems['link'][$i]['link_id'] = 0;
            
            if(count($linkArray) == 1)
            {
                $downloadableitems['link'][$i]['title'] = str_replace("", "", $data[2]);
            }
            else
            {
                $downloadableitems['link'][$i]['title'] = $linkArray[1];
            }
            
            //$downloadableitems['link'][$i]['title'] = "TEST1";
          
            $downloadableitems['link'][$i]['number_of_downloads'] = 0;
            $downloadableitems['link'][$i]['is_shareable'] = 3;
            $downloadableitems['link'][$i]['type'] = 'url';
            $downloadableitems['link'][$i]['link_url'] = "http://media2.fwpublications.com/interweave/".$linkArray[0];

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

        return "";
    }


     /**
	 * Initialize the Magento Product record - not saved though
	 * 
	 * @param $product Mage Product
	 * @param $data array ==> csv data row
	 */
    function initNonActiveStoreProduct($product, $data)
    {
        global $websiteId;
        global $storeId;
        global $taxClassIds;
        global $myEditionTypes;
		
        //SET IW STORE - wipeout association with Main Website
        $storeIds[0] = $storeId;
        $websiteIds[0] = $websiteId;
        
        $product->setWebsiteIDs($websiteIds);
        $product->setStoreIDs($storeIds);

        //Attribute Set Id
        $editionCode = "";
        foreach ($myEditionTypes as $editionType)
        {
            if($editionType['value'] == $product->getVistaEditionType())
            {
                $editionCode = $editionType['label'];
            }					
        }
        $attrSetName = "";
        switch(strtoupper($editionCode))
        {
            case "A":
            case "R":
            case "1":
            case "2":
            case "4":
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
        $attributeSetId     = Mage::getModel('eav/entity_attribute_set')
                ->getCollection()
                ->setEntityTypeFilter($entityTypeId)
                ->addFieldToFilter('attribute_set_name', $attrSetName)
                ->getFirstItem()->getAttributeSetId();

        $product->setAttributeSetId($attributeSetId);

        //SKU
        $product->setSku($data[1]);
            
        //Name
        $product->setName($data[2]);

        //Description
        $product->setDescription($data[21]);

        //Short Description
        if($data[6] != "")
        {
            $shortDesc = $data[6];
        }
        else 
        {
            $shortDesc = strip_tags(substr($data[21], 0, 100)); 	
        }
        $product->setShortDescription($shortDesc);
        $product->setShoppingFeedDescription($shortDesc);
            
        //Url Key
        if($data[103] != "")
        {
            $tmpUrlKey = str_replace(".html" , "", (strtolower(substr($data[103], strrpos($data[103], '/') + 1))));
            $product->setUrlKey($tmpUrlKey);
        }

        if(strtoupper($data[100]) == 'TRUE')
        {
            $product->setStatus(2);
            echo "got a hider\n";
        }
        else
        {
            $product->setStatus(1);
        }
            
        //Special Pricing
        $advancedPricingArray = explode('|', $data[12]);
        $specialPrice = -1;
        $specialPriceStart = "-1";
        $specialPriceEnd = "-1";

        foreach($advancedPricingArray as $specialPriceBlock)
        {
            $potentialSpecialPriceBlock = explode('~', $specialPriceBlock);
            //5 position = Price
            //6 position = Start Date
            //7 position  End date

            //CHECK FOR CURRENT ACTIVE 1st

            $todaysDate = strtotime(date("m/d/Y"));
            $startDate = strtotime($potentialSpecialPriceBlock[6]);
            $endDate = strtotime($potentialSpecialPriceBlock[7]);

            if($endDate > $todaysDate && $todaysDate > $startDate)
            {
                $specialPrice = $potentialSpecialPriceBlock[5];
                $specialPriceStart = $potentialSpecialPriceBlock[6];
                $specialPriceEnd = $potentialSpecialPriceBlock[7];
            }

        }

        //CHECK FOR FUTURE if no current actives found
       if($specialPrice == -1)
       {
            $currentStartDate = "";
            $foundAFutureSpecial = false;
            $i = 0;
            foreach($advancedPricingArray as $specialPriceBlock)
            {
                $potentialSpecialPriceBlock = explode('~', $specialPriceBlock);
                //5 position = Price
                //6 position = Start Date
                //7 position  End date

                //CHECK FOR future special prices
                $todaysDate = strtotime(date("m/d/Y"));
                $startDate = strtotime($potentialSpecialPriceBlock[6]);
                $endDate = strtotime($potentialSpecialPriceBlock[7]);

                if($foundAFutureSpecial == false)
                {
                    $currentFutureStartDate = strtotime($potentialSpecialPriceBlock[6]);
                }

                if($endDate > $todaysDate && $todaysDate < $startDate && $startDate <= $currentFutureStartDate)
                {
                    $specialPrice = $potentialSpecialPriceBlock[5];
                    $specialPriceStart = $potentialSpecialPriceBlock[6];
                    $specialPriceEnd = $potentialSpecialPriceBlock[7];
                    $currentStartDate = $startDate;
                    $foundAFutureSpecial = true;
                }
                $i++;
            }
        }
           
        if($specialPrice != -1)
        {
            $product->setSpecialPrice($specialPrice);
            $product->setSpecialPriceFromDate($specialPriceStart);
            $product->setSpecialPriceToDate($specialPriceEnd);            
        }
  
        $attributeListBlock = explode("|", $data[101]);
        foreach ($attributeListBlock as $attribute)
        {
            $attributeArray = explode("~", $attribute);
            //Format
            if(strtoupper($attributeArray[0]) == "PRODUCT TYPE" || strtoupper($attributeArray[0]) == "PROJECT TYPE")
            {
                $format_val = attributeValueExists('format', $attributeArray[1]);

                if($format_val == "")
                {
                    addAttributeValue('format', $attributeArray[1]);
                    $format_val = attributeValueExists('format', $attributeArray[1]);

                    if($format_val == "")
                    {
                        throw new Exception(' --- FORMAT VALUE NOT CREATED ---');
                    }
                }

                $product->setFormat($format_val);
            }

             //Author Speaker Editor
            if(strtoupper($attributeArray[0]) == "AUTHOR")
            {
                $product->setAuthorSpeakerEditor($attributeArray[1]);
            }
        }
        	
	//Brand
        $product->setBrand($data[10]);	
        
        //Can be discountable
        if(strtoupper($data[123]) == "TRUE")
        {
            $product->setCanBeDiscountable(0);
        }
        else
        {
           $product->setCanBeDiscountable(1); 
        }
        
        //Meta Title
        $product->setMetaTitle($data[2]);

        //Meta Keywords
        if($data[51] != "")
        {
            $product->setMetaKeyword($data[51]);
           
        }
        
        //Meta Deescription
        if($data[52] != "")
        {
            $product->setMetaDescription($data[52]);
        }
        
        $product->setTaxClassId($taxClassIds["Taxable Goods"]);
    	
    	if($product->getTypeId() == "downloadable")
    	{
            $product->setTaxClassId($taxClassIds["Downloads"]);
    	}
    	
    	if($product->getTypeId() == "virtual")
    	{
            $product->setTaxClassId(0);
    	}
        
        addIWAttributes($product,$data);
        
        //Related Products
        if($data[104] != "")
        {
            $param = array();
            unset($param);
             $position = 1;
            $upSellItems = explode("|", $data[104]);
            foreach($upSellItems as $upSellString)
            {
                $upSellPieces = explode("~",$upSellString);
                $upSellSku = $upSellPieces[0];

                $upSellProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$upSellSku);
                if($upSellProduct)
                {
                    $param[$upSellProduct->getId()] = array('position'=>$position);
                    $position++;
                }
   
                $product->setUpSellLinkData($param);
            }
        }
        
        return 	$product;		
    }
   
    function addIWAttributes($product,$data)
    {
        $attributeListBlock = explode("|", $data[101]);
        foreach ($attributeListBlock as $attribute)
        {
            $attributeArray = explode("~", $attribute);
            //Technique
           if(strtoupper($attributeArray[0]) == "TECHNIQUE")
           {
                $techniqueVal = attributeValueExists('technique', $attributeArray[1]);
               $product->setTechnique($techniqueVal);
           }

           //Skill Level
           if(strtoupper($attributeArray[0]) == "SKILL LEVEL")
           {
               $skillLevelVal = attributeValueExists('skill_level', $attributeArray[1]);
               $product->setSkillLevel($skillLevelVal);
           }

           //For
           if(strtoupper($attributeArray[0]) == "FOR")
           {
               $forVal = attributeValueExists('for', $attributeArray[1]);
               $product->setFor($forVal);
           }
        }
        
        //Table of Contents
        if($data[23] != "")
        {
            $product->setTableOfContents($data[23]);
        }
        
        //Preview
        if($data[25] != "")
        {
            $product->setPreview($data[25]);
        }
        
        //About Author
        if($data[27] != "")
        {
            $product->setAboutAuthor($data[27]);
        }
        
        //Details
        if($data[29] != "")
        {
            $product->setDetails($data[29]);
        }
        
        //External Purchase Link
        if($data[159] != "")
        {
            $product->setExternalPurchaseLink($data[159]);
        }
        
        //PayPal Enabled
        if(strtoupper($data[154]) == "PAYPAL EXTRESS CHECKOUT")
        {
             $product->setEnablePaypal(0);
        }
        else
        {
            $product->setEnablePaypal(1);
        } 
        
        $product->setIsCdsItem(0);
    }

     /**
	 * If Product already exists add the current website being imported to its website ids list
	 * 
	 * @param $product Mage Product
	 */
    function addStore($product)
    {
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
                Mage::log("Updated Product (website id) : ".$product->getSku(),null, $logFile);
            } 
            catch (Exception $e) 
            {
                Mage::log("addStore (website id) Error: ".$e->getMessage(),null, $logFile);
            }

        }
        if(!$storeAssociated)
        {
            $existingStoreIds[] = count($existingStoreIds) + 1;
            $existingStoreIds[count($existingStoreIds) - 1] = $storeId;

            try 
            {
                $product->setStoreIDs($existingStoreIds)->save();
                Mage::log("Updated Product (store id) : ".$product->getSku(),null, $logFile);
            } 
            catch (Exception $e) 
            {
                Mage::log("addStore (store id) Error: ".$e->getMessage(),null, $logFile);
            }
        }
    }
    
    function validTopLevel($topLevelRootName)
    {
        switch(strtoupper($topLevelRootName))
        {
            case "KNITTING":
            case "BEADING":
            case "CROCHET":
            case "JEWELRY MAKING":
            case "QUILTING":
            case "SEWING":
            case "SPINNING":
            case "WEAVING":
            case "MIXED MEDIA":
                return TRUE;
                break;
            default:
                return FALSE;
                break;	        				
        }
    }

?>
