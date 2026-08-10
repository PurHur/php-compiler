--TEST--
stdlib float math(null) TypeError under strict_types (#29782, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
foreach (['sin', 'sqrt', 'log', 'exp', 'deg2rad', 'atan2'] as $fn) {
    try {
        if ('atan2' === $fn) {
            $fn(null, 1.0);
        } else {
            $fn(null);
        }
        echo "bad:{$fn}\n";
    } catch (TypeError $e) {
        echo "ok:{$fn}:", $e->getMessage(), "\n";
    }
}
--EXPECT--
ok:sin:sin(): Argument #1 ($num) must be of type float, null given
ok:sqrt:sqrt(): Argument #1 ($num) must be of type float, null given
ok:log:log(): Argument #1 ($num) must be of type float, null given
ok:exp:exp(): Argument #1 ($num) must be of type float, null given
ok:deg2rad:deg2rad(): Argument #1 ($num) must be of type float, null given
ok:atan2:atan2(): Argument #1 ($y) must be of type float, null given
