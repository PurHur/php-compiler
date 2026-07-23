--TEST--
Fiber must not advertise getTrace()/getTraceAsString() — Zend uses ReflectionFiber (#22562)
--FILE--
<?php
$f = new Fiber(function () {
    Fiber::suspend(1);
});
$f->start();

echo 'method_getTrace=', method_exists($f, 'getTrace') ? '1' : '0', "\n";
echo 'method_getTraceAsString=', method_exists($f, 'getTraceAsString') ? '1' : '0', "\n";
echo 'gcm_getTrace=', in_array('getTrace', get_class_methods($f), true) ? '1' : '0', "\n";
echo 'gcm_getTraceAsString=', in_array('getTraceAsString', get_class_methods($f), true) ? '1' : '0', "\n";

try {
    $f->getTrace();
    echo "call_getTrace=ok\n";
} catch (Error $e) {
    echo 'call_getTrace=Error', "\n";
}

try {
    $f->getTraceAsString();
    echo "call_getTraceAsString=ok\n";
} catch (Error $e) {
    echo 'call_getTraceAsString=Error', "\n";
}

// Stack capture remains on ReflectionFiber (php-src).
$rf = new ReflectionFiber($f);
$trace = $rf->getTrace();
echo is_array($trace) ? "reflection_getTrace=ok\n" : "reflection_getTrace=bad\n";
--EXPECT--
method_getTrace=0
method_getTraceAsString=0
gcm_getTrace=0
gcm_getTraceAsString=0
call_getTrace=Error
call_getTraceAsString=Error
reflection_getTrace=ok
