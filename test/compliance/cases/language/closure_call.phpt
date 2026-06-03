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
--EXPECT--
ok
