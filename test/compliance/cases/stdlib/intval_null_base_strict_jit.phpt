--TEST--
stdlib intval(null $base) JIT TypeError under strict_types (#31227, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    intval('10', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
intval(): Argument #2 ($base) must be of type int, null given
