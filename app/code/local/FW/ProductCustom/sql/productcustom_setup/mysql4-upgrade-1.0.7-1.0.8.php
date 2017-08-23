<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'project', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'project', array(
    'group'         => 'Custom',
    'sort_order'    => '277',
    'input'         => 'multiselect',
    'type'          => 'varchar',
    'label'         => 'Project',
    'backend'       => 'eav/entity_attribute_backend_array',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->addAttribute('catalog_product', 'theme', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'theme', array(
    'group'         => 'Custom',
    'sort_order'    => '357',
    'input'         => 'multiselect',
    'type'          => 'varchar',
    'label'         => 'Theme',
    'backend'       => 'eav/entity_attribute_backend_array',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->endSetup(); 



