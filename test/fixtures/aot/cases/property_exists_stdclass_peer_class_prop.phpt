--TEST--
AOT: property_exists() on stdClass when a peer class already declared the same name (#32688 leftover)
--FILE--
<?php
class C {
    public $x = 1;
}
$o = new stdClass();
$o->x = 1;
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo "\n";
--EXPECT--
10
