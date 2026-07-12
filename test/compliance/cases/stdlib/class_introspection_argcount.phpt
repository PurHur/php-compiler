--TEST--
stdlib is_a/is_subclass_of/method_exists/property_exists too-few-args ArgumentCountError (#17905, ext/standard/class.c)
--FILE--
<?php
declare(strict_types=1);

try {
    is_a('x');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    is_subclass_of('x');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    method_exists('x');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    property_exists('x');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
is_a() expects at least 2 arguments, 1 given
is_subclass_of() expects at least 2 arguments, 1 given
method_exists() expects exactly 2 arguments, 1 given
property_exists() expects exactly 2 arguments, 1 given
