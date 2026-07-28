--TEST--
AOT: named args skipping an earlier optional must not crash or misbind (#23972)
--FILE--
<?php
function n($a = 1, $b = 2) {
    echo "$a-$b\n";
}
n(b: 7);
n(a: 3, b: 4);
--EXPECT--
1-7
3-4
