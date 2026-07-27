--TEST--
crc32() named args + Reflection (VM, issue #23491, ext/standard/crc32.c)
--FILE--
<?php
echo crc32(string: 'x'), "\n";
$rf = new ReflectionFunction('crc32');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
--EXPECT--
2363233923
string
