--TEST--
Stdlib: property_exists() — numeric property name coercion JIT (#5163, ext/standard/class.c)
--FILE--
<?php
class C {
    public int $x = 1;
    public string $foo = 'bar';
}
$c = new C();
$zero = 0;
$one = 1;
echo property_exists($c, 0) ? '1' : '0';
echo property_exists($c, $zero) ? '1' : '0';
echo property_exists($c, $one) ? '1' : '0';
echo property_exists($c, 0.0) ? '1' : '0';
echo property_exists($c, true) ? '1' : '0';
echo property_exists($c, false) ? '1' : '0';
echo property_exists($c, 'foo') ? '1' : '0';
echo property_exists($c, '0') ? '1' : '0';
echo property_exists('C', 0) ? '1' : '0';
echo property_exists('C', 'x') ? '1' : '0';
echo "\n";
--EXPECT--
0000001001
