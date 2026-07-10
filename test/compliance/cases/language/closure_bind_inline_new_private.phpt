--TEST--
language: Closure::bind(inline closure, new $obj, $scope) — private method access (#17633, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
class A {
    private function f(): string { return 'ok'; }
}
$c = Closure::bind(function (): string { return $this->f(); }, new A(), A::class);
echo $c(), "\n";
?>
--EXPECT--
ok
