<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';

    Mage::app();
   
    //Command Line Args

     $fileName = "CategoryExport.csv";
    
    $storeViewName = "InterweaveStore.com";
    
    //Global Vars
    $count=0;
    $logFile = 'IW_Category_Import.log';
    //Mage::app('admin');  
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    Mage::app('default');

    //
    //IW MAP
    //data[1] = Name
    //data[4] = HideCategory
    //data[7] = PageTitle
    //data[8] = Meta Keywords
    //data[9] = Meta Description
    //data[10] = Hierarchy
    //data[12] = Descripion
    //data[17] = url
    //data[20] = 'community' category
    //knit = Knit
    //crochet = Crochet
    //jewelry = Jewelry
    //quilt = Quilt
    //sew = Sew
    //spin = Spin
    //weave = Weave
    //mixedmedia = Mixed Media
    //needle = Needle ?????????????????????????
    	
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
                $catName = trim($data[10]);
                $catExists = false;
				
                if(!$catExists)
                {
                    $catagoryHierarchy = explode(">", $catName);
                    $topLevelRoot = trim($catagoryHierarchy[0]);
                    $greaterThanPos = strpos($catName, ">");
                    $category = new Mage_Catalog_Model_Category();
                    $category->setStoreId(0);
                    $category->setIsAnchor(0);
                    $category->setDescription($data[12]);
                    $category->setMetaTitle($data[7]);
                    $category->setMetaDescription($data[9]);
                    $category->setMetaKeywords($data[8]);
					
                    if($data[4] == "TRUE")
                    {
                         $category->setIsActive(0);
                    }
                    else
                    {
                         $category->setIsActive(1);
                    }
                    
                    //URL KEY
                    $urlKey = $data[17];

                    //string first slash
                    $urlKey = substr($urlKey, 1);

                    //replace slashes with dashes
                    $urlKey = str_replace("/", "-", $urlKey);

                    //remove .html
                    $urlKey = strtolower(str_replace(".html", "", $urlKey));
                    
                    if($urlKey != "")
                    {
                        $category->setUrlKey($urlKey);  
                    }

                    if($greaterThanPos == FALSE) //1st level
                    {
                        if(validTopLevel($topLevelRoot))
                        {
                            $category->setName($catName);
                            $category->setPath($parentCategory->getPath());
                            $category->setCustomDesign('iw/'.strtolower(str_replace(" ", "", $topLevelRoot)));
                            try 
                            {
                                $category->save();
                                echo $category->getName()."\n";
                                Mage::log($storeViewName."::Created Category ".$data[0],null, $logFile);
                            } 
                            catch (Exception $e) 
                            {
                                Mage::log($storeViewName."::Category Creation Error: ".$e->getMessage(),null, $logFile);
                            }

                            $parentCategorys[0] = $category;
                        }
                       
                    }
                    else 
                    {
                        if(validTopLevel($topLevelRoot))
                        {
                            while ($greaterThanPos != "")
                            {
                                $level++;
                                $catName = trim(substr($catName, $greaterThanPos + 1));
                                $greaterThanPos = strpos($catName, ">");

                                if($greaterThanPos == "") 
                                {
                                    $category->setName($catName);
                                    $category->setPath($parentCategorys[$level - 1]->getPath());
             
                                    try 
                                    {
                                        $category->save();
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
                }	
                
            }

            $firstRowSkipped = true;	
    	}
    	fclose($handle);

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