<?php
class  FW_ProductCustom_Model_Resource_Eav_Source_Badges extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
{
    public function getAllOptions()
    {
        if (!$this->_options) {
            $this->_options = array(
                array(
                    'value' => 0,
                    'label' => '',
                ),
                array(
                    'value' => 1,
                    'label' => 'New',
                ),
                array(
                    'value' => 2,
                    'label' => 'Sale',
                ),
                array(
                    'value' => 3,
                    'label' => 'Best Seller',
                )
            );
        }
        return $this->_options;
    }
}