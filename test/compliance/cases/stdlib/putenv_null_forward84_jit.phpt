--TEST--
stdlib putenv(null) — TypeError JIT forward 8.4 profile (#21004, re-#17041, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    putenv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

try {
    putenv('');
    echo "empty: uncaught\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
putenv(): Argument #1 ($assignment) must be of type string, null given
empty: putenv(): Argument #1 ($assignment) must have a valid syntax
