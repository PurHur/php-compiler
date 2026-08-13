--TEST--
language: ReflectionEnum getCases/hasCase/getCase excess argc → ArgumentCountError JIT (#30865, php_reflection.c)
--FILE--
<?php
enum ReflEnumArgcEJit { case A; }
$r = new ReflectionEnum('ReflEnumArgcEJit');
try {
    echo count($r->getCases(1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export($r->hasCase('A', 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getCase('A', 1)->getName(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export($r->hasCase());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getCase()->getName(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', count($r->getCases()), ',', $r->hasCase('A') ? '1' : '0', ',', $r->getCase('A')->getName(), "\n";
--EXPECT--
ArgumentCountError: ReflectionEnum::getCases() expects exactly 0 arguments, 1 given
ArgumentCountError: ReflectionEnum::hasCase() expects exactly 1 argument, 2 given
ArgumentCountError: ReflectionEnum::getCase() expects exactly 1 argument, 2 given
ArgumentCountError: ReflectionEnum::hasCase() expects exactly 1 argument, 0 given
ArgumentCountError: ReflectionEnum::getCase() expects exactly 1 argument, 0 given
ok=1,1,A
