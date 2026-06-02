--TEST--
AOT foreach by-reference mutates associative array elements (#4364)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
foreach ($a as $k => &$v) {
    $v++;
}
echo $a['a'], $a['b'], "\n";
--EXPECT--
23
