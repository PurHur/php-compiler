--TEST--
stdlib round() invalid legacy mode int — JIT ValueError on PHP 8.4 profile (#15802)
--JIT--
--FILE--
<?php
try {
    round(1.5, 0, 99);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)
--EXPECT_EXIT--
0
