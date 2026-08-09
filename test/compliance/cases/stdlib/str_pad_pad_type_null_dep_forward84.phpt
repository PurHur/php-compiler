--TEST--
stdlib str_pad(null) $pad_type — Z_PARAM_LONG soft-null DEP+coerce to STR_PAD_LEFT (#29353, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
function str_pad_pad_type_null_dep_vm_handler(int $errno, string $errstr): bool
{
    echo "ERR:$errno:$errstr\n";
    return true;
}
set_error_handler('str_pad_pad_type_null_dep_vm_handler');
try {
    echo var_export(str_pad('a', 5, '.', null), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ERR:8192:str_pad(): Passing null to parameter #4 ($pad_type) of type int is deprecated
'....a'
