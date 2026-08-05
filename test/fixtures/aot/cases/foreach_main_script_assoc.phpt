--TEST--
AOT foreach over main-script assoc/packed locals (VALUE script globals, #27105)
--FILE--
<?php
$packed = [1, 2, 3];
$n = 0;
foreach ($packed as $v) {
    $n += $v;
}
echo 'sum=', $n, "\n";
$assoc = ['a' => 1, 'b' => 2, 'c' => 3];
foreach ($assoc as $k => $v) {
    echo $k, '=', $v, "\n";
}
--EXPECT--
sum=6
a=1
b=2
c=3
