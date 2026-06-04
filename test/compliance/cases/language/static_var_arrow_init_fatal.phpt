--TEST--
Language: function static initialized with arrow function — compile-time fatal (#5478)
--FILE--
<?php
function g() {
    static $a = fn () => 1;
}
g();
--EXPECT_EXIT--
255
