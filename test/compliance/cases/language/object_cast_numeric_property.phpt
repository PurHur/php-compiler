--TEST--
Language: (array) cast — numeric dynamic property names stringify (#7427, zend_operators.c)
--FILE--
<?php
$o = new stdClass();
$o->{1} = 'a';
var_export((array) $o);
echo "\n";
var_export((array) (object) [1 => 'a']);
echo "\n";
--EXPECT--
array (
  1 => 'a',
)
array (
  1 => 'a',
)
