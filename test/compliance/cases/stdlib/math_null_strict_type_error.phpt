--TEST--
stdlib abs() — null under declare(strict_types=1) raises TypeError (#16410, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);
try {
    echo abs(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
abs(): Argument #1 ($num) must be of type int|float, null given
