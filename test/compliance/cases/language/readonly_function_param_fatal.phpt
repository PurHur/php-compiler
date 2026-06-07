--TEST--
Language: readonly on standalone function parameter — compile-time fatal (#6291)
--FILE--
<?php
function f(readonly string $x) {
    echo $x;
}
f('hi');
--EXPECT_EXIT--
255
