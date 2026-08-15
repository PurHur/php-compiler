--TEST--
stdlib dirname(null $levels) JIT TypeError under strict_types (#31210, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    dirname('/a/b/c', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
dirname(): Argument #2 ($levels) must be of type int, null given
