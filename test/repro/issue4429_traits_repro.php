<?php

trait T1 { public function f() { return 't1'; } }
trait T2 { public function f() { return 't2'; } public function g() { return 'g2'; } }

class C {
    use T1, T2 {
        T1::f insteadof T2;
        T2::g as private gg;
    }
}

$c = new C();
echo $c->f(), "\n";
try {
    $c->gg();
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

