<?php
declare(strict_types=1);

$fiber = new Fiber(static function (int $n): int {
    Fiber::suspend($n);
    return $n * 2;
});
$fiber->start(5);
$ref = new ReflectionFiber($fiber);
if (!method_exists($ref, 'getCallable')) {
    fwrite(STDERR, "MISSING ReflectionFiber::getCallable\n");
    exit(1);
}
echo get_class($ref->getCallable()), "\n";
