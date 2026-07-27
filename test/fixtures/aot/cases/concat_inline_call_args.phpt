--TEST--
AOT: concat expressions as call args must not vanish (#23779 / c04_concat)
--FILE--
<?php
function f($a, $b) {
    echo "$a $b\n";
}
$s = 's';
f($s . '1', $s . '2');
f('x' . 'y', 'p' . 'q');
--EXPECT--
s1 s2
xy pq
