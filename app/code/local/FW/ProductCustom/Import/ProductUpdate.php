<?php

class FW_ProductCustom_Import_ProductUpdate extends FW_Report_Model_Report
{
    protected $_errorLog;
    protected $_errorCount;
    protected $_log;
    protected $_updateDir;
    protected $_updateExecutedDir;
    protected $_updateFiles;

    /**
     * @var FW_ProductCustom_Helper_Data $helper
     */
    protected $helper;

    /**
     * An array of fields to be processed automatically by a loop
     * Most fields can be added here and will work without any additional code
     *
     * @var $fields array
     */

    private $fields = array('website_ids',
        'category_ids', 
        'name', 
        'meta_title', 
        'meta_description',
        'meta_keyword',
        'url_key', 
        'file_trim_size', 
        'format', 
        'sub_title', 
        'brand', 
        'drop_ship_message', 
        'drop_ship_sku', 
        'author_speaker_editor', 
        'shopping_feed_description', 
        'special_price', 
        'color',
        'color_filter',
        'status', 
        'visibility', 
        'size', 
        'fw_options', 
        'dropship_vendor_id', 
        'sold_by_length', 
        'is_discountable', 
        'description', 
        'short_description', 
        'special_instructions', 
        'special_requirements', 
        'additional_feature', 
        'additional_content', 
        'special_from_date', 
        'special_to_date', 
        'runtime', 
        'file_type', 
        'market_restriction', 
        'include_in_amazon', 
        'about_author', 
        'details', 
        'preview', 
        'table_of_contents', 
        'publication_date', 
        'vista_answer_code', 
        'warehouse_avail_date', 
        'number_of_pages', 
        'isbn13', 
        'kit_style', 
        'kit_time', 
        'needle_brand', 
        'needle_diameter', 
        'needle_length', 
        'needle_material', 
        'needle_type', 
        'pattern_difficulty', 
        'upc', 
        'material', 
        'manufacturer', 
        'designer', 
        'yarn_weight', 
        'yarn_fiber', 
        'tax_class_id', 
        'external_purchase_link', 
        'price', 
        'for', 
        'skill_level',
        'technique', 
        'project', 
        'theme',
        'fw_collection');
    
    private $storeFields = array('description', 
        'short_description', 
        'meta_title', 
        'meta_description', 
        'custom_design');

    /**
     * Fields that are required when creating a grouped product
     *
     * @var $required_group_fields array
     */
    private $required_group_fields = array('name', 
        'description', 
        'short_description', 
        'status', 
        'visibility', 
        'shopping_feed_description', 
        'associated_products');

    /**
     * Fields that are required when creating a virtual product THAT IS ONLY A PRODUCT AND NOT SOLD ON SITE: It has an external_link field value ==> this is a very specific application of creating a virtual product
     *
     * @var $required_virtual_fields array
     */
    private $required_virtual_fields = array('name', 
        'description', 
        'short_description', 
        'status', 
        'visibility', 
        'shopping_feed_description', 
        'tax_class_id',
        'external_purchase_link', 
        'price');

    
    /**
     * Sort the field array in to appropriate types
     */
    public function __construct()
    {
        $this->helper = Mage::helper('productcustom');      // Get the product custom helper
        $this->fields = array_flip($this->fields);          // Make the field values the arrays keys
        foreach ($this->fields as $field => &$type) {       // Loop through all the fields
            $attribute = Mage::getSingleton('eav/config')// Get the EAV config singleton
            ->getAttribute('catalog_product', $field);  // Get the attribute from EAV config
            $type = $attribute->getFrontendInput();         // Get the attributes input type
            if ($type == "textarea") $type = "text";        // textarea == text (saved same way on model)
        }
        $this->fields['website_ids'] = "multi_id";   // Setting a custom "type" handler for fields with multiple IDs
        $this->fields['category_ids'] = "multi_id";  // Setting a custom "type" handler for fields with multiple IDs
        //print_r($this->fields, true) . PHP_EOL; die;
    }

    /**
     * Update IW Products
     * CSV MAP
     * sku - primary key/identifier (required)
     * images: pipe delimited field; within field, tilda delimited subfields of image file name, image location and
     * default image flag download_files: pipe delimited field; within field, tilda delimited subfields of download
     * file location and download title
     */
    public function processProducts()
    {
        if (!$this->helper->isProductUpdaterEnabled()) return;  // Make sure the updater is enabled
        $productCount = 0;

        //create folders
        $baseDir = Mage::getBaseDir() . '/var/importexport';
        if (!file_exists($baseDir)) mkdir($baseDir, 0777);

        $updateDir = $baseDir . '/product_update';
        if (!file_exists($updateDir)) mkdir($updateDir, 0777);

        $executedDir = $updateDir.'/executed';
        if (!file_exists($executedDir)) mkdir($executedDir, 0777);

        $this->_errorLog = 'Product_Update_Error.log';
        $this->_log = 'Product_Update.log';
        $this->_updateDir = $this->isDir($updateDir);
        $this->_updateExecutedDir = $this->isDir($executedDir);

        //retrieve all available csv files from ftp location and order them by date in local array
        try {
            $this->getUpdateFiles();
            $this->orderUpdateFiles();
        } catch (Exception $e) {
            Mage::log('Error: ' . $e, null, $this->_errorLog);
        }

        if (!$this->_updateFiles) return;  // No update files to process

        foreach ($this->_updateFiles as $updateFile) {
            /** @var resource $handle */
            $handle = fopen($updateFile, "r");  // Open the file
            if ($handle === false) {            // Check if file opened
                Mage::log("Could not open file: " . $updateFile, null, $this->_errorLog);
                $this->_errorCount++;
                fclose($handle);
                $this->moveFile($updateFile);
                continue;  // SKIP this file
            }
            Mage::log("Processing File:" . $updateFile, null, $this->_log);
            $cols = fgetcsv($handle, 10000, ",");
            if (count($cols) < 2 || $cols[0] != 'sku') {
                Mage::log("Malformed header: " . $updateFile, null, $this->_errorLog);
                $this->_errorCount++;
                fclose($handle);
                $this->moveFile($updateFile);
                continue;  // SKIP this file
            }
            /*
             * The following creates an array of fields the user wants to update sorted by store
             * This is to make sure each product is only updated once per store, even if user adds fields out of order, etc
             * Example: $storeFields = ['5' => ['name', 'description'], '35' => ['name']]
             */
            $storeFields = array();                         // Array to hold store level fields the user wants to update
            foreach ($cols as $i => &$col) {                // Loop through every csv header
                if (strpos($col, '|') === false) continue;  // Check if col has store specific data
                try {                                       // Handle errors returned from Mage::app()->getStore()
                    $colData = explode('|', $col);          // Get the field [0] and store [1] user wants to update
                    if (!in_array($colData[0], $this->storeFields)) {  // Check if field is allowed
                        Mage::log("Store field '" . $colData[0] . "' not supported: " . $updateFile, null, $this->_errorLog);
                        $this->_errorCount++;
                        continue;  // SKIP this store field
                    }
                    $storeCollection = Mage::getModel('core/store')->getCollection()
                            ->addFieldToFilter('name', $colData[1]);
                    $store = $storeCollection->getFirstItem();
                    $storeId = $store->getId();                                                 // Get the store ID
                    if (!isset($storeFields[$storeId])) $storeFields[$storeId] = array();       // Create array if this is first field for store
                    if (!is_array($storeFields[$storeId])) $storeFields[$storeId] = array();    // Make sure there is an array
                    $storeFields[$storeId][] = $colData[0];                                     // Add the field the user wants to update
                    $colData[1] = $storeId;                                                     // Change col header to always be store ID
                    $col = implode('|', $colData);                                              // Save the col header data back to the cols array
                } catch (Exception $e) {                                                        // Catch exception from Mage::app()->getStore() call
                    Mage::log("Couldn't find store '" . $colData[1] . "': " . $updateFile, null, $this->_errorLog);
                    $this->_errorCount++;
                }
            }
            while (($data = fgetcsv($handle, 10000, ",")) !== false) {
                if (count($data) !== count($cols)) {  // Make sure the number of fields matches number of headers
                    Mage::log("Numbers of fields does not match number of headers: " . $updateFile, null, $this->_errorLog);
                    $this->_errorCount++;
                    continue;  // SKIP this row of the file
                }
                $data = array_combine($cols, $data);
                if ($data['sku'] == '') {  // Make sure there is a sku in row
                    Mage::log("Missing SKU in file: " . $updateFile, null, $this->_errorLog);
                    $this->_errorCount++;
                    continue;  // SKIP this row of the file
                }
                /** @var Mage_Catalog_Model_Product $product */
                $product = Mage::getModel('catalog/product');     // Get the product model
                $productID = $product->getIdBySku($data['sku']);  // Get the product ID
                if (empty($productID)) {  // Make sure the sku returned a product ID
                    $data['type'] = strtolower($data['type']);
                    if ($data['type'] == 'grouped') {  // Allow new grouped products to be created
                        $required_group_fields_error = 0;
                        foreach ($this->required_group_fields as $field) {
                            if (empty($data[$field])) {
                                Mage::log('Failed to create Grouped SKU: ' . $data['sku'] . '. Missing ' . ucwords(str_replace('_', ' ', $field)), null, $this->_errorLog);
                                $required_group_fields_error++;
                                $this->_errorCount++;
                            }
                        }
                        if ($required_group_fields_error) continue;  // SKIP this row of the file
                        $product->setSku($data['sku']);
                        $product->setWebsiteIDs(array(1));
                        $product->setStoreIDs(array(1));
                        $product->setTypeId('grouped');
                        $product->setAttributeSetId($product->getDefaultAttributeSetId());
                        $product->setUrlKey($product->getUrlModel()->formatUrlKey($data['name'] . '-' . $data['sku']));
                    } else if ($data['type'] == 'virtual'){
                        $required_virtual_fields_error = 0;
                        foreach ($this->required_virtual_fields as $field) {
                            if (empty($data[$field]) && $data[$field] != "0" ) {
                                Mage::log('Failed to create Virtual SKU: ' . $data['sku'] . '. Missing ' . ucwords(str_replace('_', ' ', $field)), null, $this->_errorLog);
                                $required_virtual_fields_error++;
                                $this->_errorCount++;
                            }
                        }
                        if ($required_virtual_fields_error) continue;  // SKIP this row of the file
                        $product->setSku($data['sku']);
                        $product->setWebsiteIDs(array(1));
                        $product->setStoreIDs(array(1));
                        $product->setTypeId('virtual');
                        $product->setAttributeSetId($product->getDefaultAttributeSetId());
                        $product->setUrlKey($product->getUrlModel()->formatUrlKey($data['name'] . '-' . $data['sku']));
                    } else {
                        Mage::log("Invalid SKU: " . $data['sku'], null, $this->_errorLog);
                        $this->_errorCount++;
                        continue;  // SKIP this row of the file
                    }
                } else {
                    $product->load($productID);  // Load the full product model
                }

                try {
                    foreach ($cols as $field) {
                        if (!isset($this->fields[$field])) continue;
                        if ($data[$field] == '') continue;                  // Check if file had new data for the field
                        if (!preg_match('//u', serialize($data[$field]))) { // Check for non UTF-8
                            Mage::log("Invalid non-UTF-8 {$field} for SKU: " . $data['sku'], null, $this->_errorLog);
                            $this->_errorCount++;
                            continue;  // SKIP this field
                        }
                        switch ($this->fields[$field]) {  // Process fields based on field type
                            case 'boolean':
                                $this->updateProductDataBoolean($product, $data, $field);
                                break;
                            case 'date':
                                $this->updateProductDataDate($product, $data, $field);
                                break;
                            case 'multi_id':
                                $this->updateProductDataMultiID($product, $data, $field);
                                break;
                            case 'multiselect':
                                $this->updateProductDataMultiSelect($product, $data, $field);
                                break;
                            case 'price':
                                $this->updateProductDataPrice($product, $data, $field);
                                break;
                            case 'select':
                                $this->updateProductDataSelect($product, $data, $field);
                                break;
                            default:
                                $this->updateProductDataText($product, $data, $field);
                                break;
                        }
                    }

                    if (!empty($data['associated_products'])) {
                        $groupedLinkData = array();
                        $assocProducts = explode('|', $data['associated_products']);
                        foreach ($assocProducts as $assocProd) {
                            $assocProd = explode('~', $assocProd);
                            $pid = $product->getIdBySku($assocProd[0]);
                            if (empty($pid)) {
                                Mage::log("Invalid Associated Product SKU: " . $assocProd[0], null, $this->_errorLog);
                                $this->_errorCount++;
                                continue;  // SKIP this associated product
                            }
                            $groupedLinkData[$pid] = array('qty' => 0, 'position' => $assocProd[1]);
                        }
                        $product->setGroupedLinkData($groupedLinkData);
                    }

                    $this->updateRelatedLinkData($product, $data);
                    $this->updateUpSellLinkData($product, $data);
                    $this->updateDownloads($product, $data);  //Update Download Links

                    if ($data['min_sale_qty'] != '') {
                        $product->setStockData(array(
                            'min_sale_qty' => $data['min_sale_qty']
                        ));
                    }
                    
                    if ($data['is_qty_decimal'] != '') {
                        $product->setStockData(array(
                            'is_qty_decimal' => $data['is_qty_decimal']
                        ));
                    }
                        
                    if (empty($productID) && ($data['type'] == 'grouped' || $data['type'] == 'virtual')) {
                        $product->setStockData(array(
                            'use_config_manage_stock' => 0,
                            'manage_stock'            => 0
                            ));

                        if($data['type'] == 'virtual'){
                            
                            $product->save();
                            $stockItem = Mage::getModel('cataloginventory/stock_item');
                            $stockItem->assignProduct($product);
                            $stockItem->setData('is_in_stock', 1);
                            $stockItem->setData('qty', 1);
                            $stockItem->save();
                            $product->setStockItem($stockItem);
                        }
                        
                        $product->setStockData();           
                        Mage::log('Created Complex Product: ' . $product->getSku(), null, $this->_log);
                    }

                    $product->save();
                    $product->setDownloadableData(''); //have to clear out the download data otherwise on other product saves other links will get added
                    
                    // Edit Store Level Data
                    $storeViewProduct = Mage::getModel('catalog/product')->load($product->getId());
                    foreach ($storeFields as $storeId => $tempfields) {                 // Loop through each store
                       foreach ($tempfields as $tempfield) {                               // Loop through each field to update
                            $tempCol = $tempfield . '|' . $storeId;                         // Build string for col header data is stored in
                            if (empty($data[$tempCol])) continue;                       // Data is empty for this field, continue to next
                            switch($tempfield){
                                 case "description":
                                     if ($data[$tempCol] == '[remove]') {
                                         $storeViewProduct->setStoreId($storeId)->setDescription(false);
                                     }else{
                                         $storeViewProduct->setStoreId($storeId)->setDescription($data[$tempCol]);
                                     }

                                     $storeViewProduct->getResource()->saveAttribute($storeViewProduct, 'description');
                                     break;
                                 case "short_description":
                                     if ($data[$tempCol] == '[remove]') {
                                         $storeViewProduct->setStoreId($storeId)->setShortDescription(false);
                                     }else{
                                         $storeViewProduct->setStoreId($storeId)->setShortDescription($data[$tempCol]);
                                     }

                                     $storeViewProduct->getResource()->saveAttribute($storeViewProduct, 'short_description');
                                     break;
                                 case "meta_title":
                                     if ($data[$tempCol] == '[remove]') {
                                         $storeViewProduct->setStoreId($storeId)->setMetaTitle(false);
                                     }else{
                                         $storeViewProduct->setStoreId($storeId)->setMetaTitle($data[$tempCol]);
                                     }
                                     $storeViewProduct->getResource()->saveAttribute($storeViewProduct, 'meta_title');
                                     break;
                                 case "meta_description":
                                     if ($data[$tempCol] == '[remove]') {
                                         $storeViewProduct->setStoreId($storeId)->setMetaDescription(false);
                                     }else{
                                         $storeViewProduct->setStoreId($storeId)->setMetaDescription($data[$tempCol]); 
                                     }

                                     $storeViewProduct->getResource()->saveAttribute($storeViewProduct, 'meta_description');
                                     break;
                                 case "custom_design":
                                      if ($data[$tempCol] == '[remove]') {
                                         $storeViewProduct->setStoreId($storeId)->setCustomDesign(false);
                                     }else{
                                         $storeViewProduct->setStoreId($storeId)->setCustomDesign($data[$tempCol]); 
                                     }

                                     $storeViewProduct->getResource()->saveAttribute($storeViewProduct, 'custom_design');
                                     break;    
                             }
                        }
                    }
                     
                    //This block allows for the resetting of the custom design field on the default store view back to nothing
                    if (!empty($data['custom_design']) && $data['custom_design'] == '[remove]') {                               // Check if file had new custom design
                        $product->setData('custom_design', false);  
                    }
                    
                    //Important to do product image updates last
                    $this->updateImages($product, $data);     //Update Images
                    $product->save();
                    
                    $productCount++;
                    Mage::log("Processed SKU: " . $product->getSku(), null, $this->_log);
                    Mage::log("Total Processed: " . $productCount, null, $this->_log);
                } catch (Exception $e) {
                    Mage::log("Product Update Error for SKU: " . $product->getSku() . " " . $e->getMessage(), null, $this->_errorLog);
                    $this->_errorCount++;
                }
            }
            fclose($handle);
            $this->moveFile($updateFile);
        }

        if ($this->_errorCount > 0) {
            $this->sendAlertEmail("FW Product Updater Error", "There was an error in the product updater", Mage::getBaseDir() . '/var/log/', $this->_errorLog);
        }

        if ($productCount > 0) {
            $this->sendAlertEmail("FW Product Updater", "The F+W product updater ran", Mage::getBaseDir() . '/var/log/', $this->_log);
        }
    }

    /**
     * Move file into 'executed' folder
     *
     * @param $updateFile
     */
    function moveFile($updateFile)
    {
        $lastSlashPos = strrpos($updateFile, "/");
        $fileNameExecuted = substr($updateFile, $lastSlashPos + 1);
        $fileNameExecuted = str_replace('.csv', '.executed.' . date('Ymd-His') . '.csv', $fileNameExecuted);
        rename($updateFile, $this->_updateExecutedDir . "/" . $fileNameExecuted);
    }

    /**
     * Remove old download files, adds updated files to the product
     */
    function updateDownloads($product, $data)
    {
        if (empty($data['download_files']) || $product->getTypeId() != 'downloadable') return;

        Mage::log("Updating Download Links: " . $product->getSku(), null, $this->_log);
        //Delete any previously existing links
        $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
        $conn->query("Delete FROM downloadable_link WHERE product_id = " . $product->getId());

        $product->setDownloadableData($this->createDownloadableData($data));
        $product->setLinksPurchasedSeparately(0);
    }

    /**
     * Remove old images, retrieve new image(s) and add to product
     */
    function updateImages($product, $data)
    {
        if (empty($data['images'])) return;  // Make sure the file had new images

        //create folders
        $baseDir = Mage::getBaseDir() . '/var/importexport';
        if (!file_exists($baseDir)) mkdir($baseDir, 0777);

        $path = $this->isDir($baseDir . '/images' . DS);  // Make sure path exists
        if (!file_exists($path)) mkdir($path, 0777);

        //Check for pre-existing images and remove them
        $mediaGalleryData = $product->getData('media_gallery');
        if ($mediaGalleryData['images']) {
            Mage::log("Removing Existing Images:" . $product->getSku(), null, $this->_log);
            foreach ($mediaGalleryData ['images'] as &$image) {
                unlink(Mage::getBaseDir('media') . "/catalog/product/{$image['file']}");
                $image['removed'] = 1;
            }
            $product->setData('media_gallery', $mediaGalleryData);
        }
        $imageNameCollection = explode("|", $data['images']);

        Mage::log("Adding New Images:" . $product->getSku(), null, $this->_log);
        foreach ($imageNameCollection as $fullImageUrl) {
            $imageArray = explode("~", $fullImageUrl);
            $img = @file_get_contents($imageArray[1]);
            if ($img) {
                if (file_exists($path . $imageArray[0])) unlink($path . $imageArray[0]);
                file_put_contents($path . $imageArray[0], $img);
                if (filesize($path . $imageArray[0]) > 1048576) {
                    unlink($path . $imageArray[0]);
                    $this->_errorCount++;
                    Mage::log('File bigger than 1MB:' . $imageArray[0] . ' for SKU: ' . $product->getSku(), null, $this->_errorLog);
                    continue;
                }
            } else {
                $this->_errorCount++;
                Mage::log('Source Image Not Found for SKU:' . $product->getSku(), null, $this->_errorLog);
            }
            if (file_exists($path . $imageArray[0]) && $imageArray[0] != "") {
                try {
                    if ($imageArray[2] == 1) {
                        $product->addImageToMediaGallery($path . $imageArray[0], array('thumbnail', 'small_image', 'image'), true, false);
                    } else {
                        $product->addImageToMediaGallery(realpath($path . $imageArray[0]), null, true, false);
                    }
                } catch (Exception $e) {
                    Mage::log("Image Not Imported for SKU:" . $product->getSku() . " and file name: " . $imageArray[0] . "==>" . $e->getMessage(), null, $this->_errorLog);
                    $this->_errorCount++;
                }
            } else {
                $this->_errorCount++;
                Mage::log('Image Not Imported for SKU:' . $product->getSku(), null, $this->_errorLog);
            }
        }
    }

    /**
     * Create the link data for downloadable products
     */
    function createDownloadableData($data)
    {
        $downloadableitems = array();
        $linkBlocks = explode("|", $data['download_files']);

        $i = 0;
        foreach ($linkBlocks as $link) {
            $linkArray = explode("~", $link);
            if (strstr($linkArray[0], "media2.fwpublications.com") === false) {
                $downloadableitems['link'][$i]['is_delete'] = 0;
                $downloadableitems['link'][$i]['link_id'] = 0;
                $downloadableitems['link'][$i]['title'] = $linkArray[1];
                $downloadableitems['link'][$i]['number_of_downloads'] = 0;
                $downloadableitems['link'][$i]['is_shareable'] = 3;
                $downloadableitems['link'][$i]['type'] = 'url';
                $downloadableitems['link'][$i]['link_url'] = $linkArray[0];
                $i++;
            } else {
                $this->_errorCount++;
                Mage::log('Download file not updated; contains media2.fwpublications url for SKU:' . $data['sku'], null, $this->_errorLog);
            }
        }
        return $downloadableitems;
    }

    /**
     * Retrieve inventory file(s) from Vista FTP Server
     */
    private function getUpdateFiles()
    {
        # ftp-login
        $helper = Mage::helper('productcustom');
        $ftp_server = $helper->getFtpHost();
        $ftp_user = $helper->getFtpUser();
        $ftp_pw = $helper->getFtpPassword();
        $ftp_folder = $helper->getFtpLocation();

        // set up basic connection
        $conn_id = ftp_connect($ftp_server);

        // login with username and password
        if ($conn_id == false) {
            Mage::log('Connection to ftp server failed\r\n', null, $this->_errorLog);
            $this->_errorCount++;
            return;
        }

        $login_result = ftp_login($conn_id, $ftp_user, $ftp_pw);

        if ($login_result == false) {
            Mage::log('Login to ftp server failed\r\n', null, $this->_errorLog);
            $this->_errorCount++;
            return;
        }

        // turn passive mode on
        ftp_pasv($conn_id, true);

        ftp_chdir($conn_id, $ftp_folder);

        // get current directory
        $dir = ftp_pwd($conn_id);
        $rawfiles = ftp_rawlist($conn_id, '-1t');
        foreach ($rawfiles as $file) {
            $local_file = $this->_updateDir . "/" . $file;
            $filename = strtoupper($file);
            if (strpos($filename, "CUSTOM") === 0 && strpos($filename, ".CSV") > 0) {
                ftp_get($conn_id, $local_file, $file, FTP_BINARY);
                ftp_delete($conn_id, $file);
            }
        }
        ftp_close($conn_id);    // close the connection
    }

    /**
     * Send email success and/or failure
     *
     * @param $subject  string
     * @param $bodyMsg  string
     * @param $filePath string
     * @param $file     string
     */
    public function sendAlertEmail($subject, $bodyMsg, $filePath, $file)
    {
        $email_from = "Magento/VISTA Inventory Update Processer";
        $fileatt = $filePath . $file; // full Path to the file
        $fileatt_type = "application/text"; // File Type

        $to = Mage::getStoreConfig('productupdate/productupdate_group/emailnotice', 1);
        $subject = $subject;
        $fileatt_name = $file;
        $file = fopen($fileatt, 'rb');
        $data = fread($file, filesize($fileatt));
        fclose($file);
        $semi_rand = md5(time());
        $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
        $headers = "From:" . $email_from;
        $headers .= "\nMIME-Version: 1.0\n" .
            "Content-Type: multipart/mixed;\n" .
            " boundary=\"{$mime_boundary}\"";
        $email_message = $bodyMsg;
        $email_message .= "This is a multi-part message in MIME format.\n\n" .
            "--{$mime_boundary}\n" .
            "Content-Type:text/html; charset=\"iso-8859-1\"\n" .
            "Content-Transfer-Encoding: 7bit\n\n" .
            $email_message .= "\n\n";
        $data = chunk_split(base64_encode($data));
        $email_message .= "--{$mime_boundary}\n" .
            "Content-Type: {$fileatt_type};\n" .
            " name=\"{$fileatt_name}\"\n" .
            "Content-Transfer-Encoding: base64\n\n" .
            $data .= "\n\n" .
                "--{$mime_boundary}--\n";

        //Send email
        $ok = @mail($to, $subject, $email_message, $headers);
    }

    /**
     * Initialize global file array that will store the inventory files to process
     */
    private function orderUpdateFiles()
    {
        $file_names = array();
        $fileDates = array();

        //Get the files & order by oldest first
        foreach (glob($this->_updateDir . '/*.csv', GLOB_BRACE) AS $file) {
            $slashPosition = strripos($file, '/');

            if ($slashPosition == false) {
                $slashPosition = strripos($file, '\\');
            }

            $fileName = substr($file, $slashPosition + 1);

            $currentModified = substr($fileName, strlen($fileName) - 16);
            $currentModified = substr($currentModified, 0, (strlen($currentModified) - 4));
            $file_names[] = $file;
            $fileDates[] = $currentModified;
        }

        if (!$fileDates) return;  // Stop executing when no file dates exists

        //Sort the date array by oldest first
        asort($fileDates);

        //Match file_names array to file_dates array 
        $file_names_Array = array_keys($fileDates);
        foreach ($file_names_Array as $idx => $name) $name = $file_names[$name];
        $fileDates = array_merge($fileDates);

        //Loop through dates array 
        $i = 0;
        foreach ($fileDates as $aFileDate) {
            $date = (string)$fileDates[$i];
            $j = $file_names_Array[$i];
            $file = $file_names[$j];
            $i++;

            $this->_updateFiles[$i] = $file;
        }
    }

    /**
     * Update product data that consists of a boolean
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataBoolean($product, $data, $field)
    {
        if ($data[$field] != 0 && $data[$field] != 1) return;  // Make sure the value is 0 or 1
        $product->setData($field, $data[$field]);              // Set the new data to the product model
    }

    /**
     * Update product data that consists of date
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataDate($product, $data, $field)
    {
        $date = strtotime($data[$field]);    // Convert the data to a UNIX timestamp
        $date = date("Y-m-d H:i:s", $date);  // Convert the UNIX timestamp to MySQL DATETIME format
        $product->setData($field, $date);    // Set the new data to the product model
    }

    /**
     * Update product data that consists of multiple ID values
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataMultiID($product, $data, $field)
    {
        if ($newIDs = explode('~', $data[$field])) {  // Get the new IDs
            $product->setData($field, $newIDs);       // Set new IDs to product model
        }
    }

    /**
     * Update product data that is populated by a dropdown in admin
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataSelect($product, $data, $field)
    {
        $fieldID = $this->getAttributeID($field, $data[$field]);
        if (!($fieldID === FALSE)) {  // Get ID for new data
            $product->setData($field, $fieldID);                       // Set new data to product model
        } else {
            Mage::log("Invalid {$field} for SKU: " . $data['sku'], null, $this->_errorLog);
            $this->_errorCount++;
        }
    }

    /**
     * Update product data that is populated by a multi-select in admin
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataMultiSelect($product, $data, $field)
    {
        $dataValidated = TRUE;
        $sourceModel = Mage::getModel('catalog/product')
                ->getResource()
                ->getAttribute($field)
                ->getSource();
        $selectValues = explode("~", $data[$field]);
        foreach($selectValues as $selectValue){
            $fieldID = $this->getAttributeID($field, $selectValue);
            if($fieldID === FALSE) {  // Get ID for new data
                Mage::log("Invalid {$field} for SKU: " . $data['sku'], null, $this->_errorLog);
                $this->_errorCount++;                     // Set new data to product model
                $dataValidated = FALSE;
            } 
        }
        
        if($dataValidated){
            $valuesIds = array_map(array($sourceModel, 'getOptionId'), $selectValues);
            $product->setData($field, $valuesIds);
        }  
    }

    /**
     * Update product data that consists of a price
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataPrice($product, $data, $field)
    {
        $price = trim($data[$field]);
        if (!is_numeric($price)) {
            Mage::log("Invalid {$field} for SKU: " . $data['sku'], null, $this->_errorLog);
            $this->_errorCount++;
            return;
        }
        $product->setData($field, $price);  // Set the new price to the product model
    }

    /**
     * Update product data that consists of text (text/varchar)
     *
     * @param $product
     * @param $data
     * @param $field
     */
    private function updateProductDataText($product, $data, $field)
    {
        $dataVal = $data[$field];
        
        if($field == 'shopping_feed_description'){
           $dataVal =  strip_tags($dataVal);
           $dataVal .=  ' '; // This is here to ensure a product save which is important to correct some data for this attriubte - it once was a varchar attribute and now is a text att, this created some legacy data issue
        }

        $product->setData($field, $dataVal);  // Set the new data to the product model
    }

    /**
     * Update product related link data (related products)
     *
     * @param $product
     * @param $data
     */
    private function updateRelatedLinkData($product, $data)
    {
        if ($data['related_products'] == '') return;
        $relatedLinkData = array();
        if ($data['related_products'] == '[remove]') {
            $product->setRelatedLinkData($relatedLinkData);
            Mage::log('Related product data removed for: ' . $product->getSku(), null, $this->_log);
            return;
        }
        $relatedProducts = explode('|', $data['related_products']);
        foreach ($relatedProducts as $relatedProduct) {
            $relatedProduct = explode('~', $relatedProduct);
            $pid = $product->getIdBySku($relatedProduct[0]);
            if (empty($pid)) {
                Mage::log("Invalid Related Product SKU: " . $relatedProduct[0], null, $this->_errorLog);
                $this->_errorCount++;
                continue;  // SKIP this related product
            }
            $relatedLinkData[$pid] = array('position' => $relatedProduct[1]);
        }
        $product->setRelatedLinkData($relatedLinkData);
    }
    
     /**
     * Update product related link data (related products)
     *
     * @param $product
     * @param $data
     */
    private function updateUpSellLinkData($product, $data)
    {
        if ($data['upsell_products'] == '') return;
        $upSellLinkData = array();
        if ($data['upsell_products'] == '[remove]') {
            $product->setUpSellLinkData($upsellLinkData);
            Mage::log('UpSell product data removed for: ' . $product->getSku(), null, $this->_log);
            return;
        }
        $upSellProducts = explode('|', $data['upsell_products']);
        foreach ($upSellProducts as $upSellProduct) {
            $upSellProduct = explode('~', $upSellProduct);
            $pid = $product->getIdBySku($upSellProduct[0]);
            if (empty($pid)) {
                Mage::log("Invalid Upsell Product SKU: " . $upSellProduct[0], null, $this->_errorLog);
                $this->_errorCount++;
                continue;  // SKIP this related product
            }
            $upSellLinkData[$pid] = array('position' => $upSellProduct[1]);
        }
        $product->setUpSellLinkData($upSellLinkData);
    }

    /**
     * Get attribute ID value
     */
    function getAttributeID($arg_attribute, $arg_value)
    {
        $attribute = Mage::getSingleton('eav/config')
            ->getAttribute('catalog_product', $arg_attribute);
        if (!$attribute->usesSource()) return FALSE;
        $options = $attribute->getSource()->getAllOptions(false);
        if (empty($options)) return FALSE;
        foreach ($options as $option) {
            if ($option['label'] == $arg_value) {
                return $option['value'];
            }
        }
    }
}
