--TEST--
Language: instance method first-class callable $obj->m(...) (#7007, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function m(): int {
        return 1;
    }
}

$c = new C();
$f = $c->m(...);
var_dump($f());
--EXPECT--
int(1)
