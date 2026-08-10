--TEST--
stdlib natsort() with null keeps natural order among strings (#29691)
--FILE--
<?php
$a = [null, 'img2.png', 'img10.png'];
natsort($a);
echo implode(',', array_map(static function ($v) {
    return null === $v ? 'NULL' : $v;
}, array_values($a))), "\n";

$b = ['img12.png', 'img10.png', 'img2.png'];
natsort($b);
echo implode(',', array_values($b)), "\n";

$c = ['img2.png', null, 'img10.png'];
natsort($c);
$out = [];
foreach ($c as $k => $v) {
    $out[] = $k.':'.(null === $v ? 'NULL' : $v);
}
echo implode(',', $out), "\n";
--EXPECT--
NULL,img2.png,img10.png
img2.png,img10.png,img12.png
1:NULL,0:img2.png,2:img10.png
