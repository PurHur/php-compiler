--TEST--
JIT: natcasesort() with null keeps natural order among strings (#29704)
--FILE--
<?php
$a = [null, 'img2.png', 'img10.png', 'img1.png'];
natcasesort($a);
$parts = [];
foreach (array_values($a) as $v) {
    $parts[] = null === $v ? 'NULL' : $v;
}
echo implode(',', $parts), "\n";
--EXPECT--
NULL,img1.png,img2.png,img10.png
