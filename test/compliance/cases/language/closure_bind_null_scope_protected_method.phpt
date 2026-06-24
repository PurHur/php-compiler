--TEST--
Language: Closure::bind() null scope — protected method Error cites Closure scope (#10109, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    protected function m(): int {
        return 1;
    }
}

$cl = Closure::bind(function (): int {
    return $this->m();
}, new C(), null);

try {
    echo $cl(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to protected method C::m() from scope Closure
