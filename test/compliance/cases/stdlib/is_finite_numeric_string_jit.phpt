--TEST--
stdlib is_finite()/is_infinite()/is_nan() JIT — Z_PARAM_DOUBLE coercion (#4728)
--FILE--
<?php
var_export(is_finite('3.14'));
echo "\n";
var_export(is_finite('10'));
echo "\n";
var_export(is_finite(true));
echo "\n";
var_export(is_finite(null));
echo "\n";
var_export(is_infinite('3.14'));
echo "\n";
var_export(is_nan('3.14'));
echo "\n";
try {
    is_finite([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_nan('nan');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
true
false
false
is_finite(): Argument #1 ($num) must be of type float, array given
is_nan(): Argument #1 ($num) must be of type float, string given
