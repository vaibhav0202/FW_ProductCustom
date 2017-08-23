<?php
$installer = $this;

$installer->createNewAttributeSet('Book'); 
$installer->createNewAttributeSet('CD'); 
$installer->createNewAttributeSet('DVD'); 
$installer->createNewAttributeSet('Subscription'); 
$installer->createNewAttributeSet('Premium'); 
$installer->createNewAttributeSet('Streaming'); 
$installer->createNewAttributeSet('Download'); 
$installer->createNewAttributeSet('Magazine'); 
$installer->createNewAttributeSet('Fabric');
$installer->createNewAttributeSet('Lace/Trim');  
$installer->createNewAttributeSet('Pattern');
$installer->createNewAttributeSet('Apparel'); 
$installer->createNewAttributeSet('VHS'); 

$installer->installEntities();
