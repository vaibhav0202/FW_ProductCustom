<?php

/**
 * @category    FW
 * @package     FW_ProductCustom
 * @copyright   Copyright (c) 2015 F+W (http://www.fwcommunity.com)
 */
class FW_ProductCustom_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Config path for using throughout the code
     *
     * @var string $XML_PATH
     */
    const XML_PATH = 'productupdate/productupdate_group/';

    /**
     * Check if product updater is enabled or not
     *
     * @param null $store
     *
     * @return bool
     */
    public function isProductUpdaterEnabled($store = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH . 'active', $store);
    }

    /**
     * @param $product (Magento Product object)
     *
     * @return boolean - whether or not the product is a pre-order product or not.
     */
    public function isPreOrderProduct($product)
    {
        // If pre-order or vista answer code is 1 (NYP) then return true
        return ($product->getPreorder() || $product->getVistaAnswerCode() == 1);
    }

    /**
     * @param $product (Magento Product object)
     *
     * @return date - either the warehouse availability date or publication date if the warehouse date is not populated.
     */
	public function getPreOrderAvailabilityDate($product) {
        if (!$this->isPreOrderProduct($product)) return;
        return $product->getWarehouseAvailDate() ? $product->getWarehouseAvailDate() : $product->getPublicationDate();
    }
    
                /**
	 * Get the FTP Host
	 * @param mixed $store
	 * @return string
	 */
	public function getFtpHost($store = null)
	{
		return Mage::getStoreConfig(self::XML_PATH.'ftp_host', $store);
	}
        
         /**
	 * Get the FTP User
	 * @param mixed $store
	 * @return string
	 */
	public function getFtpUser($store = null)
	{
		return Mage::getStoreConfig(self::XML_PATH.'ftp_user', $store);
	}
        
          /**
	 * Get the FTP Password
	 * @param mixed $store
	 * @return string
	 */
	public function getFtpPassword($store = null)
	{
		return Mage::getStoreConfig(self::XML_PATH.'ftp_password', $store);
	}
        
           /**
	 * Get the FTP Location
	 * @param mixed $store
	 * @return string
	 */
	public function getFtpLocation($store = null)
	{
		return Mage::getStoreConfig(self::XML_PATH.'ftp_location', $store);
	}
}
