<?php
    // include the Mage engine
    require_once '../../../../../../../app/Mage.php';

    Mage::app();
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
   
    //globals
    $logFile = 'IW_Product_Community_Import.log';
    $fileName = "product_theme.csv";

    echo "\nProcessing file: ".$reviewFileName."\n";

    processProductThemes($fileName);
    

    
	/**
	 * Create Product Reviews
	 * 
	 * 
	 */
    function processProductThemes($fileName)
    {
    	global $logFile;
        
	if (($handle = fopen($fileName, "r")) !== FALSE)
    	{
            $firstRowSkipped = false;

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE)
            {
                if($firstRowSkipped)
                {
                    $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);
                    
                    if($product && $data[1] != "")
                    {
                        try 
                        {
                            $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                            $conn->query("Insert into catalog_product_entity_varchar (entity_type_id, attribute_id, store_id, entity_id, value) values(7, 191,  35, ".$product->getId().",'iw/".strtolower(str_replace(" ", "", $data[1]))."')");
     
                            echo $product->getSku()."\n";
                            Mage::log("Updated Product ".$data[0]." to custom theme ".$data[1],null, $logFile);
                        } 
                        catch (Exception $e) 
                        {
                           try 
                            {
                                $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
                                $conn->query("update catalog_product_entity_varchar set value = 'iw/".strtolower(str_replace(" ", "", $data[1]))."' where attribute_id = 191 and entity_id = ".$product->getId()." and store_id = 35");
                            
                                echo $product->getSku()."\n";
                                Mage::log("Updated Product ".$data[0]." to custom theme ".$data[1],null, $logFile);
                            } 
                            catch (Exception $e) 
                            {
                                Mage::log("Category Creation Error: ".$e->getMessage(),null, $logFile);
                            }
                        }
                    }
                    exit;
                }
                $firstRowSkipped = true;	
            }
    	}
    	fclose($handle);
    }
?>