<?php
$installer = $this;

$installer->startSetup();


$installer->addAttribute('catalog_product', 'zircon_product_name', array('type' => Varien_Db_Ddl_Table::TYPE_VARCHAR, 'visible' => true));

$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$setup->addAttribute('catalog_product', 'zircon_product_name', array(
    'group'         => 'Custom',
    'sort_order'    => '300',
    'input'         => 'text',
    'type'          => 'varchar',
    'label'         => 'Zircon Product Name',
    'visible'       => 1,
    'required'      => 0,
    'default'       => 0,
    'user_defined'  => 1,
));
