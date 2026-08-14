--TEST--
language: ReflectionFiber excess argc ACE + getTrace(string) TypeError (#30928, ext/reflection/php_reflection.c)
--FILE--
<?php
$f = new Fiber(function () { Fiber::suspend(); });
$f->start();
$r = new ReflectionFiber($f);
foreach (['getCallable', 'getExecutingFile', 'getExecutingLine', 'getFiber'] as $m) {
    try {
        $r->$m('x');
        echo $m, ": OK\n";
    } catch (Throwable $e) {
        echo $m, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    $r->getTrace('x');
    echo "getTrace: OK\n";
} catch (Throwable $e) {
    echo 'getTrace: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $r->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo "getTrace2: OK\n";
} catch (Throwable $e) {
    echo 'getTrace2: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $t = $r->getTrace();
    echo 'getTrace0: ', is_array($t) ? 'array' : gettype($t), "\n";
} catch (Throwable $e) {
    echo 'getTrace0: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
getCallable: ArgumentCountError: ReflectionFiber::getCallable() expects exactly 0 arguments, 1 given
getExecutingFile: ArgumentCountError: ReflectionFiber::getExecutingFile() expects exactly 0 arguments, 1 given
getExecutingLine: ArgumentCountError: ReflectionFiber::getExecutingLine() expects exactly 0 arguments, 1 given
getFiber: ArgumentCountError: ReflectionFiber::getFiber() expects exactly 0 arguments, 1 given
getTrace: TypeError: ReflectionFiber::getTrace(): Argument #1 ($options) must be of type int, string given
getTrace2: ArgumentCountError: ReflectionFiber::getTrace() expects at most 1 argument, 2 given
getTrace0: array
