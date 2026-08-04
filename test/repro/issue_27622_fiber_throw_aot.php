<?php
declare(strict_types=1);

/**
 * Issue #27622: Fiber::throw() — AOT must propagate uncaught exception to caller catch.
 */
$f = new Fiber(function () {
    echo 'in:', Fiber::suspend('s'), "\n";

    return 'done';
});
echo 'start:', $f->start(), "\n";
try {
    echo 'throw:', $f->throw(new RuntimeException('boom')), "\n";
} catch (Throwable $e) {
    echo 'catch:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'term:', $f->isTerminated() ? '1' : '0', "\n";
