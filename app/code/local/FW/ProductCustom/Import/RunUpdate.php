<?php
chdir(dirname(__FILE__));  // Change working directory to script location

require_once '../../../../../Mage.php';  // Include Mage
require_once 'ProductUpdate.php';  // Include Update Model
Mage::app('admin');  // Run Mage app() and set scope to admin

$update = new FW_ProductCustom_Import_ProductUpdate();  // Create update model
$update->processProducts();  // Process products
