<?php
$installer = $this;

$installer->startSetup();

$oAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'sold_by_length');
$oAttribute->setData('used_in_product_listing', 1);
$oAttribute->save();

$oAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'product_type');
$oAttribute->setData('is_used_for_promo_rules', 1);
$oAttribute->save();


$installer->addAttribute('catalog_product', 'has_free_patterns', array('type' => Varien_Db_Ddl_Table::TYPE_BOOLEAN, 'visible' => true));

$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$setup->addAttribute('catalog_product', 'has_free_patterns', array(
    'group'         => 'Custom',
    'sort_order'    => '234',
    'input'         => 'boolean',
    'type'          => 'int',
    'label'         => 'Has Free Patterns',
    'visible'       => 1,
    'required'      => 0,
    'default'       => 0,
    'user_defined'  => 1,
));