<?php
/**
 * @category    FW
 * @package     FW_ProductCustom
 * @copyright   Copyright (c) 2012 F+W Media, Inc. (http://www.fwmedia.com)
 * @author		Allen Cook <allen.cook@fwmedia.com>
 * @author		J.P. Daniel <jp.daniel@fwmedia.com>
 */
class FW_ProductCustom_Model_Observer
{
	
	/**
	 * add Massaction Option to Productgrid
	 * 
	 * @param $observer Varien_Event
	 */
	public function addMassactionToProductGrid($observer)
	{
		$block = $observer->getBlock();
		if($block instanceof Mage_Adminhtml_Block_Catalog_Product_Grid){
			
			$typeSets = array(
				'simple' => 'Simple Product', 
				'grouped' => 'Grouped Product', 
				'configurable' => 'Configurable Product',
				'virtual' => 'Virtual Product',
				'bundle' => 'Bundle Product',
				'downloadable' => 'Downloadable Product',
				'giftcard' => 'Gift Card');
	

			$block->getMassactionBlock()->addItem('flagbit_changetype', array(
				'label'=> Mage::helper('catalog')->__('Change type'),
				'url'  => $block->getUrl('*/*/changetype', array('_current'=>true)),
				'additional' => array(
					'visibility' => array(
						'name' => 'type_id',
						'type' => 'select',
						'class' => 'required-entry',
						'label' => Mage::helper('catalog')->__('Type Yd'),
						'values' => $typeSets
					)
				)
			)); 
		}
	}

	/**
	 * add Sold By Length Attribute Option to Sales Quote
	 *
	 * @param $observer Varien_Event
	 */
	public function salesQuoteItemSetSoldByLength($observer)
	{
		$quoteItem = $observer->getQuoteItem();
		$product = $observer->getProduct();
		$quoteItem->setSoldByLength($product->getSoldByLength());
	}
        
        public function salesQuoteItemSetZirconProductName($observer)
	{
		$quoteItem = $observer->getQuoteItem();
		$product = $observer->getProduct();
                $quoteItem->setZirconProductName($product->getZirconProductName());
	}
        
    public function salesQuoteItemSetRequireLogin($observer){
           $quoteItem = $observer->getQuoteItem();
           $product = $observer->getProduct();
           $quoteItem->setRequireLogin($product->getRequireLogin());
    }
        
    public function isAllowedGuestCheckout($observer)
    {
        $quote  = $observer->getEvent()->getQuote();
        $result = $observer->getEvent()->getResult();

        foreach ($quote->getAllItems() as $item) {
            if ($item->getRequireLogin()){
                $result->setIsAllowed(false);
            }
        }
          
        return $this;
    }
    
    	
    public function logProductSaveAfter($observer){
       $productId = $observer->getControllerAction()->getRequest()->getParam('id');
       Mage::log('PRODUCT ID '.$productId, null, 'PRODUCT_SAVE.log');
       
    }
   
    public function logProductSaveBefore($observer){
       $productId = $observer->getControllerAction()->getRequest()->getParam('id');
       Mage::log('PRODUCT ID '. $productId, null, 'PRODUCT_SAVE.log');
    }
       
}
