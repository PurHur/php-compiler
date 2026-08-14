--TEST--
get_parent_class()/siblings TypeError bool actual is true/false not bool (#29631, zend_execute.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['get_parent_class(false)', static fn () => get_parent_class(false)],
    ['get_parent_class(true)', static fn () => get_parent_class(true)],
    ['get_class(false)', static fn () => get_class(false)],
    ['method_exists(false)', static fn () => method_exists(false, 'x')],
    ['get_class_methods(false)', static fn () => get_class_methods(false)],
] as [$label, $call]) {
    try {
        $call();
        echo $label, ": uncaught\n";
    } catch (TypeError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
get_parent_class(false): get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, false given
get_parent_class(true): get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, true given
get_class(false): get_class(): Argument #1 ($object) must be of type object, false given
method_exists(false): method_exists(): Argument #1 ($object_or_class) must be of type object|string, false given
get_class_methods(false): get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, false given
