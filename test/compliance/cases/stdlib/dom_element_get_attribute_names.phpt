--TEST--
stdlib DOMElement::getAttributeNames() — PHP 8.3+ profile (#16823, ext/dom/element.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('div');
$el->setAttribute('id', 'x');
$el->setAttribute('class', 'a');
var_export($el->getAttributeNames());
echo "\n";
$empty = $doc->createElement('span');
var_export($empty->getAttributeNames());
echo "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root xmlns:ex="http://example.com/ns" ex:attr="1" plain="2"/>');
var_export($doc2->documentElement->getAttributeNames());
?>
--EXPECT--
array (
  0 => 'id',
  1 => 'class',
)
array (
)
array (
  0 => 'xmlns:ex',
  1 => 'ex:attr',
  2 => 'plain',
)
