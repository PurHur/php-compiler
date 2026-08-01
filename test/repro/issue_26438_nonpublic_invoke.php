<?php
/**
 * Repro #26438 — non-public __invoke: Zend warns + `$obj()` dispatches;
 * explicit `$obj->__invoke()` still enforces visibility.
 */
error_reporting(E_ALL);

class Priv
{
    private function __invoke(): mixed
    {
        return 42;
    }
}

class Prot
{
    protected function __invoke(): mixed
    {
        return 7;
    }
}

$p = new Priv;
try {
    echo 'call:', $p(), "\n";
} catch (Throwable $e) {
    echo 'call:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $v = $p->__invoke();
    echo 'method:', $v, "\n";
} catch (Throwable $e) {
    echo 'method:', get_class($e), ':', $e->getMessage(), "\n";
}

$t = new Prot;
try {
    echo 'prot:', $t(), "\n";
} catch (Throwable $e) {
    echo 'prot:', get_class($e), ':', $e->getMessage(), "\n";
}
