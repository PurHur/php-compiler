--TEST--
Stdlib: property_exists() on class and object (VM, #1372)
--FILE--
<?php
class Box {
    public $x;
    private $y;
    public static $s;
}
$o = new Box();
$prop = 'x';
echo property_exists('Box', 'x') ? '1' : '0';
echo property_exists('Box', 'y') ? '1' : '0';
echo property_exists('Box', 's') ? '1' : '0';
echo property_exists('Box', 'z') ? '1' : '0';
echo property_exists('Missing', 'x') ? '1' : '0';
echo property_exists('Box', $prop) ? '1' : '0';
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo property_exists($o, 'z') ? '1' : '0';
echo "\n";
--EXPECT--
111001110
