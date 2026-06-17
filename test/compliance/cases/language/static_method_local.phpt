--TEST--
Language: static local in instance method increments across calls (#9351, Zend/zend_execute.c)
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
--EXPECT--
int(1)
int(2)
--CREDITS--
PurHur/php-compiler issue #9351
