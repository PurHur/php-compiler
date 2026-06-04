--TEST--
Language: call-time pass-by-reference must not compile (PHP 8+, #5354)
--FILE--
<?php
function f($x) {
    return $x;
}
$a = 1;
echo f(&$a), "\n";
--EXPECT_EXIT--
255
