--TEST--
AOT: array_rand() packed list (#2321)
--FILE--
<?php
$a = array();
$a[] = 1;
$a[] = 2;
$a[] = 3;
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
