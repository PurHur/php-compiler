--TEST--
PHP 8.4 asymmetric visibility: JIT catchable Error on private(set) write (#4029)
--FILE--
<?php
class Demo {
    public private(set) string $name = 'x';
}
$d = new Demo();
try {
    $d->name = 'z';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify private(set) property Demo::$name from global scope
