--TEST--
AOT: SimpleXMLElement::getNamespaces/getDocNamespaces match Zend (ext/simplexml/sxe.c)
--FILE--
<?php
$xml = '<root xmlns="urn:def" xmlns:a="urn:a"><child xmlns:b="urn:b"><b:x a:y="1"/></child></root>';
$x = new SimpleXMLElement($xml);
echo 'root_true=';
var_export($x->getNamespaces(true));
echo "\ndoc_root=";
var_export($x->getDocNamespaces(false));
echo "\n";
--EXPECT--
root_true=array (
  '' => 'urn:def',
  'b' => 'urn:b',
  'a' => 'urn:a',
)
doc_root=array (
  '' => 'urn:def',
  'a' => 'urn:a',
)
