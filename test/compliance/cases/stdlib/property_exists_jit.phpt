--TEST--
Stdlib: property_exists() on class name string (JIT, #1372)
--FILE--
<?php
class Box {
    public int $x = 1;
    private string $secret = 'hi';
}
echo property_exists('Box', 'x') ? '1' : '0';
echo property_exists('Box', 'X') ? '1' : '0';
echo property_exists('Box', 'secret') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo property_exists('Missing', 'x') ? '1' : '0';
echo "\n";
--EXPECT--
10100
