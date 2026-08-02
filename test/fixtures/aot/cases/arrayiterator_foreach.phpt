--TEST--
AOT: ArrayIterator foreach walks constructor array (#26783)
--FILE--
<?php
$it = new ArrayIterator([1, 2, 3]);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
--EXPECT--
1,2,3
