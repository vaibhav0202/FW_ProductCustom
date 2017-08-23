<?php

/**
 * @category    FW
 * @package     FW_Report
 * @copyright   Copyright (c) 2014 F+W Media, Inc. (http://www.fwmedia.com)
 * This file's purpose is to import CV3 product data into Magento
 * This class's difference from the ImportCV3Product.php file is this is for a CV3 import that has products that are not currently in Vista,
 * Which means that there is no Vista data in the CV3 exports. i.e. in the Manufacturer field
 */
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';
    require_once '../../Model/Resource/Eav/Source/Vistaeditioncodes.php';
    Mage::app();
   
    //Command Line Args
    $fileName = $argv[1];
    $storeViewName = $argv[2];
    $webSiteName = $argv[3];
    $vendorFileName = $argv[4];
    $imageUrlRoot = $argv[5];
            
    //Global Vars
    $parentProducts = array();
    $parentChildren = array();
    $vendors = array();
    $count = 0;
    $logFile = 'CV3_Product_Import'. $storeViewName . '.log';
  	
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
    getVendors();
	  
    //create all the child option values based off sku name
    Mage::log($storeViewName."::Creating all fw_option attribute values based off export file records",null, $logFile);   
    createOptionValues();
  
    //Populate the format attribute option values
    Mage::log($storeViewName."::Creating all format attribute values based off export file records",null, $logFile);   
    createFormatValues();

    //Create Products    
    findParentProducts();
 
    Mage::log($storeViewName."::Creating all configurable products",null, $logFile);  
    processParentChildren();
    
    Mage::log($storeViewName."::Creating all basic products",null, $logFile);   
    processProducts();
    
    Mage::log($storeViewName."::Creating all related and upsell products",null, $logFile);  
    processRelatedProducts();
    		
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
    function processProducts(){
    	global $fileName;
    	global $logFile;
    	global $websiteId;
    	global $storeId;
    	global $storeViewName;
    	global $parentProducts;
    	global $count;
    	global $taxClassIds;

	if (($handle = fopen($fileName, "r")) !== FALSE){
            $col = array();
            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
                if (empty($col)){
                    $col = $data;
		} 
		else{
                    array_splice($col, count($data)); //header file has more empty csv fields that product lines
					
                    if(count($col) == count($data)) {
                        $data = array_combine($col, $data);
                        
                        if($data['ParentSKU'] == ""){
                            if(!isset($parentProducts[$data['SKU']])){
                                if($data['SKU'] != "" && $data['ProdName'] != '[No Product Name]'){

                                    $sku = $data['SKU'];
                                    $sku = translateSku($sku);
                                    
                                    $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
								
                                    if($product) addStore($product);
                                    $product = initProduct($product, $data);
                                    
                                    try{
                                        $product->save();
				    	Mage::log($storeViewName."::Processed Product: ".$product->getSku(),null, $logFile);
				    	createStockItem($product); //creating stock item after product saved was a necessary order of steps
				    					
				    	//Download Links
                                        if ($data['HasElectronicDelivery'] == "TRUE"){
                                            $productType = new Mage_Downloadable_Model_Product_Type;
                                            $links = $productType->getLinks($product);

                                            foreach ($links as $link)
                                            {
                                                $link->delete(); 			
                                            }
                                            $product->setDownloadableData(createDownloadableData($data));
                                            $product->setLinksPurchasedSeparately(0);
                                            $product->save();
                                            $product->setDownloadableData('');
                                            Mage::log($storeViewName."::Created Download Link(s) for : ".$product->getSku(),null, $logFile);
                                        }
					
                                        processImage($product, $data['SKU']);
                                        $product->save();
					echo "Processed SKU:".$product->getSku()."\n";
                                        $count++;
                                    } catch(Exception $e){
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
    function findParentProducts(){ 
        global $fileName;
    	global $parentProducts;
    	global $parentChildren;
    	global $logFile;
    	
    	$childrenParentSkus = array();
    	$childProducts = array();
    	$parentSkus = array();
    	
	//first identify all parents
	$i = 0;
    	if (($handle = fopen($fileName, "r")) !== FALSE){
    	    $col = array();

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
                if (empty($col)){
                    $col = $data;
		}else{
                    array_splice($col, count($data)); //header file has more empty csv fields that product lines
                    if(count($col) == count($data)){
                        $data = array_combine($col, $data);
                        
                        if($data['ParentSKU'] != ""){
                            if(!in_array($data['ParentSKU'], $parentSkus)){
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
    	if (($handle = fopen($fileName, "r")) !== FALSE){
            $col = array();
            
            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
                if (empty($col)){
                    $col = $data;
		}else{
                    array_splice($col, count($data));
                    
                    if(count($col) == count($data)){
                        $data = array_combine($col, $data);
                        
                        if(in_array($data['SKU'], $parentSkus)){
                            $parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
                            
                            if($parentProduct){
                                addStore($parentProduct);
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
    	if (($handle = fopen($fileName, "r")) !== FALSE){
    	   $col = array();
           
           while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
               if (empty($col)){
                   $col = $data;
               }else{
                   array_splice($col, count($data));
                   if(count($col) == count($data)){
                       $data = array_combine($col, $data);
                       
                       if($data['ParentSKU'] != ""){
                           $childProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
                           
                           if($childProduct){	
                            addStore($childProduct);
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
    	foreach ($parentSkus as $parentSku){
            $children = array();
            unset($children);
            $i = 0;
    		
            foreach ($childrenParentSkus as $key=>$value){
                if($value == $parentSku){
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
    function processParentChildren(){
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

    	foreach($parentChildren as $parent=>$children){
            $configAtt = array();
            $configurableProductsData  = array();
            $configurableAttributesData = array();
    	
            $parentProduct = $parentProducts[$parent];

            if($parentProduct){
                //get lowest child price to use for the parent
                $i = 0;
                
                foreach ($children as $child){
                    if($i == 0){
                        $lowestPrice = $child->getPrice();  
                    }else{
                        if($child->getPrice() < $lowestPrice){
                            $lowestPrice = $child->getPrice();
                        }
                    }
	
                    $i++;
                }

                $parentProduct->setPrice($lowestPrice);
				
                //get lowest child sepcial price to use for the parent
                $i = 0;
                foreach ($children as $child){
                    if($i == 0){
                        $lowestSpecialPrice = $child->getSpecialPrice();
                    }else{
                        if($child->getSpecialPrice() < $lowestSpecialPrice && $child->getSpecialPrice() != 0){
                            $lowestSpecialPrice = $child->getSpecialPrice();
                        }
                    }
	    			
                    $i++;
                }
	
                if($lowestSpecialPrice != 0){
                    $parentProduct->setSpecialPrice($lowestSpecialPrice);
                }
					
                $i = 0;
                foreach ($children as $child){	
                    $optionId = attributeValueExists('fw_options', $child->getName());//name of child is also the option value
                    $child->setFwOptions($optionId);
                    $child->setVisibility(1);
					
                    //set the drop ship vendor id for all children to be the same as the parent
                    if($parentProduct->getDropShipVendorId() != "0" && $parentProduct->getDropShipVendorId() != ""){
                        $child->setDropShipVendorId($parentProduct->getDropShipVendorId());
                    }
	
                    try{
                        $child->save();
                        assignTaxClass($child);
                        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child->getId());
	
                        if($stockItem->getId() == ""){
                            createStockItem($child);
                        }
				
                        Mage::log($storeViewName."::Processed Child Product: ".$child->getSku(),null, $logFile); 
                        echo "Processed child SKU:".$child->getSku()."\n";
                        $count++;
                    }catch(Exception $e){
                        echo $e->getMessage();
                        Mage::log($storeViewName."::processParentChildren (create child):Error: ".$e->getMessage(),null, $logFile);
                    }
					
                    $deltaPrice = $child->getPrice() - $parentProduct->getPrice();
					
                    if($parentProduct->getSpecialPrice() != 0 && $child->getSpecialPrice() != 0){
                        $deltaPrice = $child->getSpecialPrice() - $parentProduct->getSpecialPrice();
                    }
							
                    if($child->getAttributeSetId() == $parentProduct->getAttributeSetId()){
                        $configurableProductsData[$child->getId()] = array('0'=>
                            array(
                                    'attribute_id'  => $attributeId,
                                    'label'         => $child->getName(),
                                    'value_index'   => $optionId,
                                    'is_percent'    => 0,
                                    'pricing_value' => $deltaPrice
                            )
                        );
		
                        $configAtt[$i] =  array(
                            'attribute_id'  => $attributeId, //The attribute id
                            'label'         => $child->getName(),
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
                        '0'             => array(
                        'id'            => NULL,
                        'label'         => 'Options', //optional, will be replaced by the modified api.php
                        'position'	=> NULL,
                        'values'	=> $configAtt,
                        'attribute_id' 	=> $attributeId, //get this value from attributes api call
                        'attribute_code'=> 'fw_options', 
                        'frontend_label'=> '', //optional, will be replaced by the modifed api.php
                        'html_id'	=> 'config_super_product__attribute_'.$html_id
                        )
                    );
				
                    $testProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$parentProduct->getSku());
                    $parentProduct->setConfigurableProductsData($configurableProductsData);
                    $parentProduct->setConfigurableAttributesData($configurableAttributesData);
                    $parentProduct->setCanSaveConfigurableAttributes(1);
						
                    try{	
                        $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                        $conn->query("Delete from catalog_product_super_attribute where product_id = ".$parentProduct->getId());	
                        $parentProduct->save();
                        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentProduct->getId());

                        if($stockItem->getId() == ""){
                            createStockItem($parentProduct);
                        }
					
                        Mage::log($storeViewName."::Processed Parent Product: ".$parentProduct->getSku(),null, $logFile);  
                        echo "Processed Parent SKU:".$parentProduct->getSku()."\n";
	
                    }catch (Exception $e){
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
    function processRelatedProducts(){
    	global $fileName;
    	global $logFile;
    	global $storeViewName;
    	
    	//Process the Related and Upsell products...
        if (($handle = fopen($fileName, "r")) !== FALSE){
            $col = array();

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
                if (empty($col)){
                    $col = $data;
                }else{
                    array_splice($col, count($data));
                    if(count($col) == count($data)) {
                        $data = array_combine($col, $data);

			if($data['AdditionalProdSKUs'] != ""){
                            $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
				 			
                            if($product){
                                $relatedSkus = explode(",", $data['AdditionalProdSKUs']);
                                $position = 1;
                                unset($param);
                                foreach ($relatedSkus as &$sku){
                                    $relatedProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                                    if($relatedProduct){
                                        $param[$relatedProduct->getId()] = array('position'=>$position);
                                        $position++;
                                    }
                                }
								
                                $product->setRelatedLinkData($param);
								
                                try{
                                    $product->save();
                                    mage::log($storeViewName."::Relating SKU:".$sku." with SKU:".$data[0],null, $logFile);
                                }catch(Exception $e){
                                    Mage::log($storeViewName."::processRelatedProducts (related) Error: ".$e->getMessage(),null, $logFile);
                                }
                            }
                        }
                        
			//Upsell SKUs		
			if($data['RelatedProdSKUs'] != ""){
                            $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data['SKU']);
				 			
                            if($product){
                                $upSellSkus = explode(",", $data['RelatedProdSKUs']);
                                unset($param);
                                $position = 1;
                                
                                foreach ($upSellSkus as &$sku){
                                        $upsellProduct = Mage::getModel('catalog/product')->loadByAttribute('sku',$sku);
                                        if($upsellProduct){
                                                $param[$upsellProduct->getId()] = array('position'=>$position);
                                                $position++;
                                        }
                                }	
	
                                $product->setUpSellLinkData($param);

                                try{
                                    $product->save();
                                    Mage::log($storeViewName."::Upselling SKU:".$sku." with SKU:".$data[0],null, $logFile);
                                }catch(Exception $e){
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

        for($i = 1; $i < 20; $i++){
            $linkFieldName = 'ElectronicDeliveryLink'.$i;
            $titleFieldName = 'ElectronicDeliveryDescription'.$i;
			
            if(isset($data[$linkFieldName]) &&  $data[$linkFieldName] != "" && $data[$linkFieldName] != "0"){
                $links[$i] = array('title' => $data[$titleFieldName], 'link'=>$data[$linkFieldName]) ;
            }
        }

        $i = 0;

        foreach($links as $link) {
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

            try{
                $attribute->save();
            	Mage::log($storeViewName."::Adding Option value: ".$arg_value,null, $logFile);
            }catch (Exception $e){
                Mage::log($storeViewName."::addAttributeValue Error: ".$e->getMessage(),null, $logFile);
            }
        }
        
        foreach($options as $option){
            if ($option['label'] == $arg_value){
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
    function attributeValueExists($arg_attribute, $arg_value){
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);
        
        foreach($options as $option){	
            if ($option['label'] == $arg_value){
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
    function createOptionValues(){  
    	global $fileName;
    	    	
    	if (($handle = fopen($fileName, "r")) !== FALSE){
            $col = array();

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
                if (empty($col)){
                    $col = $data;
                }else {
                    array_splice($col, count($data)); //header file has more empty csv fields that product lines
					
                    if(count($col) == count($data)) {
                        $data = array_combine($col, $data);
			
                        //a child
			if($data['ParentSKU'] != ""){
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
		else if ($data['HasElectronicDelivery'] == "TRUE") //Downloadable
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
		$sku = $data['SKU'];
                $sku = translateSku($sku);
                $initProduct->setSku($sku);		    	   
    	   	
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
		
        foreach ($existingWebsiteIds as &$aWebsiteId){
            if($websiteId == $aWebsiteId){
                $websiteAssociated = TRUE;
            }
        }
			
        $existingStoreIds = $product->getStoreIds();
        foreach ($existingStoreIds as &$aStoreId){
            if($storeId == $aStoreId){
                $storeAssociated = TRUE;
            }
        }
						
        if(!$websiteAssociated){
            $existingWebsiteIds[] = count($existingWebsiteIds) + 1;
            $existingWebsiteIds[count($existingWebsiteIds) - 1] = $websiteId;

            try {
                $product->setWebsiteIDs($existingWebsiteIds)->save();
                Mage::log($storeViewName."::Updated Product (website id) : ".$product->getSku(),null, $logFile);
            }catch (Exception $e) {
                Mage::log($storeViewName."::addStore (website id) Error: ".$e->getMessage(),null, $logFile);
            }
        }
        
        if(!$storeAssociated){
            $existingStoreIds[] = count($existingStoreIds) + 1;
            $existingStoreIds[count($existingStoreIds) - 1] = $storeId;

            try {
                $product->setStoreIDs($existingStoreIds)->save();
                Mage::log($storeViewName."::Updated Product (store id) : ".$product->getSku(),null, $logFile);
            }catch (Exception $e) {
                    Mage::log($storeViewName."::addStore (store id) Error: ".$e->getMessage(),null, $logFile);
            }
        }
    }

     /**
	 * Create,Save a Stock Item record
	 * 
	 * @param $product Mage Product
	 */
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
            
            if($product->getVistaEditionType() == 'W'){
                $stockItem->setData('qty', 1);
            }
            $stockItem->save();
            echo "Created Stock Item for ".$product->getSku()."\n";
        }catch(Exception $e){
            Mage::log($storeViewName."::createStockItem Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
        }
    }

    /**
	 * Assign the tax class to a product
	 * 
	 * @param $product Mage Product
	 */
    function assignTaxClass($product){
    	global $storeViewName;
    	global $taxClassIds;
    	global $logFile;
    	
    	$product->setTaxClassId($taxClassIds["Taxable Goods"]);
    	
    	if($product->getTypeId() == "downloadable"){
            $product->setTaxClassId($taxClassIds["Downloads"]);
    	}
    	
    	if($product->getTypeId() == "virtual"){
            $product->setTaxClassId(0);
    	}
    	  	
    	try{
            $product->save();
        }catch(Exception $e){
            Mage::log($storeViewName."::assignTaxClass Error for product ".$product->getSku().": ".$e->getMessage(),null, $logFile);
        }
    }
    
        //Remove old images, retrieve new image(s) and add to product
    function processImage($product, $originalSku){
        global $imageUrlRoot;
        
        $imageName = strtoupper($originalSku).'.jpg';
      
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
        $img = @file_get_contents($imageUrlRoot.$imageName);

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
    
        function translateSku($sku){
        
        $skuTraslationArray = array();
        $skuTraslationArray['MARSGLOBEDVD'] = 'S0484';
        $skuTraslationArray['INNPLANBNDL'] = 'S3207';
        $skuTraslationArray['DPSKYBNDL14'] = 'S3208';
        $skuTraslationArray['DPSKYTR0002A'] = 'T9171';
        $skuTraslationArray['DPSKYTR0002B'] = 'T9172';
        $skuTraslationArray['DPSKYTR0003'] = 'T9173';
        $skuTraslationArray['DPSKYTR0004A'] = 'T9174';
        $skuTraslationArray['DPSKYTR0004B'] = 'T9175';
        $skuTraslationArray['DPSKYTR0005'] = 'T9176';
        $skuTraslationArray['DPSKYTR0006'] = 'T9177';
        $skuTraslationArray['DPSKYTR0007'] = 'T9178';
        $skuTraslationArray['DPSKYTR0008A'] = 'T9179';
        $skuTraslationArray['DPSKYTR0008B'] = 'T9180';
        $skuTraslationArray['DPSKYTR0009A'] = 'T9181';
        $skuTraslationArray['DPSKYTR0009B'] = 'T9182';
        $skuTraslationArray['DPSKYTR0010'] = 'T9183';
        $skuTraslationArray['DPSKYTR0011A'] = 'T9184';
        $skuTraslationArray['DPSKYTR0011B'] = 'T9185';
        $skuTraslationArray['DPSKYTR0102'] = 'T9186';
        $skuTraslationArray['DPSKYTR0103'] = 'T9187';
        $skuTraslationArray['DPSKYTR0104'] = 'T9188';
        $skuTraslationArray['DPSKYTR0105'] = 'T9189';
        $skuTraslationArray['DPSKYTR0106A'] = 'T9190';
        $skuTraslationArray['DPSKYTR0106B'] = 'T9191';
        $skuTraslationArray['DPSKYTR0107'] = 'T9192';
        $skuTraslationArray['DPSKYTR0108A'] = 'T9193';
        $skuTraslationArray['DPSKYTR0108B'] = 'T9194';
        $skuTraslationArray['DPSKYTR0109'] = 'T9195';
        $skuTraslationArray['DPSKYTR0110A'] = 'T9196';
        $skuTraslationArray['DPSKYTR0110B'] = 'T9197';
        $skuTraslationArray['DPSKYTR0111'] = 'T9198';
        $skuTraslationArray['DPSKYTR0202'] = 'T9199';
        $skuTraslationArray['DPSKYTR0203'] = 'T9200';
        $skuTraslationArray['DPSKYTR0204'] = 'T9201';
        $skuTraslationArray['DPSKYTR0205'] = 'T9202';
        $skuTraslationArray['DPSKYTR0206'] = 'T9203';
        $skuTraslationArray['DPSKYTR0207'] = 'T9204';
        $skuTraslationArray['DPSKYTR0208'] = 'T9205';
        $skuTraslationArray['DPSKYTR0209'] = 'T9206';
        $skuTraslationArray['DPSKYTR0210'] = 'T9207';
        $skuTraslationArray['DPSKYTR0211A'] = 'T9208';
        $skuTraslationArray['DPSKYTR0211B'] = 'T9209';
        $skuTraslationArray['DPSKYTR0212'] = 'T9210';
        $skuTraslationArray['DPSKYTR0302'] = 'T9211';
        $skuTraslationArray['DPSKYTR0303'] = 'T9212';
        $skuTraslationArray['DPSKYTR0304'] = 'T9213';
        $skuTraslationArray['DPSKYTR0305'] = 'T9214';
        $skuTraslationArray['DPSKYTR0306'] = 'T9215';
        $skuTraslationArray['DPSKYTR0307'] = 'T9216';
        $skuTraslationArray['DPSKYTR0308'] = 'T9217';
        $skuTraslationArray['DPSKYTR0309'] = 'T9218';
        $skuTraslationArray['DPSKYTR0402'] = 'T9219';
        $skuTraslationArray['DPSKYTR0403'] = 'T9220';
        $skuTraslationArray['DPSKYTR0404'] = 'T9221';
        $skuTraslationArray['DPSKYTR0405A'] = 'T9222';
        $skuTraslationArray['DPSKYTR0405B'] = 'T9223';
        $skuTraslationArray['DPSKYTR0406A'] = 'T9224';
        $skuTraslationArray['DPSKYTR0406B'] = 'T9225';
        $skuTraslationArray['DPSKYTR0407A'] = 'T9226';
        $skuTraslationArray['DPSKYTR0407B'] = 'T9227';
        $skuTraslationArray['DPSKYTR0408A'] = 'T9228';
        $skuTraslationArray['DPSKYTR0408B'] = 'T9229';
        $skuTraslationArray['DPSKYTR0409A'] = 'T9230';
        $skuTraslationArray['DPSKYTR0409B'] = 'T9231';
        $skuTraslationArray['DPSKYTR0410A'] = 'T9232';
        $skuTraslationArray['DPSKYTR0410B'] = 'T9233';
        $skuTraslationArray['DPSKYTR0411A'] = 'T9234';
        $skuTraslationArray['DPSKYTR0411B'] = 'T9235';
        $skuTraslationArray['DPSKYTR0412A'] = 'T9236';
        $skuTraslationArray['DPSKYTR0412B'] = 'T9237';
        $skuTraslationArray['DPSKYTR0502A'] = 'T9238';
        $skuTraslationArray['DPSKYTR0502B'] = 'T9239';
        $skuTraslationArray['DPSKYTR0503A'] = 'T9240';
        $skuTraslationArray['DPSKYTR0503B'] = 'T9241';
        $skuTraslationArray['DPSKYTR0504A'] = 'T9242';
        $skuTraslationArray['DPSKYTR0504B'] = 'T9243';
        $skuTraslationArray['DPSKYTR0505'] = 'T9244';
        $skuTraslationArray['DPSKYTR0506A'] = 'T9245';
        $skuTraslationArray['DPSKYTR0506B'] = 'T9246';
        $skuTraslationArray['DPSKYTR0507A'] = 'T9247';
        $skuTraslationArray['DPSKYTR0507B'] = 'T9248';
        $skuTraslationArray['DPSKYTR0508'] = 'T9249';
        $skuTraslationArray['DPSKYTR0509'] = 'T9250';
        $skuTraslationArray['DPSKYTR0510A'] = 'T9251';
        $skuTraslationArray['DPSKYTR0510B'] = 'T9252';
        $skuTraslationArray['DPSKYTR0511A'] = 'T9253';
        $skuTraslationArray['DPSKYTR0511B'] = 'T9254';
        $skuTraslationArray['DPSKYTR0512A'] = 'T9255';
        $skuTraslationArray['DPSKYTR0512B'] = 'T9256';
        $skuTraslationArray['DPSKYTR0602'] = 'T9257';
        $skuTraslationArray['DPSKYTR0603'] = 'T9258';
        $skuTraslationArray['DPSKYTR0604A'] = 'T9259';
        $skuTraslationArray['DPSKYTR0604B'] = 'T9260';
        $skuTraslationArray['DPSKYTR0605'] = 'T9261';
        $skuTraslationArray['DPSKYTR0606'] = 'T9262';
        $skuTraslationArray['DPSKYTR0607'] = 'T9263';
        $skuTraslationArray['DPSKYTR0608'] = 'T9264';
        $skuTraslationArray['DPSKYTR0609'] = 'T9265';
        $skuTraslationArray['DPSKYTR0610'] = 'T9266';
        $skuTraslationArray['DPSKYTR0611'] = 'T9267';
        $skuTraslationArray['DPSKYTR0612A'] = 'T9268';
        $skuTraslationArray['DPSKYTR0612B'] = 'T9269';
        $skuTraslationArray['DPSKYTR0702'] = 'T9270';
        $skuTraslationArray['DPSKYTR0703'] = 'T9271';
        $skuTraslationArray['DPSKYTR0704'] = 'T9272';
        $skuTraslationArray['DPSKYTR0705'] = 'T9273';
        $skuTraslationArray['DPSKYTR0706'] = 'T9274';
        $skuTraslationArray['DPSKYTR0707'] = 'T9275';
        $skuTraslationArray['DPSKYTR0708'] = 'T9276';
        $skuTraslationArray['DPSKYTR0709'] = 'T9277';
        $skuTraslationArray['DPSKYTR0710'] = 'T9278';
        $skuTraslationArray['DPSKYTR0711'] = 'T9279';
        $skuTraslationArray['DPSKYTR0712A'] = 'T9280';
        $skuTraslationArray['DPSKYTR0712B'] = 'T9281';
        $skuTraslationArray['DPSKYTR0802'] = 'T9282';
        $skuTraslationArray['DPSKYTR0803'] = 'T9283';
        $skuTraslationArray['DPSKYTR0804'] = 'T9284';
        $skuTraslationArray['DPSKYTR0805'] = 'T9285';
        $skuTraslationArray['DPSKYTR0806'] = 'T9286';
        $skuTraslationArray['DPSKYTR0807'] = 'T9287';
        $skuTraslationArray['DPSKYTR0809'] = 'T9288';
        $skuTraslationArray['DPSKYTR0810'] = 'T9289';
        $skuTraslationArray['DPSKYTR0811'] = 'T9290';
        $skuTraslationArray['DPSKYTR0812'] = 'T9291';
        $skuTraslationArray['DPSKYTR0902'] = 'T9292';
        $skuTraslationArray['DPSKYTR0903'] = 'T9293';
        $skuTraslationArray['DPSKYTR0904A'] = 'T9294';
        $skuTraslationArray['DPSKYTR0904B'] = 'T9295';
        $skuTraslationArray['DPSKYTR0905'] = 'T9296';
        $skuTraslationArray['DPSKYTR0906'] = 'T9297';
        $skuTraslationArray['DPSKYTR0907'] = 'T9298';
        $skuTraslationArray['DPSKYTR0909'] = 'T9299';
        $skuTraslationArray['DPSKYTR0910'] = 'T9300';
        $skuTraslationArray['DPSKYTR0911'] = 'T9301';
        $skuTraslationArray['DPSKYTR1002'] = 'T9302';
        $skuTraslationArray['DPSKYTR1004'] = 'T9303';
        $skuTraslationArray['DPSKYTR1005'] = 'T9304';
        $skuTraslationArray['DPSKYTR1006'] = 'T9305';
        $skuTraslationArray['DPSKYTR1007'] = 'T9306';
        $skuTraslationArray['DPSKYTR1009'] = 'T9307';
        $skuTraslationArray['DPSKYTR1010'] = 'T9308';
        $skuTraslationArray['DPSKYTR1011'] = 'T9309';
        $skuTraslationArray['DPSKYTR1012'] = 'T9310';
        $skuTraslationArray['DPSKYTR1102'] = 'T9311';
        $skuTraslationArray['DPSKYTR1105'] = 'T9312';
        $skuTraslationArray['DPSKYTR1106'] = 'T9313';
        $skuTraslationArray['SKY7DECADEINT'] = 'T9314';
        $skuTraslationArray['SKY7COMBOINT'] = 'T9315';
        $skuTraslationArray['DPSKYTR0311'] = 'T9316';
        $skuTraslationArray['DPSKYTR0312'] = 'T9317';
        $skuTraslationArray['DPSKYTR0310'] = 'T9318';
         $skuTraslationArray['59031'] = 'S3246';
        $skuTraslationArray['53247'] = 'S3247';
        $skuTraslationArray['59112'] = 'S3249';
        $skuTraslationArray['46010'] = 'S3245';
        $skuTraslationArray['59091'] = 'S3248';
        $skuTraslationArray['S0032'] = 'S3250';
        $skuTraslationArray['S0059'] = 'S3251';
        $skuTraslationArray['S0069'] = 'S3252';
        $skuTraslationArray['S0509'] = 'T9319';
        $skuTraslationArray['S0512'] = 'T9320';
        $skuTraslationArray['SW0909'] = 'T9321';
        $skuTraslationArray['SW0910'] = 'T9322';
        $skuTraslationArray['P0109'] = 'T9323';
        $skuTraslationArray['DPBQFAL0901'] = 'S5902';
        $skuTraslationArray['DPBQFAL0902'] = 'S5903';
        $skuTraslationArray['DPQKSUM0901'] = 'S5904';
        $skuTraslationArray['DPQKSUM0902'] = 'S5905';
        $skuTraslationArray['DPQKSUM0903'] = 'S5906';
        $skuTraslationArray['DPCDLOK141201'] = 'S5907';
        $skuTraslationArray['DPCDLOK141202'] = 'S5908';
        $skuTraslationArray['DPLOCP140801'] = 'S5909';
        $skuTraslationArray['DPLOCP140802'] = 'S5910';
        $skuTraslationArray['DPLOCP140803'] = 'S5911';
        $skuTraslationArray['DPLOCP140804'] = 'S5912';
        $skuTraslationArray['DPLOCP140805'] = 'S5913';
        $skuTraslationArray['DPLOCP140806'] = 'S5914';
        $skuTraslationArray['DPLOCP140807'] = 'S5915';
        $skuTraslationArray['DPLOCP140808'] = 'S5916';
        $skuTraslationArray['DPLOCP140809'] = 'S5917';
        $skuTraslationArray['DPLOCP140810'] = 'S5918';
        $skuTraslationArray['DPLOCP140811'] = 'S5919';
        $skuTraslationArray['DPLOCP140812'] = 'S5920';
        $skuTraslationArray['DPLOCP140813'] = 'S5921';
        $skuTraslationArray['DPLOCP140814'] = 'S5922';
        $skuTraslationArray['DPLOCP140815'] = 'S5923';
        $skuTraslationArray['DPLOCP140816'] = 'S5924';
        $skuTraslationArray['DPLOCP140817'] = 'S5925';
        $skuTraslationArray['DPLOCP140818'] = 'S5926';
        $skuTraslationArray['DPLOCP141001'] = 'S5927';
        $skuTraslationArray['DPLOCP141002'] = 'S5928';
        $skuTraslationArray['DPLOCP141003'] = 'S5929';
        $skuTraslationArray['DPLOCP141004'] = 'S5930';
        $skuTraslationArray['DPLOCP141005'] = 'S5931';
        $skuTraslationArray['DPLOCP141006'] = 'S5932';
        $skuTraslationArray['DPLOCP141007'] = 'S5933';
        $skuTraslationArray['DPLOCP141008'] = 'S5934';
        $skuTraslationArray['DPLOCP141009'] = 'S5935';
        $skuTraslationArray['DPLOCP141010'] = 'S5936';
        $skuTraslationArray['DPLOCP141011'] = 'S5937';
        $skuTraslationArray['DPLOCP141012'] = 'S5938';
        $skuTraslationArray['DPLOCP141013'] = 'S5939';
        $skuTraslationArray['DPLOCP141014'] = 'S5940';
        $skuTraslationArray['DPLOCP141015'] = 'S5941';
        $skuTraslationArray['DPLOCP141016'] = 'S5942';
        $skuTraslationArray['DPLOCP141017'] = 'S5943';
        $skuTraslationArray['DPLOCP141018'] = 'S5944';
        $skuTraslationArray['DPLOCP141019'] = 'S5945';
        $skuTraslationArray['DPLOCP141020'] = 'S5946';
        $skuTraslationArray['DPLOCP141021'] = 'S5947';
        $skuTraslationArray['DPLOCP141022'] = 'S5948';
        $skuTraslationArray['DPLOCP141201'] = 'S5949';
        $skuTraslationArray['DPLOCP141202'] = 'S5950';
        $skuTraslationArray['DPLOCP141203'] = 'S5951';
        $skuTraslationArray['DPLOCP141204'] = 'S5952';
        $skuTraslationArray['DPLOCP141205'] = 'S5953';
        $skuTraslationArray['DPLOCP141206'] = 'S5954';
        $skuTraslationArray['DPLOCP141207'] = 'S5955';
        $skuTraslationArray['DPLOCP141208'] = 'S5956';
        $skuTraslationArray['DPLOCP141209'] = 'S5957';
        $skuTraslationArray['DPLOCP141210'] = 'S5958';
        $skuTraslationArray['DPLOCP141211'] = 'S5959';
        $skuTraslationArray['DPLOCP141212'] = 'S5960';
        $skuTraslationArray['DPLOCP141213'] = 'S5961';
        $skuTraslationArray['DPLOCP141214'] = 'S5962';
        $skuTraslationArray['DPLOCP141215'] = 'S5963';
        $skuTraslationArray['DPLOCP141216'] = 'S5964';
        $skuTraslationArray['DPLOCP141217'] = 'S5965';
        $skuTraslationArray['DPLOCP141218'] = 'S5966';
        $skuTraslationArray['DPLOCP141219'] = 'S5967';
        $skuTraslationArray['DPLOCP141220'] = 'S5968';
        $skuTraslationArray['DPLOCP150101'] = 'S5969';
        $skuTraslationArray['DPLOCP150301'] = 'S5970';
        $skuTraslationArray['DPLOCP150302'] = 'S5971';
        $skuTraslationArray['DPLOCP150303'] = 'S5972';
        $skuTraslationArray['DPLOCP150304'] = 'S5973';
        $skuTraslationArray['DPLOCP150305'] = 'S5974';
        $skuTraslationArray['DPLOCP150306'] = 'S5975';
        $skuTraslationArray['DPLOCP150307'] = 'S5976';
        $skuTraslationArray['DPLOCP150308'] = 'S5977';
        $skuTraslationArray['DPLOCP150309'] = 'S5978';
        $skuTraslationArray['DPLOCP150310'] = 'S5979';
        $skuTraslationArray['DPLOCP150311'] = 'S5980';
        $skuTraslationArray['DPLOCP150312'] = 'S5981';
        $skuTraslationArray['DPLOCP150313'] = 'S5982';
        $skuTraslationArray['DPLOCP150314'] = 'S5983';
        $skuTraslationArray['DPLOCP150315'] = 'S5984';
        $skuTraslationArray['DPLOCP150316'] = 'S5985';
        $skuTraslationArray['DPLOCP150317'] = 'S5986';
        $skuTraslationArray['DPLOCP150318'] = 'S5987';
        $skuTraslationArray['DPLOCP150319'] = 'S5988';
        $skuTraslationArray['DPLOKP140901'] = 'S5989';
        $skuTraslationArray['DPLOKP140902'] = 'S5990';
        $skuTraslationArray['DPLOKP140903'] = 'S5991';
        $skuTraslationArray['DPLOKP140904'] = 'S5992';
        $skuTraslationArray['DPLOKP140905'] = 'S5993';
        $skuTraslationArray['DPLOKP140906'] = 'S5994';
        $skuTraslationArray['DPLOKP140907'] = 'S5995';
        $skuTraslationArray['DPLOKP140908'] = 'S5996';
        $skuTraslationArray['DPLOKP140909'] = 'S5997';
        $skuTraslationArray['DPLOKP140910'] = 'S5998';
        $skuTraslationArray['DPLOKP140911'] = 'S5999';
        $skuTraslationArray['DPLOKP140912'] = 'S6000';
        $skuTraslationArray['DPLOKP140913'] = 'S6001';
        $skuTraslationArray['DPLOKP140914'] = 'S6002';
        $skuTraslationArray['DPLOKP140915'] = 'S6003';
        $skuTraslationArray['DPLOKP140916'] = 'S6004';
        $skuTraslationArray['DPLOKP140917'] = 'S6005';
        $skuTraslationArray['DPLOKP140918'] = 'S6006';
        $skuTraslationArray['DPLOKP140919'] = 'S6007';
        $skuTraslationArray['DPLOKP140920'] = 'S6008';
        $skuTraslationArray['DPLOKP140921'] = 'S6009';
        $skuTraslationArray['DPLOKP140922'] = 'S6010';
        $skuTraslationArray['DPLOKP140923'] = 'S6011';
        $skuTraslationArray['DPLOKP140924'] = 'S6012';
        $skuTraslationArray['DPLOKP140925'] = 'S6013';
        $skuTraslationArray['DPLOKP141101'] = 'S6014';
        $skuTraslationArray['DPLOKP141102'] = 'S6015';
        $skuTraslationArray['DPLOKP141103'] = 'S6016';
        $skuTraslationArray['DPLOKP141104'] = 'S6017';
        $skuTraslationArray['DPLOKP141105'] = 'S6018';
        $skuTraslationArray['DPLOKP141106'] = 'S6019';
        $skuTraslationArray['DPLOKP141107'] = 'S6020';
        $skuTraslationArray['DPLOKP141108'] = 'S6021';
        $skuTraslationArray['DPLOKP141109'] = 'S6022';
        $skuTraslationArray['DPLOKP141110'] = 'S6023';
        $skuTraslationArray['DPLOKP141111'] = 'S6024';
        $skuTraslationArray['DPLOKP141112'] = 'S6025';
        $skuTraslationArray['DPLOKP141113'] = 'S6026';
        $skuTraslationArray['DPLOKP141114'] = 'S6027';
        $skuTraslationArray['DPLOKP141115'] = 'S6028';
        $skuTraslationArray['DPLOKP141116'] = 'S6029';
        $skuTraslationArray['DPLOKP141117'] = 'S6030';
        $skuTraslationArray['DPLOKP141118'] = 'S6031';
        $skuTraslationArray['DPLOKP141119'] = 'S6032';
        $skuTraslationArray['DPLOKP141120'] = 'S6033';
        $skuTraslationArray['DPLOKP141121'] = 'S6034';
        $skuTraslationArray['DPLOKP150101'] = 'S6035';
        $skuTraslationArray['DPLKP140701'] = 'S6036';
        $skuTraslationArray['DPLKP140702'] = 'S6037';
        $skuTraslationArray['DPLKP140703'] = 'S6038';
        $skuTraslationArray['DPLKP140704'] = 'S6039';
        $skuTraslationArray['DPLKP140705'] = 'S6040';
        $skuTraslationArray['DPLKP140706'] = 'S6041';
        $skuTraslationArray['DPLKP140707'] = 'S6042';
        $skuTraslationArray['DPLKP140708'] = 'S6043';
        $skuTraslationArray['DPLKP140709'] = 'S6044';
        $skuTraslationArray['DPLKP140710'] = 'S6045';
        $skuTraslationArray['DPLKP140711'] = 'S6046';
        $skuTraslationArray['DPLKP140712'] = 'S6047';
        $skuTraslationArray['DPLKP140713'] = 'S6048';
        $skuTraslationArray['DPLKP140714'] = 'S6049';
        $skuTraslationArray['DPLKP140715'] = 'S6050';
        $skuTraslationArray['DPLKP140716'] = 'S6051';
        $skuTraslationArray['DPLKP140717'] = 'S6052';
        $skuTraslationArray['DPLKP140718'] = 'S6053';
        $skuTraslationArray['DPLKP140719'] = 'S6054';
        $skuTraslationArray['DPLKP140720'] = 'S6055';
        $skuTraslationArray['DPLKP140721'] = 'S6056';
        $skuTraslationArray['DPLKP140722'] = 'S6057';
        $skuTraslationArray['DPLKP140723'] = 'S6058';
        $skuTraslationArray['DPLKP140724'] = 'S6059';
        $skuTraslationArray['DPLOK150201'] = 'S6060';
        $skuTraslationArray['DPLOK150202'] = 'S6061';
        $skuTraslationArray['DPLOK150203'] = 'S6062';
        $skuTraslationArray['DPLOK150204'] = 'S6063';
        $skuTraslationArray['DPLOK150205'] = 'S6064';
        $skuTraslationArray['DPLOK150206'] = 'S6065';
        $skuTraslationArray['DPLOK150207'] = 'S6066';
        $skuTraslationArray['DPLOK150208'] = 'S6067';
        $skuTraslationArray['DPLOK150209'] = 'S6068';
        $skuTraslationArray['DPLOK150210'] = 'S6069';
        $skuTraslationArray['DPLOK150211'] = 'S6070';
        $skuTraslationArray['DPLOK150212'] = 'S6071';
        $skuTraslationArray['DPLOK150213'] = 'S6072';
        $skuTraslationArray['DPLOK150214'] = 'S6073';
        $skuTraslationArray['DPLOK150215'] = 'S6074';
        $skuTraslationArray['DPLOK150216'] = 'S6075';
        $skuTraslationArray['DPLOK150217'] = 'S6076';
        $skuTraslationArray['DPLOK150218'] = 'S6077';
        $skuTraslationArray['DPLOK150219'] = 'S6078';
        $skuTraslationArray['DPMQP140409'] = 'S6079';
        $skuTraslationArray['DPMQP140801'] = 'S6080';
        $skuTraslationArray['DPMQP140802'] = 'S6081';
        $skuTraslationArray['DPMQP140803'] = 'S6082';
        $skuTraslationArray['DPMQP140804'] = 'S6083';
        $skuTraslationArray['DPMQP140805'] = 'S6084';
        $skuTraslationArray['DPMQP140806'] = 'S6085';
        $skuTraslationArray['DPMQP140807'] = 'S6086';
        $skuTraslationArray['DPMQP140808'] = 'S6087';
        $skuTraslationArray['DPMQP140809'] = 'S6088';
        $skuTraslationArray['DPMQP140810'] = 'S6089';
        $skuTraslationArray['DPMQP140811'] = 'S6090';
        $skuTraslationArray['DPMQP140812'] = 'S6091';
        $skuTraslationArray['DPMQP140813'] = 'S6092';
        $skuTraslationArray['DPMQP140814'] = 'S6093';
        $skuTraslationArray['DPMQP140815'] = 'S6094';
        $skuTraslationArray['DPMQP140816'] = 'S6095';
        $skuTraslationArray['DPMQP141001'] = 'S6096';
        $skuTraslationArray['DPMQP141002'] = 'S6097';
        $skuTraslationArray['DPMQP141003'] = 'S6098';
        $skuTraslationArray['DPMQP141004'] = 'S6099';
        $skuTraslationArray['DPMQP141005'] = 'S6100';
        $skuTraslationArray['DPMQP141006'] = 'S6101';
        $skuTraslationArray['DPMQP141007'] = 'S6102';
        $skuTraslationArray['DPMQP141008'] = 'S6103';
        $skuTraslationArray['DPMQP141009'] = 'S6104';
        $skuTraslationArray['DPMQP141010'] = 'S6105';
        $skuTraslationArray['DPMQP141011'] = 'S6106';
        $skuTraslationArray['DPMQP141012'] = 'S6107';
        $skuTraslationArray['DPMQP141013'] = 'S6108';
        $skuTraslationArray['DPMQP141014'] = 'S6109';
        $skuTraslationArray['DPMQP141015'] = 'S6110';
        $skuTraslationArray['DPMQP141016'] = 'S6111';
        $skuTraslationArray['DPMQP141201'] = 'S6112';
        $skuTraslationArray['DPMQP141202'] = 'S6113';
        $skuTraslationArray['DPMQP141203'] = 'S6114';
        $skuTraslationArray['DPMQP141204'] = 'S6115';
        $skuTraslationArray['DPMQP141205'] = 'S6116';
        $skuTraslationArray['DPMQP141206'] = 'S6117';
        $skuTraslationArray['DPMQP141207'] = 'S6118';
        $skuTraslationArray['DPMQP141208'] = 'S6119';
        $skuTraslationArray['DPMQP141209'] = 'S6120';
        $skuTraslationArray['DPMQP141210'] = 'S6121';
        $skuTraslationArray['DPMQP141211'] = 'S6122';
        $skuTraslationArray['DPMQP141212'] = 'S6123';
        $skuTraslationArray['DPMQP141213'] = 'S6124';
        $skuTraslationArray['DPMQP141215'] = 'S6125';
        $skuTraslationArray['DPMQP141216'] = 'S6126';
        $skuTraslationArray['DPMQP150201'] = 'S6127';
        $skuTraslationArray['DPMQP150202'] = 'S6128';
        $skuTraslationArray['DPMQP150203'] = 'S6129';
        $skuTraslationArray['DPMQP150204'] = 'S6130';
        $skuTraslationArray['DPMQP150205'] = 'S6131';
        $skuTraslationArray['DPMQP150206'] = 'S6132';
        $skuTraslationArray['DPMQP150207'] = 'S6133';
        $skuTraslationArray['DPMQP150208'] = 'S6134';
        $skuTraslationArray['DPMQP150209'] = 'S6135';
        $skuTraslationArray['DPMQP150210'] = 'S6136';
        $skuTraslationArray['DPMQP150211'] = 'S6137';
        $skuTraslationArray['DPMQP150212'] = 'S6138';
        $skuTraslationArray['DPMQP150213'] = 'S6139';
        $skuTraslationArray['DPMQP150214'] = 'S6140';
        $skuTraslationArray['DPMQP150215'] = 'S6141';
        $skuTraslationArray['DPMQP150216'] = 'S6142';
        $skuTraslationArray['DPMQP150401'] = 'S6143';
        $skuTraslationArray['DPMQP150402'] = 'S6144';
        $skuTraslationArray['DPMQP150403'] = 'S6145';
        $skuTraslationArray['DPMQP150404'] = 'S6146';
        $skuTraslationArray['DPMQP150405'] = 'S6147';
        $skuTraslationArray['DPMQP150406'] = 'S6148';
        $skuTraslationArray['DPMQP150407'] = 'S6149';
        $skuTraslationArray['DPMQP150408'] = 'S6150';
        $skuTraslationArray['DPMQP150409'] = 'S6151';
        $skuTraslationArray['DPMQP150410'] = 'S6152';
        $skuTraslationArray['DPMQP150411'] = 'S6153';
        $skuTraslationArray['DPMQP150412'] = 'S6154';
        $skuTraslationArray['DPMQP150413'] = 'S6155';
        $skuTraslationArray['DPMQP150414'] = 'S6156';
        $skuTraslationArray['DPMQP150415'] = 'S6157';
        $skuTraslationArray['DPMQP150416'] = 'S6158';
        $skuTraslationArray['DPMQP201401'] = 'S6159';
        $skuTraslationArray['DPMQS141201'] = 'S6160';
        $skuTraslationArray['DPMQS141202'] = 'S6161';
        $skuTraslationArray['DPMQS141203'] = 'S6162';
        $skuTraslationArray['DPMQS141204'] = 'S6163';
        $skuTraslationArray['DPMQS141205'] = 'S6164';
        $skuTraslationArray['DPMQS141206'] = 'S6165';
        $skuTraslationArray['DPMQS141207'] = 'S6166';
        $skuTraslationArray['DPMQS141208'] = 'S6167';
        $skuTraslationArray['DPMQS141209'] = 'S6168';
        $skuTraslationArray['DPMQS141210'] = 'S6169';
        $skuTraslationArray['DPMQS141211'] = 'S6170';
        $skuTraslationArray['DPMQS141212'] = 'S6171';
        $skuTraslationArray['DPMQS141213'] = 'S6172';
        $skuTraslationArray['DPMQS141214'] = 'S6173';
        $skuTraslationArray['DPMQS141215'] = 'S6174';
        $skuTraslationArray['DPMQS141216'] = 'S6175';
        $skuTraslationArray['DPMQS141217'] = 'S6176';
        $skuTraslationArray['DPMQS141218'] = 'S6177';
        $skuTraslationArray['DPQQP140711'] = 'S6178';
        $skuTraslationArray['DPQQP140901'] = 'S6179';
        $skuTraslationArray['DPQQP140902'] = 'S6180';
        $skuTraslationArray['DPQQP140903'] = 'S6181';
        $skuTraslationArray['DPQQP140904'] = 'S6182';
        $skuTraslationArray['DPQQP140905'] = 'S6183';
        $skuTraslationArray['DPQQP140906'] = 'S6184';
        $skuTraslationArray['DPQQP140907'] = 'S6185';
        $skuTraslationArray['DPQQP140908'] = 'S6186';
        $skuTraslationArray['DPQQP140909'] = 'S6187';
        $skuTraslationArray['DPQQP140910'] = 'S6188';
        $skuTraslationArray['DPQQP140911'] = 'S6189';
        $skuTraslationArray['DPQQP140912'] = 'S6190';
        $skuTraslationArray['DPQQP140913'] = 'S6191';
        $skuTraslationArray['DPQQP141101'] = 'S6192';
        $skuTraslationArray['DPQQP141102'] = 'S6193';
        $skuTraslationArray['DPQQP141103'] = 'S6194';
        $skuTraslationArray['DPQQP141104'] = 'S6195';
        $skuTraslationArray['DPQQP141105'] = 'S6196';
        $skuTraslationArray['DPQQP141106'] = 'S6197';
        $skuTraslationArray['DPQQP141107'] = 'S6198';
        $skuTraslationArray['DPQQP141108'] = 'S6199';
        $skuTraslationArray['DPQQP141109'] = 'S6200';
        $skuTraslationArray['DPQQP141110'] = 'S6201';
        $skuTraslationArray['DPQQP141111'] = 'S6202';
        $skuTraslationArray['DPQQP141112'] = 'S6203';
        $skuTraslationArray['DPQQP141113'] = 'S6204';
        $skuTraslationArray['DPQQP150101'] = 'S6205';
        $skuTraslationArray['DPQQP150102'] = 'S6206';
        $skuTraslationArray['DPQQP150103'] = 'S6207';
        $skuTraslationArray['DPQQP150104'] = 'S6208';
        $skuTraslationArray['DPQQP150105'] = 'S6209';
        $skuTraslationArray['DPQQP150106'] = 'S6210';
        $skuTraslationArray['DPQQP150107'] = 'S6211';
        $skuTraslationArray['DPQQP150108'] = 'S6212';
        $skuTraslationArray['DPQQP150109'] = 'S6213';
        $skuTraslationArray['DPQQP150110'] = 'S6214';
        $skuTraslationArray['DPQQP150111'] = 'S6215';
        $skuTraslationArray['DPQQP150112'] = 'S6216';
        $skuTraslationArray['DPQQP150113'] = 'S6217';
        $skuTraslationArray['DPQQP150114'] = 'S6218';
        $skuTraslationArray['DPQQP150115'] = 'S6219';
        $skuTraslationArray['DPQQP150301'] = 'S6220';
        $skuTraslationArray['DPQQP150302'] = 'S6221';
        $skuTraslationArray['DPQQP150303'] = 'S6222';
        $skuTraslationArray['DPQQP150304'] = 'S6223';
        $skuTraslationArray['DPQQP150305'] = 'S6224';
        $skuTraslationArray['DPQQP150306'] = 'S6225';
        $skuTraslationArray['DPQQP150307'] = 'S6226';
        $skuTraslationArray['DPQQP150308'] = 'S6227';
        $skuTraslationArray['DPQQP150309'] = 'S6228';
        $skuTraslationArray['DPQQP150310'] = 'S6229';
        $skuTraslationArray['DPQQP150311'] = 'S6230';
        $skuTraslationArray['DPQQP150312'] = 'S6231';
        $skuTraslationArray['DPQQP150313'] = 'S6232';
        $skuTraslationArray['DPQQP150314'] = 'S6233';
        $skuTraslationArray['DPQQP150501'] = 'S6234';
        $skuTraslationArray['DPQQP150502'] = 'S6235';
        $skuTraslationArray['DPQQP150503'] = 'S6236';
        $skuTraslationArray['DPQQP150504'] = 'S6237';
        $skuTraslationArray['DPQQP150505'] = 'S6238';
        $skuTraslationArray['DPQQP150506'] = 'S6239';
        $skuTraslationArray['DPQQP150507'] = 'S6240';
        $skuTraslationArray['DPQQP150508'] = 'S6241';
        $skuTraslationArray['DPQQP150509'] = 'S6242';
        $skuTraslationArray['DPQQP150510'] = 'S6243';
        $skuTraslationArray['DPQQP150511'] = 'S6244';
        $skuTraslationArray['DPQQP150512'] = 'S6245';
        $skuTraslationArray['DPQQP150513'] = 'S6246';
        $skuTraslationArray['DPTRTLSTMPD'] = 'S6247';
        $skuTraslationArray['DPSNP150102'] = 'S6248';
        $skuTraslationArray['DPSNE141101'] = 'S6249';
        $skuTraslationArray['DPSNP150103'] = 'S6250';
        $skuTraslationArray['DPSNP150104'] = 'S6251';
        $skuTraslationArray['DPSNP150101'] = 'S6252';
        $skuTraslationArray['DPSNP140902'] = 'S6253';
        $skuTraslationArray['DPSNP140903'] = 'S6254';
        $skuTraslationArray['DPSNP140904'] = 'S6255';
        $skuTraslationArray['DPSNP140905'] = 'S6256';
        $skuTraslationArray['DP140147'] = 'DP140147D';
        $skuTraslationArray['DP140576'] = 'DP140576D';
        $skuTraslationArray['DP120033'] = 'DP120033D';
        $skuTraslationArray['DP140260'] = 'DP140260D';
        $skuTraslationArray['DP130276'] = 'DP130276D';
        $skuTraslationArray['DP120414'] = 'DP120414D';
        $skuTraslationArray['DP130241'] = 'DP130241D';
        $skuTraslationArray['DP120296'] = 'DP120296D';
        $skuTraslationArray['DPEBK1400'] = 'DPEBK1400D';
        $skuTraslationArray['DP140343'] = 'DP140343D';
        $skuTraslationArray['DP140178'] = 'DP140178D';
        $skuTraslationArray['DP140018'] = 'DP140018D';
        $skuTraslationArray['DP130443'] = 'DP130443D';
        $skuTraslationArray['DP130455'] = 'DP130455D';
        $skuTraslationArray['DP1112009'] = 'DP1112009D';
        $skuTraslationArray['DP1100694'] = 'DP1100694D';
        $skuTraslationArray['DP140140'] = 'DP140140D';
        $skuTraslationArray['DP140154'] = 'DP140154D';
        $skuTraslationArray['DP130500'] = 'DP130500D';
        $skuTraslationArray['DP140264'] = 'DP140264D';
        $skuTraslationArray['DP130369'] = 'DP130369D';
        $skuTraslationArray['DP140251'] = 'DP140251D';
        $skuTraslationArray['DP130305'] = 'DP130305D';
        $skuTraslationArray['DP140275'] = 'DP140275D';
        $skuTraslationArray['DP140276'] = 'DP140276D';
        $skuTraslationArray['DP124010'] = 'DP124010D';
        $skuTraslationArray['DP120415'] = 'DP120415D';
        $skuTraslationArray['DP140462'] = 'DP140462D';
        $skuTraslationArray['DP140468'] = 'DP140468D';
        $skuTraslationArray['DP140586'] = 'DP140586D';
        $skuTraslationArray['DP130389'] = 'DP130389D';
        $skuTraslationArray['DP130469'] = 'DP130469D';
        $skuTraslationArray['DP140162'] = 'DP140162D';
        $skuTraslationArray['DP140297'] = 'DP140297D';
        $skuTraslationArray['DPJFALPINE'] = 'DPJFALPINED';
        $skuTraslationArray['DP140554'] = 'DP140554D';
        $skuTraslationArray['DP130258'] = 'DP130258D';
        $skuTraslationArray['DPAT01'] = 'DPAT01D';
        $skuTraslationArray['DP1108008'] = 'DP1108008D';
        $skuTraslationArray['DP1202008'] = 'DP1202008D';
        $skuTraslationArray['DP140093'] = 'DP140093D';
        $skuTraslationArray['DP140228'] = 'DP140228D';
        $skuTraslationArray['DP140571'] = 'DP140571D';
        $skuTraslationArray['DP140259'] = 'DP140259D';
        $skuTraslationArray['DPE1100708'] = 'DPE1100708D';
        $skuTraslationArray['DPQ12223'] = 'DPQ12223D';
        $skuTraslationArray['DP130368'] = 'DP130368D';
        $skuTraslationArray['DP130260'] = 'DP130260D';
        $skuTraslationArray['DP1200793'] = 'DP1200793D';
        $skuTraslationArray['DP130537'] = 'DP130537D';
        $skuTraslationArray['DP140449'] = 'DP140449D';
        $skuTraslationArray['DPEQARIANN'] = 'DPEQARIANND';
        $skuTraslationArray['DP140587'] = 'DP140587D';
        $skuTraslationArray['DP1108012'] = 'DP1108012D';
        $skuTraslationArray['DP120281'] = 'DP120281D';
        $skuTraslationArray['DPEBK1401'] = 'DPEBK1401D';
        $skuTraslationArray['DP130468'] = 'DP130468D';
        $skuTraslationArray['DP140249'] = 'DP140249D';
        $skuTraslationArray['DP120243'] = 'DP120243D';
        $skuTraslationArray['DP121009'] = 'DP121009D';
        $skuTraslationArray['DP140525'] = 'DP140525D';
        $skuTraslationArray['DP140173'] = 'DP140173D';
        $skuTraslationArray['DP130378'] = 'DP130378D';
        $skuTraslationArray['DPC1100742'] = 'DPC1100742D';
        $skuTraslationArray['DP130370'] = 'DP130370D';
        $skuTraslationArray['DBDDP'] = 'DBDDPD';
        $skuTraslationArray['DP1100722'] = 'DP1100722D';
        $skuTraslationArray['DP140294'] = 'DP140294D';
        $skuTraslationArray['DPQ12233'] = 'DPQ12233D';
        $skuTraslationArray['DP140063'] = 'DP140063D';
        $skuTraslationArray['DPBQFAL09'] = 'DPBQFAL09D';
        $skuTraslationArray['DPBQFAL10'] = 'DPBQFAL10D';
        $skuTraslationArray['DBRCP'] = 'DBRCPD';
        $skuTraslationArray['DP130266'] = 'DP130266D';
        $skuTraslationArray['DP140131'] = 'DP140131D';
        $skuTraslationArray['DP140338'] = 'DP140338D';
        $skuTraslationArray['DP140458'] = 'DP140458D';
        $skuTraslationArray['DP140179'] = 'DP140179D';
        $skuTraslationArray['DP1110007'] = 'DP1110007D';
        $skuTraslationArray['DP122809'] = 'DP122809D';
        $skuTraslationArray['DPLQ110607'] = 'DPLQ110607D';
        $skuTraslationArray['DP140406'] = 'DP140406D';
        $skuTraslationArray['DPC1100748'] = 'DPC1100748D';
        $skuTraslationArray['DP140099'] = 'DP140099D';
        $skuTraslationArray['DP140459'] = 'DP140459D';
        $skuTraslationArray['DP140220'] = 'DP140220D';
        $skuTraslationArray['DP140033'] = 'DP140033D';
        $skuTraslationArray['DPEQBEARLT'] = 'DPEQBEARLTD';
        $skuTraslationArray['DPEBK1402'] = 'DPEBK1402D';
        $skuTraslationArray['DP130267'] = 'DP130267D';
        $skuTraslationArray['DP140277'] = 'DP140277D';
        $skuTraslationArray['DP140526'] = 'DP140526D';
        $skuTraslationArray['DP140172'] = 'DP140172D';
        $skuTraslationArray['DP140238'] = 'DP140238D';
        $skuTraslationArray['DP130450'] = 'DP130450D';
        $skuTraslationArray['DPQ12232'] = 'DPQ12232D';
        $skuTraslationArray['DP1108002'] = 'DP1108002D';
        $skuTraslationArray['DP140110'] = 'DP140110D';
        $skuTraslationArray['DP140311'] = 'DP140311D';
        $skuTraslationArray['DP1110013'] = 'DP1110013D';
        $skuTraslationArray['DP1112016'] = 'DP1112016D';
        $skuTraslationArray['DP140312'] = 'DP140312D';
        $skuTraslationArray['DPLA5561'] = 'DPLA5561D';
        $skuTraslationArray['DPLA5615'] = 'DPLA5615D';
        $skuTraslationArray['DPLA6051'] = 'DPLA6051D';
        $skuTraslationArray['DPLA6052'] = 'DPLA6052D';
        $skuTraslationArray['DPLA6036FP'] = 'DPLA6036FPD';
        $skuTraslationArray['DPLA6034FP'] = 'DPLA6034FPD';
        $skuTraslationArray['DPE1100705'] = 'DPE1100705D';
        $skuTraslationArray['DP140404'] = 'DP140404D';
        $skuTraslationArray['DP130217'] = 'DP130217D';
        $skuTraslationArray['DP140015'] = 'DP140015D';
        $skuTraslationArray['DP140337'] = 'DP140337D';
        $skuTraslationArray['DP1100733'] = 'DP1100733D';
        $skuTraslationArray['DP140527'] = 'DP140527D';
        $skuTraslationArray['DP130493'] = 'DP130493D';
        $skuTraslationArray['DP140463'] = 'DP140463D';
        $skuTraslationArray['DPEBK1403'] = 'DPEBK1403D';
        $skuTraslationArray['DP130516'] = 'DP130516D';
        $skuTraslationArray['DP130403'] = 'DP130403D';
        $skuTraslationArray['DP140142'] = 'DP140142D';
        $skuTraslationArray['DPEBK1404'] = 'DPEBK1404D';
        $skuTraslationArray['DPTQS82'] = 'DPTQS82D';
        $skuTraslationArray['DPQ12227'] = 'DPQ12227D';
        $skuTraslationArray['DP130548'] = 'DP130548D';
        $skuTraslationArray['DP140237'] = 'DP140237D';
        $skuTraslationArray['DP140116'] = 'DP140116D';
        $skuTraslationArray['DP124009'] = 'DP124009D';
        $skuTraslationArray['DBLGP'] = 'DBLGPD';
        $skuTraslationArray['DP140020'] = 'DP140020D';
        $skuTraslationArray['DP140078'] = 'DP140078D';
        $skuTraslationArray['DP140114'] = 'DP140114D';
        $skuTraslationArray['DBLUESP'] = 'DBLUESPD';
        $skuTraslationArray['DP140014'] = 'DP140014D';
        $skuTraslationArray['DP1112008'] = 'DP1112008D';
        $skuTraslationArray['DP130399'] = 'DP130399D';
        $skuTraslationArray['DP140136'] = 'DP140136D';
        $skuTraslationArray['DP140491'] = 'DP140491D';
        $skuTraslationArray['DP130383'] = 'DP130383D';
        $skuTraslationArray['DP130374'] = 'DP130374D';
        $skuTraslationArray['DP140522'] = 'DP140522D';
        $skuTraslationArray['DP1100724'] = 'DP1100724D';
        $skuTraslationArray['DP124006'] = 'DP124006D';
        $skuTraslationArray['DP140591'] = 'DP140591D';
        $skuTraslationArray['DP130310'] = 'DP130310D';
        $skuTraslationArray['DP140357'] = 'DP140357D';
        $skuTraslationArray['DP130212'] = 'DP130212D';
        $skuTraslationArray['DP140144'] = 'DP140144D';
        $skuTraslationArray['DP140358'] = 'DP140358D';
        $skuTraslationArray['DP140089'] = 'DP140089D';
        $skuTraslationArray['DP140077'] = 'DP140077D';
        $skuTraslationArray['DP140207'] = 'DP140207D';
        $skuTraslationArray['DP140315'] = 'DP140315D';
        $skuTraslationArray['DP140031'] = 'DP140031D';
        $skuTraslationArray['DP140257'] = 'DP140257D';
        $skuTraslationArray['DP140460'] = 'DP140460D';
        $skuTraslationArray['DPLQ110412'] = 'DPLQ110412D';
        $skuTraslationArray['DP140296'] = 'DP140296D';
        $skuTraslationArray['DP140356'] = 'DP140356D';
        $skuTraslationArray['DPEQBRKLYN'] = 'DPEQBRKLYND';
        $skuTraslationArray['DP140106'] = 'DP140106D';
        $skuTraslationArray['DP140359'] = 'DP140359D';
        $skuTraslationArray['DP140233'] = 'DP140233D';
        $skuTraslationArray['DP1100684'] = 'DP1100684D';
        $skuTraslationArray['DPEBK1405'] = 'DPEBK1405D';
        $skuTraslationArray['DPE1100695'] = 'DPE1100695D';
        $skuTraslationArray['DBUNNYP'] = 'DBUNNYPD';
        $skuTraslationArray['DP130447'] = 'DP130447D';
        $skuTraslationArray['DP120295'] = 'DP120295D';
        $skuTraslationArray['DP120028'] = 'DP120028D';
        $skuTraslationArray['DPEQBTLYBL'] = 'DPEQBTLYBLD';
        $skuTraslationArray['DP140056'] = 'DP140056D';
        $skuTraslationArray['DP140055'] = 'DP140055D';
        $skuTraslationArray['DP1200783'] = 'DP1200783D';
        $skuTraslationArray['DP1108013'] = 'DP1108013D';
        $skuTraslationArray['DP130230'] = 'DP130230D';
        $skuTraslationArray['DP121204'] = 'DP121204D';
        $skuTraslationArray['DP140319'] = 'DP140319D';
        $skuTraslationArray['DP140097'] = 'DP140097D';
        $skuTraslationArray['DP140284'] = 'DP140284D';
        $skuTraslationArray['DP140211'] = 'DP140211D';
        $skuTraslationArray['DP140398'] = 'DP140398D';
        $skuTraslationArray['DP140025'] = 'DP140025D';
        $skuTraslationArray['DP130219'] = 'DP130219D';
        $skuTraslationArray['DP1200790'] = 'DP1200790D';
        $skuTraslationArray['DP1100718'] = 'DP1100718D';
        $skuTraslationArray['DP130363'] = 'DP130363D';
        $skuTraslationArray['DPEQCNDYFL'] = 'DPEQCNDYFLD';
        $skuTraslationArray['DP120294'] = 'DP120294D';
        $skuTraslationArray['DP126003'] = 'DP126003D';
        $skuTraslationArray['DP1200787'] = 'DP1200787D';
        $skuTraslationArray['DCARBP'] = 'DCARBPD';
        $skuTraslationArray['DPLQ110611'] = 'DPLQ110611D';
        $skuTraslationArray['DP120422'] = 'DP120422D';
        $skuTraslationArray['DP1112007'] = 'DP1112007D';
        $skuTraslationArray['DP1202003'] = 'DP1202003D';
        $skuTraslationArray['DP130345'] = 'DP130345D';
        $skuTraslationArray['DP120035'] = 'DP120035D';
        $skuTraslationArray['DP140210'] = 'DP140210D';
        $skuTraslationArray['DP140156'] = 'DP140156D';
        $skuTraslationArray['DP130222'] = 'DP130222D';
        $skuTraslationArray['DPEQCELDRM'] = 'DPEQCELDRMD';
        $skuTraslationArray['DP140278'] = 'DP140278D';
        $skuTraslationArray['DP1200784'] = 'DP1200784D';
        $skuTraslationArray['DP140450'] = 'DP140450D';
        $skuTraslationArray['DP140221'] = 'DP140221D';
        $skuTraslationArray['DP140119'] = 'DP140119D';
        $skuTraslationArray['DP140322'] = 'DP140322D';
        $skuTraslationArray['DP1200788'] = 'DP1200788D';
        $skuTraslationArray['DP130229'] = 'DP130229D';
        $skuTraslationArray['DP140469'] = 'DP140469D';
        $skuTraslationArray['DCHARMP'] = 'DCHARMPD';
        $skuTraslationArray['DP140573'] = 'DP140573D';
        $skuTraslationArray['DP140421'] = 'DP140421D';
        $skuTraslationArray['DP130209'] = 'DP130209D';
        $skuTraslationArray['DP130201'] = 'DP130201D';
        $skuTraslationArray['DPQ12228'] = 'DPQ12228D';
        $skuTraslationArray['DP140232'] = 'DP140232D';
        $skuTraslationArray['DPC1100741'] = 'DPC1100741D';
        $skuTraslationArray['DP130396'] = 'DP130396D';
        $skuTraslationArray['DCSP'] = 'DCSPD';
        $skuTraslationArray['DP120424'] = 'DP120424D';
        $skuTraslationArray['DP140536'] = 'DP140536D';
        $skuTraslationArray['DP140464'] = 'DP140464D';
        $skuTraslationArray['DPEQ110687'] = 'DPEQ110687D';
        $skuTraslationArray['DP130542'] = 'DP130542D';
        $skuTraslationArray['DP130382'] = 'DP130382D';
        $skuTraslationArray['DPJFCHNGRL'] = 'DPJFCHNGRLD';
        $skuTraslationArray['DP130387'] = 'DP130387D';
        $skuTraslationArray['DPC1100743'] = 'DPC1100743D';
        $skuTraslationArray['DP1112011'] = 'DP1112011D';
        $skuTraslationArray['DPEBK1406'] = 'DPEBK1406D';
        $skuTraslationArray['DP1110003'] = 'DP1110003D';
        $skuTraslationArray['DPC1100750'] = 'DPC1100750D';
        $skuTraslationArray['DP140240'] = 'DP140240D';
        $skuTraslationArray['DP121209'] = 'DP121209D';
        $skuTraslationArray['DP130541'] = 'DP130541D';
        $skuTraslationArray['DP130518'] = 'DP130518D';
        $skuTraslationArray['DP130547'] = 'DP130547D';
        $skuTraslationArray['DP120286'] = 'DP120286D';
        $skuTraslationArray['DP124001'] = 'DP124001D';
        $skuTraslationArray['DP140501'] = 'DP140501D';
        $skuTraslationArray['DP140389'] = 'DP140389D';
        $skuTraslationArray['DP130207'] = 'DP130207D';
        $skuTraslationArray['DCIRCLEP'] = 'DCIRCLEPD';
        $skuTraslationArray['DP140427'] = 'DP140427D';
        $skuTraslationArray['DP140408'] = 'DP140408D';
        $skuTraslationArray['DP120032'] = 'DP120032D';
        $skuTraslationArray['DPEBK1407'] = 'DPEBK1407D';
        $skuTraslationArray['DPJFCVLWAR'] = 'DPJFCVLWARD';
        $skuTraslationArray['DP140098'] = 'DP140098D';
        $skuTraslationArray['DP140340'] = 'DP140340D';
        $skuTraslationArray['DP140502'] = 'DP140502D';
        $skuTraslationArray['DPLQ110408'] = 'DPLQ110408D';
        $skuTraslationArray['DP130364'] = 'DP130364D';
        $skuTraslationArray['DP140043'] = 'DP140043D';
        $skuTraslationArray['DP1100737'] = 'DP1100737D';
        $skuTraslationArray['DP130354'] = 'DP130354D';
        $skuTraslationArray['DPLQ110603'] = 'DPLQ110603D';
        $skuTraslationArray['DP140505'] = 'DP140505D';
        $skuTraslationArray['DP140453'] = 'DP140453D';
        $skuTraslationArray['DP140266'] = 'DP140266D';
        $skuTraslationArray['DP140058'] = 'DP140058D';
        $skuTraslationArray['DP130357'] = 'DP130357D';
        $skuTraslationArray['DP140174'] = 'DP140174D';
        $skuTraslationArray['DP140299'] = 'DP140299D';
        $skuTraslationArray['DP130391'] = 'DP130391D';
        $skuTraslationArray['DP140531'] = 'DP140531D';
        $skuTraslationArray['DPE1100691'] = 'DPE1100691D';
        $skuTraslationArray['DCHRMP'] = 'DCHRMPD';
        $skuTraslationArray['DCOTTP'] = 'DCOTTPD';
        $skuTraslationArray['DP120426'] = 'DP120426D';
        $skuTraslationArray['DP1100714'] = 'DP1100714D';
        $skuTraslationArray['DP140254'] = 'DP140254D';
        $skuTraslationArray['DP130570'] = 'DP130570D';
        $skuTraslationArray['DP140183'] = 'DP140183D';
        $skuTraslationArray['DP130397'] = 'DP130397D';
        $skuTraslationArray['DP140047'] = 'DP140047D';
        $skuTraslationArray['DCNPP'] = 'DCNPPD';
        $skuTraslationArray['DP140345'] = 'DP140345D';
        $skuTraslationArray['DPODW080514'] = 'DPODW080514D';
        $skuTraslationArray['DP130372'] = 'DP130372D';
        $skuTraslationArray['DP140085'] = 'DP140085D';
        $skuTraslationArray['DP126006'] = 'DP126006D';
        $skuTraslationArray['DP140361'] = 'DP140361D';
        $skuTraslationArray['DP130452'] = 'DP130452D';
        $skuTraslationArray['DP130571'] = 'DP130571D';
        $skuTraslationArray['DP140588'] = 'DP140588D';
        $skuTraslationArray['DP126007'] = 'DP126007D';
        $skuTraslationArray['DP1110006'] = 'DP1110006D';
        $skuTraslationArray['DP140206'] = 'DP140206D';
        $skuTraslationArray['DP130465'] = 'DP130465D';
        $skuTraslationArray['DP140346'] = 'DP140346D';
        $skuTraslationArray['DP130221'] = 'DP130221D';
        $skuTraslationArray['DP1108005'] = 'DP1108005D';
        $skuTraslationArray['DP1200782'] = 'DP1200782D';
        $skuTraslationArray['DPEQ110677'] = 'DPEQ110677D';
        $skuTraslationArray['DP140157'] = 'DP140157D';
        $skuTraslationArray['DP140279'] = 'DP140279D';
        $skuTraslationArray['DP140362'] = 'DP140362D';
        $skuTraslationArray['DP140300'] = 'DP140300D';
        $skuTraslationArray['DP120419'] = 'DP120419D';
        $skuTraslationArray['DP140341'] = 'DP140341D';
        $skuTraslationArray['DP130317'] = 'DP130317D';
        $skuTraslationArray['DP140532'] = 'DP140532D';
        $skuTraslationArray['DPQ12224'] = 'DPQ12224D';
        $skuTraslationArray['DP130326'] = 'DP130326D';
        $skuTraslationArray['DP140189'] = 'DP140189D';
        $skuTraslationArray['DPEQ110684'] = 'DPEQ110684D';
        $skuTraslationArray['DP130406'] = 'DP130406D';
        $skuTraslationArray['DP140556'] = 'DP140556D';
        $skuTraslationArray['DP120282'] = 'DP120282D';
        $skuTraslationArray['DPQKSUM0903'] = 'DPQKSUM0903D';
        $skuTraslationArray['DP130234'] = 'DP130234D';
        $skuTraslationArray['DP130535'] = 'DP130535D';
        $skuTraslationArray['DP140577'] = 'DP140577D';
        $skuTraslationArray['DP1108014'] = 'DP1108014D';
        $skuTraslationArray['DP130409'] = 'DP130409D';
        $skuTraslationArray['DDTOTEP'] = 'DDTOTEPD';
        $skuTraslationArray['DP140165'] = 'DP140165D';
        $skuTraslationArray['DP140318'] = 'DP140318D';
        $skuTraslationArray['DP140209'] = 'DP140209D';
        $skuTraslationArray['DP140401'] = 'DP140401D';
        $skuTraslationArray['DPJFDMDJOY'] = 'DPJFDMDJOYD';
        $skuTraslationArray['DP1100742'] = 'DP1100742D';
        $skuTraslationArray['DPQ12229'] = 'DPQ12229D';
        $skuTraslationArray['DP1200785'] = 'DP1200785D';
        $skuTraslationArray['DP130519'] = 'DP130519D';
        $skuTraslationArray['DP1100726'] = 'DP1100726D';
        $skuTraslationArray['DP1112002'] = 'DP1112002D';
        $skuTraslationArray['DP140028'] = 'DP140028D';
        $skuTraslationArray['DP140301'] = 'DP140301D';
        $skuTraslationArray['DP140470'] = 'DP140470D';
        $skuTraslationArray['DP1108009'] = 'DP1108009D';
        $skuTraslationArray['DP140166'] = 'DP140166D';
        $skuTraslationArray['DP120289'] = 'DP120289D';
        $skuTraslationArray['DP122811'] = 'DP122811D';
        $skuTraslationArray['DP130242'] = 'DP130242D';
        $skuTraslationArray['DP1200786'] = 'DP1200786D';
        $skuTraslationArray['DP130529'] = 'DP130529D';
        $skuTraslationArray['DP130530'] = 'DP130530D';
        $skuTraslationArray['DP130400'] = 'DP130400D';
        $skuTraslationArray['DP140145'] = 'DP140145D';
        $skuTraslationArray['DP130473'] = 'DP130473D';
        $skuTraslationArray['DP140180'] = 'DP140180D';
        $skuTraslationArray['DPEBK1408'] = 'DPEBK1408D';
        $skuTraslationArray['DP130308'] = 'DP130308D';
        $skuTraslationArray['DP140091'] = 'DP140091D';
        $skuTraslationArray['DP130410'] = 'DP130410D';
        $skuTraslationArray['DP130348'] = 'DP130348D';
        $skuTraslationArray['DP1200794'] = 'DP1200794D';
        $skuTraslationArray['DP130261'] = 'DP130261D';
        $skuTraslationArray['DP140024'] = 'DP140024D';
        $skuTraslationArray['DP140428'] = 'DP140428D';
        $skuTraslationArray['DP130303'] = 'DP130303D';
        $skuTraslationArray['DP1100712'] = 'DP1100712D';
        $skuTraslationArray['DP140124'] = 'DP140124D';
        $skuTraslationArray['DP130262'] = 'DP130262D';
        $skuTraslationArray['DP130325'] = 'DP130325D';
        $skuTraslationArray['DPLQ110604'] = 'DPLQ110604D';
        $skuTraslationArray['DP130315'] = 'DP130315D';
        $skuTraslationArray['DP140113'] = 'DP140113D';
        $skuTraslationArray['DP120280'] = 'DP120280D';
        $skuTraslationArray['DPJFENGIVY'] = 'DPJFENGIVYD';
        $skuTraslationArray['DETHOSP'] = 'DETHOSPD';
        $skuTraslationArray['DPJFERPSTR'] = 'DPJFERPSTRD';
        $skuTraslationArray['DP140335'] = 'DP140335D';
        $skuTraslationArray['DP120030'] = 'DP120030D';
        $skuTraslationArray['DPCB1169'] = 'DPCB1169D';
        $skuTraslationArray['DP130384'] = 'DP130384D';
        $skuTraslationArray['DP130312'] = 'DP130312D';
        $skuTraslationArray['DP140592'] = 'DP140592D';
        $skuTraslationArray['DP140083'] = 'DP140083D';
        $skuTraslationArray['DP1110002'] = 'DP1110002D';
        $skuTraslationArray['DP130404'] = 'DP130404D';
        $skuTraslationArray['DP130449'] = 'DP130449D';
        $skuTraslationArray['DP140363'] = 'DP140363D';
        $skuTraslationArray['DP140347'] = 'DP140347D';
        $skuTraslationArray['DP130344'] = 'DP130344D';
        $skuTraslationArray['DP124004'] = 'DP124004D';
        $skuTraslationArray['DPLQ110405'] = 'DPLQ110405D';
        $skuTraslationArray['DP140274'] = 'DP140274D';
        $skuTraslationArray['DPEQ110673'] = 'DPEQ110673D';
        $skuTraslationArray['DP140416'] = 'DP140416D';
        $skuTraslationArray['DP120416'] = 'DP120416D';
        $skuTraslationArray['DP140430'] = 'DP140430D';
        $skuTraslationArray['DP120292'] = 'DP120292D';
        $skuTraslationArray['DP1100736'] = 'DP1100736D';
        $skuTraslationArray['DP1202005'] = 'DP1202005D';
        $skuTraslationArray['DP130349'] = 'DP130349D';
        $skuTraslationArray['DP130492'] = 'DP130492D';
        $skuTraslationArray['DP130347'] = 'DP130347D';
        $skuTraslationArray['DPEQFNSQR'] = 'DPEQFNSQRD';
        $skuTraslationArray['DP140471'] = 'DP140471D';
        $skuTraslationArray['DP1100681'] = 'DP1100681D';
        $skuTraslationArray['DPQ12230'] = 'DPQ12230D';
        $skuTraslationArray['DP140558'] = 'DP140558D';
        $skuTraslationArray['DP140426'] = 'DP140426D';
        $skuTraslationArray['DP130498'] = 'DP130498D';
        $skuTraslationArray['DP120804'] = 'DP120804D';
        $skuTraslationArray['DP140103'] = 'DP140103D';
        $skuTraslationArray['DP140472'] = 'DP140472D';
        $skuTraslationArray['DP130232'] = 'DP130232D';
        $skuTraslationArray['DP140121'] = 'DP140121D';
        $skuTraslationArray['DP130226'] = 'DP130226D';
        $skuTraslationArray['DP140125'] = 'DP140125D';
        $skuTraslationArray['DP124003'] = 'DP124003D';
        $skuTraslationArray['DP1108011'] = 'DP1108011D';
        $skuTraslationArray['DP140022'] = 'DP140022D';
        $skuTraslationArray['DP140403'] = 'DP140403D';
        $skuTraslationArray['DP130458'] = 'DP130458D';
        $skuTraslationArray['DP122802'] = 'DP122802D';
        $skuTraslationArray['DP130448'] = 'DP130448D';
        $skuTraslationArray['DP140495'] = 'DP140495D';
        $skuTraslationArray['DP120802'] = 'DP120802D';
        $skuTraslationArray['DP140193'] = 'DP140193D';
        $skuTraslationArray['DP1202007'] = 'DP1202007D';
        $skuTraslationArray['DP140336'] = 'DP140336D';
        $skuTraslationArray['DP1100720'] = 'DP1100720D';
        $skuTraslationArray['DP120038'] = 'DP120038D';
        $skuTraslationArray['DP140100'] = 'DP140100D';
        $skuTraslationArray['DP140589'] = 'DP140589D';
        $skuTraslationArray['DPLA5616'] = 'DPLA5616D';
        $skuTraslationArray['DPLA5619'] = 'DPLA5619D';
        $skuTraslationArray['DP140021'] = 'DP140021D';
        $skuTraslationArray['DP140506'] = 'DP140506D';
        $skuTraslationArray['DP140042'] = 'DP140042D';
        $skuTraslationArray['DP130515'] = 'DP130515D';
        $skuTraslationArray['DP130351'] = 'DP130351D';
        $skuTraslationArray['DP140095'] = 'DP140095D';
        $skuTraslationArray['DP140305'] = 'DP140305D';
        $skuTraslationArray['DP130203'] = 'DP130203D';
        $skuTraslationArray['DP140452'] = 'DP140452D';
        $skuTraslationArray['DP1100723'] = 'DP1100723D';
        $skuTraslationArray['DP140520'] = 'DP140520D';
        $skuTraslationArray['DP140473'] = 'DP140473D';
        $skuTraslationArray['DP140293'] = 'DP140293D';
        $skuTraslationArray['DP120809'] = 'DP120809D';
        $skuTraslationArray['DP130307'] = 'DP130307D';
        $skuTraslationArray['DP1100743'] = 'DP1100743D';
        $skuTraslationArray['DP130273'] = 'DP130273D';
        $skuTraslationArray['DP140170'] = 'DP140170D';
        $skuTraslationArray['DP140570'] = 'DP140570D';
        $skuTraslationArray['DP140574'] = 'DP140574D';
        $skuTraslationArray['DP130544'] = 'DP130544D';
        $skuTraslationArray['DP140314'] = 'DP140314D';
        $skuTraslationArray['DP140235'] = 'DP140235D';
        $skuTraslationArray['DP140270'] = 'DP140270D';
        $skuTraslationArray['DP140053'] = 'DP140053D';
        $skuTraslationArray['DP120248'] = 'DP120248D';
        $skuTraslationArray['DP120801'] = 'DP120801D';
        $skuTraslationArray['DP121002'] = 'DP121002D';
        $skuTraslationArray['DP140559'] = 'DP140559D';
        $skuTraslationArray['DP130282'] = 'DP130282D';
        $skuTraslationArray['DPLQ110609'] = 'DPLQ110609D';
        $skuTraslationArray['DP140062'] = 'DP140062D';
        $skuTraslationArray['DP140034'] = 'DP140034D';
        $skuTraslationArray['DPEQGSDLGT'] = 'DPEQGSDLGTD';
        $skuTraslationArray['DP130216'] = 'DP130216D';
        $skuTraslationArray['DPEBK1409'] = 'DPEBK1409D';
        $skuTraslationArray['DP1108004'] = 'DP1108004D';
        $skuTraslationArray['DGWINP'] = 'DGWINPD';
        $skuTraslationArray['DPTQS78'] = 'DPTQS78D';
        $skuTraslationArray['DP140016'] = 'DP140016D';
        $skuTraslationArray['DP130574'] = 'DP130574D';
        $skuTraslationArray['DP140302'] = 'DP140302D';
        $skuTraslationArray['DP140133'] = 'DP140133D';
        $skuTraslationArray['DPC1100752'] = 'DPC1100752D';
        $skuTraslationArray['DP130211'] = 'DP130211D';
        $skuTraslationArray['DP1110012'] = 'DP1110012D';
        $skuTraslationArray['DP140521'] = 'DP140521D';
        $skuTraslationArray['DP140051'] = 'DP140051D';
        $skuTraslationArray['DP140422'] = 'DP140422D';
        $skuTraslationArray['DPE1100699'] = 'DPE1100699D';
        $skuTraslationArray['DP120039'] = 'DP120039D';
        $skuTraslationArray['DP130413'] = 'DP130413D';
        $skuTraslationArray['DP140246'] = 'DP140246D';
        $skuTraslationArray['DP140435'] = 'DP140435D';
        $skuTraslationArray['DP130346'] = 'DP130346D';
        $skuTraslationArray['DP140560'] = 'DP140560D';
        $skuTraslationArray['DP1100735'] = 'DP1100735D';
        $skuTraslationArray['DGFLOWP'] = 'DGFLOWPD';
        $skuTraslationArray['DPLQ110409'] = 'DPLQ110409D';
        $skuTraslationArray['DP140026'] = 'DP140026D';
        $skuTraslationArray['DGBPP'] = 'DGBPPD';
        $skuTraslationArray['DP1202004'] = 'DP1202004D';
        $skuTraslationArray['DP130564'] = 'DP130564D';
        $skuTraslationArray['DP130523'] = 'DP130523D';
        $skuTraslationArray['DP130528'] = 'DP130528D';
        $skuTraslationArray['DPEBK1410'] = 'DPEBK1410D';
        $skuTraslationArray['DP120246'] = 'DP120246D';
        $skuTraslationArray['DP120297'] = 'DP120297D';
        $skuTraslationArray['DP140032'] = 'DP140032D';
        $skuTraslationArray['DP121010'] = 'DP121010D';
        $skuTraslationArray['DP130379'] = 'DP130379D';
        $skuTraslationArray['DP140222'] = 'DP140222D';
        $skuTraslationArray['DP120250'] = 'DP120250D';
        $skuTraslationArray['DP130271'] = 'DP130271D';
        $skuTraslationArray['DP140017'] = 'DP140017D';
        $skuTraslationArray['DP140163'] = 'DP140163D';
        $skuTraslationArray['DP140529'] = 'DP140529D';
        $skuTraslationArray['DP1110001'] = 'DP1110001D';
        $skuTraslationArray['DP1200795'] = 'DP1200795D';
        $skuTraslationArray['DPEBK1411'] = 'DPEBK1411D';
        $skuTraslationArray['DP120418'] = 'DP120418D';
        $skuTraslationArray['DP140129'] = 'DP140129D';
        $skuTraslationArray['DP130474'] = 'DP130474D';
        $skuTraslationArray['DP120029'] = 'DP120029D';
        $skuTraslationArray['DP130306'] = 'DP130306D';
        $skuTraslationArray['DP1200781'] = 'DP1200781D';
        $skuTraslationArray['DP140436'] = 'DP140436D';
        $skuTraslationArray['DPEQHRTAGE'] = 'DPEQHRTAGED';
        $skuTraslationArray['DP140432'] = 'DP140432D';
        $skuTraslationArray['DP140575'] = 'DP140575D';
        $skuTraslationArray['DP140572'] = 'DP140572D';
        $skuTraslationArray['DP140271'] = 'DP140271D';
        $skuTraslationArray['DP140497'] = 'DP140497D';
        $skuTraslationArray['DPEQHDNSTR'] = 'DPEQHDNSTRD';
        $skuTraslationArray['DP130328'] = 'DP130328D';
        $skuTraslationArray['DP140388'] = 'DP140388D';
        $skuTraslationArray['DP140248'] = 'DP140248D';
        $skuTraslationArray['DP140218'] = 'DP140218D';
        $skuTraslationArray['DP140306'] = 'DP140306D';
        $skuTraslationArray['DPTQS85'] = 'DPTQS85D';
        $skuTraslationArray['DP140580'] = 'DP140580D';
        $skuTraslationArray['DP1112010'] = 'DP1112010D';
        $skuTraslationArray['DP130438'] = 'DP130438D';
        $skuTraslationArray['DP140241'] = 'DP140241D';
        $skuTraslationArray['DP130445'] = 'DP130445D';
        $skuTraslationArray['DPC1100746'] = 'DPC1100746D';
        $skuTraslationArray['DP130324'] = 'DP130324D';
        $skuTraslationArray['DP121005'] = 'DP121005D';
        $skuTraslationArray['DP140127'] = 'DP140127D';
        $skuTraslationArray['DP140320'] = 'DP140320D';
        $skuTraslationArray['DP140596'] = 'DP140596D';
        $skuTraslationArray['DP122814'] = 'DP122814D';
        $skuTraslationArray['DPODW071514'] = 'DPODW071514D';
        $skuTraslationArray['DP130436'] = 'DP130436D';
        $skuTraslationArray['DP140280'] = 'DP140280D';
        $skuTraslationArray['DP130440'] = 'DP130440D';
        $skuTraslationArray['DPSTR04'] = 'DPSTR04D';
        $skuTraslationArray['DP130495'] = 'DP130495D';
        $skuTraslationArray['DP130565'] = 'DP130565D';
        $skuTraslationArray['DPLQ110407'] = 'DPLQ110407D';
        $skuTraslationArray['DP130206'] = 'DP130206D';
        $skuTraslationArray['DP140082'] = 'DP140082D';
        $skuTraslationArray['DP1108006'] = 'DP1108006D';
        $skuTraslationArray['DP140219'] = 'DP140219D';
        $skuTraslationArray['DP140285'] = 'DP140285D';
        $skuTraslationArray['DP130385'] = 'DP130385D';
        $skuTraslationArray['DP130224'] = 'DP130224D';
        $skuTraslationArray['DP130208'] = 'DP130208D';
        $skuTraslationArray['DP140597'] = 'DP140597D';
        $skuTraslationArray['DP140583'] = 'DP140583D';
        $skuTraslationArray['DP140474'] = 'DP140474D';
        $skuTraslationArray['DP130407'] = 'DP130407D';
        $skuTraslationArray['DP130398'] = 'DP130398D';
        $skuTraslationArray['DP1112005'] = 'DP1112005D';
        $skuTraslationArray['DP130231'] = 'DP130231D';
        $skuTraslationArray['DP140316'] = 'DP140316D';
        $skuTraslationArray['DP130386'] = 'DP130386D';
        $skuTraslationArray['DPJFINTRLK'] = 'DPJFINTRLKD';
        $skuTraslationArray['DPQ12231'] = 'DPQ12231D';
        $skuTraslationArray['DP140561'] = 'DP140561D';
        $skuTraslationArray['DP1100690'] = 'DP1100690D';
        $skuTraslationArray['DP130550'] = 'DP130550D';
        $skuTraslationArray['DPE1100694'] = 'DPE1100694D';
        $skuTraslationArray['DP120274'] = 'DP120274D';
        $skuTraslationArray['DPC1100745'] = 'DPC1100745D';
        $skuTraslationArray['DP140164'] = 'DP140164D';
        $skuTraslationArray['DP140050'] = 'DP140050D';
        $skuTraslationArray['DP130270'] = 'DP130270D';
        $skuTraslationArray['DP140044'] = 'DP140044D';
        $skuTraslationArray['DPLQ110608'] = 'DPLQ110608D';
        $skuTraslationArray['DP130236'] = 'DP130236D';
        $skuTraslationArray['DP130395'] = 'DP130395D';
        $skuTraslationArray['DP140272'] = 'DP140272D';
        $skuTraslationArray['DP130366'] = 'DP130366D';
        $skuTraslationArray['DP1110009'] = 'DP1110009D';
        $skuTraslationArray['DP130375'] = 'DP130375D';
        $skuTraslationArray['DP130444'] = 'DP130444D';
        $skuTraslationArray['DP140029'] = 'DP140029D';
        $skuTraslationArray['DPLQ110602'] = 'DPLQ110602D';
        $skuTraslationArray['DP1200791'] = 'DP1200791D';
        $skuTraslationArray['DPC1100747'] = 'DPC1100747D';
        $skuTraslationArray['DP140245'] = 'DP140245D';
        $skuTraslationArray['DPAT03'] = 'DPAT03D';
        $skuTraslationArray['DPEQ110682'] = 'DPEQ110682D';
        $skuTraslationArray['DP140407'] = 'DP140407D';
        $skuTraslationArray['DP140396'] = 'DP140396D';
        $skuTraslationArray['DP130220'] = 'DP130220D';
        $skuTraslationArray['DP130343'] = 'DP130343D';
        $skuTraslationArray['DP130283'] = 'DP130283D';
        $skuTraslationArray['DKSSP'] = 'DKSSPD';
        $skuTraslationArray['DP140185'] = 'DP140185D';
        $skuTraslationArray['DP1100734'] = 'DP1100734D';
        $skuTraslationArray['DP120413'] = 'DP120413D';
        $skuTraslationArray['DPQKSUM09'] = 'DPQKSUM09D';
        $skuTraslationArray['DPQKSUM10'] = 'DPQKSUM10D';
        $skuTraslationArray['DP140475'] = 'DP140475D';
        $skuTraslationArray['DP140500'] = 'DP140500D';
        $skuTraslationArray['DPE1100704'] = 'DPE1100704D';
        $skuTraslationArray['DP126009'] = 'DP126009D';
        $skuTraslationArray['DP140159'] = 'DP140159D';
        $skuTraslationArray['DP140135'] = 'DP140135D';
        $skuTraslationArray['DP130464'] = 'DP130464D';
        $skuTraslationArray['DP140486'] = 'DP140486D';
        $skuTraslationArray['DP130304'] = 'DP130304D';
        $skuTraslationArray['DP140595'] = 'DP140595D';
        $skuTraslationArray['DP130401'] = 'DP130401D';
        $skuTraslationArray['DPLQ0109'] = 'DPLQ0109D';
        $skuTraslationArray['DP140217'] = 'DP140217D';
        $skuTraslationArray['DPLQ110410'] = 'DPLQ110410D';
        $skuTraslationArray['DP140466'] = 'DP140466D';
        $skuTraslationArray['DP130408'] = 'DP130408D';
        $skuTraslationArray['DP140348'] = 'DP140348D';
        $skuTraslationArray['DP140493'] = 'DP140493D';
        $skuTraslationArray['DP130360'] = 'DP130360D';
        $skuTraslationArray['DP1100713'] = 'DP1100713D';
        $skuTraslationArray['DP140268'] = 'DP140268D';
        $skuTraslationArray['DPQ12225'] = 'DPQ12225D';
        $skuTraslationArray['DP1108010'] = 'DP1108010D';
        $skuTraslationArray['DP140088'] = 'DP140088D';
        $skuTraslationArray['DP120291'] = 'DP120291D';
        $skuTraslationArray['DP121007'] = 'DP121007D';
        $skuTraslationArray['DP140290'] = 'DP140290D';
        $skuTraslationArray['DP120427'] = 'DP120427D';
        $skuTraslationArray['DP120271'] = 'DP120271D';
        $skuTraslationArray['DPE1100706'] = 'DPE1100706D';
        $skuTraslationArray['DP130204'] = 'DP130204D';
        $skuTraslationArray['DP140494'] = 'DP140494D';
        $skuTraslationArray['DP120031'] = 'DP120031D';
        $skuTraslationArray['DP140412'] = 'DP140412D';
        $skuTraslationArray['DP130309'] = 'DP130309D';
        $skuTraslationArray['DP121201'] = 'DP121201D';
        $skuTraslationArray['DPE1100709'] = 'DPE1100709D';
        $skuTraslationArray['DPLQ130400'] = 'DPLQ130400D';
        $skuTraslationArray['DP140541'] = 'DP140541D';
        $skuTraslationArray['DP130568'] = 'DP130568D';
        $skuTraslationArray['DP1110008'] = 'DP1110008D';
        $skuTraslationArray['DP130361'] = 'DP130361D';
        $skuTraslationArray['DP140598'] = 'DP140598D';
        $skuTraslationArray['DPSTR03'] = 'DPSTR03D';
        $skuTraslationArray['DP121208'] = 'DP121208D';
        $skuTraslationArray['DPCMEE1505'] = 'DPCMEE1505D';
        $skuTraslationArray['DPODW021715'] = 'DPODW021715D';
        $skuTraslationArray['DP130380'] = 'DP130380D';
        $skuTraslationArray['DP140041'] = 'DP140041D';
        $skuTraslationArray['DPODW051414'] = 'DPODW051414D';
        $skuTraslationArray['DPODW110414'] = 'DPODW110414D';
        $skuTraslationArray['DP130437'] = 'DP130437D';
        $skuTraslationArray['DP140530'] = 'DP140530D';
        $skuTraslationArray['DP140488'] = 'DP140488D';
        $skuTraslationArray['DP120807'] = 'DP120807D';
        $skuTraslationArray['DPEQ110683'] = 'DPEQ110683D';
        $skuTraslationArray['DP140420'] = 'DP140420D';
        $skuTraslationArray['DPODWCLRME'] = 'DPODWCLRMED';
        $skuTraslationArray['DP130390'] = 'DP130390D';
        $skuTraslationArray['DMATBP'] = 'DMATBPD';
        $skuTraslationArray['DP121202'] = 'DP121202D';
        $skuTraslationArray['DP120290'] = 'DP120290D';
        $skuTraslationArray['DPEBK1412'] = 'DPEBK1412D';
        $skuTraslationArray['DP140054'] = 'DP140054D';
        $skuTraslationArray['DP130546'] = 'DP130546D';
        $skuTraslationArray['DP122813'] = 'DP122813D';
        $skuTraslationArray['DP140030'] = 'DP140030D';
        $skuTraslationArray['DP130411'] = 'DP130411D';
        $skuTraslationArray['DP140104'] = 'DP140104D';
        $skuTraslationArray['DPC1100751'] = 'DPC1100751D';
        $skuTraslationArray['DP140087'] = 'DP140087D';
        $skuTraslationArray['DP140230'] = 'DP140230D';
        $skuTraslationArray['DP1110004'] = 'DP1110004D';
        $skuTraslationArray['DP140146'] = 'DP140146D';
        $skuTraslationArray['DP130318'] = 'DP130318D';
        $skuTraslationArray['DP120249'] = 'DP120249D';
        $skuTraslationArray['DP126012'] = 'DP126012D';
        $skuTraslationArray['DP140414'] = 'DP140414D';
        $skuTraslationArray['DP140476'] = 'DP140476D';
        $skuTraslationArray['DPC1100744'] = 'DPC1100744D';
        $skuTraslationArray['DP140234'] = 'DP140234D';
        $skuTraslationArray['DP140086'] = 'DP140086D';
        $skuTraslationArray['DPJFMODFL'] = 'DPJFMODFLD';
        $skuTraslationArray['DP1100717'] = 'DP1100717D';
        $skuTraslationArray['DPODW022715'] = 'DPODW022715D';
        $skuTraslationArray['DP1200789'] = 'DP1200789D';
        $skuTraslationArray['DP140161'] = 'DP140161D';
        $skuTraslationArray['DP140057'] = 'DP140057D';
        $skuTraslationArray['DP140364'] = 'DP140364D';
        $skuTraslationArray['DP130381'] = 'DP130381D';
        $skuTraslationArray['DP140365'] = 'DP140365D';
        $skuTraslationArray['DPLQ110404'] = 'DPLQ110404D';
        $skuTraslationArray['DP140184'] = 'DP140184D';
        $skuTraslationArray['DPE1100701'] = 'DPE1100701D';
        $skuTraslationArray['DP130210'] = 'DP130210D';
        $skuTraslationArray['DP1108003'] = 'DP1108003D';
        $skuTraslationArray['DP140349'] = 'DP140349D';
        $skuTraslationArray['DP140393'] = 'DP140393D';
        $skuTraslationArray['DP120811'] = 'DP120811D';
        $skuTraslationArray['DP122808'] = 'DP122808D';
        $skuTraslationArray['DP140387'] = 'DP140387D';
        $skuTraslationArray['DP140120'] = 'DP140120D';
        $skuTraslationArray['DP120026'] = 'DP120026D';
        $skuTraslationArray['DP140255'] = 'DP140255D';
        $skuTraslationArray['DPEQ110676'] = 'DPEQ110676D';
        $skuTraslationArray['DP120272'] = 'DP120272D';
        $skuTraslationArray['DP1100728'] = 'DP1100728D';
        $skuTraslationArray['DP140292'] = 'DP140292D';
        $skuTraslationArray['DP140117'] = 'DP140117D';
        $skuTraslationArray['DPEQ110681'] = 'DPEQ110681D';
        $skuTraslationArray['DP124002'] = 'DP124002D';
        $skuTraslationArray['DNYBP'] = 'DNYBPD';
        $skuTraslationArray['DP120245'] = 'DP120245D';
        $skuTraslationArray['DP140585'] = 'DP140585D';
        $skuTraslationArray['DP140109'] = 'DP140109D';
        $skuTraslationArray['DP140286'] = 'DP140286D';
        $skuTraslationArray['DP130463'] = 'DP130463D';
        $skuTraslationArray['DPODW011915'] = 'DPODW011915D';
        $skuTraslationArray['DP130321'] = 'DP130321D';
        $skuTraslationArray['DP130494'] = 'DP130494D';
        $skuTraslationArray['DP130441'] = 'DP130441D';
        $skuTraslationArray['DP140317'] = 'DP140317D';
        $skuTraslationArray['DP124008'] = 'DP124008D';
        $skuTraslationArray['DP130237'] = 'DP130237D';
        $skuTraslationArray['DP140298'] = 'DP140298D';
        $skuTraslationArray['DP130268'] = 'DP130268D';
        $skuTraslationArray['DPEQOASIS'] = 'DPEQOASISD';
        $skuTraslationArray['DP121011'] = 'DP121011D';
        $skuTraslationArray['DP140392'] = 'DP140392D';
        $skuTraslationArray['DP130223'] = 'DP130223D';
        $skuTraslationArray['DP140191'] = 'DP140191D';
        $skuTraslationArray['DP140258'] = 'DP140258D';
        $skuTraslationArray['DP140224'] = 'DP140224D';
        $skuTraslationArray['DP140390'] = 'DP140390D';
        $skuTraslationArray['DP130569'] = 'DP130569D';
        $skuTraslationArray['DP130263'] = 'DP130263D';
        $skuTraslationArray['DP140372'] = 'DP140372D';
        $skuTraslationArray['DP140079'] = 'DP140079D';
        $skuTraslationArray['DP140214'] = 'DP140214D';
        $skuTraslationArray['DP140477'] = 'DP140477D';
        $skuTraslationArray['DP130451'] = 'DP130451D';
        $skuTraslationArray['DP130545'] = 'DP130545D';
        $skuTraslationArray['DP130497'] = 'DP130497D';
        $skuTraslationArray['DP140478'] = 'DP140478D';
        $skuTraslationArray['DP130405'] = 'DP130405D';
        $skuTraslationArray['DP130358'] = 'DP130358D';
        $skuTraslationArray['DPE1100703'] = 'DPE1100703D';
        $skuTraslationArray['DP140192'] = 'DP140192D';
        $skuTraslationArray['DP140160'] = 'DP140160D';
        $skuTraslationArray['DP130412'] = 'DP130412D';
        $skuTraslationArray['DP140231'] = 'DP140231D';
        $skuTraslationArray['DPEQ110672'] = 'DPEQ110672D';
        $skuTraslationArray['DP140342'] = 'DP140342D';
        $skuTraslationArray['DPDP'] = 'DPDPD';
        $skuTraslationArray['DP140451'] = 'DP140451D';
        $skuTraslationArray['DP140060'] = 'DP140060D';
        $skuTraslationArray['DP1202006'] = 'DP1202006D';
        $skuTraslationArray['DPDOLLP'] = 'DPDOLLPD';
        $skuTraslationArray['DPODW042415'] = 'DPODW042415D';
        $skuTraslationArray['DPTQS19'] = 'DPTQS19D';
        $skuTraslationArray['DP140262'] = 'DP140262D';
        $skuTraslationArray['DP130538'] = 'DP130538D';
        $skuTraslationArray['DP140247'] = 'DP140247D';
        $skuTraslationArray['DP140423'] = 'DP140423D';
        $skuTraslationArray['DP140582'] = 'DP140582D';
        $skuTraslationArray['DP140562'] = 'DP140562D';
        $skuTraslationArray['DP130225'] = 'DP130225D';
        $skuTraslationArray['DP120812'] = 'DP120812D';
        $skuTraslationArray['DP140456'] = 'DP140456D';
        $skuTraslationArray['DP140090'] = 'DP140090D';
        $skuTraslationArray['DPBKPAT'] = 'DPBKPATD';
        $skuTraslationArray['DP130496'] = 'DP130496D';
        $skuTraslationArray['DPEEKP'] = 'DPEEKPD';
        $skuTraslationArray['DP140579'] = 'DP140579D';
        $skuTraslationArray['DP130392'] = 'DP130392D';
        $skuTraslationArray['DP121205'] = 'DP121205D';
        $skuTraslationArray['DP140134'] = 'DP140134D';
        $skuTraslationArray['DP140339'] = 'DP140339D';
        $skuTraslationArray['DP140350'] = 'DP140350D';
        $skuTraslationArray['DP140418'] = 'DP140418D';
        $skuTraslationArray['DP130286'] = 'DP130286D';
        $skuTraslationArray['DP120283'] = 'DP120283D';
        $skuTraslationArray['DP130316'] = 'DP130316D';
        $skuTraslationArray['DP130275'] = 'DP130275D';
        $skuTraslationArray['DP130454'] = 'DP130454D';
        $skuTraslationArray['DP1110014'] = 'DP1110014D';
        $skuTraslationArray['DP130243'] = 'DP130243D';
        $skuTraslationArray['DPEBK1413'] = 'DPEBK1413D';
        $skuTraslationArray['DP122810'] = 'DP122810D';
        $skuTraslationArray['DP130371'] = 'DP130371D';
        $skuTraslationArray['DP120242'] = 'DP120242D';
        $skuTraslationArray['DP140212'] = 'DP140212D';
        $skuTraslationArray['DPINAP'] = 'DPINAPD';
        $skuTraslationArray['DP1202009'] = 'DP1202009D';
        $skuTraslationArray['DPINEP'] = 'DPINEPD';
        $skuTraslationArray['DPQ12221'] = 'DPQ12221D';
        $skuTraslationArray['DP130472'] = 'DP130472D';
        $skuTraslationArray['DP130478'] = 'DP130478D';
        $skuTraslationArray['DPLQ110606'] = 'DPLQ110606D';
        $skuTraslationArray['DP126010'] = 'DP126010D';
        $skuTraslationArray['DP140273'] = 'DP140273D';
        $skuTraslationArray['DPEBK1414'] = 'DPEBK1414D';
        $skuTraslationArray['DPWSP'] = 'DPWSPD';
        $skuTraslationArray['DP140153'] = 'DP140153D';
        $skuTraslationArray['DP1112014'] = 'DP1112014D';
        $skuTraslationArray['DP122806'] = 'DP122806D';
        $skuTraslationArray['DP130214'] = 'DP130214D';
        $skuTraslationArray['DP120278'] = 'DP120278D';
        $skuTraslationArray['DP130539'] = 'DP130539D';
        $skuTraslationArray['DPE1100697'] = 'DPE1100697D';
        $skuTraslationArray['DP130278'] = 'DP130278D';
        $skuTraslationArray['DP140366'] = 'DP140366D';
        $skuTraslationArray['DP140419'] = 'DP140419D';
        $skuTraslationArray['DP140454'] = 'DP140454D';
        $skuTraslationArray['DP1100721'] = 'DP1100721D';
        $skuTraslationArray['DP130522'] = 'DP130522D';
        $skuTraslationArray['DP1100738'] = 'DP1100738D';
        $skuTraslationArray['DP130402'] = 'DP130402D';
        $skuTraslationArray['DP140281'] = 'DP140281D';
        $skuTraslationArray['DP120293'] = 'DP120293D';
        $skuTraslationArray['DP120041'] = 'DP120041D';
        $skuTraslationArray['DP130323'] = 'DP130323D';
        $skuTraslationArray['DP140367'] = 'DP140367D';
        $skuTraslationArray['DP140368'] = 'DP140368D';
        $skuTraslationArray['DPE1100702'] = 'DPE1100702D';
        $skuTraslationArray['DP130356'] = 'DP130356D';
        $skuTraslationArray['DP130527'] = 'DP130527D';
        $skuTraslationArray['DP1112017'] = 'DP1112017D';
        $skuTraslationArray['DP130319'] = 'DP130319D';
        $skuTraslationArray['DP140223'] = 'DP140223D';
        $skuTraslationArray['DP140138'] = 'DP140138D';
        $skuTraslationArray['DPEQPRFLWR'] = 'DPEQPRFLWRD';
        $skuTraslationArray['DPSP'] = 'DPSPD';
        $skuTraslationArray['DP140490'] = 'DP140490D';
        $skuTraslationArray['DPC1100749'] = 'DPC1100749D';
        $skuTraslationArray['DP1100719'] = 'DP1100719D';
        $skuTraslationArray['DP1100686'] = 'DP1100686D';
        $skuTraslationArray['DP140479'] = 'DP140479D';
        $skuTraslationArray['DP140537'] = 'DP140537D';
        $skuTraslationArray['DP1110005'] = 'DP1110005D';
        $skuTraslationArray['DP130314'] = 'DP130314D';
        $skuTraslationArray['DP121211'] = 'DP121211D';
        $skuTraslationArray['DP140208'] = 'DP140208D';
        $skuTraslationArray['DP140187'] = 'DP140187D';
        $skuTraslationArray['DP120425'] = 'DP120425D';
        $skuTraslationArray['DP140594'] = 'DP140594D';
        $skuTraslationArray['DP130573'] = 'DP130573D';
        $skuTraslationArray['DP140344'] = 'DP140344D';
        $skuTraslationArray['DP140269'] = 'DP140269D';
        $skuTraslationArray['DPLQ133034'] = 'DPLQ133034D';
        $skuTraslationArray['DPQC11SPRING'] = 'DPQC11SPRINGD';
        $skuTraslationArray['DPQIA2014'] = 'DPQIA2014D';
        $skuTraslationArray['DPQMEBND1'] = 'DPQMEBND1D';
        $skuTraslationArray['DPEBK1415'] = 'DPEBK1415D';
        $skuTraslationArray['DPQY150043'] = 'DPQY150043D';
        $skuTraslationArray['DPLQ130036'] = 'DPLQ130036D';
        $skuTraslationArray['DP1100715'] = 'DP1100715D';
        $skuTraslationArray['DP1108001'] = 'DP1108001D';
        $skuTraslationArray['DP140226'] = 'DP140226D';
        $skuTraslationArray['DP140035'] = 'DP140035D';
        $skuTraslationArray['DPEQRNBWHP'] = 'DPEQRNBWHPD';
        $skuTraslationArray['DP1100740'] = 'DP1100740D';
        $skuTraslationArray['DP140122'] = 'DP140122D';
        $skuTraslationArray['DP140225'] = 'DP140225D';
        $skuTraslationArray['DP140096'] = 'DP140096D';
        $skuTraslationArray['DP140303'] = 'DP140303D';
        $skuTraslationArray['DP140267'] = 'DP140267D';
        $skuTraslationArray['DP140229'] = 'DP140229D';
        $skuTraslationArray['DP140213'] = 'DP140213D';
        $skuTraslationArray['DP140108'] = 'DP140108D';
        $skuTraslationArray['DP140425'] = 'DP140425D';
        $skuTraslationArray['DP140126'] = 'DP140126D';
        $skuTraslationArray['DP130259'] = 'DP130259D';
        $skuTraslationArray['DP120034'] = 'DP120034D';
        $skuTraslationArray['DP140321'] = 'DP140321D';
        $skuTraslationArray['DP130467'] = 'DP130467D';
        $skuTraslationArray['DP130355'] = 'DP130355D';
        $skuTraslationArray['DP140503'] = 'DP140503D';
        $skuTraslationArray['DP140046'] = 'DP140046D';
        $skuTraslationArray['DP140538'] = 'DP140538D';
        $skuTraslationArray['DP140507'] = 'DP140507D';
        $skuTraslationArray['DPLQ110610'] = 'DPLQ110610D';
        $skuTraslationArray['DP140038'] = 'DP140038D';
        $skuTraslationArray['DP130365'] = 'DP130365D';
        $skuTraslationArray['DP140539'] = 'DP140539D';
        $skuTraslationArray['DP140508'] = 'DP140508D';
        $skuTraslationArray['DP140080'] = 'DP140080D';
        $skuTraslationArray['DP121003'] = 'DP121003D';
        $skuTraslationArray['DP140132'] = 'DP140132D';
        $skuTraslationArray['DP1100731'] = 'DP1100731D';
        $skuTraslationArray['DP140216'] = 'DP140216D';
        $skuTraslationArray['DPEQRUBIES'] = 'DPEQRUBIESD';
        $skuTraslationArray['DP140434'] = 'DP140434D';
        $skuTraslationArray['DP140413'] = 'DP140413D';
        $skuTraslationArray['DP130279'] = 'DP130279D';
        $skuTraslationArray['DP130567'] = 'DP130567D';
        $skuTraslationArray['DP140308'] = 'DP140308D';
        $skuTraslationArray['DP130499'] = 'DP130499D';
        $skuTraslationArray['DP140578'] = 'DP140578D';
        $skuTraslationArray['DP140139'] = 'DP140139D';
        $skuTraslationArray['DPSTR01'] = 'DPSTR01D';
        $skuTraslationArray['DPEQ110685'] = 'DPEQ110685D';
        $skuTraslationArray['DP140115'] = 'DP140115D';
        $skuTraslationArray['DP130311'] = 'DP130311D';
        $skuTraslationArray['DPQ12222'] = 'DPQ12222D';
        $skuTraslationArray['DP140128'] = 'DP140128D';
        $skuTraslationArray['DP130281'] = 'DP130281D';
        $skuTraslationArray['DP1100744'] = 'DP1100744D';
        $skuTraslationArray['DPLQ110613'] = 'DPLQ110613D';
        $skuTraslationArray['DP140584'] = 'DP140584D';
        $skuTraslationArray['DP140399'] = 'DP140399D';
        $skuTraslationArray['DPEBK1416'] = 'DPEBK1416D';
        $skuTraslationArray['DP120275'] = 'DP120275D';
        $skuTraslationArray['DP140409'] = 'DP140409D';
        $skuTraslationArray['DP140112'] = 'DP140112D';
        $skuTraslationArray['DP130264'] = 'DP130264D';
        $skuTraslationArray['DP130540'] = 'DP130540D';
        $skuTraslationArray['DP130475'] = 'DP130475D';
        $skuTraslationArray['DP1100746'] = 'DP1100746D';
        $skuTraslationArray['DP140397'] = 'DP140397D';
        $skuTraslationArray['DP1112006'] = 'DP1112006D';
        $skuTraslationArray['DP130462'] = 'DP130462D';
        $skuTraslationArray['DP140391'] = 'DP140391D';
        $skuTraslationArray['DPEQ110674'] = 'DPEQ110674D';
        $skuTraslationArray['DP1100688'] = 'DP1100688D';
        $skuTraslationArray['DP130373'] = 'DP130373D';
        $skuTraslationArray['DP130439'] = 'DP130439D';
        $skuTraslationArray['DP140593'] = 'DP140593D';
        $skuTraslationArray['DP1100689'] = 'DP1100689D';
        $skuTraslationArray['DPEBK1417'] = 'DPEBK1417D';
        $skuTraslationArray['DP140480'] = 'DP140480D';
        $skuTraslationArray['DP130393'] = 'DP130393D';
        $skuTraslationArray['DPJFSTSAIL'] = 'DPJFSTSAILD';
        $skuTraslationArray['DPTQS36'] = 'DPTQS36D';
        $skuTraslationArray['DP130394'] = 'DP130394D';
        $skuTraslationArray['DP140039'] = 'DP140039D';
        $skuTraslationArray['DPSHDWPLAY'] = 'DPSHDWPLAYD';
        $skuTraslationArray['DP140492'] = 'DP140492D';
        $skuTraslationArray['DP120423'] = 'DP120423D';
        $skuTraslationArray['DSPP'] = 'DSPPD';
        $skuTraslationArray['DP140081'] = 'DP140081D';
        $skuTraslationArray['DP140370'] = 'DP140370D';
        $skuTraslationArray['DP140489'] = 'DP140489D';
        $skuTraslationArray['DP140263'] = 'DP140263D';
        $skuTraslationArray['DP121004'] = 'DP121004D';
        $skuTraslationArray['DP140148'] = 'DP140148D';
        $skuTraslationArray['DP140175'] = 'DP140175D';
        $skuTraslationArray['DP130534'] = 'DP130534D';
        $skuTraslationArray['DP122804'] = 'DP122804D';
        $skuTraslationArray['DP140215'] = 'DP140215D';
        $skuTraslationArray['DP130477'] = 'DP130477D';
        $skuTraslationArray['DP120417'] = 'DP120417D';
        $skuTraslationArray['DP120247'] = 'DP120247D';
        $skuTraslationArray['DP140371'] = 'DP140371D';
        $skuTraslationArray['DP140415'] = 'DP140415D';
        $skuTraslationArray['DP140265'] = 'DP140265D';
        $skuTraslationArray['DP1100683'] = 'DP1100683D';
        $skuTraslationArray['DP130228'] = 'DP130228D';
        $skuTraslationArray['DP130235'] = 'DP130235D';
        $skuTraslationArray['DP130442'] = 'DP130442D';
        $skuTraslationArray['DP140563'] = 'DP140563D';
        $skuTraslationArray['DPSTR05'] = 'DPSTR05D';
        $skuTraslationArray['DP120037'] = 'DP120037D';
        $skuTraslationArray['DP130549'] = 'DP130549D';
        $skuTraslationArray['DP122803'] = 'DP122803D';
        $skuTraslationArray['DP1202002'] = 'DP1202002D';
        $skuTraslationArray['DP140351'] = 'DP140351D';
        $skuTraslationArray['DP1112012'] = 'DP1112012D';
        $skuTraslationArray['DP140569'] = 'DP140569D';
        $skuTraslationArray['DP140092'] = 'DP140092D';
        $skuTraslationArray['DP140040'] = 'DP140040D';
        $skuTraslationArray['DPLQ110406'] = 'DPLQ110406D';
        $skuTraslationArray['DPBQFAL0901'] = 'DPBQFAL0901D';
        $skuTraslationArray['DP120805'] = 'DP120805D';
        $skuTraslationArray['DP130218'] = 'DP130218D';
        $skuTraslationArray['DPEQSPICE'] = 'DPEQSPICED';
        $skuTraslationArray['DP120027'] = 'DP120027D';
        $skuTraslationArray['DP140283'] = 'DP140283D';
        $skuTraslationArray['DP140499'] = 'DP140499D';
        $skuTraslationArray['DP140429'] = 'DP140429D';
        $skuTraslationArray['DP1100739'] = 'DP1100739D';
        $skuTraslationArray['DP140528'] = 'DP140528D';
        $skuTraslationArray['DP140168'] = 'DP140168D';
        $skuTraslationArray['DPLQ110402'] = 'DPLQ110402D';
        $skuTraslationArray['DP130265'] = 'DP130265D';
        $skuTraslationArray['DP124005'] = 'DP124005D';
        $skuTraslationArray['DPLQ110413'] = 'DPLQ110413D';
        $skuTraslationArray['DP126001'] = 'DP126001D';
        $skuTraslationArray['DP140250'] = 'DP140250D';
        $skuTraslationArray['DP140369'] = 'DP140369D';
        $skuTraslationArray['DP140394'] = 'DP140394D';
        $skuTraslationArray['DP130521'] = 'DP130521D';
        $skuTraslationArray['DP130227'] = 'DP130227D';
        $skuTraslationArray['DP120036'] = 'DP120036D';
        $skuTraslationArray['DP140059'] = 'DP140059D';
        $skuTraslationArray['DP130461'] = 'DP130461D';
        $skuTraslationArray['DP140190'] = 'DP140190D';
        $skuTraslationArray['DP130566'] = 'DP130566D';
        $skuTraslationArray['DP140061'] = 'DP140061D';
        $skuTraslationArray['DP140137'] = 'DP140137D';
        $skuTraslationArray['DPBKSTR'] = 'DPBKSTRD';
        $skuTraslationArray['DP121206'] = 'DP121206D';
        $skuTraslationArray['DP140149'] = 'DP140149D';
        $skuTraslationArray['DP1112013'] = 'DP1112013D';
        $skuTraslationArray['DP130350'] = 'DP130350D';
        $skuTraslationArray['DP120810'] = 'DP120810D';
        $skuTraslationArray['DP140242'] = 'DP140242D';
        $skuTraslationArray['DP121006'] = 'DP121006D';
        $skuTraslationArray['DPAT02'] = 'DPAT02D';
        $skuTraslationArray['DP140252'] = 'DP140252D';
        $skuTraslationArray['DP140402'] = 'DP140402D';
        $skuTraslationArray['DP140564'] = 'DP140564D';
        $skuTraslationArray['DP140352'] = 'DP140352D';
        $skuTraslationArray['DP1100725'] = 'DP1100725D';
        $skuTraslationArray['DP1108007'] = 'DP1108007D';
        $skuTraslationArray['DP126011'] = 'DP126011D';
        $skuTraslationArray['DP140417'] = 'DP140417D';
        $skuTraslationArray['DP140313'] = 'DP140313D';
        $skuTraslationArray['DP1404033'] = 'DP1404033D';
        $skuTraslationArray['DP140049'] = 'DP140049D';
        $skuTraslationArray['DP140567'] = 'DP140567D';
        $skuTraslationArray['DP130284'] = 'DP130284D';
        $skuTraslationArray['DP140566'] = 'DP140566D';
        $skuTraslationArray['DP1100732'] = 'DP1100732D';
        $skuTraslationArray['DP130531'] = 'DP130531D';
        $skuTraslationArray['DP130327'] = 'DP130327D';
        $skuTraslationArray['DP140411'] = 'DP140411D';
        $skuTraslationArray['DP130457'] = 'DP130457D';
        $skuTraslationArray['DP120285'] = 'DP120285D';
        $skuTraslationArray['DP1200792'] = 'DP1200792D';
        $skuTraslationArray['DP140094'] = 'DP140094D';
        $skuTraslationArray['DP130352'] = 'DP130352D';
        $skuTraslationArray['DP130215'] = 'DP130215D';
        $skuTraslationArray['DP130536'] = 'DP130536D';
        $skuTraslationArray['DP130280'] = 'DP130280D';
        $skuTraslationArray['DP140457'] = 'DP140457D';
        $skuTraslationArray['DP130313'] = 'DP130313D';
        $skuTraslationArray['DFLIPP'] = 'DFLIPPD';
        $skuTraslationArray['DPQKSUM0902'] = 'DPQKSUM0902D';
        $skuTraslationArray['DP130240'] = 'DP130240D';
        $skuTraslationArray['DP130287'] = 'DP130287D';
        $skuTraslationArray['DP121001'] = 'DP121001D';
        $skuTraslationArray['DPEQ110679'] = 'DPEQ110679D';
        $skuTraslationArray['DPEQ110675'] = 'DPEQ110675D';
        $skuTraslationArray['DP140169'] = 'DP140169D';
        $skuTraslationArray['DP140353'] = 'DP140353D';
        $skuTraslationArray['DP130233'] = 'DP130233D';
        $skuTraslationArray['DP121207'] = 'DP121207D';
        $skuTraslationArray['DP140123'] = 'DP140123D';
        $skuTraslationArray['DP140557'] = 'DP140557D';
        $skuTraslationArray['DP140533'] = 'DP140533D';
        $skuTraslationArray['DP140158'] = 'DP140158D';
        $skuTraslationArray['DP130526'] = 'DP130526D';
        $skuTraslationArray['DPE1100710'] = 'DPE1100710D';
        $skuTraslationArray['DP1100716'] = 'DP1100716D';
        $skuTraslationArray['DPEQSWTHRT'] = 'DPEQSWTHRTD';
        $skuTraslationArray['DP140181'] = 'DP140181D';
        $skuTraslationArray['DP120421'] = 'DP120421D';
        $skuTraslationArray['DPLQ110605'] = 'DPLQ110605D';
        $skuTraslationArray['DP130274'] = 'DP130274D';
        $skuTraslationArray['DPEQ110686'] = 'DPEQ110686D';
        $skuTraslationArray['DP140481'] = 'DP140481D';
        $skuTraslationArray['DPEQMISSN'] = 'DPEQMISSND';
        $skuTraslationArray['DPEBK1418'] = 'DPEBK1418D';
        $skuTraslationArray['DMATP'] = 'DMATPD';
        $skuTraslationArray['DP120273'] = 'DP120273D';
        $skuTraslationArray['DP130272'] = 'DP130272D';
        $skuTraslationArray['DP130525'] = 'DP130525D';
        $skuTraslationArray['DP140400'] = 'DP140400D';
        $skuTraslationArray['DP1100745'] = 'DP1100745D';
        $skuTraslationArray['DP140084'] = 'DP140084D';
        $skuTraslationArray['DP130213'] = 'DP130213D';
        $skuTraslationArray['DP140410'] = 'DP140410D';
        $skuTraslationArray['DTEXASP'] = 'DTEXASPD';
        $skuTraslationArray['DPJFTXSTAR'] = 'DPJFTXSTARD';
        $skuTraslationArray['DP120279'] = 'DP120279D';
        $skuTraslationArray['DP140540'] = 'DP140540D';
        $skuTraslationArray['DP130572'] = 'DP130572D';
        $skuTraslationArray['DP140176'] = 'DP140176D';
        $skuTraslationArray['DP130377'] = 'DP130377D';
        $skuTraslationArray['DP140150'] = 'DP140150D';
        $skuTraslationArray['DP126005'] = 'DP126005D';
        $skuTraslationArray['DPLA5298'] = 'DPLA5298D';
        $skuTraslationArray['DPLA5601'] = 'DPLA5601D';
        $skuTraslationArray['DPLA5297'] = 'DPLA5297D';
        $skuTraslationArray['DPLA5620'] = 'DPLA5620D';
        $skuTraslationArray['DPLA5296'] = 'DPLA5296D';
        $skuTraslationArray['DP120806'] = 'DP120806D';
        $skuTraslationArray['DP140141'] = 'DP140141D';
        $skuTraslationArray['DPE1100693'] = 'DPE1100693D';
        $skuTraslationArray['DP140360'] = 'DP140360D';
        $skuTraslationArray['DP130501'] = 'DP130501D';
        $skuTraslationArray['DP130524'] = 'DP130524D';
        $skuTraslationArray['DP140167'] = 'DP140167D';
        $skuTraslationArray['DCATP'] = 'DCATPD';
        $skuTraslationArray['DP140256'] = 'DP140256D';
        $skuTraslationArray['DP130459'] = 'DP130459D';
        $skuTraslationArray['DP124011'] = 'DP124011D';
        $skuTraslationArray['DPJFLKGLSS'] = 'DPJFLKGLSSD';
        $skuTraslationArray['DPLQ110601'] = 'DPLQ110601D';
        $skuTraslationArray['DP121203'] = 'DP121203D';
        $skuTraslationArray['DP140482'] = 'DP140482D';
        $skuTraslationArray['DP130388'] = 'DP130388D';
        $skuTraslationArray['DP1110010'] = 'DP1110010D';
        $skuTraslationArray['DP120287'] = 'DP120287D';
        $skuTraslationArray['DP140498'] = 'DP140498D';
        $skuTraslationArray['DP140102'] = 'DP140102D';
        $skuTraslationArray['DP130359'] = 'DP130359D';
        $skuTraslationArray['DP140045'] = 'DP140045D';
        $skuTraslationArray['DP1100741'] = 'DP1100741D';
        $skuTraslationArray['DP1112004'] = 'DP1112004D';
        $skuTraslationArray['DP130238'] = 'DP130238D';
        $skuTraslationArray['DP140186'] = 'DP140186D';
        $skuTraslationArray['DP140151'] = 'DP140151D';
        $skuTraslationArray['DP140483'] = 'DP140483D';
        $skuTraslationArray['DP140581'] = 'DP140581D';
        $skuTraslationArray['DP140354'] = 'DP140354D';
        $skuTraslationArray['DP120276'] = 'DP120276D';
        $skuTraslationArray['DP122807'] = 'DP122807D';
        $skuTraslationArray['DPBQFAL0902'] = 'DPBQFAL0902D';
        $skuTraslationArray['DP140253'] = 'DP140253D';
        $skuTraslationArray['DP121210'] = 'DP121210D';
        $skuTraslationArray['DP1100711'] = 'DP1100711D';
        $skuTraslationArray['DP126002'] = 'DP126002D';
        $skuTraslationArray['DP130533'] = 'DP130533D';
        $skuTraslationArray['DP140243'] = 'DP140243D';
        $skuTraslationArray['DP124012'] = 'DP124012D';
        $skuTraslationArray['DP140484'] = 'DP140484D';
        $skuTraslationArray['DPEBK1419'] = 'DPEBK1419D';
        $skuTraslationArray['DPJFTRISPR'] = 'DPJFTRISPRD';
        $skuTraslationArray['DP140143'] = 'DP140143D';
        $skuTraslationArray['DP120244'] = 'DP120244D';
        $skuTraslationArray['DP140523'] = 'DP140523D';
        $skuTraslationArray['DP140227'] = 'DP140227D';
        $skuTraslationArray['DP140155'] = 'DP140155D';
        $skuTraslationArray['DP121008'] = 'DP121008D';
        $skuTraslationArray['DP140461'] = 'DP140461D';
        $skuTraslationArray['DPQ12226'] = 'DPQ12226D';
        $skuTraslationArray['DPLQ110403'] = 'DPLQ110403D';
        $skuTraslationArray['DP1100687'] = 'DP1100687D';
        $skuTraslationArray['DP140027'] = 'DP140027D';
        $skuTraslationArray['DP140295'] = 'DP140295D';
        $skuTraslationArray['DPEQ110678'] = 'DPEQ110678D';
        $skuTraslationArray['DPEQ110671'] = 'DPEQ110671D';
        $skuTraslationArray['DP130322'] = 'DP130322D';
        $skuTraslationArray['DPEQ110680'] = 'DPEQ110680D';
        $skuTraslationArray['DP140288'] = 'DP140288D';
        $skuTraslationArray['DP140496'] = 'DP140496D';
        $skuTraslationArray['DP140485'] = 'DP140485D';
        $skuTraslationArray['DP140395'] = 'DP140395D';
        $skuTraslationArray['DP140355'] = 'DP140355D';
        $skuTraslationArray['DPEBK1420'] = 'DPEBK1420D';
        $skuTraslationArray['DP120420'] = 'DP120420D';
        $skuTraslationArray['DP120808'] = 'DP120808D';
        $skuTraslationArray['DP140023'] = 'DP140023D';
        $skuTraslationArray['DP140239'] = 'DP140239D';
        $skuTraslationArray['DPAT04'] = 'DPAT04D';
        $skuTraslationArray['DP1110011'] = 'DP1110011D';
        $skuTraslationArray['DP130362'] = 'DP130362D';
        $skuTraslationArray['DPAT05'] = 'DPAT05D';
        $skuTraslationArray['DP140386'] = 'DP140386D';
        $skuTraslationArray['DP140261'] = 'DP140261D';
        $skuTraslationArray['DP140182'] = 'DP140182D';
        $skuTraslationArray['DPEQWALLFR'] = 'DPEQWALLFRD';
        $skuTraslationArray['DWBCP'] = 'DWBCPD';
        $skuTraslationArray['DP130320'] = 'DP130320D';
        $skuTraslationArray['DP1100727'] = 'DP1100727D';
        $skuTraslationArray['DP120803'] = 'DP120803D';
        $skuTraslationArray['DP130543'] = 'DP130543D';
        $skuTraslationArray['DP122800'] = 'DP122800D';
        $skuTraslationArray['DP130353'] = 'DP130353D';
        $skuTraslationArray['DPJFWEAVNG'] = 'DPJFWEAVNGD';
        $skuTraslationArray['DWEDP'] = 'DWEDPD';
        $skuTraslationArray['DP130532'] = 'DP130532D';
        $skuTraslationArray['DP140524'] = 'DP140524D';
        $skuTraslationArray['DPODW050614'] = 'DPODW050614D';
        $skuTraslationArray['DP130456'] = 'DP130456D';
        $skuTraslationArray['DP140568'] = 'DP140568D';
        $skuTraslationArray['DPE1100696'] = 'DPE1100696D';
        $skuTraslationArray['DP130205'] = 'DP130205D';
        $skuTraslationArray['DP120813'] = 'DP120813D';
        $skuTraslationArray['DP140152'] = 'DP140152D';
        $skuTraslationArray['DP130476'] = 'DP130476D';
        $skuTraslationArray['DPEQ110688'] = 'DPEQ110688D';
        $skuTraslationArray['DP140244'] = 'DP140244D';
        $skuTraslationArray['DP124007'] = 'DP124007D';
        $skuTraslationArray['DP140171'] = 'DP140171D';
        $skuTraslationArray['DP140487'] = 'DP140487D';
        $skuTraslationArray['DPE1100707'] = 'DPE1100707D';
        $skuTraslationArray['DPLQ110401'] = 'DPLQ110401D';
        $skuTraslationArray['DP130471'] = 'DP130471D';
        $skuTraslationArray['DP130429'] = 'DP130429D';
        $skuTraslationArray['DPEQWNDFL'] = 'DPEQWNDFLD';
        $skuTraslationArray['DP130466'] = 'DP130466D';
        $skuTraslationArray['DP1303025'] = 'DP1303025D';
        $skuTraslationArray['DP140534'] = 'DP140534D';
        $skuTraslationArray['DP140105'] = 'DP140105D';
        $skuTraslationArray['DP130202'] = 'DP130202D';
        $skuTraslationArray['DPEQWNTMGR'] = 'DPEQWNTMGRD';
        $skuTraslationArray['DPEQWNTGRD'] = 'DPEQWNTGRDD';
        $skuTraslationArray['DP140289'] = 'DP140289D';
        $skuTraslationArray['DPSTR02'] = 'DPSTR02D';
        $skuTraslationArray['DWINTERP'] = 'DWINTERPD';
        $skuTraslationArray['DP1112001'] = 'DP1112001D';
        $skuTraslationArray['DP130435'] = 'DP130435D';
        $skuTraslationArray['DP140455'] = 'DP140455D';
        $skuTraslationArray['DP140236'] = 'DP140236D';
        $skuTraslationArray['DP130551'] = 'DP130551D';
        $skuTraslationArray['DP122801'] = 'DP122801D';
        $skuTraslationArray['DP130288'] = 'DP130288D';
        $skuTraslationArray['DP140405'] = 'DP140405D';
        $skuTraslationArray['DP130453'] = 'DP130453D';
        $skuTraslationArray['DP140107'] = 'DP140107D';
        $skuTraslationArray['DP1202001'] = 'DP1202001D';
        $skuTraslationArray['DP140287'] = 'DP140287D';
        $skuTraslationArray['DP120040'] = 'DP120040D';
        $skuTraslationArray['DP126008'] = 'DP126008D';
        $skuTraslationArray['DP140504'] = 'DP140504D';
        $skuTraslationArray['DP140291'] = 'DP140291D';
        $skuTraslationArray['DP130520'] = 'DP130520D';
        $skuTraslationArray['DP130367'] = 'DP130367D';
        $skuTraslationArray['DP130470'] = 'DP130470D';
        $skuTraslationArray['DP130239'] = 'DP130239D';
        $skuTraslationArray['DPE1100698'] = 'DPE1100698D';
        $skuTraslationArray['DP140019'] = 'DP140019D';
        $skuTraslationArray['DP130277'] = 'DP130277D';
        $skuTraslationArray['DP130517'] = 'DP130517D';
        $skuTraslationArray['DP140130'] = 'DP140130D';
        $skuTraslationArray['DP122805'] = 'DP122805D';
        $skuTraslationArray['DP120277'] = 'DP120277D';
        $skuTraslationArray['DP140424'] = 'DP140424D';
        $skuTraslationArray['DP130285'] = 'DP130285D';
        $skuTraslationArray['DP140188'] = 'DP140188D';
        $skuTraslationArray['DP140101'] = 'DP140101D';
        $skuTraslationArray['DP122812'] = 'DP122812D';
        $skuTraslationArray['DPE1100692'] = 'DPE1100692D';
        $skuTraslationArray['DP130426'] = 'DP130426D';
        $skuTraslationArray['DP140445'] = 'DP140445D';
        $skuTraslationArray['DP130256'] = 'DP130256D';
        $skuTraslationArray['DP140327'] = 'DP140327D';
        $skuTraslationArray['DP130423'] = 'DP130423D';
        $skuTraslationArray['DP130252'] = 'DP130252D';
        $skuTraslationArray['DP130424'] = 'DP130424D';
        $skuTraslationArray['DP140011'] = 'DP140011D';
        $skuTraslationArray['DP140003'] = 'DP140003D';
        $skuTraslationArray['DP130292'] = 'DP130292D';
        $skuTraslationArray['DP140334'] = 'DP140334D';
        $skuTraslationArray['DP140509'] = 'DP140509D';
        $skuTraslationArray['DP130257'] = 'DP130257D';
        $skuTraslationArray['DP130508'] = 'DP130508D';
        $skuTraslationArray['DP130484'] = 'DP130484D';
        $skuTraslationArray['DP140324'] = 'DP140324D';
        $skuTraslationArray['DP130295'] = 'DP130295D';
        $skuTraslationArray['DP140385'] = 'DP140385D';
        $skuTraslationArray['DP130339'] = 'DP130339D';
        $skuTraslationArray['DP140510'] = 'DP140510D';
        $skuTraslationArray['DP130488'] = 'DP130488D';
        $skuTraslationArray['DP130505'] = 'DP130505D';
        $skuTraslationArray['DP130480'] = 'DP130480D';
        $skuTraslationArray['DP140511'] = 'DP140511D';
        $skuTraslationArray['DP140072'] = 'DP140072D';
        $skuTraslationArray['DP140329'] = 'DP140329D';
        $skuTraslationArray['DP130245'] = 'DP130245D';
        $skuTraslationArray['DP130300'] = 'DP130300D';
        $skuTraslationArray['DP130502'] = 'DP130502D';
        $skuTraslationArray['DP130331'] = 'DP130331D';
        $skuTraslationArray['DP130298'] = 'DP130298D';
        $skuTraslationArray['DP140512'] = 'DP140512D';
        $skuTraslationArray['DP130513'] = 'DP130513D';
        $skuTraslationArray['DP140002'] = 'DP140002D';
        $skuTraslationArray['DP140204'] = 'DP140204D';
        $skuTraslationArray['DP140330'] = 'DP140330D';
        $skuTraslationArray['DP140381'] = 'DP140381D';
        $skuTraslationArray['DP140012'] = 'DP140012D';
        $skuTraslationArray['DP130293'] = 'DP130293D';
        $skuTraslationArray['DP140202'] = 'DP140202D';
        $skuTraslationArray['DP130250'] = 'DP130250D';
        $skuTraslationArray['DP130510'] = 'DP130510D';
        $skuTraslationArray['DP130334'] = 'DP130334D';
        $skuTraslationArray['DP140375'] = 'DP140375D';
        $skuTraslationArray['DP140201'] = 'DP140201D';
        $skuTraslationArray['DP140199'] = 'DP140199D';
        $skuTraslationArray['DP130417'] = 'DP130417D';
        $skuTraslationArray['DP140437'] = 'DP140437D';
        $skuTraslationArray['DP130416'] = 'DP130416D';
        $skuTraslationArray['DP130297'] = 'DP130297D';
        $skuTraslationArray['DP140075'] = 'DP140075D';
        $skuTraslationArray['DP130418'] = 'DP130418D';
        $skuTraslationArray['DP140195'] = 'DP140195D';
        $skuTraslationArray['DP140376'] = 'DP140376D';
        $skuTraslationArray['DP140066'] = 'DP140066D';
        $skuTraslationArray['DP140513'] = 'DP140513D';
        $skuTraslationArray['DP130506'] = 'DP130506D';
        $skuTraslationArray['DP130507'] = 'DP130507D';
        $skuTraslationArray['DP140065'] = 'DP140065D';
        $skuTraslationArray['DP140514'] = 'DP140514D';
        $skuTraslationArray['DP130428'] = 'DP130428D';
        $skuTraslationArray['DP130247'] = 'DP130247D';
        $skuTraslationArray['DP130246'] = 'DP130246D';
        $skuTraslationArray['DP130512'] = 'DP130512D';
        $skuTraslationArray['DP140442'] = 'DP140442D';
        $skuTraslationArray['DP140515'] = 'DP140515D';
        $skuTraslationArray['DP140007'] = 'DP140007D';
        $skuTraslationArray['DP130299'] = 'DP130299D';
        $skuTraslationArray['DP130511'] = 'DP130511D';
        $skuTraslationArray['DP140005'] = 'DP140005D';
        $skuTraslationArray['DP140374'] = 'DP140374D';
        $skuTraslationArray['DP130301'] = 'DP130301D';
        $skuTraslationArray['DP140516'] = 'DP140516D';
        $skuTraslationArray['DP130291'] = 'DP130291D';
        $skuTraslationArray['DP130427'] = 'DP130427D';
        $skuTraslationArray['DP140328'] = 'DP140328D';
        $skuTraslationArray['DP140378'] = 'DP140378D';
        $skuTraslationArray['DP130289'] = 'DP130289D';
        $skuTraslationArray['DP130420'] = 'DP130420D';
        $skuTraslationArray['DP140377'] = 'DP140377D';
        $skuTraslationArray['DP130414'] = 'DP130414D';
        $skuTraslationArray['DP140382'] = 'DP140382D';
        $skuTraslationArray['DP140323'] = 'DP140323D';
        $skuTraslationArray['DP140447'] = 'DP140447D';
        $skuTraslationArray['DP130249'] = 'DP130249D';
        $skuTraslationArray['DP140071'] = 'DP140071D';
        $skuTraslationArray['DP140073'] = 'DP140073D';
        $skuTraslationArray['DP130330'] = 'DP130330D';
        $skuTraslationArray['DP140194'] = 'DP140194D';
        $skuTraslationArray['DP140331'] = 'DP140331D';
        $skuTraslationArray['DP140196'] = 'DP140196D';
        $skuTraslationArray['DP140069'] = 'DP140069D';
        $skuTraslationArray['DP140006'] = 'DP140006D';
        $skuTraslationArray['DP130485'] = 'DP130485D';
        $skuTraslationArray['DP130337'] = 'DP130337D';
        $skuTraslationArray['DP130479'] = 'DP130479D';
        $skuTraslationArray['DP140379'] = 'DP140379D';
        $skuTraslationArray['DP130422'] = 'DP130422D';
        $skuTraslationArray['DP140197'] = 'DP140197D';
        $skuTraslationArray['DP130332'] = 'DP130332D';
        $skuTraslationArray['DP130335'] = 'DP130335D';
        $skuTraslationArray['DP130481'] = 'DP130481D';
        $skuTraslationArray['DP130296'] = 'DP130296D';
        $skuTraslationArray['DP130415'] = 'DP130415D';
        $skuTraslationArray['DP140444'] = 'DP140444D';
        $skuTraslationArray['DP130340'] = 'DP130340D';
        $skuTraslationArray['DP140438'] = 'DP140438D';
        $skuTraslationArray['DP130419'] = 'DP130419D';
        $skuTraslationArray['DP130342'] = 'DP130342D';
        $skuTraslationArray['DP130255'] = 'DP130255D';
        $skuTraslationArray['DP140517'] = 'DP140517D';
        $skuTraslationArray['DP130483'] = 'DP130483D';
        $skuTraslationArray['DP130329'] = 'DP130329D';
        $skuTraslationArray['DP130244'] = 'DP130244D';
        $skuTraslationArray['DP130254'] = 'DP130254D';
        $skuTraslationArray['DP140009'] = 'DP140009D';
        $skuTraslationArray['DP140446'] = 'DP140446D';
        $skuTraslationArray['DP130503'] = 'DP130503D';
        $skuTraslationArray['DP140443'] = 'DP140443D';
        $skuTraslationArray['DP140518'] = 'DP140518D';
        $skuTraslationArray['DP140333'] = 'DP140333D';
        $skuTraslationArray['DP140010'] = 'DP140010D';
        $skuTraslationArray['DP140070'] = 'DP140070D';
        $skuTraslationArray['DP130253'] = 'DP130253D';
        $skuTraslationArray['DP140205'] = 'DP140205D';
        $skuTraslationArray['DP140439'] = 'DP140439D';
        $skuTraslationArray['DP130333'] = 'DP130333D';
        $skuTraslationArray['DP140001'] = 'DP140001D';
        $skuTraslationArray['DP140383'] = 'DP140383D';
        $skuTraslationArray['DP130509'] = 'DP130509D';
        $skuTraslationArray['DP140325'] = 'DP140325D';
        $skuTraslationArray['DP140519'] = 'DP140519D';
        $skuTraslationArray['DP130482'] = 'DP130482D';
        $skuTraslationArray['DP140200'] = 'DP140200D';
        $skuTraslationArray['DP140198'] = 'DP140198D';
        $skuTraslationArray['DP130504'] = 'DP130504D';
        $skuTraslationArray['DP130338'] = 'DP130338D';
        $skuTraslationArray['DP140384'] = 'DP140384D';
        $skuTraslationArray['DP140441'] = 'DP140441D';
        $skuTraslationArray['DP130486'] = 'DP130486D';
        $skuTraslationArray['DP130251'] = 'DP130251D';
        $skuTraslationArray['DP130336'] = 'DP130336D';
        $skuTraslationArray['DP130491'] = 'DP130491D';
        $skuTraslationArray['DP130425'] = 'DP130425D';
        $skuTraslationArray['DP130248'] = 'DP130248D';
        $skuTraslationArray['DP140013'] = 'DP140013D';
        $skuTraslationArray['DP130341'] = 'DP130341D';
        $skuTraslationArray['DP130294'] = 'DP130294D';
        $skuTraslationArray['DP140203'] = 'DP140203D';
        $skuTraslationArray['DP140074'] = 'DP140074D';
        $skuTraslationArray['DP130489'] = 'DP130489D';
        $skuTraslationArray['DP140064'] = 'DP140064D';
        $skuTraslationArray['DP140004'] = 'DP140004D';
        $skuTraslationArray['DP140068'] = 'DP140068D';
        $skuTraslationArray['DP140448'] = 'DP140448D';
        $skuTraslationArray['DP130490'] = 'DP130490D';
        $skuTraslationArray['DP140326'] = 'DP140326D';
        $skuTraslationArray['DP140076'] = 'DP140076D';
        $skuTraslationArray['DP130487'] = 'DP130487D';
        $skuTraslationArray['DP140067'] = 'DP140067D';
        $skuTraslationArray['DP130514'] = 'DP130514D';
        $skuTraslationArray['DP140440'] = 'DP140440D';
        $skuTraslationArray['DP140008'] = 'DP140008D';
        $skuTraslationArray['DP140332'] = 'DP140332D';
        $skuTraslationArray['DP130290'] = 'DP130290D';


        
        if($skuTraslationArray[$sku]){
           $returnSku = $skuTraslationArray[$sku]; 
        }
        else{
            $returnSku = $sku;
        }

        return $returnSku;
    }
    
    
    ?>
