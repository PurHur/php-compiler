--TEST--
AOT: function-static string concat persists across calls (#32889)
--FILE--
<?php
function f() {
    static $s = 'hi';
    $s .= '!';
    return $s;
}
function g() {
    static $s = 'hi';
    $s = $s . '!';
    return $s;
}
echo f(), f(), '|', g(), g(), "\n";
--EXPECT--
hi!hi!!|hi!hi!!
--EXPECT_EXIT--
0
