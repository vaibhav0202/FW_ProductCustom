<?php
/** @var Mage_Eav_Model_Entity_Setup $setup */
$setup = Mage::getModel('eav/entity_setup', 'core_setup');

$setup->addAttribute(
    Mage_Catalog_Model_Product::ENTITY,
    'designer',
    array(
        'group'         => 'Custom',
        'type'          => 'int',
        'input'         => 'select',
        'label'         => 'Designer',
        'visible'       => 1,
        'required'      => 0,
        'default'       => 0,
        'user_defined'  => 1,
    )
);
