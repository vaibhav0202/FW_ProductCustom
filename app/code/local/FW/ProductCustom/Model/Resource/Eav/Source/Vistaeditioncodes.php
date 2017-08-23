<?php
class  FW_ProductCustom_Model_Resource_Eav_Source_VistaEditionCodes extends Mage_Eav_Model_Entity_Attribute_Source_Abstract
{
    public function getAllOptions()
    {
        if (!$this->_options) {
            $this->_options = array(
                array(
                    'value' => 1,
                    'label' => 'A',
                ),
                array(
                    'value' => 2,
                    'label' => 'B',
                ),
                array(
                    'value' => 3,
                    'label' => 'C',
                ),
                array(
                    'value' => 4,
                    'label' => 'D',
                ),
                array(
                    'value' => 5,
                    'label' => 'E',
                ),
                array(
                    'value' => 6,
                    'label' => 'F',
                ),
                array(
                    'value' => 7,
                    'label' => 'G',
                ),
                array(
                    'value' => 8,
                    'label' => 'H',
                ),
                array(
                    'value' => 9,
                    'label' => 'I',
                ),
                array(
                    'value' => 10,
                    'label' => 'J',
                ),
                array(
                    'value' => 11,
                    'label' => 'K',
                ),
                array(
                    'value' => 12,
                    'label' => 'L',
                ),
                array(
                    'value' => 13,
                    'label' => 'M',
                ),
                array(
                    'value' => 14,
                    'label' => 'N',
                ),
                array(
                    'value' => 15,
                    'label' => 'O',
                ),
                array(
                    'value' => 16,
                    'label' => 'P',
                ),
                array(
                    'value' => 17,
                    'label' => 'R',
                ),
                array(
                    'value' => 18,
                    'label' => 'S',
                ),
                array(
                    'value' => 19,
                    'label' => 'T',
                ),
                array(
                    'value' => 20,
                    'label' => 'U',
                ),
                array(
                    'value' => 21,
                    'label' => 'V',
                ),
                array(
                    'value' => 22,
                    'label' => 'W',
                ),
                array(
                    'value' => 23,
                    'label' => 'X',
                ),
                array(
                    'value' => 24,
                    'label' => 'Y',
                ),
                array(
                    'value' => 25,
                    'label' => 'Z',
                ),
                array(
                    'value' => 26,
                    'label' => '1',
                ),
                array(
                    'value' => 27,
                    'label' => '2',
                ),
                array(
                    'value' => 28,
                    'label' => '3',
                ),
                array(
                    'value' => 29,
                    'label' => '4',
                ),
                array(
                    'value' => 30,
                    'label' => '5',
                )
            );
        }
        return $this->_options;
    }
}