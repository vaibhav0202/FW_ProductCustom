<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'yarn_fiber', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'yarn_fiber', array(
    'group'         => 'Custom',
    'sort_order'    => '410',
    'input'         => 'multiselect',
    'type'          => 'varchar',
    'label'         => 'Yarn Fiber',
    'backend'       => 'eav/entity_attribute_backend_array',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->addAttribute('catalog_product', 'yarn_weight', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));
$installer->addAttribute('catalog_product', 'yarn_weight', array(
    'group'         => 'Custom',
    'sort_order'    => '420',
    'input'         => 'select',
    'type'          => 'int',
    'label'         => 'Yarn Weight',
    'visible'       => 1,
    'required'      => 0,
    'user_defined'  => 1,
));

$installer->endSetup(); 



