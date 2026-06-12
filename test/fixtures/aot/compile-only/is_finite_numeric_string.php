<?php
// Compile-only (#4728): is_finite()/is_infinite()/is_nan() Z_PARAM_DOUBLE lowering for AOT.
var_export(is_finite('3.14'));
echo "\n";
var_export(is_finite('10'));
echo "\n";
try {
    is_finite([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
