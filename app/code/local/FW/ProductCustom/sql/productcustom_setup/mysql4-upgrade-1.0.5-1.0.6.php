<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'require_login', array('type' => Varien_Db_Ddl_Table::TYPE_BOOLEAN, 'visible' => true));

$installer->addAttribute('catalog_product', 'require_login', array(
    'group'         => 'Custom',
    'sort_order'    => '285',
    'input'         => 'boolean',
    'type'          => 'int',
    'label'         => 'Require Login',
    'visible'       => 1,
    'required'      => 0,
    'default'       => 0,
    'user_defined'  => 1,
));


$installer = new Mage_Sales_Model_Resource_Setup('core_setup');

/**
 * Add 'custom_attribute' attribute for entities
 */
$entities = array(
    'quote_item',
    'order_item'
);
$options = array(
    'type'     => Varien_Db_Ddl_Table::TYPE_VARCHAR,
    'visible'  => true,
    'required' => false
);
foreach ($entities as $entity) {
    $installer->addAttribute($entity, 'require_login', $options);
}

$installer->endSetup(); 



