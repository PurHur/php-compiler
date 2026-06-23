--TEST--
AOT: property_exists() on stdClass dynamic property (issue #10643)
--FILE--
<?php
$o = new stdClass();
$o->x = 1;
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo "\n";
--EXPECT--
10
