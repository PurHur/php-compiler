--TEST--
Language: undefined bare constant in namespace cites FQ name once (#10510, Zend/zend_constants.c)
--FILE--
<?php
try {
    var_export(empty(N\UNDEF_CONST));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
Undefined constant "N\UNDEF_CONST"
