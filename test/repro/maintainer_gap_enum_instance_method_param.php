<?php
enum E: string {
    case A = 'a';
}

class C {
    public function f(E $e): void {
        echo get_class($e), "\n";
    }
    public function g(E|int $x): void {
        echo is_object($x) ? get_class($x) : (string) $x, "\n";
    }
}

(new C())->f(E::A);
(new C())->g(E::A);
(new C())->g(1);
