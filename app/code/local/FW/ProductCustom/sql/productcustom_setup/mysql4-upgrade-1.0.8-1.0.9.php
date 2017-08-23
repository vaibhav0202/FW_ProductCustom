<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$installer = Mage::getModel('eav/entity_setup', 'core_setup');

$installer->addAttribute('catalog_product', 'taxware_geocode', array('type' => Varien_Db_Ddl_Table::TYPE_VARCHAR, 'visible' => true));
$installer->addAttribute('catalog_product', 'taxware_taxcode', array('type' => Varien_Db_Ddl_Table::TYPE_VARCHAR, 'visible' => true));

$setup = new Mage_Eav_Model_Entity_Setup('core_setup');

$setup->addAttribute('catalog_product', 'taxware_geocode', array(
    'group'         => 'Custom',
    'sort_order'    => '300',
    'input'         => 'text',
    'type'          => 'varchar',
    'label'         => 'Taxware Product GEOCODE',
    'visible'       => 1,
    'required'      => 0,
    'default'       => '840300325300',
    'user_defined'  => 1,
    'used_in_product_listing' => 1,
));

$setup->addAttribute('catalog_product', 'taxware_taxcode', array(
    'group'         => 'Custom',
    'sort_order'    => '301',
    'input'         => 'text',
    'type'          => 'varchar',
    'label'         => 'Taxware Product TAXCODE',
    'visible'       => 1,
    'required'      => 0,
    'default'       => '76800',
    'user_defined'  => 1,
    'used_in_product_listing' => 1,
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
    $installer->addAttribute($entity, 'taxware_geocode', $options);
    $installer->addAttribute($entity, 'taxware_taxcode', $options);
}

$installer->endSetup();