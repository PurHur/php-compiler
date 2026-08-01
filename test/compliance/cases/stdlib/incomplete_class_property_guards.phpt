--TEST--
stdlib __PHP_Incomplete_Class property read/isset/write guards (#19632, zend_object_handlers.c)
--FILE--
<?php
class Secret {
    public $secret = 42;
}
$blob = serialize(new Secret());
$obj = unserialize($blob, ['allowed_classes' => false]);
echo get_class($obj), "\n";
echo ($obj instanceof __PHP_Incomplete_Class) ? "incomplete\n" : "no\n";

$warnings = 0;
set_error_handler(function () use (&$warnings) {
    $warnings++;
    return true;
});

var_export(isset($obj->secret));
echo "\n";
var_export($obj->secret);
echo "\n";
var_export(empty($obj->secret));
echo "\n";
var_export(property_exists($obj, 'secret'));
echo "\n";

try {
    $obj->secret = 99;
    echo "wrote\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'incomplete object') ? "write_err\n" : "other_err\n");
}

try {
    unset($obj->secret);
    echo "unset_ok\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'incomplete object') ? "unset_err\n" : "other_err\n");
}

echo "warnings=", $warnings, "\n";

// Internals still see stored properties (var_export / get_object_vars).
$vars = get_object_vars($obj);
echo isset($vars['secret']) && 42 === $vars['secret'] ? "vars_ok\n" : "vars_bad\n";
--EXPECT--
__PHP_Incomplete_Class
incomplete
false
NULL
true
false
write_err
unset_err
warnings=4
vars_ok
