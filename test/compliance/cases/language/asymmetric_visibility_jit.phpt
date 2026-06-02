--TEST--
PHP 8.4 asymmetric visibility: JIT read + catchable Error on private(set) write (#4020)
--JIT--
--FILE--
<?php
class Demo {
    public private(set) string $name = 'x';
}
$d = new Demo();
echo $d->name, "\n";
try {
    $d->name = 'z';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
x
Cannot modify private(set) property Demo::$name from global scope
