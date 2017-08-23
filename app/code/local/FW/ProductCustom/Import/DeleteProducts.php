<?php
    // include the Mage engine
    require_once '../../../../../../app/Mage.php';
    Mage::app();
   
    //Command Line Args
    $fileName = $argv[1];

    $logFile = 'ProductDelete.log';
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));

    $count = 0;

    if (($handle = fopen($fileName, "r")) !== FALSE){
        $firstRowSkipped = false;
        
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE){
            if($firstRowSkipped){
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku',$data[0]);

                if($product){
                    try{
                        $product->delete();
                        $count++;
                        Mage::log("Deleted SKU:".$data[0]."\n",null, $logFile);
                        echo "Deleted SKU:".$data[0].", count: ".$count."\n";
                    } 
                    catch(Exception $e)
                    {
                        Mage::log($e->getMessage(),null, $logFile);
                    }
                }
            }
            $firstRowSkipped = true;
        }
    }
    fclose($handle);
?>
