--TEST--
stdlib class_alias() rejects internal class targets (#9211, Zend/zend_builtin_functions.c)
--FILE--
<?php
try {
    class_alias('stdClass', 'SC9211');
    echo "alias ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(class_exists('SC9211', false));
echo "\n";
--EXPECT--
ValueError: class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given
false
