<?php
class  FW_ProductCustom__Model_Resource_Eav_Source_Formats extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
{
    public function getAllOptions()
    {
        if (!$this->_options) {
            $this->_options = array(
                array(
                    'value' => '',
                    'label' => '',
                )
            );
        }
        return $this->_options;
    }
}