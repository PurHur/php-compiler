<?php

// #27143 — Closure::fromCallable([$this, protected]) from subclass (sibling of #27137).
class A {
    protected function f(): string {
        return 'A';
    }
}

class B extends A {
    public function g(): void {
        $c = Closure::fromCallable([$this, 'f']);
        echo "this_form=", $c(), "\n";
    }
}

(new B())->g();
