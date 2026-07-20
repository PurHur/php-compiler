--TEST--
Language: asymmetric visibility — catchable Error on illegal external write (#6834, zend_object_handlers.c)
--FILE--
<?php
class PrivateSet {
    public (private(set)) string $name = 'x';
}
$p = new PrivateSet();
try {
    $p->name = 'bad';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $p->name, "\n";
--EXPECT--
Error: Cannot modify private(set) property PrivateSet::$name from global scope
x
