--TEST--
AOT: DOMElement::hasAttribute/hasAttributeNS return bool like Zend (#33762)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$e = $d->documentElement;
var_dump($e->hasAttribute('a'));
var_dump($e->hasAttribute('missing'));
var_dump($e->hasAttributeNS(null, 'a'));
var_dump($e->hasAttributeNS(null, 'missing'));
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
--EXPECT_EXIT--
0
