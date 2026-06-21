<?php

declare(strict_types=1);

// Maintainer repro for #9645 / #4613 — static closure bindTo/bind must Error (Zend/zend_closures.c).

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
try {
    $fn->bindTo($c);
    echo "bindTo ok\n";
} catch (Error) {
    echo "Error: Cannot bind static closure to object\n";
}

try {
    Closure::bind($fn, $c, 'C');
    echo "bind ok\n";
} catch (Error) {
    echo "Error: Cannot bind static closure to object\n";
}

$unbound = $fn->bindTo(null);
echo $unbound === null ? "null\n" : "object\n";
