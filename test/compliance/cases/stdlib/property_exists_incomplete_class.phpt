--TEST--
stdlib property_exists() on __PHP_Incomplete_Class — warning + false (#26366, zend_builtin_functions.c)
--FILE--
<?php
class Secret {
    public $v = 1;
}
$blob = serialize(new Secret());
$obj = unserialize($blob, ['allowed_classes' => false]);
echo get_class($obj), "\n";

$warnings = 0;
set_error_handler(function ($errno, $errstr) use (&$warnings) {
    if (str_contains($errstr, 'incomplete object')) {
        $warnings++;
    }
    return true;
});

var_export(property_exists($obj, 'v'));
echo "\n";
var_export(property_exists($obj, '__PHP_Incomplete_Class_Name'));
echo "\n";
// Class-string form does not warn (no object handlers).
var_export(property_exists('__PHP_Incomplete_Class', 'v'));
echo "\n";
echo "warnings=", $warnings, "\n";
--EXPECT--
__PHP_Incomplete_Class
false
false
false
warnings=2
