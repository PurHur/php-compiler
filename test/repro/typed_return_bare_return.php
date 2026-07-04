<?php

declare(strict_types=1);

/**
 * Bare `return;` in typed non-void closure/method must fatal (#16117, Zend/zend_execute.c).
 */

$fn = function (): int {
    return;
};

try {
    $fn();
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class C {
    public function f(): int {
        return;
    }
}

try {
    (new C())->f();
    echo "method no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

function typed_bare_return_fn(): int {
    return;
}

try {
    typed_bare_return_fn();
    echo "function no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
