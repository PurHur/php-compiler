--TEST--
stdlib is_a/is_subclass_of(null $allow_string) under strict_types TypeError (#31339, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(is_a(new stdClass(), 'stdClass', null));
    echo "\nuncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(is_subclass_of(new class extends stdClass {}, 'stdClass', null));
    echo "\nuncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
// Two-arg forms still OK under strict_types.
echo is_a(new stdClass(), 'stdClass') ? "is_a_ok\n" : "is_a_fail\n";
echo is_subclass_of(new class extends stdClass {}, 'stdClass') ? "is_subclass_ok\n" : "is_subclass_fail\n";
--EXPECT--
is_a(): Argument #3 ($allow_string) must be of type bool, null given
is_subclass_of(): Argument #3 ($allow_string) must be of type bool, null given
is_a_ok
is_subclass_ok
