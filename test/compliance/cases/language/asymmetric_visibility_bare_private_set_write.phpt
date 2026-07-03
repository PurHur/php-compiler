--TEST--
Language: bare private(set) write enforcement from global scope (#15694, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
$c = new C();
echo $c->p, "\n";
try {
    $c->p = 'y';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
x
Error: Cannot modify private(set) property C::$p from global scope
