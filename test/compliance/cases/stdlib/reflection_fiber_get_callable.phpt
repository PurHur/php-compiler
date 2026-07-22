--TEST--
ReflectionFiber::getCallable() returns Fiber entry Closure (#22066, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$cb = static function (int $n): int {
    Fiber::suspend($n);
    return $n * 2;
};
$fiber = new Fiber($cb);
$fiber->start(5);
$ref = new ReflectionFiber($fiber);
if (!method_exists($ref, 'getCallable')) {
    fwrite(STDERR, "MISSING ReflectionFiber::getCallable\n");
    exit(1);
}
$got = $ref->getCallable();
echo get_class($got), "\n";
echo 'same=', (int) ($got === $cb), "\n";
--EXPECT--
Closure
same=1
