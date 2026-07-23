--TEST--
Closure must not advertise __debugInfo — Zend uses get_debug_info handler (#22565, re-#7069)
--FILE--
<?php
$c = function () {};

echo 'method_exists=', method_exists($c, '__debugInfo') ? '1' : '0', "\n";
echo 'in_gcm=', in_array('__debugInfo', get_class_methods($c), true) ? '1' : '0', "\n";
echo 'hasMethod=', (new ReflectionObject($c))->hasMethod('__debugInfo') ? '1' : '0', "\n";

try {
    $c->__debugInfo();
    echo "call=ok\n";
} catch (Error $e) {
    echo "call=Error\n";
}
--EXPECT--
method_exists=0
in_gcm=0
hasMethod=0
call=Error
