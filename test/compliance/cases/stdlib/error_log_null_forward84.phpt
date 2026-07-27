--TEST--
stdlib error_log(null) — TypeError on 8.4 forward profile (#23858, reverts #21446, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(error_log(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
error_log(): Argument #1 ($message) must be of type string, null given
