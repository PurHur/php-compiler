--TEST--
AOT: property_exists() — numeric property name coercion (#5163, ext/standard/class.c)
--FILE--
<?php
class C {
    public int $x = 1;
    public string $foo = 'bar';
}
$c = new C();
echo property_exists($c, 0) ? '1' : '0';
echo property_exists($c, 1) ? '1' : '0';
echo property_exists($c, 0.0) ? '1' : '0';
echo property_exists($c, true) ? '1' : '0';
echo property_exists($c, false) ? '1' : '0';
echo property_exists($c, 'foo') ? '1' : '0';
echo property_exists($c, '0') ? '1' : '0';
echo property_exists('C', 0) ? '1' : '0';
echo property_exists('C', 'x') ? '1' : '0';
echo "\n";
--EXPECT--
000001001
