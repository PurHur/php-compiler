--TEST--
language: ReflectionEnum isBacked/getBackingType excess argc → ArgumentCountError JIT (#30929, php_reflection.c)
--FILE--
<?php
enum ReflEnumIsBackedArgcEJit: int { case A = 1; }
$r = new ReflectionEnum('ReflEnumIsBackedArgcEJit');
try {
    var_export($r->isBacked(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getBackingType(1)->getName(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', $r->isBacked() ? '1' : '0', ',', $r->getBackingType()->getName(), "\n";
--EXPECT--
ArgumentCountError: ReflectionEnum::isBacked() expects exactly 0 arguments, 1 given
ArgumentCountError: ReflectionEnum::getBackingType() expects exactly 0 arguments, 1 given
ok=1,int
