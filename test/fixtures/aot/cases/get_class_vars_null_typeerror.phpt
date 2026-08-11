--TEST--
AOT get_class_vars(null) TypeError without null-string Deprecated (#30060)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    get_class_vars(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    get_class_vars(1);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
get_class_vars(): Argument #1 ($class) must be a valid class name,  given
get_class_vars(): Argument #1 ($class) must be a valid class name, 1 given
