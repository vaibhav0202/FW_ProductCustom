<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'color_filter', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'color_filter', array(
    'group'         => 'Custom',
    'sort_order'    => '450',
    'input'         => 'multiselect',
    'type'          => 'varchar',
    'label'         => 'Color Filter',
    'backend'       => 'eav/entity_attribute_backend_array',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->endSetup();



