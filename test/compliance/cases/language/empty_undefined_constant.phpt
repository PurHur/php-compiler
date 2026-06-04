--TEST--
Language: empty() on undefined bare constant throws runtime Error (#5355, zend_compile.c)
--FILE--
<?php
try {
    var_export(empty(UNDEFINED_CONST_XYZ));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
Undefined constant "UNDEFINED_CONST_XYZ"
