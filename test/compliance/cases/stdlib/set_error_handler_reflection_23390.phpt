--TEST--
set_error_handler Reflection callback/error_levels + named args (#23390, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('set_error_handler');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

function set_error_handler_reflection_23390_cb(): bool
{
    return false;
}

set_error_handler(callback: 'set_error_handler_reflection_23390_cb');
echo "ok\n";
set_error_handler(callback: 'set_error_handler_reflection_23390_cb', error_levels: E_WARNING);
echo "ok_levels\n";

try {
    set_error_handler(error_handler: 'set_error_handler_reflection_23390_cb');
    echo "error_handler accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

try {
    set_error_handler(callback: 'set_error_handler_reflection_23390_cb', error_types: E_ALL);
    echo "error_types accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
callback,error_levels
ok
ok_levels
Unknown named parameter $error_handler
Unknown named parameter $error_types
