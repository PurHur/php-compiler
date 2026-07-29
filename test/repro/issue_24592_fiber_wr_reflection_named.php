<?php
/**
 * Issue #24592: Fiber::__construct / WeakReference::create Reflection + named args.
 * php-src: Zend/zend_fibers.stub.php, Zend/zend_weakrefs.stub.php
 */
foreach (
    [
        [Fiber::class, '__construct'],
        [WeakReference::class, 'create'],
    ] as [$c, $m]
) {
    $r = new ReflectionMethod($c, $m);
    $ns = [];
    foreach ($r->getParameters() as $p) {
        $ns[] = $p->getName();
    }
    echo "$c::$m arity=", $r->getNumberOfParameters(), ' [', implode(',', $ns), "]\n";
}
try {
    $f = new Fiber(callback: function () {});
    echo "fiber_named=OK\n";
} catch (Throwable $e) {
    echo 'fiber_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $obj = new stdClass();
    WeakReference::create(object: $obj);
    echo "wr_named=OK\n";
} catch (Throwable $e) {
    echo 'wr_named=', get_class($e), ':', $e->getMessage(), "\n";
}
