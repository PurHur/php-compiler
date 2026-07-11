--TEST--
stdlib microtime(null) under strict_types throws TypeError (#17049, ext/standard/microtime.c)
--FILE--
<?php
declare(strict_types=1);

try {
    microtime(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
microtime(): Argument #1 ($as_float) must be of type bool, null given
