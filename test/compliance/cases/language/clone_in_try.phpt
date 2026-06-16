--TEST--
Language: clone inside try inherits outer locals (#9114, Zend/zend_execute.c)
--FILE--
<?php
class C {
    public int $n = 1;
}
$obj = new C();
try {
    $c = clone $obj;
    var_dump($c->n);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
int(1)
