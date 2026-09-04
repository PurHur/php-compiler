<?php

declare(strict_types=1);

/**
 * #36382 — Closure::bindTo mid-AOT must keep insert block after ClosureBindRuntime
 * NestedJIT (parentless valueBoxKind calls). php-src: Zend/zend_closures.c
 */
class C
{
    private $x = 1;

    public function make(): Closure
    {
        return function () {
            return $this->x;
        };
    }
}

$c = new C();
$fn = $c->make();
$bound = $fn->bindTo($c, C::class);
echo $bound(), "\n";
