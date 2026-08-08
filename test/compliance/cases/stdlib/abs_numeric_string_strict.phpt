--TEST--
stdlib abs() — string/bool under declare(strict_types=1) raise TypeError (#4189, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);
foreach (["5", true] as $v) {
    try {
        echo abs($v), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
abs(): Argument #1 ($num) must be of type int|float, string given
abs(): Argument #1 ($num) must be of type int|float, true given
