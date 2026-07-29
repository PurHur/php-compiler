<?php
/**
 * Repro #24591 — Closure::bind/call/bindTo Reflection names + Zend named args
 * (Zend/zend_closures.stub.php).
 */
class C
{
}
$c = function () {
    return 42;
};
foreach (['bind', 'call', 'bindTo'] as $method) {
    $r = new ReflectionMethod(Closure::class, $method);
    $ns = [];
    foreach ($r->getParameters() as $p) {
        $ns[] = $p->getName();
    }
    echo $method.' names=', implode(',', $ns), "\n";
}
try {
    Closure::bind(closure: $c, newThis: null);
    echo "bind_named=OK\n";
} catch (Throwable $e) {
    echo 'bind_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    Closure::bind(old: $c, to: null);
    echo "bind_old=OK\n";
} catch (Throwable $e) {
    echo 'bind_old=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'call_named=', $c->call(newThis: new C()), "\n";
} catch (Throwable $e) {
    echo 'call_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $c->bindTo(newThis: null);
    echo "bindTo_named=OK\n";
} catch (Throwable $e) {
    echo 'bindTo_named=', get_class($e), ':', $e->getMessage(), "\n";
}
