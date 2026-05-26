--TEST--
JIT: natsort() natural order (#2358)
--FILE--
<?php
$a = array();
$a[] = 'z2';
$a[] = 'z10';
$a[] = 'z1';
natsort($a);
echo implode(',', $a), "\n";
$data = array('b' => 'file10', 'a' => 'file2', 'c' => 'file1');
natsort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
z1,z2,z10
c:file1
a:file2
b:file10
