--TEST--
Stdlib: Fiber::getTrace()/getTraceAsString() on suspended fiber (VM, #6470)
--FILE--
<?php
function depth(): void {
    Fiber::suspend(['marker' => 1]);
}

$f = new Fiber(function (): void {
    depth();
});
$f->start();

echo method_exists(Fiber::class, 'getTrace') ? "getTrace yes\n" : "getTrace no\n";
echo method_exists(Fiber::class, 'getTraceAsString') ? "getTraceAsString yes\n" : "getTraceAsString no\n";

$trace = $f->getTrace();
echo is_array($trace) && isset($trace[0]['function']) ? "trace ok\n" : "trace bad\n";
echo $trace[0]['function'], "\n";
echo count($trace) >= 2 ? "depth ok\n" : "depth bad\n";

$str = $f->getTraceAsString();
echo is_string($str) && str_starts_with($str, '#0') ? "trace_str ok\n" : "trace_str bad\n";

try {
    $f->getTrace();
    echo "still_suspended ok\n";
} catch (FiberError $e) {
    echo "still_suspended bad\n";
}

$f->resume(null);

try {
    $f->getTrace();
    echo "terminated bad\n";
} catch (FiberError $e) {
    echo "terminated ok\n";
}

$fresh = new Fiber(function (): void {
    Fiber::suspend(1);
});
try {
    $fresh->getTrace();
    echo "not_started bad\n";
} catch (FiberError $e) {
    echo "not_started ok\n";
}
--EXPECT--
getTrace yes
getTraceAsString yes
trace ok
suspend
depth ok
trace_str ok
still_suspended ok
terminated ok
not_started ok
