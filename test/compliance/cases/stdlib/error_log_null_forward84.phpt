--TEST--
stdlib error_log(null) — DEP+coerce on 8.4 forward profile (#21446, reverts #20253, ext/standard/basic_functions.c)
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
true COERCED
