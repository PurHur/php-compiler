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
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    Closure::bind($fn, $c, 'C');
    echo "bind ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$unbound = $fn->bindTo(null);
echo $unbound === null ? "null\n" : "object\n";
