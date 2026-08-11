--TEST--
stdlib get_class_vars(null) TypeError without null-string Deprecated (#30060, Zend/zend_builtin_functions.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ([
    ['null', static fn () => get_class_vars(null)],
    ['1', static fn () => get_class_vars(1)],
    ['var-null', static function () {
        $x = null;
        return get_class_vars($x);
    }],
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
null: get_class_vars(): Argument #1 ($class) must be a valid class name,  given
1: get_class_vars(): Argument #1 ($class) must be a valid class name, 1 given
var-null: get_class_vars(): Argument #1 ($class) must be a valid class name,  given
