--TEST--
language: Closure::fromCallable([$this, private]) class-in-closure invoke outside (#29208, zend_closures.c)
--FILE--
<?php
$r = (function () {
    class F {
        private function p() { return 1; }
        public function g() { return Closure::fromCallable([$this, 'p']); }
    }
    $c = (new F())->g();
    echo 'ok=', $c(), "\n";
})();
--EXPECT--
ok=1
