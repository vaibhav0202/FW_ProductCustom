<?php
$installer = new Mage_Sales_Model_Resource_Setup('core_setup');
/**
* Add 'zircon product name' attribute for entities
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
    $installer->addAttribute($entity, 'zircon_product_name', $options);
}

$installer->endSetup(); 
