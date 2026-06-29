--TEST--
language: Closure::call() private method access with bindTo parity (#13531, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    private function m(): string { return 'ok'; }
}

$c = new C();
$fn = function (): string { return $this->m(); };

echo $fn->bindTo($c, C::class)(), "\n";
echo $fn->call($c), "\n";
--EXPECT--
ok
ok
