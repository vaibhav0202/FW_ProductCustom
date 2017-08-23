<?php
class  FW_ProductCustom_Model_Resource_Eav_Source_Restrictedmarkets extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
{
    public function getAllOptions()
    {
        if ($marketRestriction = Mage::getSingleton('fw_shipping/marketrestriction')) {            
            $this->_options = $marketRestriction->getOptionsArray();
            return $this->_options;
        }
    }
}