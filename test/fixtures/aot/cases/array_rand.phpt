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
$keys = array_rand($a, 2);
sort($keys);
$ok2 = 1;
if (count($keys) !== 2) {
    $ok2 = 0;
}
if ($keys[0] === $keys[1]) {
    $ok2 = 0;
}
echo $ok2, "\n";
--EXPECT--
1
1
