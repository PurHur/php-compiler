--TEST--
foreach by-reference mutates packed array elements (VM)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 2;
}
echo $a[0], $a[1], $a[2], "\n";
--EXPECT--
246
