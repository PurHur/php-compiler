--TEST--
SimpleXMLElement::__construct + addChild + asXML (#19306)
--FILE--
<?php
$x = new SimpleXMLElement('<r/>');
$x->addChild('c', 'v');
echo trim($x->asXML()), PHP_EOL;
--EXPECT--
<?xml version="1.0"?>
<r><c>v</c></r>
