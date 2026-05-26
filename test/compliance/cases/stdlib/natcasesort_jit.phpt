--TEST--
JIT: natcasesort() natural case-insensitive order (#2372)
--FILE--
<?php
$a = array();
$a[] = 'Img12';
$a[] = 'img10';
$a[] = 'IMG2';
$a[] = 'img1';
natcasesort($a);
echo implode(',', $a), "\n";
$data = array('x' => 'V10', 'y' => 'v2', 'z' => 'V1');
natcasesort($data);
foreach ($data as $k => $v) {
    echo $k, ':', $v, "\n";
}
--EXPECT--
img1,IMG2,img10,Img12
z:V1
y:v2
x:V10
