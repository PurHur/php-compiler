--TEST--
AOT: variadic spread pack is usable as array (array_sum) (#24167 k09)
--FILE--
<?php
function sv(...$v) {
    echo array_sum($v), "\n";
}
$p = [1, 2, 3];
sv(...$p);
--EXPECT--
6
