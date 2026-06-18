<?php
declare(strict_types=1);

/**
 * Issue #9784: Fiber::throw() — caller catch when fiber does not handle injected exception.
 */
$f = new Fiber(function (): void {
    Fiber::suspend();
});

$f->start();

try {
    $f->throw(new Exception('x'));
    echo "no catch\n";
} catch (Throwable $e) {
    echo 'caught '.get_class($e).': '.$e->getMessage()."\n";
}
