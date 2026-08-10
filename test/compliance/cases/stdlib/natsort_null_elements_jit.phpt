--TEST--
JIT: natsort() with null keeps natural order among strings (#29691)
--FILE--
<?php
$a = [null, 'img2.png', 'img10.png'];
natsort($a);
$parts = [];
foreach (array_values($a) as $v) {
    $parts[] = null === $v ? 'NULL' : $v;
}
echo implode(',', $parts), "\n";
--EXPECT--
NULL,img2.png,img10.png
