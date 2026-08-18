--TEST--
AOT: write to a function-static string variable (#31966)
--FILE--
<?php
function f() {
    static $s = 'y';
    $s = 'z';
    echo $s, "\n";
}
f();
--EXPECT--
z
--EXPECT_EXIT--
0
