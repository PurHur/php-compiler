--TEST--
language: Closure::call() temporary $this (issue #4927, Zend/zend_closures.c)
--FILE--
<?php
class C {
    private function m(): string { return 'ok'; }
}
$c = new C();
$cl = Closure::bind(function (): string { return $this->m(); }, $c, C::class);
echo $cl->call($c), "\n";

// Instance dispatch + private access without bindTo (#6411 regression).
$fn = function (): string { return $this->m(); };
try {
    echo $fn->call($c), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ok
ok
