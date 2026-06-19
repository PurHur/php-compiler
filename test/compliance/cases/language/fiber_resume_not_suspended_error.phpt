--TEST--
Fiber::resume() on non-suspended fiber throws FiberError with Zend message (#10150)
--FILE--
<?php
declare(strict_types=1);

$f = new Fiber(function () {
    return 1;
});
$f->start();
try {
    $f->resume();
} catch (FiberError $e) {
    echo $e->getMessage(), "\n";
    echo get_class($e), "\n";
}
--EXPECT--
Cannot resume a fiber that is not suspended
FiberError
