<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'additional_shipping', array('type' => Varien_Db_Ddl_Table::TYPE_DECIMAL, 'visible' => true));
$installer->addAttribute('catalog_product', 'additional_shipping', array(
    'group'         => 'Custom',
    'sort_order'    => '480',
    'input'         => 'price',
    'type'          => 'decimal',
    'label'         => 'Additional Shipping',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->endSetup();



