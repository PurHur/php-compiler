--TEST--
stdlib putenv() null under strict_types throws TypeError JIT (#17041, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    putenv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
--EXPECT--
putenv(): Argument #1 ($assignment) must be of type string, null given
