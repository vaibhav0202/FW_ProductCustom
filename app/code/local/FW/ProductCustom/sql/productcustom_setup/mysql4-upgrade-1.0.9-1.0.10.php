<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'fw_collection', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'fw_collection', array(
    'group'         => 'Custom',
    'sort_order'    => '430',
    'input'         => 'select',
    'type'          => 'varchar',
    'label'         => 'Collection',
    'backend'       => 'eav/entity_attribute_backend_array',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->endSetup(); 



