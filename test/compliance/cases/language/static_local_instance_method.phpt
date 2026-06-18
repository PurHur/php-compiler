--TEST--
Function-local static in instance method persists across calls (#9425, Zend/zend_execute.c)
--FILE--
<?php
class C {
    public function f(): int {
        static $n = 0;
        return ++$n;
    }
}
$c = new C();
var_dump($c->f(), $c->f());
$a = new C();
$b = new C();
var_dump($a->f(), $b->f(), $a->f());
--EXPECT--
int(1)
int(2)
int(3)
int(4)
int(5)
