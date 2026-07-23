--TEST--
ReflectionFiber isStarted/isSuspended/isRunning/isTerminated phantom on 8.2 reference (#22422, ext/reflection/php_reflection.c)
--FILE--
<?php
$f = new Fiber(function () { Fiber::suspend(1); });
$f->start();
$rf = new ReflectionFiber($f);
foreach (['isStarted', 'isSuspended', 'isRunning', 'isTerminated'] as $m) {
    echo $m, '=', method_exists($rf, $m) ? 'yes' : 'no', "\n";
}
try {
    $rf->isStarted();
    echo "call=ok\n";
} catch (Error $e) {
    echo "call=Error\n";
}
--EXPECT--
isStarted=no
isSuspended=no
isRunning=no
isTerminated=no
call=Error
