<?php
// #29208 — Closure::fromCallable([$this, private]) when class is declared inside a closure.
// Invoke the resulting Closure from the outer closure (outside the class) — Zend allows this
// because fromCallable already bound scope; re-checking caller visibility on invoke must not deny it.
$r = (function () {
    class F {
        private function p() { return 1; }
        public function g() { return Closure::fromCallable([$this, 'p']); }
    }
    try {
        $c = (new F())->g();
        return 'ok=' . $c();
    } catch (Throwable $e) {
        return get_class($e) . ':' . $e->getMessage();
    }
})();
echo $r, "\n";

// Top-level control: same invoke-outside shape must stay green (peer of #27137).
class A29208 {
    private function priv(): int { return 7; }
    public function g() { return Closure::fromCallable([$this, 'priv']); }
}
try {
    $c = (new A29208())->g();
    echo 'top_ok=', $c(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
