--TEST--
never parameter type: compile-time fatal (issue #3506)
--FILE--
<?php
function f(never $x) {
    echo "hi";
}
f(1);
--EXPECT_EXIT--
255
