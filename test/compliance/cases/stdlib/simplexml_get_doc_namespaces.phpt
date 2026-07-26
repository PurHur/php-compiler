--TEST--
SimpleXML: getDocNamespaces/getNamespaces with bool $recursive (#19410, ext/simplexml/sxe.c)
--FILE--
<?php
$s = simplexml_load_string('<r xmlns:a="urn:a"><a:c/></r>');
var_export($s->getDocNamespaces(true));
echo "\n";
var_export($s->getNamespaces(true));
echo "\n";
var_export($s->getDocNamespaces(false));
echo "\n";
var_export($s->getNamespaces(false));
echo "\n";
--EXPECT--
array (
  'a' => 'urn:a',
)
array (
  'a' => 'urn:a',
)
array (
  'a' => 'urn:a',
)
array (
)
