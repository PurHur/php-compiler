--TEST--
stdlib class_alias() duplicate internal class alias throws ValueError (#18290, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    class_alias('stdClass', 'stdClass');
    echo "alias ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ValueError: class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given
