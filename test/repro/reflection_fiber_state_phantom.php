<?php
// #22422 — ReflectionFiber must not advertise Fiber state probes (php-src 8.2 profile).
$f = new Fiber(function () { Fiber::suspend(1); });
$f->start();
$rf = new ReflectionFiber($f);
foreach (['isStarted', 'isSuspended', 'isRunning', 'isTerminated'] as $m) {
    echo $m, '=', method_exists($rf, $m) ? '1' : '0', "\n";
}
try {
    $rf->isStarted();
    echo "call=ok\n";
} catch (Error $e) {
    echo "call=Error\n";
}
