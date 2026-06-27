<?php

declare(strict_types=1);

/**
 * Repro for #12309 — createlazyghost() first property read (php-src-strict).
 *
 * @see Zend/zend_lazy_objects.c
 */

class C
{
    public int $v = 0;
}

// Case 1: void initializer (primary repro from issue)
$ghost = createLazyGhost(C::class, function (C $o): void {
    $o->v = 42;
});

try {
    echo $ghost->v, "\n";
} catch (LogicException $e) {
    echo 'fail void: ', $e->getMessage(), "\n";
    exit(1);
}

// Case 2: initializer returns ghost object — php-src ignores return (#12309)
$ghost2 = createLazyGhost(C::class, function (C $o) {
    $o->v = 99;
    return $o;
});

try {
    echo $ghost2->v, "\n";
} catch (LogicException $e) {
    echo 'fail return_obj: ', $e->getMessage(), "\n";
    exit(1);
}

echo "ok\n";
