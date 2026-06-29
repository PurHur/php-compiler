<?php

declare(strict_types=1);

// Issue #13531 — Closure::call() temporary $this for private member access (Zend/zend_closures.c).
class C
{
    private function m(): string
    {
        return 'ok';
    }
}

$c = new C();
$fn = function (): string {
    return $this->m();
};

$b = $fn->bindTo($c, C::class);
echo $b(), "\n";
echo $fn->call($c), "\n";
