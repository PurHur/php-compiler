--TEST--
stdlib proc_nice() null under strict_types throws TypeError (#17267, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    proc_nice(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
proc_nice(): Argument #1 ($priority) must be of type int, null given
