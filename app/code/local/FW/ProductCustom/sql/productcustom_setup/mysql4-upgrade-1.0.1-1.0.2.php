<?php
$installer = $this;

$installer->startSetup();


$installer->addAttribute('catalog_product', 'upc', array('type' => Varien_Db_Ddl_Table::TYPE_VARCHAR, 'visible' => true));
$installer->addAttribute('catalog_product', 'material', array('type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'visible' => true));

$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$setup->addAttribute('catalog_product', 'upc', array(
    'group'         => 'Custom',
    'sort_order'    => '270',
    'input'         => 'text',
    'type'          => 'varchar',
    'label'         => 'UPC',
    'visible'       => 1,
    'required'      => 0,
    'default'       => 0,
    'user_defined'  => 1,
));
$setup->addAttribute('catalog_product', 'material', array(
    'group'                         => 'Custom',
    'label'                         => 'Material',
    'type'                          => 'int',
    'input'                         => 'select',
    'default'                       => '',
    'class'                         => '',
    'backend'                       => '',
    'frontend'                      => '',
    'source'                        => '',
    'global'                        => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'                       => true,
    'required'                      => false,
    'user_defined'                  => true,
    'searchable'                    => false,
    'filterable'                    => false,
    'comparable'                    => false,
    'visible_on_front'              => false,
    'visible_in_advanced_search'    => false,
    'unique'                        => false,
    'sort_order'                    => 265
));