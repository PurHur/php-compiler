--TEST--
language: define(..., null) $case_insensitive → TypeError under strict_types (#31406, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(define('X_31406', 1, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo defined('X_31406') ? "defined\n" : "undef\n";
--EXPECT--
TypeError: define(): Argument #3 ($case_insensitive) must be of type bool, null given
undef
