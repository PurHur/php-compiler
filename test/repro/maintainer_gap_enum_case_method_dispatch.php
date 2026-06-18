<?php
enum E {
    case A;
    public function m(): string { return 'x'; }
}

interface I { public function foo(): void; }
enum E2 implements I {
    case A;
    public function foo(): void { echo "ok\n"; }
}

echo E::A->m(), "\n";
E2::A->foo();
