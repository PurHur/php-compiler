<?php
// AOT lint-only: crc32 Zend stub named params (#23491, ext/standard/crc32.c)
echo crc32(string: 'x'), "\n";
$rf = new ReflectionFunction('crc32');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
