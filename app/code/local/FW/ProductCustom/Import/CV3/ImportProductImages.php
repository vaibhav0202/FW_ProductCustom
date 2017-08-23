<?php
/**
 * @category    FW
 * @package     FW_ProductCustom
 * @copyright   Copyright (c) 2012 F+W Media, Inc. (http://www.fwmedia.com)
 * @author		J.P. Daniel (jp.daniel@fwmedia.com)
 */
 
require_once '../../../../../../app/Mage.php';


Mage::app();

Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

echo 'Importing Product Images...'.PHP_EOL;

$path = 'images'.DS;
if (!is_dir($path)) mkdir($path);

//Start Time Metrics
$mtime = microtime();
$mtime = explode(' ', $mtime);
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;

$MPData = array();
if (($handle = fopen("MPProducts.csv", "r")) !== FALSE) {
	$col = array();
    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
        if (empty($col)) {
			$col = $data;
		} else {
			if(count($col) == count($data)) {
				$data = array_combine($col, $data);
				if (!($MPData[$data['SKU']] = $data['ImageFilenameOverride'])) {
					$MPData[$data['SKU']] = $data['VariantImageFilenameOverride'];
				}
			}	
		}
    }
    fclose($handle);
}

$count = 0;

$MPStoreID = Mage::getModel('core/website')->load('Martha Pullen', 'name')->getId();

$conn = Mage::getSingleton('core/resource')->getConnection('core_write');

$products = null;

if(isset($argv[1])) {
	$lastProductId = $argv[1];
	$products=$conn->query("SELECT entity_id from catalog_product_entity WHERE entity_id >= $lastProductId ORDER BY entity_id ASC");
} else {
	$products=$conn->query("SELECT entity_id from catalog_product_entity ORDER BY entity_id ASC");
}

while($productId = $products->fetch())
{
	$product = Mage::getModel('catalog/product')->load($productId['entity_id']);
	$sku = $product->getSku();
	
	echo 'ProductID: ' . $productId['entity_id'] . ' -> SKU: '.$sku;
	Mage::log("Product ID Attempt: " . $productId['entity_id'],null, "ProductImageImport-ProductIDs.log");
	
	if ($product->getVisibility() == 1) {
		echo ' -> Child Product'.PHP_EOL;
		continue;
	}
	$mediaGalleryData = $product->getData('media_gallery');
	if ($mediaGalleryData['images']  && $argv[2] != TRUE) {
		Mage::log("SKU: $sku already imported.",null, "ProductImageImport-AlreadyImported.log");
		echo " -> Already Imported\n";
		continue;
	}
	if (isset($MPData[$sku])) {
		$file = $MPData[$sku];
		foreach (array('large', 'medium', 'icon') as $size) {
			if ($img = @file_get_contents('http://store.marthapullen.com/images/Product/'.$size.'/'.$file)) break;
		}
		echo ' -> '. 'http://store.marthapullen.com/images/Product/'.$size.'/'.$file;
	} else {
		$file = $sku.'.jpg';
		foreach (array('popup', 'large', 'thumbnail') as $size) {
			if ($img = @file_get_contents('http://images.fwbookstore.com/'.$size.'/'.$file)) break;
		}
		echo ' -> '.'http://images.fwbookstore.com/'.$size.'/'.$file;
	}
	
	if ($img) {	
		if (file_exists($path.$file)) unlink($path.$file);
		file_put_contents($path.$file, $img);
		@imagedestroy($img);
		list(,,$type) = getimagesize($path.$file);
		switch ($type) {
			case IMAGETYPE_GIF:
				$img = imagecreatefromgif($path.$file);
				break;
			case IMAGETYPE_PNG:
				$img = imagecreatefrompng($path.$file);
				break;
			case IMAGETYPE_BMP:
			case IMAGETYPE_WBMP:
				$img = imagecreatefromwbmp($path.$file);
				break;
			default:
				$img = imagecreatefromjpeg($path.$file);
		}
		$newfile = explode('.', $file);
		$newfile[count($newfile) - 1] = 'jpg';
		$newfile = implode('.', $newfile);
		imagejpeg($img, $path.$newfile);
		if ($file != $newfile ) {
			echo ' -> '.$newfile;
			unlink($path.$file);
			$file = $newfile;
		}
	} else {
		echo ' -> Not Found'.PHP_EOL;
		Mage::log("SKU: $sku ",null, "ProductImageImport-NotFound.log");
		continue;
	}
	echo ' -> ';
	if (file_exists($path.$file)) {
		echo 'Imported';
		Mage::log("SKU: $sku ",null, "ProductImageImport-Successful.log");   
		
		foreach ($mediaGalleryData['images'] as &$image) {
			 $image['removed'] = 1;
		}
		$product->setData('media_gallery', $mediaGalleryData);
		$product->addImageToMediaGallery(realpath($path.$file), array('thumbnail','small_image','image'), TRUE, FALSE);
		
		$count++;
	} else {
		echo 'Not Imported';
		Mage::log("SKU: $sku ",null, "ProductImageImport-Failed.log");
	}
	$product->save();
	
	//Used for time metrics to see how long 1000 products takes
//	if($count == 1000) {
//		break;
//	}
	echo PHP_EOL;
}

//Stop Time metrics
$mtime = microtime();
$mtime = explode(" ", $mtime); 
$mtime = $mtime[1] + $mtime[0];
$endtime = $mtime;
$totaltime = ($endtime - $starttime);
$logFile = 'ProductImageImport.log';
Mage::log("Total Process Time for image import: ".$totaltime." seconds for ".$count." products",null, $logFile);   

echo $count.' images imported'.PHP_EOL;
