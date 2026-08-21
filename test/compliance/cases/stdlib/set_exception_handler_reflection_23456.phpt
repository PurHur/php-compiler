--TEST--
set_exception_handler Reflection callback + named args (#23456, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('set_exception_handler');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

function set_exception_handler_reflection_23456_cb($e): void
{
}

set_exception_handler(callback: 'set_exception_handler_reflection_23456_cb');
echo "ok\n";
restore_exception_handler();

try {
    set_exception_handler(exception_handler: 'set_exception_handler_reflection_23456_cb');
    echo "exception_handler accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
callback
ok
Unknown named parameter $exception_handler
