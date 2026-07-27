--TEST--
AOT: multi-argument builtins max() and sprintf() (#23799)
--FILE--
<?php
$x = 1;
echo max($x + 1, $x + 2), "\n";
echo sprintf("%d-%d", $x + 1, $x + 2), "\n";
function g($v) {
    return $v * 2;
}
echo max(g(1), g(3)), "\n";
--EXPECT--
3
2-3
6
