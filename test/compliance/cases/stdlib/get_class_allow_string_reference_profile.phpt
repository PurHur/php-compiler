--TEST--
Stdlib: get_class() second argument rejected on reference profile (#17395, basic_functions.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') === '8.4' || getenv('PHP_COMPILER_PROFILE') === 'forward') {
    die('skip requires reference profile (unset PHP_COMPILER_PROFILE)');
}
?>
--FILE--
<?php
try {
    get_class(new stdClass(), false);
    echo "no-error\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_class() expects at most 1 argument, 2 given
