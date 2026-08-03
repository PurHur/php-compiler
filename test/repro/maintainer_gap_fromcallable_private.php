<?php

// #27137 — Closure::fromCallable([$this, private]) must not treat `$this` as class "this".
class A {
    private function priv(): int {
        return 7;
    }

    public function run(): void {
        $c = Closure::fromCallable([$this, 'priv']);
        echo "this_form=", $c(), "\n";
    }
}

(new A())->run();
