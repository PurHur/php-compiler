--TEST--
AOT call-arg spread into variadic function (#23971 e08)
--FILE--
<?php
function s(...$v) {
    echo implode(",", $v), "\n";
}
$p = [1, 2, 3];
s(...$p);
s(0, ...$p);
--EXPECT--
1,2,3
0,1,2,3
