--TEST--
Language: $expr::class TypeError uses zend_zval_value_name under PROFILE=8.4 (#29576, #29592)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$s = 'stdClass';
try {
    echo $s::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$i = 0;
try {
    echo $i::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$b = true;
try {
    echo $b::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$b = false;
try {
    echo $b::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot use "::class" on string
TypeError: Cannot use "::class" on int
TypeError: Cannot use "::class" on true
TypeError: Cannot use "::class" on false
