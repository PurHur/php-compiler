<?php

declare(strict_types=1);

/**
 * Repro for #12310 — createLazyProxy() void factory (php-src-strict).
 *
 * @see Zend/zend_lazy_objects.c
 */

class Svc
{
    public int $v = 0;
}

$proxy = createLazyProxy(Svc::class, function (Svc $o): void {
    $o->v = 99;
});

try {
    echo $proxy->v, "\n";
} catch (LogicException $e) {
    echo 'fail void: ', $e->getMessage(), "\n";
    exit(1);
}

echo "ok\n";
