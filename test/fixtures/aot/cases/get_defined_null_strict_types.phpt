--TEST--
AOT: get_defined_constants/functions/get_loaded_extensions(null) under strict_types TypeError (#30169, Z_PARAM_BOOL)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(get_defined_constants(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(get_defined_functions(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(get_loaded_extensions(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
get_defined_constants(): Argument #1 ($categorize) must be of type bool, null given
get_defined_functions(): Argument #1 ($exclude_disabled) must be of type bool, null given
get_loaded_extensions(): Argument #1 ($zend_extensions) must be of type bool, null given
