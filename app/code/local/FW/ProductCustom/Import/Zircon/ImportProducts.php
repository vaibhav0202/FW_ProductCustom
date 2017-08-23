<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';
      Mage::app();
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
   
    //Command Line Args
    // PW Store View Name = PatternWorks.com
    // PW Store Website Name = PatternWorks.com
    // KQ Store View Name = KeepSakeQuilting.com
    // KQ Store Website Name = KeepSakeQuilting.com
    // KNA Store View Name = KeepSakeNeedleArts.com
    // KNA Store Website Name = KeepSakeNeedleArts.com
    
    
    ///////////////////////////////////////////////INITIALIZATION OF GLOBAL ELEMENTS/////////////////////////////////
    //Global Vars
    $simpleVariationMothers = array();
    $simpleVariationDaughters = array();
    $simpleVariationMotherDaughters = array();
    $simpleVariationDaughterOptions = array();
    $simpleVariationYarnDaughters = array();
    $medleyPartners = array();
    $medleyOptions = array();
    $processedMedleySkus = array();
    $xrefValues = array();
    $taxClassIds = array();
    $prodFiles = array();
    $xrefFiles = array();
    $prodDir = '';
    $prodExecutedDir = '';
    $count = 0;
    $logFile = 'PatternWorks_Product_Import.log';
    $storeViewName = 'PatternWorks.com';
    $webSiteName = 'PatternWorks.com';
    $companyCode = "03";
    
    $needleBrands = array
    (
        1=> "Addi",
        12=> "Balene",
        2=> "Brittany",
        3=> "Bryspun",
        13=> "Clover",
        20=> "Colonial Rosewood",
        4=> "Crystal Palace",
        29=> "Destiny Rosewood",
        24=> "George",
        15=> "HiyaHiya",
        5=> "Lantern Moon",
        6=> "Jiffy Plastic",
        27=> "KA Classic",
        16=> "Knitters Pride",
        14=> "Kollage Yarn",
        17=> "Palmwood",
        7=> "Plymouth Bamboo",
        8=> "Pony Pearls",
        25=> "Profihook",
        23=> "QuickSilver",
        26=> "Soft Touch",
        22=> "Speedway",
        9=> "Susan Bates",
        10=> "Susannes Ebony",
        11=> "Sussanes Rosewood",
        28=> "Takumi"
    );
    
    $needleMaterials = array
    (
        23=> "Bamboo",
        20=> "Metal",
        21=> "Plastic",
        22=> "Wood"
    );
    
    $needleTypes = array
    (
        10=> "Circular",
        13=> "Double Point",
        11=> "Flex",
        12=> "Single Point",
        19=> "Crochet"
    );
    
    $kitTimes = array
    (
        "O"=> "Other",
        "A"=> "Afternoon",
        "W"=> "Weekend",
        "2W"=> "Two Weekends"
    );
    
    $storeImageRoots = array
    (
        "01"=> "https://www.kqimageserver.com/kqimages/large/",
        "02"=> "https://www.kqimageserver.com/naimages/large/",
        "03"=> "https://www.kqimageserver.com/pwimages/large/"
    );
  	
    //Initialize import directory
    $prodDir = Mage::getBaseDir() . '/var/importexport/zircon_inventory';

    //Load Tax Classes to be used in Product Creation
    $taxClasses = Mage::getModel('tax/class')->getCollection();

    foreach ($taxClasses as $taxClass){
        $taxClassIds[$taxClass->getClassName()] = $taxClass->getId();
    }
 
    //Load Store and Website Ids
    $store = Mage::getModel('core/store')->load($storeViewName, 'name');
    $storeId = $store->getId();
    $website = Mage::getModel('core/website')->load($webSiteName, 'name');
    $websiteId = $website->getId();
	
    //////////////////////////////////////////////////////////////////////////////////////////////////////
    // Get Product Records
    getZirconRecords($companyCode, "product");
    
    //Populate the XREF File
    //getZirconRecords("http://localhost:9191/rbkeep/Page_xref_web", "xref");
    initProductFiles();
    initXrefFiles();
    loadXrefValues();

    //Create Variation Products    
    findSimpleVariationMothers();
    findSimpleVariationDaughters();
    processSimpleVariations();
    
    //Create Yarn Products 
    findYarnMothers();
    findYarnDaughters();
    processYarns();
    
    //Simple Products
    processSimpleProducts();
    
    //KQ Medleys
    //processMedleys();
        
    function processSimpleProducts()
    {
    	global $logFile;
    	global $storeViewName;
    	global $count;
        global $prodFiles;

         //Process each found file
        foreach ($prodFiles AS $prodFile) {  
            if (($handle = fopen($prodFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($prodFile), true);
                foreach($json["Parts_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $sizeflag = $zirconProduct["sizeflag1"];
                    $daughterFlag =  $zirconProduct["daughter"];
                    $yarnFlag =  $zirconProduct["yarn_flag"];
                    $name = trim($zirconProduct["desc"]);
//Mage::log("|".$sku."|".$name,null, $logFile);  
//continue;
                    //SIMPLE PRODUCTS - No simple variations or yarn
                    if($zirconProduct["prodtyp2"] != '' && $name != ""){
                        if(($sizeflag <> "Y" && $daughterFlag == '') || $sizeflag == "Y" && $daughterFlag == '' && $yarnFlag == "M"){
                        
                            //Determine if this product exists already
                            $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);

                            //Create new product or update existing product
                            $product = initProduct($product, $zirconProduct);

                            try{
                                $product->save();
                                processImage($product, $zirconProduct);
                                $product->save();
                                createStockItem($product);
                                updateStockItem($zirconProduct, $product);
                                $count++;
                                Mage::log($storeViewName."::Processed Product: ".$product->getSku()."::Count ".$count,null, $logFile);                                    
                            }
                            catch(Exception $e){
                                Mage::log($storeViewName."::processProducts Error: ".$e->getMessage(),null, $logFile);
                            }
                        } 
                    } 
                }
            }
            fclose($handle);
        }
    }

    //Create Basic Producs
    function processMedleys()
    {
    	global $logFile;
    	global $storeViewName;
        global $websiteId;
        global $storeId;
    	$count = 10000;
        global $prodFiles;
        global $medleyPartners;
        global $processedMedleySkus;
        global $medleyOptions;
        global $taxClassIds;

        foreach($medleyPartners as $medleySku=>$partnerSku){
            if(in_array($medleySku,$processedMedleySkus) || in_array($medleySku,$processedMedleySkus)){
                continue;
            }
            
            $count++;
            $partner1 = Mage::getModel('catalog/product')->loadByAttribute('sku',$medleySku);
            $partner2 = Mage::getModel('catalog/product')->loadByAttribute('sku',$partnerSku[0]);
            
            if(!$partner1 || !$partner2){
                continue;
            }
            $processedMedleySkus[] = $medleySku;
            $processedMedleySkus[] = $partnerSku[0];
            $parentSku = $medleySku . "_" . $partnerSku[0] . "_MEDLEY";
            $parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$parentSku);
             
             if(!$parentProduct)
             {
                 $parentProduct = new Mage_Catalog_Model_Product();
             }

             $parentName = $partner1->getName();
             $parentName = str_replace("1/2 YARD ", "", strtoupper($parentName));
             $parentName = str_replace("1/4 YARD ", "", strtoupper($parentName));
             $parentName = str_replace("FAT-QUARTER ", "", strtoupper($parentName));
             $parentName = str_replace("FAT QUARTER ", "", strtoupper($parentName));
            
            //Parent Price
             
            if($partner1->getSpecialPrice() != ""){
                $lowestPrice = $partner1->getSpecialPrice();
            }
            else{
                $lowestPrice = $partner1->getPrice();
            }
            
            if($partner1->getSpecialPrice() != ""){
                 if($partner2->getSpecialPrice() < $lowestPrice){
                    $lowestPrice = $partner2->getSpecialPrice();
                 }   
            }
            else{
                 if($partner2->getPrice() < $lowestPrice){
                    $lowestPrice = $partner2->getPrice();
                 }   
            }
                
            $parentProduct->setPrice($lowestPrice);	 
            
            //SKU
            $parentProduct->setSku($parentSku);	
            
            //NAME
            $parentProduct->setName($parentName);
            
            //VISIBILITY
            $parentProduct->setVisibility(4);
    	   
            //Store Values
            $websiteIds[0] = $websiteId;
            $storeIds[0] = $storeId;
            $parentProduct->setWebsiteIDs($websiteIds);
            $parentProduct->setStoreIDs($storeIds);
            
            $parentProduct->setStatus(1);
            $parentProduct->setWeight(1);
            $parentProduct->setTaxClassId($taxClassIds["Taxable Goods"]);
            //Url Key
            $urlKey = $parentSku;
            Mage::helper('catalog/product_url')->format($urlKey);
            preg_replace('#[^0-9a-z_]+#i', '-',$urlKey);
            strtolower($urlKey);
            trim($urlKey, '-');
            $parentProduct->setUrlKey($urlKey);
            
            //Description
            $parentProduct->setDescription($partner1->getDescription());
            $attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product','fw_options'); 
            
            //partner1 child
            $label = $medleyOptions[$medleySku];
            addAttributeValue('fw_options', $label);
            $optionId = attributeValueExists('fw_options', $label);
            $partner1->setFwOptions($optionId);
            $partner1->setVisibility(1);
            $partner1->save();

            if($partner1->getSpecialPrice() != ""){
               $deltaPrice = $partner1->getSpecialPrice() - $parentProduct->getPrice();
            }
            else{
                $deltaPrice = $partner1->getPrice() - $parentProduct->getPrice();
            }

            $configurableProductsData[$partner1->getId()] = array('0'=>
                array(
                    'attribute_id'	=> $attributeId,
                    'label'             => $label,
                    'value_index'	=> $optionId,
                    'is_percent'	=> 0,
                    'pricing_value'	=> $deltaPrice
                    )
                );
		
            $configAtt[0] =  array(
                    'attribute_id'  => $attributeId, //The attribute id
                    'label'         => $label,
                    'value_index'   => $optionId, //The option id
                    'is_percent'    => 0,
                    'pricing_value' => $deltaPrice				
            );
            
            //partner2 child
            $label = $medleyOptions[$partnerSku[0]];
            addAttributeValue('fw_options', $label);
            $optionId = attributeValueExists('fw_options', $label);
            $partner2->setFwOptions($optionId);
            $partner2->setVisibility(1);
            $partner2->save();

            if($partner2->getSpecialPrice() != ""){
               $deltaPrice = $partner2->getSpecialPrice() - $parentProduct->getPrice();
            }
            else{
                $deltaPrice = $partner2->getPrice() - $parentProduct->getPrice();
            }

            $configurableProductsData[$partner2->getId()] = array('0'=>
                array(
                    'attribute_id'	=> $attributeId,
                    'label'             => $label,
                    'value_index'	=> $optionId,
                    'is_percent'	=> 0,
                    'pricing_value'	=> $deltaPrice
                    )
                );
		
            $configAtt[1] =  array(
                    'attribute_id'  => $attributeId, //The attribute id
                    'label'         => $label,
                    'value_index'   => $optionId, //The option id
                    'is_percent'    => 0,
                    'pricing_value' => $deltaPrice				
            );

            $html_id = "config_super_product__attribute_".$count;
            
            //Create the configurable attributes data
            $configurableAttributesData = array(
                '0'                 => array(
                'id'                => NULL,
                'label'             => 'Options', //optional, will be replaced by the modified api.php
                'position'          => NULL,
                'values'            => $configAtt,
                'attribute_id'      => $attributeId, //get this value from attributes api call
                'attribute_code'    => 'fw_options', 
                'frontend_label'    => '', //optional, will be replaced by the modifed api.php
                'html_id'           => 'config_super_product__attribute_'.$html_id
                    )
            );
            
            $parentProduct->setTypeId('configurable');
            $parentProduct->setConfigurableProductsData($configurableProductsData);
            $parentProduct->setConfigurableAttributesData($configurableAttributesData);
            $parentProduct->setCanSaveConfigurableAttributes(1);
            $parentProduct->setPrice($lowestPrice);
            
            //Attribute Set Id
            $attrSetName = "Default";;
            $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
            $attributeSetName   = $attrSetName;
            $attributeSetId     = Mage::getModel('eav/entity_attribute_set')
                    ->getCollection()
                    ->setEntityTypeFilter($entityTypeId)
                    ->addFieldToFilter('attribute_set_name', $attributeSetName)
                    ->getFirstItem()->getAttributeSetId();
            $parentProduct->setAttributeSetId($attributeSetId);

            try{
                if($parentProduct->getId()){
                    $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                    $conn->query("Delete from catalog_product_super_attribute where product_id = ".$parentProduct->getId());
                }
        
                $parentProduct->save();
                createStockItem($parentProduct);
                Mage::log("Created Medley Parent:".$parentProduct->getSku().":".$parentProduct->getName(),null, $logFile);                                     
             }
             catch(Exception $e){
                 Mage::log("processMedleys Error: ".$e->getMessage(),null, $logFile);
             }
            
            unset($configurableProductsData);
            unset($configAtt);
            unset($configurableAttributesData);
        }
    }

    function findSimpleVariationMothers(){
        global $simpleVariationMothers;
        global $storeViewName;
        global $prodFiles;
        global $logFile;
    
        //Process each found file
        foreach ($prodFiles AS $prodFile) {  
            if (($handle = fopen($prodFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($prodFile), true);
                foreach($json["Parts_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $sizeflag = $zirconProduct["sizeflag1"];
                    $daughterFlag =  $zirconProduct["daughter"];
                    $motherFlag =  $zirconProduct["mother"];
                    $yarnFlag =  $zirconProduct["yarn_flag"];
                    $name = trim($zirconProduct["desc"]);
                    
                    //simple variation mother and daughters - non yarn and medleys
                    if($sizeflag == "Y" && $yarnFlag <> "M" && $yarnFlag <> "Y" && $name != ""){
                        if($motherFlag != '' && $daughterFlag == ''){
                            $simpleVariationMothers[] = $sku;

                            $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                            $product = initProduct($product, $zirconProduct);

                            try{
                                $product->save();
                                processImage($product, $zirconProduct);
                                $product->save();
                                createStockItem($product);
                                updateStockItem($zirconProduct, $product);
                                Mage::log($storeViewName."::Processed Mother Product: ".$product->getSku(),null, $logFile);
                            }
                            catch(Exception $e){
                                Mage::log($storeViewName."::findSimpleVariationMothers Error: ".$e->getMessage(),null, $logFile);
                            }
                        }
                    }     
                }  
            }
        }
    }
   
    function findSimpleVariationDaughters(){
        global $simpleVariationMothers;
        global $simpleVariationDaughters;
        global $simpleVariationDaughterOptions;
        global $prodFiles;
        global $storeViewName;
        global $logFile;
        
    
        //Process each found file
        foreach ($prodFiles AS $prodFile) {  
            if (($handle = fopen($prodFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($prodFile), true);
                foreach($json["Parts_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $sizeflag = $zirconProduct["sizeflag1"];
                    $daughterFlag =  $zirconProduct["daughter"];
                    $motherFlag =  $zirconProduct["mother"];
                    $name = trim($zirconProduct["desc"]);
                    //simple variation daughters - non  medleys
                    if($sizeflag == "Y" && $name != ""){
                        if($motherFlag != "" && $daughterFlag != ""){ //daughters
                            if(in_array($motherFlag, $simpleVariationMothers)){
         
                                $simpleVariationDaughters[$sku] = $motherFlag;
                                $simpleVariationDaughterOptions[$sku] = $zirconProduct["color"];

                                $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                                $product = initProduct($product, $zirconProduct);

                                try{
                                    
                                    $product->save();
                                    createStockItem($product);
                                    updateStockItem($zirconProduct, $product);
                                    Mage::log($storeViewName."::Processed Daughter Product: ".$product->getSku(),null, $logFile);
                                }
                                catch(Exception $e){
                                    Mage::log($storeViewName."::findSimpleVariationDaughters Error: ".$e->getMessage(),null, $logFile);
                                }
                            }
                        }   
                    }
                }
            }
        }   
    }
    
    function findYarnMothers(){
        global $yarnMothers;
        global $storeViewName;
        global $prodFiles;
        global $logFile;
    
        //Process each found file
        foreach ($prodFiles AS $prodFile) {  
            if (($handle = fopen($prodFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($prodFile), true);
                foreach($json["Parts_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $sizeflag = $zirconProduct["sizeflag1"];
                    $daughterFlag =  $zirconProduct["daughter"];
                    $motherFlag =  $zirconProduct["mother"];
                    $yarnFlag =  $zirconProduct["yarn_flag"];
                    $name = trim($zirconProduct["desc"]);

                    //simple variation mother and daughters - non yarn and medleys
                    if($sizeflag == "Y" && $yarnFlag == "Y" && $name != ""){
                        if($motherFlag != '' && $daughterFlag == ''){
                            $yarnMothers[] = $sku;

                            $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                            $product = initProduct($product, $zirconProduct);

                            try{
                                $product->save();
                                createStockItem($product);
                                updateStockItem($zirconProduct, $product);
                                Mage::log($storeViewName."::Processed Mother Yarn Product: ".$product->getSku(),null, $logFile);
                            }
                            catch(Exception $e){
                                Mage::log($storeViewName."::findYarnMothers Error: ".$e->getMessage(),null, $logFile);
                            }
                        }
                    }     
                }  
            }
        }
    }

    function findYarnDaughters(){
        global $yarnMothers;
        global $yarnDaughters;
        global $prodFiles;
        global $storeViewName;
        global $logFile;
        
        //Process each found file
        foreach ($prodFiles AS $prodFile) {  
            if (($handle = fopen($prodFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($prodFile), true);
                foreach($json["Parts_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $sizeflag = $zirconProduct["sizeflag1"];
                    $daughterFlag =  $zirconProduct["daughter"];
                    $motherFlag =  $zirconProduct["mother"];
                    $name = trim($zirconProduct["desc"]);

                    //simple yarn daughters
                    if($sizeflag == "Y" && $name != ""){
                        if($motherFlag != "" && $daughterFlag != ""){ //daughters
                            if(in_array($motherFlag, $yarnMothers)){
         
                                $yarnDaughters[$sku] = $motherFlag;

                                $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                                $product = initProduct($product, $zirconProduct);
                                
                                $motherProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$motherFlag);

                                try{
                                    $product->save();
                                    processImage($product, $zirconProduct);
                                    $product->save();
                                    createStockItem($product);
                                    updateStockItem($zirconProduct, $product);

                                    //Set the mother product's image to the daughter image - this is because the current site's mother's image is not used/displayed and assumed to be incorrect
                                    processYarnMotherImage($motherProduct, $zirconProduct);
                                    $motherProduct->save();
                                    Mage::log($storeViewName."::Processed Daughter Yarn Product: ".$product->getSku(),null, $logFile);
                                }
                                catch(Exception $e){
                                    Mage::log($storeViewName."::findYarnDaughters Error: ".$e->getMessage(),null, $logFile);
                                }
                            }
                        }   
                    }
                }
            }
        }   
    }
    
    function processYarns(){
        global $yarnMotherDaughters;
        global $yarnMothers;
        global $yarnDaughters;
        global $storeViewName;
        global $logFile;
        

        //Loop through all the parent skus then find the matching childer skus
    	foreach ($yarnMothers as $mother){
            $daughters = array();
            $i = 0;

            foreach ($yarnDaughters as $daughterSku=>$motherSku){
                if($motherSku == $mother){ //its a child associated with the parent at hand{
                    $daughters[$i] = $daughterSku;
                }
                $i++;
            }

            $yarnMotherDaughters[$mother] = $daughters;
    	}

        foreach($yarnMotherDaughters as $mother=>$daughters){
            $motherProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$mother);
            if($motherProduct){
                $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($motherProduct->getId());	
                $stockItem->setData('manage_stock', 0);
                $stockItem->save(); 
            }
            else{
                continue;
            }

            $groupedLinkData = array();
            if($daughters){
                $i = 0;

                foreach($daughters as $daughter){
                    $daughterProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$daughter);

                    if($daughterProduct){
                        $groupedLinkData[$daughterProduct->getId()] = array('qty' => 0, 'position' => $i);
                        $daughterProduct->setVisibility(1);
                        $daughterProduct->save();
                    } 
                    $i++;
                }
                
                $motherProduct->setGroupedLinkData($groupedLinkData); 
                $motherProduct->setTypeId('grouped');

                try {
                    $motherProduct->save();
                    Mage::log($storeViewName."::Processed Parent Yarn Product: ".$motherProduct->getSku(),null, $logFile);  
                }
                catch (Exception $e){
                    echo $e;
                    Mage::log($storeViewName."::processYarns (create parent):Error: ".$e->getMessage(),null, $logFile);
                }
            }    
        }
    }
  
    function processSimpleVariations(){
        global $simpleVariationMotherDaughters;
        global $simpleVariationDaughterOptions;
        global $simpleVariationMothers;
        global $simpleVariationDaughters;
        global $logFile;
        global $storeViewName;
        $count = 20000;
        
        $configAtt = array();
    	$configurableProductsData  = array();
    	$configurableAttributesData = array();
        
            //Loop throuh all the parent skus then find the matching childer skus
    	foreach ($simpleVariationMothers as $mother)
    	{
            $daughters = array();
            $i = 0;

            foreach ($simpleVariationDaughters as $daughterSku=>$motherSku)
            {
                if($motherSku == $mother) //its a child associated with the parent at hand
                {
                    $daughters[$i] = $daughterSku;
                }
                $i++;
            }

            $simpleVariationMotherDaughters[$mother] = $daughters;
    	}
        
        $attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product','fw_options'); 
        
        
        foreach($simpleVariationMotherDaughters as $mother=>$daughters){
            $motherProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$mother);
                    
            if($motherProduct){
                $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($motherProduct->getId());	
                $stockItem->setData('manage_stock', 0);
                $stockItem->save(); 
            }
            else{
                continue;
            }

            if($daughters){
                $i = 0;
                $j = 0;
                foreach($daughters as $daughter){
                   $daughterProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$daughter);  

                    if($daughterProduct){
                    if($j == 0) {
                        $lowestPrice = $daughterProduct->getPrice(); 
                    }
                    else {
                        if($daughterProduct->getPrice() < $lowestPrice){
                           $lowestPrice = $daughterProduct->getPrice();
                        }
                    }
                    $j++;
                 }
                 }
                 $motherProduct->setPrice($lowestPrice);    

                foreach($daughters as $daughter){
                    $daughterProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$daughter);
                    $count++;   

                    if($daughterProduct){
                        //add fw_option value
                        $label = $simpleVariationDaughterOptions[$daughterProduct->getSku()];
                        addAttributeValue('fw_options', $label);

                        $optionId = attributeValueExists('fw_options', $label);
                        $daughterProduct->setFwOptions($optionId);
                        $daughterProduct->setVisibility(1);
                        $daughterProduct->save();

                        if($daughterProduct->getSpecialPrice() != "" && $motherProduct->getSpecialPrice() != ""){
                             $deltaPrice = $daughterProduct->getSpecialPrice() - $motherProduct->getSpecialPrice();
                        }
                        else{
                             $deltaPrice = $daughterProduct->getPrice() - $motherProduct->getPrice();
                        }

                        $configurableProductsData[$daughterProduct->getId()] = array('0'=>
                            array(
                                'attribute_id'	=> $attributeId,
                                'label'         => $label,
                                'value_index'	=> $optionId,
                                'is_percent'	=> 0,
                                'pricing_value'	=> $deltaPrice
                                )
                            );

                        $configAtt[$i] =  array(
                                'attribute_id'  => $attributeId, //The attribute id
                                'label'         => $label,
                                'value_index'   => $optionId, //The option id
                                'is_percent'    => 0,
                                'pricing_value' => $deltaPrice				
                        );
                    } 
                    $i++;
                }
            
                $html_id = "config_super_product__attribute_".$count;
            
                //Create the configurable attributes data
                $configurableAttributesData = array(
                    '0'                 => array(
                    'id'                => NULL,
                    'label'             => 'Options', //optional, will be replaced by the modified api.php
                    'position'          => NULL,
                    'values'            => $configAtt,
                    'attribute_id'      => $attributeId, //get this value from attributes api call
                    'attribute_code'    => 'fw_options', 
                    'frontend_label'    => '', //optional, will be replaced by the modifed api.php
                    'html_id'           => 'config_super_product__attribute_'.$html_id
                        )
                );

                $motherProduct->setTypeId('configurable');
                $motherProduct->setConfigurableProductsData($configurableProductsData);
                $motherProduct->setConfigurableAttributesData($configurableAttributesData);
                $motherProduct->setCanSaveConfigurableAttributes(1);

                try {
                    $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                    $conn->query("Delete from catalog_product_super_attribute where product_id = ".$motherProduct->getId());
                    $motherProduct->save();
                    Mage::log($storeViewName."::Processed Parent Product: ".$motherProduct->getSku(),null, $logFile);  
                }
                catch (Exception $e){
                    echo $e;
                    Mage::log($storeViewName."::processSimpleVariations (create parent):Error: ".$e->getMessage(),null, $logFile);
                }

                unset($configurableProductsData);
                unset($configAtt);
                unset($configurableAttributesData);
            }
        }    
    }

    //Initialize the Magento Product record - not saved though
    function initProduct($product, $zirconProduct){
        global $websiteId;
        global $storeId;
        global $taxClassIds;
        global $needleBrands; 
        global $needleMaterials;
        global $needleTypes;
        global $kitTimes;
        global $xrefValues;
        global $medleyPartners;
        global $medleyOptions;
        global $logFile;
        global $storeViewName;

    	if(!$product){
            $initProduct = new Mage_Catalog_Model_Product();
            $websiteIds[0] = $websiteId;
            $storeIds[0] = $storeId;
            $initProduct->setWebsiteIDs($websiteIds);
            $initProduct->setStoreIDs($storeIds);
            $initProduct->setTypeId('simple');	
            $initProduct->setCreatedAt(date("Y-m-d H:i:s"));
    	}
    	else{
            $initProduct = clone $product;
    	}
        
        $company = $zirconProduct["company"];
	$ptype2 =  trim($zirconProduct["prodtyp2"]);
        $price = $zirconProduct["price"];
        $sku = $zirconProduct["_id"];
        $expectedDate = $zirconProduct["expected_date"];
        $prodType = trim($zirconProduct["prod_type"]);
        $needleLen = $zirconProduct["needlelen1"];
        $needleDia = $zirconProduct["needlediam1"];
        $materialCode = $zirconProduct["matcode1"];
        $manuCode = $zirconProduct["manucode1"];
        $yarnFlag = $zirconProduct["yarn_flag"];
        $yarnImage = $zirconProduct["yarn_image"];
        $yarnAlias = $zirconProduct["yarn_alias"];
        $notes = trim($zirconProduct["notes3"]);
        $description = trim($zirconProduct["desc"]);
        $cost = trim($zirconProduct["cog"]);
        
        if($ptype2 == '120'){
             $initProduct->setTypeId('downloadable');
        }
        
        //Attribute Set Id
        $attrSetName = "Default";;
        $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
        $attributeSetName   = $attrSetName;
        $attributeSetId     = Mage::getModel('eav/entity_attribute_set')
                ->getCollection()
                ->setEntityTypeFilter($entityTypeId)
                ->addFieldToFilter('attribute_set_name', $attributeSetName)
                ->getFirstItem()->getAttributeSetId();
        $initProduct->setAttributeSetId($attributeSetId);
           	
	//SKU
	$initProduct->setSku($sku);		    	   
    	   	
        //Name
        $initProduct->setName($description);
        
        //Product Type
        $product_type_val = attributeValueExists('product_type', $ptype2);
        $initProduct->setProductType($product_type_val);
	
        //Description
        $initProduct->setDescription($notes);
    	   
        //Short Description
        $initProduct->setShortDescription(strip_tags(substr($notes, 0, 100)));
        
        //Shopping Feed Description
        $initProduct->setShoppingFeedDescription(strip_tags(substr($notes, 0, 100)));
			    	   	
        //Url Key
        $urlKey = $description;
        Mage::helper('catalog/product_url')->format($urlKey);
        preg_replace('#[^0-9a-z\s]+#i', '-',$urlKey);
        $urlKey = str_replace(" ", "-", $urlKey);
        $urlKey = strtolower($urlKey);
        $urlKey = trim($urlKey, '-');
        $initProduct->setUrlKey($urlKey);
        
        //Meta Title
        $initProduct->setMetaTitle($description . "- Product Details");
    	   	
	//Meta Keywords
	$initProduct->setMetaKeyword($zirconProduct["specdesc"]. "," . $description);

        //Meta Description	
        $initProduct->setMetaDescription(strip_tags(substr($notes, 0, 200)));

        if($expectedDate != '')$initProduct->setWarehouseAvailDate($expectedDate); 
        
        //Visible 1 = invisible
        //Visible 2 = catalog visible
        //Visible 3 = search visible
        //Visible 4 = catalog/search visible
        if($zirconProduct["suppress"] == "1"){
            $initProduct->setVisibility(1);
        }else {
            $initProduct->setVisibility(4);
        }
        
	$initProduct->setStatus(1);
        $initProduct->setWeight(1);
        $initProduct->setTaxClassId($taxClassIds["Taxable Goods"]);
        
        //if patternworks
        if($company == "03"){
            $initProduct->setPatternDifficulty($zirconProduct["difficulty"]);
            
            //NEEDLE PRODUCTS
            if($ptype2 == '10' || $ptype2 == '11' || $ptype2 == '12' || $ptype2 == '13' || $ptype2 == '19'){

                //NEEDLE BRAND
                if($manuCode != ""){
                    $needle_brand_val = attributeValueExists('needle_brand', str_replace("'","", $needleBrands[$manuCode]));
                    $initProduct->setNeedleBrand($needle_brand_val);
                }

                //NEEDLE MATERIAL
                if($materialCode != ""){
                    $needle_material_val = attributeValueExists('needle_material', $needleMaterials[$materialCode]);
                    $initProduct->setNeedleMaterial($needle_material_val);
                }

                //NEEDLE DIAMETER
                if($needleDia != ""){
                    $needle_diameter_val = attributeValueExists('needle_diameter', $needleDia);
                    $initProduct->setNeedleDiameter($needle_diameter_val);
                }

                //NEEDLE LENGTH
                if($needleLen != ""){
                    $needle_length_val = attributeValueExists('needle_length', $needleLen);
                    $initProduct->setNeedleLength($needle_length_val);
                }

                //NEEDLE TYPE
                $needle_type_val = attributeValueExists('needle_type', $needleTypes[$ptype2]);
                $initProduct->setNeedleType($needle_type_val);
            }
        }
            
        //if keepsake quilting
        if($company == "01"){

            //Sold By Length
            if((strlen($prodType) ==  5 && (substr($prodType, 0, 1) == "0") && $yarnFlag != "M") ||  $yarnFlag == "MK"){
                 $initProduct->setSoldByLength(1);
            }
            else{
               $initProduct->setSoldByLength(0);
            }
            
            //Has Ruler
            if($prodType ==  "00000" || strtoupper($prodType) == "0000S"){
                 $initProduct->setHasRuler(1);
            }
            else{
                $initProduct->setHasRuler(0);
            }
            
            //Kits
            if($ptype2 == '25' || $ptype2 == '24'){

                //Kit Time
                if($yarnFlag != '' && $yarnFlag != 'M'){
                    $kit_time_val = attributeValueExists('kit_time', $kitTimes[$yarnFlag]);
                    $initProduct->setKitTime($kit_time_val);
                }

                //Kit Style
                if($yarnAlias != ''){
                    $kit_style_val = attributeValueExists('kit_style', str_replace("'","", $yarnAlias));
                    $initProduct->setKitStyle($kit_style_val);
                }
            }
            
            //Medley Data
            if($yarnFlag == "M")
            {
                $medleyOptions[$sku] = $zirconProduct["color"];
                $partnerArray = array();
                
                //Partner Sku
                if($xrefValues[$sku]){
                    $i = 0;
                    foreach($xrefValues[$sku] as $refSku){
                        $partnerArray[$i] = $refSku;
                        $i++;
                    }
                    
                    $medleyPartners[$sku] = $partnerArray;
                }
            }
        }
        //if keepsake needlearts
        if($company == "02"){
            //Sold By Length
            if($prodType ==  "00000" || $yarnFlag == "MK"){
                 $initProduct->setSoldByLength(1);
            }
        }
        
        //Free Patterns
        if($zirconProduct["spec_promo_list"] && $yarnFlag != "M")
        {
            $freeSkus = '';

            foreach($zirconProduct["spec_promo_list"]["Spec_promo"] as $freePattern){
                    $freeSkus =  $freeSkus . $freePattern["free_pattern"] . ",";       
            }

            if($freeSkus != ""){
               Mage::log($storeViewName."::Product with Free Patterns: ".$initProduct->getSku() . ":Free Pattern Skus:" . $freeSkus,null, $logFile);               
            }  
        }
        $initProduct->setHasFreePatterns(0); 

        //Pricing
        $price = $zirconProduct["price"];
        
        //Product is on sale
        if($price != ""){
            $origRetail = $zirconProduct["orig_retail"];
            $amazonOrigRetail = $zirconProduct["retailprice"];
        
            //Product is on sale
            if($origRetail == "" || $origRetail == "0.00")
            {
                if($amazonOrigRetail != "" && $amazonOrigRetail != "0.00 " &&  $price < $amazonOrigRetail){
                    $specialPrice = $price;
                    $price = $amazonOrigRetail;

                    if($initProduct->getSoldByLength() == 1){
                        $price = $price / 8;
                    }
                }
            }else if ($origRetail != "" && $origRetail != "0.00"){
                if($price < $origRetail){
                    $specialPrice = $price;
                    $price = $origRetail;
                }
            } 
        }
        
        
        //Gift Cards should have no price in Magento
        if($ptype2 != "86"){
            $initProduct->setPrice($price);
        } 
        $initProduct->setSpecialPrice($specialPrice);
        if($specialPrice == ''){
            $initProduct->setSpecialFromDate('');
        }
        
        $initProduct->setCost($cost);
        

        return 	$initProduct;		
    }

    //Retieve Zircon Records
    function getZirconRecords($company, $type){    
        $helper = Mage::helper('zirconprocessing');
        $api_endpoint_url = $helper->getProductEndPoint() . "?select=COMPANY=". $company;
    
        $curl = curl_init($api_endpoint_url);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
        $curl_response = curl_exec($curl);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        //write the JSON response to file 
        
        if($type == "product"){
            $local_file = Mage::getBaseDir().'/var/importexport/zircon_inventory/' . date("Ymd_Hi") . "_" . $company . ".PROD";
        }
        else{
            $local_file = Mage::getBaseDir().'/var/importexport/zircon_inventory/' . date("Ymd_Hi") . ".XREF";
        }
        
        $fp = fopen($local_file, 'w');
        fwrite($fp, $curl_response);
        fclose($fp);   
        
        // Did we get a 200 or an error?
        if($http_status != 200)
        {
            Mage::log('Connection to api import server failed $http_status . $curl_response \r\n',null,$this->errorFile);
            $this->sendEmail('Zircon Inventory Update - Connection to API import server failed', 'Connection to API import server failed $http_status . $curl_response', Mage::getBaseDir().'/var/log/', null);
            throw new Exception('Connection to api import server failed. $http_status . $curl_response');
            return;
        }
    }
    
    function initProductFiles(){
         global $prodDir, $prodFiles;
        //Get the files & order by oldest first
        foreach (glob($prodDir.'/*.{prod,PROD}', GLOB_BRACE) AS $file){    
            $slashPosition = strripos($file,'/');
            
            if($slashPosition == false) $slashPosition = strripos($file,'\\');
            $prodFiles[] = $file; 
        }
    }
    
    function initXrefFiles(){
         global $prodDir, $xrefFiles;
        //Get the files & order by oldest first
        foreach (glob($prodDir.'/*.{xref,XREF}', GLOB_BRACE) AS $file){    
            $slashPosition = strripos($file,'/');
            
            if($slashPosition == false) $slashPosition = strripos($file,'\\');
            $xrefFiles[] = $file; 
        }
    }
    
        //Create an option value for an attribute
    function addAttributeValue($arg_attribute, $arg_value){
    	global $logFile;
    	global $storeViewName;
    	
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);
        
        if(!attributeValueExists($arg_attribute, $arg_value)){
            $value['option'] = array($arg_value,$arg_value);
            $result = array('value' => $value);
            $attribute->setData('option',$result);

            try {
                $attribute->save();
            } 
            catch (Exception $e) {
                Mage::log($storeViewName."::addAttributeValue Error: ".$e->getMessage(),null, $logFile);
            }
        }
        
        foreach($options as $option){
            if ($option['label'] == $arg_value)return $option['value'];
        }
        return true;
    }
    
    //Check to see if an option value exists for an attribute
    function attributeValueExists($arg_attribute, $arg_value){
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);
        
        foreach($options as $option){	
            if ($option['label'] == $arg_value)return $option['value'];
        }

        return false;
    }

    //Remove old images, retrieve new image(s) and add to product
    function processImage($product, $zirconProduct){
        global $storeImageRoots;
        global $storeViewName;

        //If yard daughter then derive the image name
        if(($zirconProduct["prodtyp2"] == "21" 
                ||$zirconProduct["prodtyp2"] == "70" 
                || $zirconProduct["prodtyp2"] == "71" 
                || $zirconProduct["prodtyp2"] == "72"
                || $zirconProduct["prodtyp2"] == "73"
                || $zirconProduct["prodtyp2"] == "74"
                || $zirconProduct["prodtyp2"] == "75"
                || $zirconProduct["prodtyp2"] == "76"
                || $zirconProduct["prodtyp2"] == "77") and $zirconProduct["daughter"] <> ""){
            $daughter = true;
            $imageName = str_replace(".", "_", trim($zirconProduct["_id"])).".jpg";
        }
        else{
            $imageName = str_replace('.JPG', '.jpg' ,trim($zirconProduct["image"]));
        }
                
         //IMAGES
        $path = Mage::getBaseDir().DS.'media/import'.DS;

        //Check for pre-existing images and remove them     
        $product->load('media_gallery');
    
        if ($product->getMediaGalleryImages()){
            //Get the images
            
            $attributes = $product->getTypeInstance ()->getSetAttributes ();
            $gallery = $attributes ['media_gallery'];
            foreach ( $product->getMediaGalleryImages() as $image ) {
                //If image exists
                if ($gallery->getBackend ()->getImage ( $product, $image ['file'] )) {
                    $gallery->getBackend ()->removeImage ( $product, $image ['file'] );
                }
            }
            $product->save();
            $product->setData('media_gallery', $gallery);
        }
        
        if($storeViewName == "PatternWorks.com" && $daughter){
            $imgUrl = str_replace("large", "parts", $storeImageRoots[$zirconProduct['company']]);
            $img = @file_get_contents($imgUrl.$imageName);
                    
        }else{
            $img = @file_get_contents($storeImageRoots[$zirconProduct['company']].$imageName);
        }
        
        

        if ($img) {	
            if (file_exists($path.$imageName)) unlink($path.$imageName);
            file_put_contents($path.$imageName, $img);
        } 
        else {
            Mage::log("SKU:".$product->getSku() . ", FILE: ". $storeImageRoots[$company].$imageName, null, "ProductImageImport-NotFound.log");
            return;
        }

        if (file_exists($path.$imageName) && $imageName != "") {
            $product->addImageToMediaGallery($path.$imageName, array('thumbnail','small_image','image'), TRUE, FALSE);
        } 
        else {
            Mage::log("SKU:".$product->getSku() . ", FILE: ". $path.$imageName,null, "ProductImageImport-Failed.log");
        } 
    }
    
    
        //Remove old images, retrieve new image(s) and add to product
    function processYarnMotherImage($product, $zirconProduct){
        global $storeImageRoots;
        global $storeViewName;

        //If yard daughter then derive the image name
        if(($zirconProduct["prodtyp2"] == "21" 
                ||$zirconProduct["prodtyp2"] == "70" 
                || $zirconProduct["prodtyp2"] == "71" 
                || $zirconProduct["prodtyp2"] == "72"
                || $zirconProduct["prodtyp2"] == "73"
                || $zirconProduct["prodtyp2"] == "74"
                || $zirconProduct["prodtyp2"] == "75"
                || $zirconProduct["prodtyp2"] == "76"
                || $zirconProduct["prodtyp2"] == "77") and $zirconProduct["daughter"] <> ""){
            $daughter = true;
            $imageName = str_replace(".", "_", trim($zirconProduct["_id"])).".jpg";
        }
        else{
            $imageName = str_replace('.JPG', '.jpg' ,trim($zirconProduct["image"]));
        }
                
         //IMAGES
        $path = Mage::getBaseDir().DS.'media/import'.DS;

        //Check for pre-existing images and remove them     
        $product->load('media_gallery');
    
      /*  if ($product->getMediaGalleryImages()){
            //Get the images
            
            $attributes = $product->getTypeInstance ()->getSetAttributes ();
            $gallery = $attributes ['media_gallery'];
            foreach ( $product->getMediaGalleryImages() as $image ) {
                //If image exists
                if ($gallery->getBackend ()->getImage ( $product, $image ['file'] )) {
                    $gallery->getBackend ()->removeImage ( $product, $image ['file'] );
                }
            }
            $product->save();
            $product->setData('media_gallery', $gallery);
        }*/
        
        if($storeViewName == "PatternWorks.com" && $daughter){
            $imgUrl = str_replace("large", "parts", $storeImageRoots[$zirconProduct['company']]);
            $img = @file_get_contents($imgUrl.$imageName);
                    
        }else{
            $img = @file_get_contents($storeImageRoots[$zirconProduct['company']].$imageName);
        }
        
        

        if ($img) {	
            if (file_exists($path.$imageName)) unlink($path.$imageName);
            file_put_contents($path.$imageName, $img);
        } 
        else {
            Mage::log("SKU:".$product->getSku() . ", FILE: ". $storeImageRoots[$company].$imageName, null, "ProductImageImport-NotFound.log");
            return;
        }

        if (file_exists($path.$imageName) && $imageName != "") {
            $product->addImageToMediaGallery($path.$imageName, array('thumbnail','small_image','image'), TRUE, FALSE);
        } 
        else {
            Mage::log("SKU:".$product->getSku() . ", FILE: ". $path.$imageName,null, "ProductImageImport-Failed.log");
        } 
    }
    
    //Create,Save a Stock Item record 
    function createStockItem($product){
    	global $storeViewName;
    	global $logFile;   	
    	
        try{
            $stockItem = Mage::getModel('cataloginventory/stock_item');
            $stockItem->assignProduct($product);
            $stockItem->setData('stock_id', 1);
            $stockItem->setData('use_config_manage_stock', 0);
            $stockItem->setData('use_config_min_sale_qty', 0);
            $stockItem->setData('use_config_backorders', 0);
            $stockItem->save();
        } 
        catch(Exception $e){
                Mage::log($storeViewName."::createStockItem Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
        }
    }
    
    function updateStockItem($zirconProduct, $product){   
        global $logFile;
        $backorder = 0;
        $isInStock = 0;
        
        //Create Stock Item (if need be)
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getId());	
        if($stockItem->getId() == "") $stockItem = createStockItem($product, $logFile);
	
        $qty = $zirconProduct["avail2"];
        $discontinued = $zirconProduct["disc_flg"];
        $newpart = $zirconProduct["newpartflg"];
        
        if($discontinued == '' && $qty <= 0 && $zirconProduct["sizeflag1"] == '' && $newpart != 'Y'){
            $backorder = 1;
            $isInStock = 1;
        }
        elseif($newpart == 'Y' && $qty <= 0){
            $backorder = 1;
            $isInStock = 1;
            $product->setPreorder(1);
            $product->Save();
        }
        elseif($discontinued != '' && $qty <= 0){
            $backorder = 0;
            $isInStock = 0;
        }
        elseif($discontinued != '' && $qty > 0){
            $backorder = 0;
            $isInStock = 1;
        }
        else if($qty > 0){
            $backorder = 1;
            $isInStock = 1;
        }

        if($product->getTypeId() == 'downloadable' || $zirconProduct["dropship"] == "1"){
            $manageStock = 0;
        }
        else{
            $manageStock = 1;
        }
        
        $stockItem->setData('manage_stock', $manageStock);
        $stockItem->setData('is_in_stock', $isInStock);
        $stockItem->setData('stock_id', 1);
        $stockItem->setData('qty', $qty);
        $stockItem->setData('backorders', $backorder);
        $stockItem->save(); 
    }
    
    function loadXrefValues(){
         global $xrefFiles;
         global $xrefValues;

         //Process each found file
        foreach ($xrefFiles AS $xrefFile) {  
            if (($handle = fopen($xrefFile, "r")) !== FALSE){
                $json = json_decode(file_get_contents($xrefFile), true);
                foreach($json["Page_xref_web"] as $zirconProduct){
                    $sku = $zirconProduct["_id"];
                    $refSkus = array();
                    $j=0;
                    for ($i=1; $i<=25; $i++) {
                        if($zirconProduct["child".$i] != "" && $zirconProduct["child".$i] != $sku){
                            $refSkus[$j] = $zirconProduct["child".$i];
                            $j++;
                        }  
                    }
                    $xrefValues[$sku] = $refSkus;
                }
            }
        }
    }