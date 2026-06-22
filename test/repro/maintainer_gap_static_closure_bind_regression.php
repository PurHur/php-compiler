<?php

declare(strict_types=1);

// Maintainer repro for #10432 / #9645 — static closure bindTo/bind warn and no-op (Zend/zend_closures.c).

class C {
    public int $x = 1;

    public function make(): Closure {
        return static function () {
            return 0;
        };
    }
}

$c = new C();
$fn = $c->make();
$fn->bindTo($c);
echo "bindTo ok\n";

Closure::bind($fn, $c, 'C');
echo "bind ok\n";

$unbound = $fn->bindTo(null);
echo $unbound === null ? "null\n" : "object\n";
