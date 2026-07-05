<?php

declare(strict_types=1);

/**
 * Maintainer repro: nullsafe ?-> on uninitialized nullable typed property (#16637, re-#5220).
 */

class B {
    public string $v = 'ok';
}

class A {
    public ?B $b;
}

$a = new A();
echo $a->b?->v ?? 'null', "\n";
try {
    echo $a->b, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
