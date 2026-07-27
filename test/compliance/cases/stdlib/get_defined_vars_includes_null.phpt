--TEST--
stdlib get_defined_vars() includes null locals (#23567, zend_get_defined_vars)
--FILE--
<?php
declare(strict_types=1);

$x = null;
$d = get_defined_vars();
echo array_key_exists('x', $d) ? '1' : '0', "\n";
echo array_key_exists('x', $d) && null === $d['x'] ? '1' : '0', "\n";
--EXPECT--
1
1
