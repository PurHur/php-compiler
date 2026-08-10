--TEST--
stdlib natcasesort() with null keeps natural order among strings (#29704)
--FILE--
<?php
$a = [null, 'img2.png', 'img10.png', 'img1.png'];
natcasesort($a);
$parts = [];
foreach (array_values($a) as $v) {
    $parts[] = null === $v ? 'NULL' : $v;
}
echo implode(',', $parts), "\n";

$b = [null, 'Img2.png', 'img10.png', 'IMG1.png'];
natcasesort($b);
$parts = [];
foreach (array_values($b) as $v) {
    $parts[] = null === $v ? 'NULL' : $v;
}
echo implode(',', $parts), "\n";
--EXPECT--
NULL,img1.png,img2.png,img10.png
NULL,IMG1.png,Img2.png,img10.png
