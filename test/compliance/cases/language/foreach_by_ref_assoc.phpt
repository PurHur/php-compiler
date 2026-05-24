--TEST--
foreach by-reference mutates associative array elements (VM)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
foreach ($a as $k => &$v) {
    $v++;
}
echo $a['a'], $a['b'], "\n";
--EXPECT--
23
