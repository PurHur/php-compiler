--TEST--
stdlib get_class_vars(null) under strict_types — TypeError only, no Deprecated (#30060)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    get_class_vars(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
get_class_vars(): Argument #1 ($class) must be a valid class name,  given
