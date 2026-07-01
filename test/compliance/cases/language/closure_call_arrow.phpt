--TEST--
language: arrow function Closure::call() binds $this and invokes private method (#14809, zend_closures.c)
--FILE--
<?php
class C {
    private int $v = 2;

    private function m(): int {
        return $this->v;
    }
}

$c = new C();
$arrow = fn () => $this->m();
var_dump($arrow->call($c));
--EXPECT--
int(2)
