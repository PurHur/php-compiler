--TEST--
Language: function static initialized with closure — compile-time fatal (#5478)
--FILE--
<?php
function f() {
    static $c = function () { return 1; };
    return $c();
}
echo f(), f();
--EXPECT_EXIT--
255
