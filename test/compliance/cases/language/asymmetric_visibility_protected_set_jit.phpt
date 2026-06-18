--TEST--
Language: protected(set) JIT read + catchable Error on write (#9310)
--JIT--
--FILE--
<?php
class A {
    protected(set) string $x = 'ok';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'nope';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ok
Cannot modify protected(set) property A::$x from global scope
