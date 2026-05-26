--TEST--
JIT: array_rand() single key packed list (#2321)
--FILE--
<?php
$a = array();
$a[] = 10;
$a[] = 20;
$a[] = 30;
$k = array_rand($a);
$ok = 1;
if ($k < 0) {
    $ok = 0;
}
if ($k > 2) {
    $ok = 0;
}
echo $ok, "\n";
--EXPECT--
1
