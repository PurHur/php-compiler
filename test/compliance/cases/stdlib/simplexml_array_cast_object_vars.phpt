--TEST--
SimpleXMLElement (array) cast / get_object_vars — @attributes + children (#21666)
--FILE--
<?php
$sx = simplexml_load_string('<r a="1"><c>x</c></r>');
var_export((array) $sx);
echo "\n";
var_export(get_object_vars($sx));
echo "\n";
$a = $sx->attributes();
var_export((array) $a);
echo "\n";
$sx2 = simplexml_load_string('<r><c>x</c><d>y</d></r>');
$ch = $sx2->children();
var_export((array) $ch);
echo "\n";
$empty = simplexml_load_string('<r><e/></r>');
$cast = (array) $empty;
echo isset($cast['e']) && $cast['e'] instanceof SimpleXMLElement ? "empty-child-sxe\n" : "fail\n";
echo get_class($cast['e']), "\n";
var_export(get_object_vars($cast['e']));
echo "\n";
--EXPECT--
array (
  '@attributes' => array (
    'a' => '1',
  ),
  'c' => 'x',
)
array (
  '@attributes' => array (
    'a' => '1',
  ),
  'c' => 'x',
)
array (
  '@attributes' => array (
    'a' => '1',
  ),
)
array (
  'c' => 'x',
  'd' => 'y',
)
empty-child-sxe
SimpleXMLElement
array (
)
