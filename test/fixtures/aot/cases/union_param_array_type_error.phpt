--TEST--
AOT: int|string param + array — Uncaught TypeError exit 255 (#29859)
--FILE--
<?php
function f(int|string $x) {
    return $x;
}
f([]);
--EXPECT--
--EXPECT_EXIT--
255
