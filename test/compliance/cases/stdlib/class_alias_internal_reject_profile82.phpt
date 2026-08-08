--TEST--
stdlib class_alias() rejects internal originals on PROFILE≤8.2 (#29150, Zend/zend_builtin_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--SKIPIF--
<?php
if (\PHPCompiler\CompilerVersion::allowsClassAliasOfInternalClass()) {
    die('skip internal class_alias allowed on PHP 8.3+ profile');
}
?>
--FILE--
<?php
error_reporting(E_ALL);
try {
    var_export(class_alias('stdClass', 'SC29150'));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
try {
    var_export(class_alias('Exception', 'E29150'));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
class User29150 {}
var_export(class_alias(User29150::class, 'U29150'));
echo "\n";
--EXPECT--
ValueError:class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given
ValueError:class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given
true
