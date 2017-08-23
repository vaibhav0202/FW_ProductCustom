<?php
class  FW_ProductCustom_Model_Resource_Eav_Source_VistaAnswerCodes extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
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
                    'label' => 'NYP',
                ),
                array(
                    'value' => 2,
                    'label' => 'BRP',
                ),
                array(
                    'value' => 3,
                    'label' => 'TOS',
                ),
                array(
                    'value' => 4,
                    'label' => 'COS',
                ),
                array(
                    'value' => 5,
                    'label' => 'REM',
                ),
                array(
                    'value' => 6,
                    'label' => 'OSI',
                ),
                array(
                    'value' => 7,
                    'label' => 'CAN',
                ),
                array(
                    'value' => 8,
                    'label' => 'CNR',
                ),
                array(
                    'value' => 9,
                    'label' => 'OOP',
                ),
                array(
                    'value' => 10,
                    'label' => 'NLA',
                ),
                array(
                    'value' => 11,
                    'label' => 'CFE',
                ),
                array(
                    'value' => 12,
                    'label' => 'NIP',
                )
            );
        }
        return $this->_options;
    }
}