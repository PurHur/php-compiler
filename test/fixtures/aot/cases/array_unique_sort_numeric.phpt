--TEST--
AOT: array_unique() SORT_NUMERIC — numeric dedup (#4253, #29113)
--FILE--
<?php
// Avoid var_export(array) — thin standalone AOT aborts without Runtime->vm (#26855).
$u1 = array_unique(['10', '10a'], SORT_NUMERIC);
echo count($u1), ',', implode(',', array_map('strval', $u1)), PHP_EOL;
$u2 = array_unique([10, 10], SORT_NUMERIC);
echo count($u2), ',', implode(',', array_map('strval', $u2)), PHP_EOL;
$u3 = array_unique(['1', 1, '1.0', 1.0], SORT_NUMERIC);
echo count($u3), ',', implode(',', array_map('strval', $u3)), PHP_EOL;
--EXPECT--
1,10
1,10
1,1
