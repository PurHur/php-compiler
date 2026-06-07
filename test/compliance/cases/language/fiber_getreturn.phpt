--TEST--
Fiber return value via getReturn() (issue #5019, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function (): int {
    return 42;
});
$f->start();
var_dump($f->getReturn());

$g = new Fiber(function (): void {
    Fiber::suspend('x');
});
$g->start();
try {
    $g->getReturn();
} catch (FiberError $e) {
    echo $e->getMessage(), "\n";
}

$h = new Fiber(function (): int {
    return 7;
});
try {
    $h->getReturn();
} catch (FiberError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
int(42)
Cannot get fiber return value: The fiber has not returned
Cannot get fiber return value: The fiber has not been started
