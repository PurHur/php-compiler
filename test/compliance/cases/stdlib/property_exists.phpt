--TEST--
Stdlib: property_exists() on class and object (VM, #1372)
--FILE--
<?php
class Box {
    public int $x = 1;
    private string $secret = 'hi';
}
$o = new Box();
echo property_exists('Box', 'x') ? '1' : '0';
echo property_exists('Box', 'X') ? '1' : '0';
echo property_exists('Box', 'secret') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo property_exists('Missing', 'x') ? '1' : '0';
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'X') ? '1' : '0';
echo property_exists($o, 'secret') ? '1' : '0';
echo "\n";
--EXPECT--
10100101
